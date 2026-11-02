<?php
/**
 * AI Book Metadata Lookup Endpoint
 * Sourced from PDF metadata/text, OpenLibrary API, Google Books, and Wikipedia
 * Automatically matches database Category, Author, and Physical Book IDs.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['admin', 'librarian']);
header('Content-Type: application/json; charset=utf-8');

$query = trim($_GET['q'] ?? $_POST['q'] ?? '');
$titleInput = trim($_GET['title'] ?? $_POST['title'] ?? '');
$authorInput = trim($_GET['author'] ?? $_POST['author'] ?? '');
$isbnInput = trim($_GET['isbn'] ?? $_POST['isbn'] ?? '');

if ($isbnInput) {
    $searchQuery = 'isbn:' . preg_replace('/[^0-9X]/i', '', $isbnInput);
} elseif ($titleInput) {
    $searchQuery = $titleInput . ($authorInput ? ' ' . $authorInput : '');
} else {
    $searchQuery = $query;
}

if ($searchQuery === '') {
    echo json_encode(['success' => false, 'message' => 'No query or book details provided.']);
    exit;
}

$bookData = [
    'title' => $titleInput ?: $query,
    'author_name' => $authorInput,
    'author_id' => null,
    'category_id' => null,
    'category_name' => '',
    'book_id' => null,
    'year' => null,
    'pages' => null,
    'language' => 'English',
    'description' => '',
    'license_info' => 'Open Educational Resource',
    'source' => 'AI Extractor'
];

$rawSubjects = [];
$foundSources = [];

// Helper: HTTP GET request with User-Agent and timeout
function fetchApiUrl($url, $timeout = 10) {
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'user_agent' => 'SmartLibrary-AI/2.0 (contact: admin@smartlibrary.local)'
            ]
        ]);
        return @file_get_contents($url, false, $ctx);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SmartLibrary-AI/2.0 (contact: admin@smartlibrary.local)');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300) ? $res : false;
}

// 1. Try OpenLibrary API
$olUrl = 'https://openlibrary.org/search.json?q=' . urlencode($searchQuery) . '&limit=1';
$olRes = fetchApiUrl($olUrl);
$workKey = null;

if ($olRes) {
    $olJson = json_decode($olRes, true);
    $doc = $olJson['docs'][0] ?? null;
    if ($doc) {
        if (!empty($doc['title']) && empty($bookData['title'])) {
            $bookData['title'] = $doc['title'];
        }
        if (!empty($doc['author_name']) && empty($bookData['author_name'])) {
            $bookData['author_name'] = $doc['author_name'][0];
        }
        if (!empty($doc['first_publish_year'])) {
            $bookData['year'] = (int)$doc['first_publish_year'];
        }
        if (!empty($doc['number_of_pages_median']) && empty($bookData['pages'])) {
            $bookData['pages'] = (int)$doc['number_of_pages_median'];
        }
        if (!empty($doc['language'])) {
            $langCode = strtolower($doc['language'][0] ?? '');
            if (in_array($langCode, ['eng', 'en'])) $bookData['language'] = 'English';
            elseif (in_array($langCode, ['spa', 'es'])) $bookData['language'] = 'Spanish';
            elseif (in_array($langCode, ['fre', 'fra', 'fr'])) $bookData['language'] = 'French';
            elseif (in_array($langCode, ['ger', 'deu', 'de'])) $bookData['language'] = 'German';
            elseif (in_array($langCode, ['hin', 'hi'])) $bookData['language'] = 'Hindi';
        }
        if (!empty($doc['subject']) && is_array($doc['subject'])) {
            $rawSubjects = array_merge($rawSubjects, $doc['subject']);
        }
        if (!empty($doc['key'])) {
            $workKey = $doc['key'];
        }
        $foundSources[] = 'OpenLibrary';
    }
}

// 2. Fetch OpenLibrary Work details for rich description and subjects
if ($workKey) {
    $workUrl = 'https://openlibrary.org' . $workKey . '.json';
    $workRes = fetchApiUrl($workUrl);
    if ($workRes) {
        $workJson = json_decode($workRes, true);
        if ($workJson) {
            $desc = $workJson['description'] ?? '';
            if (is_array($desc)) {
                $desc = $desc['value'] ?? '';
            }
            if ($desc && empty($bookData['description'])) {
                // Clean markdown/newlines
                $descClean = preg_replace('/\[(.*?)\]\(.*?\)/', '$1', $desc);
                $bookData['description'] = trim($descClean);
            }
            if (!empty($workJson['subjects']) && is_array($workJson['subjects'])) {
                $rawSubjects = array_merge($rawSubjects, $workJson['subjects']);
            }
        }
    }
}

// 3. Try Google Books API (fallback or enrich)
$gbUrl = 'https://www.googleapis.com/books/v1/volumes?q=' . urlencode($searchQuery) . '&maxResults=1';
$gbRes = fetchApiUrl($gbUrl, 4);
if ($gbRes) {
    $gbJson = json_decode($gbRes, true);
    $item = $gbJson['items'][0]['volumeInfo'] ?? null;
    if ($item) {
        if (empty($bookData['title']) && !empty($item['title'])) {
            $bookData['title'] = $item['title'];
            if (!empty($item['subtitle'])) {
                $bookData['title'] .= ': ' . $item['subtitle'];
            }
        }
        if (empty($bookData['author_name']) && !empty($item['authors'])) {
            $bookData['author_name'] = implode(', ', $item['authors']);
        }
        if (empty($bookData['year']) && !empty($item['publishedDate'])) {
            if (preg_match('/\b\d{4}\b/', $item['publishedDate'], $m)) {
                $bookData['year'] = (int)$m[0];
            }
        }
        if (empty($bookData['pages']) && !empty($item['pageCount'])) {
            $bookData['pages'] = (int)$item['pageCount'];
        }
        if (empty($bookData['description']) && !empty($item['description'])) {
            $bookData['description'] = strip_tags($item['description']);
        }
        if (!empty($item['categories']) && is_array($item['categories'])) {
            $rawSubjects = array_merge($rawSubjects, $item['categories']);
        }
        $foundSources[] = 'Google Books';
    }
}

// 4. Try Wikipedia Summary API if description is still empty
if (empty($bookData['description']) && !empty($bookData['title'])) {
    $cleanWikiTitle = str_replace(' ', '_', preg_replace('/[^\w\s-]/u', '', $bookData['title']));
    // Try title + (book) first, then title
    foreach ([$cleanWikiTitle . '_(book)', $cleanWikiTitle] as $targetTitle) {
        $wikiUrl = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . urlencode($targetTitle);
        $wikiRes = fetchApiUrl($wikiUrl, 4);
        if ($wikiRes) {
            $wikiJson = json_decode($wikiRes, true);
            if (!empty($wikiJson['extract']) && ($wikiJson['type'] ?? '') === 'standard') {
                $bookData['description'] = trim($wikiJson['extract']);
                $foundSources[] = 'Wikipedia';
                break;
            }
        }
    }
}

// 5. Match with Local Database Categories
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$matchedCatId = null;
$matchedCatName = '';

$subjectText = strtolower(implode(' ', $rawSubjects) . ' ' . $bookData['title'] . ' ' . $bookData['description']);

// Keyword mapping dictionary for library categories
$categoryKeywords = [
    'Computer Science' => ['computer', 'programming', 'software', 'algorithm', 'coding', 'data structure', 'python', 'java', 'web development', 'architecture', 'clean code', 'database', 'system design'],
    'Technology' => ['technology', 'tech', 'digital', 'artificial intelligence', 'machine learning', 'computing', 'electronics', 'internet'],
    'Fiction' => ['fiction', 'novel', 'literature', 'stories', 'narrative', 'prose'],
    'Fantasy' => ['fantasy', 'magic', 'wizard', 'mythology', 'dragon', 'potter', 'hogwarts'],
    'Science Fiction' => ['science fiction', 'sci-fi', 'space', 'galaxy', 'cyberpunk', 'dystopian', 'future'],
    'Biography' => ['biography', 'autobiography', 'memoir', 'life of', 'steve jobs', 'biographies', 'famous persons'],
    'Mathematics' => ['mathematics', 'math', 'algebra', 'calculus', 'geometry', 'statistics', 'probability'],
    'Science' => ['science', 'physics', 'astronomy', 'biology', 'chemistry', 'universe', 'quantum', 'nature'],
    'History' => ['history', 'historical', 'war', 'revolution', 'ancient', 'civilization', 'empire', 'century'],
    'Self-Help' => ['self-help', 'habits', 'productivity', 'personal growth', 'motivation', 'success', 'mindset', 'atomic habits', 'psychology'],
    'Education' => ['education', 'learning', 'teaching', 'handbook', 'pedagogy', 'academic', 'textbook'],
    'Thriller' => ['thriller', 'suspense', 'crime', 'espionage'],
    'Horror' => ['horror', 'ghost', 'supernatural', 'fear'],
    'Mystery' => ['mystery', 'detective', 'sherlock', 'investigation', 'whodunit'],
    'Romance' => ['romance', 'love story', 'romantic'],
    'Comics' => ['comic', 'graphic novel', 'comics'],
    'Manga' => ['manga', 'anime', 'japanese comic'],
    'General Knowledge' => ['general knowledge', 'encyclopedia', 'trivia', 'facts']
];

// Check each database category against keywords and subject text
$bestScore = 0;
foreach ($categories as $cat) {
    $cName = $cat['name'];
    $score = 0;

    // Check if category name directly appears in title or subjects
    if (str_contains($subjectText, strtolower($cName))) {
        $score += 10;
    }

    if (isset($categoryKeywords[$cName])) {
        foreach ($categoryKeywords[$cName] as $kw) {
            if (str_contains($subjectText, strtolower($kw))) {
                $score += 5;
            }
        }
    }

    if ($score > $bestScore) {
        $bestScore = $score;
        $matchedCatId = $cat['id'];
        $matchedCatName = $cat['name'];
    }
}

// Fallback: Default to Fiction or General if no strong keyword
if (!$matchedCatId) {
    foreach ($categories as $cat) {
        if (strtolower($cat['name']) === 'general knowledge' || strtolower($cat['name']) === 'education') {
            $matchedCatId = $cat['id'];
            $matchedCatName = $cat['name'];
            break;
        }
    }
}

$bookData['category_id'] = $matchedCatId;
$bookData['category_name'] = $matchedCatName;

// 6. Match Author in Database
if (!empty($bookData['author_name'])) {
    $normAuthor = strtolower(trim($bookData['author_name']));
    $authorStmt = $pdo->prepare("SELECT id, name FROM authors WHERE LOWER(name) = ? OR LOWER(name) LIKE ? LIMIT 1");
    $authorStmt->execute([$normAuthor, "%$normAuthor%"]);
    $matchedAuthor = $authorStmt->fetch(PDO::FETCH_ASSOC);
    if ($matchedAuthor) {
        $bookData['author_id'] = (int)$matchedAuthor['id'];
        $bookData['author_name'] = $matchedAuthor['name'];
    } else {
        // Reverse check: check if any DB author's name is inside the incoming author string
        $allAuthors = $pdo->query("SELECT id, name FROM authors")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allAuthors as $a) {
            $aNameLower = strtolower($a['name']);
            if (str_contains($normAuthor, $aNameLower) || str_contains($aNameLower, $normAuthor)) {
                $bookData['author_id'] = (int)$a['id'];
                $bookData['author_name'] = $a['name'];
                break;
            }
        }
    }
}

// 7. Match Physical Book in Database
if (!empty($bookData['title'])) {
    $normTitle = strtolower(trim($bookData['title']));
    $bookStmt = $pdo->prepare("SELECT id, title, isbn FROM books WHERE LOWER(title) = ? OR LOWER(title) LIKE ? LIMIT 1");
    $bookStmt->execute([$normTitle, "%$normTitle%"]);
    $matchedPhysical = $bookStmt->fetch(PDO::FETCH_ASSOC);
    if ($matchedPhysical) {
        $bookData['book_id'] = (int)$matchedPhysical['id'];
    }
}

if (!empty($foundSources)) {
    $bookData['source'] = implode(' + ', array_unique($foundSources));
}

echo json_encode([
    'success' => true,
    'data' => $bookData
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
