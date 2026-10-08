<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['librarian']);
$pageTitle = 'Librarian Dashboard';
$currentPage = 'dashboard.php';

$issuedToday = (int)$pdo->query("SELECT COUNT(*) FROM book_issues WHERE issue_date = CURDATE()")->fetchColumn();
$returnedToday = (int)$pdo->query("SELECT COUNT(*) FROM book_returns WHERE return_date = CURDATE()")->fetchColumn();
$overdueBooks = (int)$pdo->query("SELECT COUNT(*) FROM book_issues WHERE status='issued' AND due_date < CURDATE()")->fetchColumn();
$availableBooks = (int)$pdo->query("SELECT COALESCE(SUM(available_copies),0) FROM books")->fetchColumn();
$pendingReservations = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status IN ('pending','approved')")->fetchColumn();

$dueSoon = $pdo->query("
    SELECT bi.*, b.title, u.name student_name, s.roll_number
    FROM book_issues bi
    JOIN books b ON b.id=bi.book_id
    JOIN students s ON s.id=bi.student_id
    JOIN users u ON u.id=s.user_id
    WHERE bi.status IN ('issued','overdue') AND bi.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ORDER BY bi.due_date ASC LIMIT 8")->fetchAll();

$activeDigital = (int)$pdo->query("SELECT COUNT(*) FROM digital_books WHERE status='active'")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-4">
    <div class="card stat-card"><div class="icon blue"><i class="fa-solid fa-right-from-bracket"></i></div><div><div class="num"><?= $issuedToday ?></div><div class="label">Issued Today</div></div></div>
    <div class="card stat-card"><div class="icon green"><i class="fa-solid fa-right-to-bracket"></i></div><div><div class="num"><?= $returnedToday ?></div><div class="label">Returned Today</div></div></div>
    <div class="card stat-card"><div class="icon red"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="num"><?= $overdueBooks ?></div><div class="label">Overdue Books</div></div></div>
    <div class="card stat-card"><div class="icon yellow"><i class="fa-solid fa-file-pdf"></i></div><div><div class="num"><?= $activeDigital ?></div><div class="label">Digital Resources</div></div></div>
</div>

<div class="grid grid-3" style="margin-top:18px;">
    <a href="issue-book.php" class="card stat-card" style="text-decoration:none;">
        <div class="icon blue"><i class="fa-solid fa-plus"></i></div>
        <div><div class="num" style="font-size:15px;">Issue Physical Book</div><div class="label">Quick action</div></div>
    </a>
    <a href="return-book.php" class="card stat-card" style="text-decoration:none;">
        <div class="icon green"><i class="fa-solid fa-arrow-rotate-left"></i></div>
        <div><div class="num" style="font-size:15px;">Process Return</div><div class="label">Quick action</div></div>
    </a>
    <a href="digital-books.php" class="card stat-card" style="text-decoration:none;">
        <div class="icon yellow"><i class="fa-solid fa-laptop-code"></i></div>
        <div><div class="num" style="font-size:15px;">Digital E-Books</div><div class="label">Catalog & Usage</div></div>
    </a>
</div>

<div class="card" style="margin-top:18px;">
    <h3>Due Soon / Overdue</h3>
    <?php if (!$dueSoon): ?>
        <div class="empty-state"><i class="fa-solid fa-circle-check"></i>Nothing due in the next 3 days.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Student</th><th>Book</th><th>Due Date</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($dueSoon as $r):
                $displayStatus = strtotime($r['due_date']) < strtotime(date('Y-m-d')) ? 'overdue' : 'issued';
            ?>
                <tr>
                    <td><?= e($r['student_name']) ?> <span class="text-muted">(<?= e($r['roll_number']) ?>)</span></td>
                    <td><?= e($r['title']) ?></td>
                    <td><?= fmtDate($r['due_date']) ?></td>
                    <td><?= statusBadge($displayStatus) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
