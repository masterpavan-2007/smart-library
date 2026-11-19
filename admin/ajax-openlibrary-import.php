<?php
/**
 * AJAX Open Library & Internet Archive Automatic PDF Importer
 * Validates public domain rights, downloads PDF directly from Internet Archive,
 * validates PDF integrity, stores in uploads/digital-books, prevents duplicates,
 * and creates digital book record in MySQL database.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['admin', 'librarian']);
header('Content-Type: application/json; charset=utf-8');

// Accept JSON payload or standard POST
$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true) ?: $_POST;

$title    = trim($inputData['title'] ?? '');
$author   = trim($inputData['author'] ?? '');
$year     = !empty($inputData['year']) ? (int)$inputData['year'] : null;
$isbn     = trim($inputData['isbn'] ?? '');
$iaId     = trim($inputData['ia_id'] ?? '');
$coverUrl = trim($inputData['cover_url'] ?? '');
$workKey  = trim($inputData['openlibrary_key'] ?? '');

// 1. Basic validation
if ($title === '') {
    echo json_encode(['success' => false, 'message' => 'Book title is required.']);
    exit;
}

if ($iaId === '') {
    echo json_encode(['success' => false, 'message' => 'Internet Archive identifier is required for PDF import.']);
    exit;
}

// Ensure IA identifier contains only safe characters
if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $iaId)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Internet Archive identifier format.']);
    exit;
}

// 2. Duplicate Prevention Check (Requirement 5)
// Check by IA identifier
$stmtIa = $pdo->prepare("SELECT id, title FROM digital_books WHERE ia_id = ? LIMIT 1");
$stmtIa->execute([$iaId]);
$dupIa = $stmtIa->fetch();
if ($dupIa) {
    echo json_encode([
        'success'   => false,
        'duplicate' => true,
        'message'   => 'This book is already available in the library.',
        'book_id'   => $dupIa['id']
    ]);
    exit;
}

// Check by ISBN (if provided)
if ($isbn !== '') {
    $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);
    if ($cleanIsbn !== '') {
        $stmtIsbn = $pdo->prepare("SELECT id, title FROM digital_books WHERE isbn = ? LIMIT 1");
        $stmtIsbn->execute([$cleanIsbn]);
        $dupIsbn = $stmtIsbn->fetch();
        if ($dupIsbn) {
            echo json_encode([
                'success'   => false,
                'duplicate' => true,
                'message'   => 'This book is already available in the library.',
                'book_id'   => $dupIsbn['id']
            ]);
            exit;
        }
    }
}

// Check by Title & Author combination
$normTitle = strtolower($title);
$authorSearch = '%' . strtolower($author) . '%';
$stmtTitle = $pdo->prepare("
    SELECT id, title FROM digital_books 
    WHERE LOWER(TRIM(title)) = ? 
      AND (LOWER(author_name) LIKE ? OR author_id IN (SELECT id FROM authors WHERE LOWER(name) LIKE ?))
    LIMIT 1
");
$stmtTitle->execute([$normTitle, $authorSearch, $authorSearch]);
$dupTitle = $stmtTitle->fetch();
if ($dupTitle) {
    echo json_encode([
        'success'   => false,
        'duplicate' => true,
        'message'   => 'This book is already available in the library.',
        'book_id'   => $dupTitle['id']
    ]);
    exit;
}

// 3. Query Internet Archive Metadata to find the actual PDF file
$iaMetaUrl = "https://archive.org/metadata/" . urlencode($iaId);

$ch = curl_init($iaMetaUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_USERAGENT, 'SmartLibrary-PDFImporter/2.0 (admin@smartlibrary.local)');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
$iaMetaRaw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$iaMetaRaw) {
    echo json_encode(['success' => false, 'message' => "Could not retrieve metadata from Internet Archive for item: $iaId."]);
    exit;
}

$iaData = json_decode($iaMetaRaw, true);
if (!$iaData) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON metadata returned by Internet Archive.']);
    exit;
}

// Verify item is not dark / deleted
if (!empty($iaData['is_dark']) || !empty($iaData['metadata']['is_dark'])) {
    echo json_encode(['success' => false, 'message' => 'This item has been withdrawn or restricted by Internet Archive.']);
    exit;
}

$files = $iaData['files'] ?? [];
$chosenPdfFile = null;
$metaDescription = '';
$metaPages = 10;

if (isset($iaData['metadata']['description'])) {
    $desc = $iaData['metadata']['description'];
    if (is_array($desc)) $desc = implode(' ', $desc);
    $metaDescription = strip_tags($desc);
}
if (!empty($iaData['metadata']['imagecount'])) {
    $metaPages = (int)$iaData['metadata']['imagecount'];
}

// Find best PDF file
// Preferred: Text PDF or {iaId}.pdf
foreach ($files as $file) {
    $fn = strtolower($file['name'] ?? '');
    $fmt = strtolower($file['format'] ?? '');
    
    // Ignore encrypted tokens, daisy or non-pdf
    if (!str_ends_with($fn, '.pdf')) continue;
    if (str_contains($fn, '_encrypted') || str_contains($fmt, 'encrypted')) continue;

    $size = (int)($file['size'] ?? 0);
    // 50MB max limit (50 * 1024 * 1024 = 52428800)
    if ($size > 52428800) {
        continue;
    }

    if ($fmt === 'text pdf' || $fn === strtolower($iaId) . '.pdf') {
        $chosenPdfFile = $file['name'];
        break;
    }

    if (!$chosenPdfFile) {
        $chosenPdfFile = $file['name'];
    }
}

// If no file found in metadata list, try standard {iaId}.pdf convention
if (!$chosenPdfFile) {
    $chosenPdfFile = $iaId . '.pdf';
}

$pdfDownloadUrl = "https://archive.org/download/" . urlencode($iaId) . "/" . rawurlencode($chosenPdfFile);

// 4. Download PDF using PHP cURL to a temporary file
$uploadDir = realpath(__DIR__ . '/../uploads/digital-books');
if (!$uploadDir) {
    $uploadDir = __DIR__ . '/../uploads/digital-books';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
}

$tempFile = tempnam(sys_get_temp_dir(), 'ia_pdf_');
$fp = fopen($tempFile, 'wb');

if (!$fp) {
    echo json_encode(['success' => false, 'message' => 'Server could not create temporary file for PDF download.']);
    exit;
}

$ch = curl_init($pdfDownloadUrl);
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60s timeout for download
curl_setopt($ch, CURLOPT_USERAGENT, 'SmartLibrary-PDFImporter/2.0 (contact: admin@smartlibrary.local)');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

// Abort if file exceeds 50MB during download
curl_setopt($ch, CURLOPT_NOPROGRESS, false);
curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) {
    if ($downloaded > 52428800) {
        return 1; // abort curl
    }
    return 0;
});

$success = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$curlError = curl_error($ch);
curl_close($ch);
fclose($fp);

// 5. Check if download was successful and legal
if ($httpCode === 401 || $httpCode === 403) {
    @unlink($tempFile);
    echo json_encode([
        'success' => false,
        'message' => 'PDF download restricted by Internet Archive (HTTP ' . $httpCode . '). This book is subject to controlled lending restrictions and cannot be downloaded.'
    ]);
    exit;
}

if ($httpCode !== 200 && $httpCode !== 206) {
    @unlink($tempFile);
    echo json_encode([
        'success' => false,
        'message' => 'Could not download PDF from Internet Archive. HTTP ' . $httpCode . ' ' . $curlError
    ]);
    exit;
}

$downloadedSize = filesize($tempFile);
if ($downloadedSize < 1024) { // Less than 1KB is likely an error page
    @unlink($tempFile);
    echo json_encode(['success' => false, 'message' => 'Downloaded file is empty or corrupted (size < 1KB).']);
    exit;
}

// 6. Validate authentic PDF format
$handle = fopen($tempFile, 'rb');
$headerBytes = fread($handle, 1024);
fclose($handle);

if (!str_contains($headerBytes, '%PDF-')) {
    @unlink($tempFile);
    echo json_encode([
        'success' => false,
        'message' => 'Security Error: Downloaded file does not have a valid %PDF- header. File discarded.'
    ]);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $tempFile);
finfo_close($finfo);

if (!str_contains($mimeType, 'pdf') && !str_contains($mimeType, 'octet-stream')) {
    @unlink($tempFile);
    echo json_encode([
        'success' => false,
        'message' => "Security Error: File MIME type '$mimeType' is not a recognized PDF format."
    ]);
    exit;
}

// Try reading page count from PDF stream if available
$pdfPages = $metaPages;
if (preg_match('/\/Count\s+(\d+)/', $headerBytes, $mCount)) {
    $pdfPages = (int)$mCount[1];
}

// 7. Move PDF to permanent digital-books storage
$safeFileName = 'ebook_' . bin2hex(random_bytes(12)) . '.pdf';
$destPath = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $safeFileName;

if (!rename($tempFile, $destPath)) {
    // Fallback copy if cross-device rename fails
    if (!copy($tempFile, $destPath)) {
        @unlink($tempFile);
        echo json_encode(['success' => false, 'message' => 'Failed to store digital book file in uploads directory.']);
        exit;
    }
    @unlink($tempFile);
}

$fileSizeKb = max(1, round(filesize($destPath) / 1024));

// 8. Download Cover Image (if available)
$savedCoverName = null;
if ($coverUrl && filter_var($coverUrl, FILTER_VALIDATE_URL)) {
    $coverDir = realpath(__DIR__ . '/../uploads/books');
    if (!$coverDir) {
        $coverDir = __DIR__ . '/../uploads/books';
        if (!is_dir($coverDir)) mkdir($coverDir, 0777, true);
    }
    
    $chCov = curl_init($coverUrl);
    curl_setopt($chCov, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chCov, CURLOPT_TIMEOUT, 10);
    curl_setopt($chCov, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($chCov, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($chCov, CURLOPT_USERAGENT, 'SmartLibrary-CoverImporter/2.0');
    $coverBytes = curl_exec($chCov);
    $covCode = curl_getinfo($chCov, CURLINFO_HTTP_CODE);
    curl_close($chCov);

    if ($covCode === 200 && strlen($coverBytes) > 500) {
        $tempCover = tempnam(sys_get_temp_dir(), 'cov_');
        file_put_contents($tempCover, $coverBytes);
        $imgInfo = @getimagesize($tempCover);
        if ($imgInfo && in_array($imgInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP])) {
            $ext = ($imgInfo[2] === IMAGETYPE_PNG) ? '.png' : '.jpg';
            $savedCoverName = 'cover_' . bin2hex(random_bytes(8)) . $ext;
            copy($tempCover, rtrim($coverDir, '/\\') . DIRECTORY_SEPARATOR . $savedCoverName);
        }
        @unlink($tempCover);
    }
}

// 9. Match or Insert Author in Database
$authorId = null;
$cleanAuthor = trim($author ?: 'Unknown Author');

if ($cleanAuthor !== '') {
    $authorStmt = $pdo->prepare("SELECT id, name FROM authors WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $authorStmt->execute([$cleanAuthor]);
    $matchedAuthor = $authorStmt->fetch();
    
    if ($matchedAuthor) {
        $authorId = (int)$matchedAuthor['id'];
    } else {
        // Create new author record
        try {
            $insA = $pdo->prepare("INSERT INTO authors (name) VALUES (?)");
            $insA->execute([$cleanAuthor]);
            $authorId = (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            // Non-fatal, use authorName string
        }
    }
}

// 10. Match or Infer Category
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$matchedCatId = null;

$categoryKeywords = [
    'Fiction'         => ['fiction', 'novel', 'classic', 'literature', 'dracula', 'frankenstein', 'pride', 'stories'],
    'Fantasy'         => ['fantasy', 'magic', 'wizard', 'mythology', 'fairy'],
    'Science Fiction' => ['science fiction', 'sci-fi', 'space', 'galaxy', 'future', 'time machine', 'h.g. wells', 'verne'],
    'Mystery'         => ['mystery', 'detective', 'sherlock', 'holmes', 'conan doyle', 'crime'],
    'Horror'          => ['horror', 'ghost', 'vampire', 'gothic', 'macabre', 'poe'],
    'History'         => ['history', 'historical', 'civilization', 'revolution', 'war', 'ancient', 'rome', 'greece'],
    'Biography'       => ['biography', 'autobiography', 'memoir', 'life of'],
    'Science'         => ['science', 'physics', 'astronomy', 'biology', 'nature', 'chemistry'],
    'Mathematics'     => ['mathematics', 'math', 'algebra', 'calculus'],
    'Education'       => ['education', 'handbook', 'pedagogy', 'academic', 'textbook'],
    'General Knowledge'=> ['encyclopedia', 'general', 'essays', 'reference']
];

$haystack = strtolower($title . ' ' . $metaDescription . ' ' . $cleanAuthor);

foreach ($categoryKeywords as $catName => $kws) {
    foreach ($kws as $kw) {
        if (str_contains($haystack, $kw)) {
            foreach ($categories as $cat) {
                if (strcasecmp($cat['name'], $catName) === 0) {
                    $matchedCatId = (int)$cat['id'];
                    break 2;
                }
            }
        }
    }
}

// Fallback to Fiction or first category
if (!$matchedCatId) {
    foreach ($categories as $cat) {
        if (strcasecmp($cat['name'], 'Fiction') === 0 || strcasecmp($cat['name'], 'General Knowledge') === 0) {
            $matchedCatId = (int)$cat['id'];
            break;
        }
    }
    if (!$matchedCatId && !empty($categories)) {
        $matchedCatId = (int)$categories[0]['id'];
    }
}

// 11. Fetch description from Open Library Works if metadata description was empty
if ($metaDescription === '' && $workKey) {
    $workUrl = "https://openlibrary.org" . $workKey . ".json";
    $chWork = curl_init($workUrl);
    curl_setopt($chWork, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chWork, CURLOPT_TIMEOUT, 6);
    curl_setopt($chWork, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($chWork, CURLOPT_USERAGENT, 'SmartLibrary-WorkImporter/2.0');
    $wRaw = curl_exec($chWork);
    curl_close($chWork);
    if ($wRaw) {
        $wData = json_decode($wRaw, true);
        $wDesc = $wData['description'] ?? '';
        if (is_array($wDesc)) $wDesc = $wDesc['value'] ?? '';
        if ($wDesc) {
            $metaDescription = preg_replace('/\[(.*?)\]\(.*?\)/', '$1', $wDesc);
            $metaDescription = strip_tags($metaDescription);
        }
    }
}

if ($metaDescription === '') {
    $metaDescription = "Public domain digital edition of \"$title\" by $cleanAuthor, imported directly from the Open Library and Internet Archive.";
}

// 12. Insert record into digital_books
$insSql = "
    INSERT INTO digital_books (
        title, author_id, author_name, category_id, publisher_id,
        isbn, ia_id, publication_year, edition, language, pages,
        description, cover_image, file_path, file_format, file_size_kb,
        access_type, license_info, status
    ) VALUES (
        ?, ?, ?, ?, NULL,
        ?, ?, ?, '1st', 'English', ?,
        ?, ?, ?, 'PDF', ?,
        'open_access', 'Public Domain / Open Access (Internet Archive)', 'active'
    )
";

$stmtIns = $pdo->prepare($insSql);
$stmtIns->execute([
    $title,
    $authorId,
    $cleanAuthor,
    $matchedCatId,
    $isbn ?: null,
    $iaId,
    $year,
    $pdfPages,
    $metaDescription,
    $savedCoverName,
    $safeFileName,
    $fileSizeKb
]);

$newBookId = (int)$pdo->lastInsertId();

// 13. Log activity in activity_logs
logActivity($pdo, $_SESSION['user_id'], "Imported Open Library book: \"$title\" (IA: $iaId)");

echo json_encode([
    'success'      => true,
    'message'      => "Book \"$title\" imported successfully!",
    'book_id'      => $newBookId,
    'title'        => $title,
    'author'       => $cleanAuthor,
    'pages'        => $pdfPages,
    'file_size_kb' => $fileSizeKb,
    'read_url'     => basePath() . '/student/read-book.php?id=' . $newBookId
]);
