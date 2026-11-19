<?php
/**
 * AJAX Open Library Search Handler
 * Searches Open Library API and cross-checks local database for duplicate entries.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['admin', 'librarian']);
header('Content-Type: application/json; charset=utf-8');

$query = trim($_GET['q'] ?? '');
$searchType = trim($_GET['type'] ?? 'all');
$openAccessOnly = !empty($_GET['open_access_only']);
$page = max(1, (int)($_GET['page'] ?? 1));

if ($query === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter a search term.', 'docs' => []]);
    exit;
}

// Build Open Library API search URL
$queryParam = match($searchType) {
    'title'  => 'title=' . urlencode($query),
    'author' => 'author=' . urlencode($query),
    'isbn'   => 'isbn=' . urlencode(preg_replace('/[^0-9X]/i', '', $query)),
    default  => 'q=' . urlencode($query),
};

$apiUrl = 'https://openlibrary.org/search.json?' . $queryParam . '&limit=24&page=' . $page;

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_USERAGENT, 'SmartLibrary-OpenLibrarySearch/2.0 (admin@smartlibrary.local)');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
$rawResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || !$rawResponse) {
    echo json_encode([
        'success' => false,
        'message' => 'Could not retrieve data from Open Library API. ' . ($curlError ?: "HTTP $httpCode"),
        'docs' => []
    ]);
    exit;
}

$data = json_decode($rawResponse, true);
if (!$data || !isset($data['docs'])) {
    echo json_encode(['success' => true, 'total' => 0, 'docs' => []]);
    exit;
}

// Fetch all existing digital books metadata to identify duplicates in batch
$existingTitles = $pdo->query("SELECT LOWER(TRIM(title)) AS title, LOWER(TRIM(author_name)) AS author_name, author_id, isbn, ia_id, id FROM digital_books")->fetchAll(PDO::FETCH_ASSOC);

$existingIaMap = [];
$existingIsbnMap = [];
$existingTitleMap = [];

foreach ($existingTitles as $row) {
    if (!empty($row['ia_id'])) {
        $existingIaMap[$row['ia_id']] = (int)$row['id'];
    }
    if (!empty($row['isbn'])) {
        $clean = preg_replace('/[^0-9X]/i', '', $row['isbn']);
        if ($clean) $existingIsbnMap[$clean] = (int)$row['id'];
    }
    if (!empty($row['title'])) {
        $existingTitleMap[$row['title']] = (int)$row['id'];
    }
}

$processedDocs = [];

foreach ($data['docs'] as $doc) {
    $title = trim($doc['title'] ?? '');
    if ($title === '') continue;

    $authors = $doc['author_name'] ?? [];
    $authorStr = !empty($authors) ? implode(', ', (array)$authors) : 'Unknown Author';
    $year = $doc['first_publish_year'] ?? null;
    $coverId = $doc['cover_i'] ?? null;
    $ebookAccess = $doc['ebook_access'] ?? 'no_ebook';
    $hasFulltext = !empty($doc['has_fulltext']);
    $publicScan = !empty($doc['public_scan_b']);
    $iaList = $doc['ia'] ?? [];
    $isbns = $doc['isbn'] ?? [];
    $firstIsbn = !empty($isbns) ? $isbns[0] : '';
    $workKey = $doc['key'] ?? '';

    // Pick the best Internet Archive identifier
    $bestIaId = '';
    if (!empty($iaList)) {
        // Filter out DAISY or non-book tokens if possible
        foreach ($iaList as $iaItem) {
            if (!str_contains($iaItem, 'daisy') && !str_starts_with($iaItem, 'bwb_')) {
                $bestIaId = $iaItem;
                break;
            }
        }
        if (!$bestIaId) {
            $bestIaId = $iaList[0];
        }
    }

    // Determine PDF Availability Status
    // Public domain / open access books on Open Library have ebook_access === 'public' or public_scan_b === true
    $pdfStatus = 'unavailable';
    $pdfStatusLabel = 'No PDF Available';
    $isEligible = false;

    if (!empty($bestIaId) && ($ebookAccess === 'public' || $publicScan)) {
        $pdfStatus = 'available';
        $pdfStatusLabel = 'Full PDF Available (Open Access)';
        $isEligible = true;
    } elseif ($ebookAccess === 'borrowable') {
        $pdfStatus = 'borrowable';
        $pdfStatusLabel = 'Borrowing Required (Protected)';
    } elseif ($ebookAccess === 'printdisabled') {
        $pdfStatus = 'restricted';
        $pdfStatusLabel = 'Print-Disabled Only';
    } elseif ($hasFulltext && !empty($bestIaId)) {
        // Full text flagged with an IA identifier
        $pdfStatus = 'available';
        $pdfStatusLabel = 'Full PDF Available';
        $isEligible = true;
    }

    // Apply open_access_only filter if requested
    if ($openAccessOnly && !$isEligible) {
        continue;
    }

    // Check duplicate status
    $isAlreadyInLibrary = false;
    $localBookId = null;

    if ($bestIaId && isset($existingIaMap[$bestIaId])) {
        $isAlreadyInLibrary = true;
        $localBookId = $existingIaMap[$bestIaId];
    } elseif ($firstIsbn) {
        $cleanIsbn = preg_replace('/[^0-9X]/i', '', $firstIsbn);
        if ($cleanIsbn && isset($existingIsbnMap[$cleanIsbn])) {
            $isAlreadyInLibrary = true;
            $localBookId = $existingIsbnMap[$cleanIsbn];
        }
    }

    if (!$isAlreadyInLibrary) {
        $normT = strtolower($title);
        if (isset($existingTitleMap[$normT])) {
            $isAlreadyInLibrary = true;
            $localBookId = $existingTitleMap[$normT];
        }
    }

    $coverUrl = $coverId 
        ? "https://covers.openlibrary.org/b/id/{$coverId}-M.jpg" 
        : "";

    $processedDocs[] = [
        'title'             => $title,
        'author'            => $authorStr,
        'year'              => $year,
        'isbn'              => $firstIsbn,
        'ia_id'             => $bestIaId,
        'cover_url'         => $coverUrl,
        'pdf_status'        => $pdfStatus,
        'pdf_status_label'  => $pdfStatusLabel,
        'is_eligible'       => $isEligible,
        'is_already_in_db'  => $isAlreadyInLibrary,
        'local_book_id'     => $localBookId,
        'openlibrary_key'   => $workKey,
        'ebook_access'      => $ebookAccess
    ];
}

echo json_encode([
    'success' => true,
    'total'   => $data['numFound'] ?? count($processedDocs),
    'docs'    => $processedDocs
], JSON_UNESCAPED_UNICODE);
