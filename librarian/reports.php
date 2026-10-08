<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['librarian']);
$pageTitle = 'Daily Reports';
$currentPage = 'reports.php';

$date = $_GET['date'] ?? date('Y-m-d');

$issuedToday = $pdo->prepare("
    SELECT u.name student, s.roll_number, b.title, bi.due_date
    FROM book_issues bi JOIN students s ON s.id=bi.student_id JOIN users u ON u.id=s.user_id JOIN books b ON b.id=bi.book_id
    WHERE bi.issue_date = ? ORDER BY bi.id DESC");
$issuedToday->execute([$date]);
$issuedToday = $issuedToday->fetchAll();

$returnedToday = $pdo->prepare("
    SELECT u.name student, s.roll_number, b.title, br.late_days, br.fine_amount
    FROM book_returns br JOIN book_issues bi ON bi.id=br.issue_id JOIN students s ON s.id=bi.student_id JOIN users u ON u.id=s.user_id JOIN books b ON b.id=bi.book_id
    WHERE br.return_date = ? ORDER BY br.id DESC");
$returnedToday->execute([$date]);
$returnedToday = $returnedToday->fetchAll();

$overdueList = $pdo->query("
    SELECT u.name student, s.roll_number, b.title, bi.due_date, DATEDIFF(CURDATE(), bi.due_date) late_days
    FROM book_issues bi JOIN students s ON s.id=bi.student_id JOIN users u ON u.id=s.user_id JOIN books b ON b.id=bi.book_id
    WHERE bi.status='issued' AND bi.due_date < CURDATE() ORDER BY late_days DESC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar no-print">
    <form method="GET" class="flex gap-2">
        <input type="date" name="date" class="form-control" value="<?= e($date) ?>">
        <button class="btn btn-outline" type="submit">View</button>
    </form>
    <button class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Report</button>
</div>

<div class="grid grid-2">
    <div class="card">
        <h3>Books Issued — <?= fmtDate($date) ?></h3>
        <?php if (!$issuedToday): ?>
            <div class="empty-state"><i class="fa-solid fa-inbox"></i>No books issued on this date.</div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Student</th><th>Book</th><th>Due Date</th></tr></thead>
            <tbody><?php foreach ($issuedToday as $r): ?>
                <tr><td><?= e($r['student']) ?> (<?= e($r['roll_number']) ?>)</td><td><?= e($r['title']) ?></td><td><?= fmtDate($r['due_date']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h3>Books Returned — <?= fmtDate($date) ?></h3>
        <?php if (!$returnedToday): ?>
            <div class="empty-state"><i class="fa-solid fa-inbox"></i>No books returned on this date.</div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Student</th><th>Book</th><th>Late Days</th><th>Fine</th></tr></thead>
            <tbody><?php foreach ($returnedToday as $r): ?>
                <tr><td><?= e($r['student']) ?> (<?= e($r['roll_number']) ?>)</td><td><?= e($r['title']) ?></td><td><?= (int)$r['late_days'] ?></td><td><?= $r['fine_amount']>0 ? '&#8377;'.number_format($r['fine_amount'],2) : '—' ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:18px;">
    <h3>All Currently Overdue Books</h3>
    <?php if (!$overdueList): ?>
        <div class="empty-state"><i class="fa-solid fa-circle-check"></i>Nothing overdue right now.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Student</th><th>Book</th><th>Due Date</th><th>Late Days</th></tr></thead>
        <tbody><?php foreach ($overdueList as $r): ?>
            <tr><td><?= e($r['student']) ?> (<?= e($r['roll_number']) ?>)</td><td><?= e($r['title']) ?></td><td><?= fmtDate($r['due_date']) ?></td><td><span class="badge badge-red"><?= (int)$r['late_days'] ?> days</span></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
