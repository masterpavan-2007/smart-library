<?php
/**
 * Student Reading History & Saved Bookmarks
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['student']);
$pageTitle = 'Reading History';
$currentPage = 'reading-history.php';

// Find student ID
$stu = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
$stu->execute([$_SESSION['user_id']]);
$studentId = (int)$stu->fetchColumn();

// Fetch reading history sessions
$stmt = $pdo->prepare("
    SELECT rh.*, db.title, db.pages, db.language, db.cover_image, db.access_type,
           c.name AS category_name, COALESCE(a.name, db.author_name) AS author_name
    FROM reading_history rh
    JOIN digital_books db ON db.id = rh.digital_book_id
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN authors a ON a.id = db.author_id
    WHERE rh.student_id = ?
    ORDER BY rh.last_accessed_at DESC
");
$stmt->execute([$studentId]);
$history = $stmt->fetchAll();

// Fetch all bookmarks for this student
$bmStmt = $pdo->prepare("
    SELECT b.*, db.title AS book_title, c.name AS category_name
    FROM bookmarks b
    JOIN digital_books db ON db.id = b.digital_book_id
    LEFT JOIN categories c ON c.id = db.category_id
    WHERE b.student_id = ?
    ORDER BY b.created_at DESC
");
$bmStmt->execute([$studentId]);
$allBookmarks = $bmStmt->fetchAll();

// Calculate total reading stats
$totalBooksRead = count($history);
$completedBooks = count(array_filter($history, fn($h) => $h['reading_status'] === 'completed' || (float)$h['progress_percent'] >= 99.0));
$inProgressBooks = $totalBooksRead - $completedBooks;

include __DIR__ . '/../includes/header.php';
?>

<!-- Stat Cards -->
<div class="grid grid-3" style="margin-bottom:24px;">
    <div class="card stat-card">
        <div class="icon blue"><i class="fa-solid fa-book-open-reader"></i></div>
        <div>
            <div class="num"><?= $totalBooksRead ?></div>
            <div class="label">Total E-Books Read</div>
        </div>
    </div>
    <div class="card stat-card">
        <div class="icon yellow"><i class="fa-solid fa-spinner"></i></div>
        <div>
            <div class="num"><?= $inProgressBooks ?></div>
            <div class="label">Currently Reading</div>
        </div>
    </div>
    <div class="card stat-card">
        <div class="icon green"><i class="fa-solid fa-circle-check"></i></div>
        <div>
            <div class="num"><?= $completedBooks ?></div>
            <div class="label">Completed Books</div>
        </div>
    </div>
</div>

<!-- Reading History Table -->
<div class="card" style="margin-bottom:24px;">
    <div class="flex justify-between items-center" style="margin-bottom:14px;">
        <h3 style="margin:0;"><i class="fa-solid fa-clock-rotate-left text-primary"></i> Digital Reading Sessions</h3>
        <a href="virtual-library.php" class="btn btn-sm btn-outline"><i class="fa-solid fa-plus"></i> Browse More Books</a>
    </div>

    <?php if (empty($history)): ?>
        <div class="empty-state" style="padding:40px 20px;">
            <i class="fa-solid fa-book-open" style="font-size:36px; margin-bottom:10px;"></i>
            <h4>No reading history yet</h4>
            <p class="text-muted">Start reading books in the Virtual Library to track your reading journey and progress.</p>
            <a href="virtual-library.php" class="btn btn-primary">Go to Virtual Library</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Book Title</th>
                        <th>Category</th>
                        <th>Progress</th>
                        <th>Last Read Page</th>
                        <th>First Opened</th>
                        <th>Last Accessed</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $r): 
                        $pct = (float)$r['progress_percent'];
                        $isDone = ($r['reading_status'] === 'completed' || $pct >= 99);
                    ?>
                        <tr>
                            <td>
                                <strong><a href="read-book.php?id=<?= $r['digital_book_id'] ?>"><?= e($r['title']) ?></a></strong>
                                <div class="text-muted" style="font-size:12px;"><?= e($r['author_name']) ?></div>
                            </td>
                            <td><span class="badge badge-blue"><?= e($r['category_name'] ?: 'General') ?></span></td>
                            <td style="min-width:140px;">
                                <div class="flex items-center gap-2">
                                    <div style="flex:1; background:var(--border); border-radius:10px; height:7px; overflow:hidden;">
                                        <div style="background:<?= $isDone ? 'var(--success)' : 'var(--primary)' ?>; width:<?= min(100, $pct) ?>%; height:100%;"></div>
                                    </div>
                                    <span style="font-size:12px; font-weight:600; width:36px; text-align:right;"><?= round($pct) ?>%</span>
                                </div>
                            </td>
                            <td>Page <?= (int)$r['last_page'] ?> of <?= (int)$r['total_pages'] ?></td>
                            <td class="text-muted" style="font-size:13px;"><?= fmtDate($r['first_accessed_at']) ?></td>
                            <td class="text-muted" style="font-size:13px;"><?= date('d M Y, h:i A', strtotime($r['last_accessed_at'])) ?></td>
                            <td>
                                <?php if ($isDone): ?>
                                    <span class="badge badge-green"><i class="fa-solid fa-check"></i> Completed</span>
                                <?php else: ?>
                                    <span class="badge badge-yellow"><i class="fa-solid fa-book-open"></i> Reading</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="read-book.php?id=<?= $r['digital_book_id'] ?>" class="btn btn-sm btn-primary">
                                    <i class="fa-solid fa-book-open-reader"></i> Continue
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Saved Bookmarks Section -->
<div class="card">
    <div class="flex justify-between items-center" style="margin-bottom:14px;">
        <h3 style="margin:0;"><i class="fa-solid fa-bookmark text-primary"></i> My Saved Bookmarks</h3>
        <span class="badge badge-blue"><?= count($allBookmarks) ?> Bookmarks</span>
    </div>

    <?php if (empty($allBookmarks)): ?>
        <div class="empty-state" style="padding:30px 20px;">
            <i class="fa-regular fa-bookmark" style="font-size:32px; margin-bottom:8px;"></i>
            <p class="text-muted" style="margin:0;">No bookmarks saved yet. While reading any digital book, click the bookmark icon to save key pages and notes.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-3">
            <?php foreach ($allBookmarks as $bm): ?>
                <div class="card" style="border:1px solid var(--border); display:flex; flex-direction:column; justify-content:space-between; margin-bottom:0;">
                    <div>
                        <div class="flex justify-between items-start" style="margin-bottom:6px;">
                            <span class="badge badge-blue" style="font-size:11px;">Page <?= (int)$bm['page_number'] ?></span>
                            <span class="text-muted" style="font-size:11px;"><?= fmtDate($bm['created_at']) ?></span>
                        </div>
                        <strong style="font-size:14px; display:block; margin-bottom:4px;"><?= e($bm['book_title']) ?></strong>
                        <p class="text-muted" style="font-size:13px; margin:0 0 12px 0; background:var(--gray-light); padding:8px 10px; border-radius:6px;">
                            <i class="fa-solid fa-quote-left text-muted" style="font-size:10px;"></i> <?= e($bm['note'] ?: 'Bookmark on Page ' . $bm['page_number']) ?>
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <a href="read-book.php?id=<?= $bm['digital_book_id'] ?>#page=<?= $bm['page_number'] ?>" class="btn btn-sm btn-outline" style="flex:1; justify-content:center;">
                            <i class="fa-solid fa-arrow-right"></i> Open Page
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
