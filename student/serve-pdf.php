<?php
/**
 * Secure Digital Book / PDF Streaming Endpoint
 * Protects digital assets by enforcing authentication and preventing unauthorized direct URL exposure.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

// Requires any valid logged in user (student, librarian, admin)
requireRole(['student', 'librarian', 'admin']);

$bookId = (int)($_GET['id'] ?? 0);
if ($bookId <= 0) {
    http_response_code(400);
    die('Invalid digital resource ID requested.');
}

// Fetch book from database
$stmt = $pdo->prepare("SELECT * FROM digital_books WHERE id = ?");
$stmt->execute([$bookId]);
$book = $stmt->fetch();

if (!$book) {
    http_response_code(404);
    die('Digital book not found.');
}

if ($book['status'] !== 'active' && $_SESSION['role'] === 'student') {
    http_response_code(403);
    die('This digital resource is currently inactive or undergoing maintenance.');
}

// Verify student permissions for restricted books
if ($book['access_type'] === 'restricted' && $_SESSION['role'] === 'student') {
    // Restricted access requires approval or specific permissions
    http_response_code(403);
    die('You do not have permission to access this restricted digital resource. Please contact the librarian.');
}

// Secure file path resolution
$baseUploadDir = realpath(__DIR__ . '/../uploads/digital-books');
$fileName = basename($book['file_path']);
$targetPath = realpath($baseUploadDir . DIRECTORY_SEPARATOR . $fileName);

// Directory traversal prevention check
if (!$targetPath || !file_exists($targetPath) || !str_starts_with($targetPath, $baseUploadDir)) {
    http_response_code(404);
    die('Digital PDF document file is missing or unavailable on server.');
}

// Increment view count on digital book
try {
    $pdo->prepare("UPDATE digital_books SET view_count = view_count + 1 WHERE id = ?")->execute([$bookId]);
} catch (Exception $e) {
    // Non-blocking log
}

// Stream PDF file
$fileSize = filesize($targetPath);
$download = isset($_GET['download']) && $_GET['download'] === '1';
$disposition = $download ? 'attachment' : 'inline';
$safeDownloadName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $book['title']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . $safeDownloadName . '"');
header('Content-Length: ' . $fileSize);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600, must-revalidate');
header('Pragma: private');

// Clean any buffer
if (ob_get_level()) {
    ob_end_clean();
}

readfile($targetPath);
exit;
