<?php
/**
 * AJAX Endpoint for Reading Progress, Bookmarks, and Favorites
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Find student id if role is student
$studentId = null;
if ($_SESSION['role'] === 'student') {
    $st = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $st->execute([$_SESSION['user_id']]);
    $studentId = $st->fetchColumn();
    if (!$studentId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Student record not found']);
        exit;
    }
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? '';

switch ($action) {
    case 'save_progress':
        if (!$studentId) {
            echo json_encode(['success' => true, 'note' => 'Guest/Staff view; progress not persisted to student record']);
            exit;
        }

        $bookId = (int)($input['digital_book_id'] ?? 0);
        $page = max(1, (int)($input['page'] ?? 1));
        $totalPages = max(1, (int)($input['total_pages'] ?? 1));

        if ($bookId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid book ID']);
            exit;
        }

        $progressPercent = min(100.0, round(($page / $totalPages) * 100, 2));
        $status = ($page >= $totalPages || $progressPercent >= 99.0) ? 'completed' : 'reading';

        // Check existing reading history
        $chk = $pdo->prepare("SELECT id, reading_status FROM reading_history WHERE student_id = ? AND digital_book_id = ?");
        $chk->execute([$studentId, $bookId]);
        $existing = $chk->fetch();

        if ($existing) {
            $upd = $pdo->prepare("
                UPDATE reading_history 
                SET last_page = ?, total_pages = ?, progress_percent = ?, reading_status = ?, last_accessed_at = NOW() 
                WHERE id = ?
            ");
            $upd->execute([$page, $totalPages, $progressPercent, $status, $existing['id']]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO reading_history 
                (student_id, digital_book_id, first_accessed_at, last_accessed_at, last_page, total_pages, progress_percent, reading_status)
                VALUES (?, ?, NOW(), NOW(), ?, ?, ?, ?)
            ");
            $ins->execute([$studentId, $bookId, $page, $totalPages, $progressPercent, $status]);
            
            // Increment read_count on digital book
            $pdo->prepare("UPDATE digital_books SET read_count = read_count + 1 WHERE id = ?")->execute([$bookId]);
        }

        echo json_encode([
            'success' => true,
            'page' => $page,
            'total_pages' => $totalPages,
            'progress' => $progressPercent,
            'status' => $status
        ]);
        exit;

    case 'add_bookmark':
        if (!$studentId) {
            echo json_encode(['success' => false, 'error' => 'Students only']);
            exit;
        }

        $bookId = (int)($input['digital_book_id'] ?? 0);
        $pageNum = max(1, (int)($input['page_number'] ?? 1));
        $note = trim($input['note'] ?? 'Bookmark on Page ' . $pageNum);

        if ($bookId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid book ID']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO bookmarks (student_id, digital_book_id, page_number, note) VALUES (?, ?, ?, ?)");
        $stmt->execute([$studentId, $bookId, $pageNum, $note]);
        $newId = $pdo->lastInsertId();

        // Return all bookmarks for this book
        $all = $pdo->prepare("SELECT id, page_number, note, created_at FROM bookmarks WHERE student_id = ? AND digital_book_id = ? ORDER BY page_number ASC");
        $all->execute([$studentId, $bookId]);
        $list = $all->fetchAll();

        echo json_encode([
            'success' => true,
            'message' => 'Bookmark saved successfully',
            'bookmark_id' => $newId,
            'bookmarks' => $list
        ]);
        exit;

    case 'delete_bookmark':
        if (!$studentId) {
            echo json_encode(['success' => false, 'error' => 'Students only']);
            exit;
        }

        $bookmarkId = (int)($input['bookmark_id'] ?? 0);
        $del = $pdo->prepare("DELETE FROM bookmarks WHERE id = ? AND student_id = ?");
        $del->execute([$bookmarkId, $studentId]);

        echo json_encode(['success' => true, 'message' => 'Bookmark removed']);
        exit;

    case 'toggle_favorite':
        if (!$studentId) {
            echo json_encode(['success' => false, 'error' => 'Students only']);
            exit;
        }

        $digitalBookId = !empty($input['digital_book_id']) ? (int)$input['digital_book_id'] : null;
        $bookId = !empty($input['book_id']) ? (int)$input['book_id'] : null;

        if (!$digitalBookId && !$bookId) {
            echo json_encode(['success' => false, 'error' => 'No book specified']);
            exit;
        }

        if ($digitalBookId) {
            $chk = $pdo->prepare("SELECT id FROM favorites WHERE student_id = ? AND digital_book_id = ?");
            $chk->execute([$studentId, $digitalBookId]);
            $fav = $chk->fetch();

            if ($fav) {
                $pdo->prepare("DELETE FROM favorites WHERE id = ?")->execute([$fav['id']]);
                echo json_encode(['success' => true, 'is_favorite' => false, 'message' => 'Removed from Favorites']);
            } else {
                $pdo->prepare("INSERT INTO favorites (student_id, digital_book_id) VALUES (?, ?)")->execute([$studentId, $digitalBookId]);
                echo json_encode(['success' => true, 'is_favorite' => true, 'message' => 'Added to Favorites!']);
            }
        } else {
            $chk = $pdo->prepare("SELECT id FROM favorites WHERE student_id = ? AND book_id = ?");
            $chk->execute([$studentId, $bookId]);
            $fav = $chk->fetch();

            if ($fav) {
                $pdo->prepare("DELETE FROM favorites WHERE id = ?")->execute([$fav['id']]);
                echo json_encode(['success' => true, 'is_favorite' => false, 'message' => 'Removed from Favorites']);
            } else {
                $pdo->prepare("INSERT INTO favorites (student_id, book_id) VALUES (?, ?)")->execute([$studentId, $bookId]);
                echo json_encode(['success' => true, 'is_favorite' => true, 'message' => 'Added to Favorites!']);
            }
        }
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
}
