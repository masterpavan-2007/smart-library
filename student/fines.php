<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['student']);
$pageTitle = 'My Fines';
$currentPage = 'fines.php';

$stu = $pdo->prepare("SELECT * FROM students WHERE user_id=?");
$stu->execute([$_SESSION['user_id']]);
$student = $stu->fetch();
$studentId = $student['id'];

$fines = $pdo->prepare("
    SELECT f.*, b.title FROM fines f
    LEFT JOIN book_issues bi ON bi.id = f.issue_id
    LEFT JOIN books b ON b.id = bi.book_id
    WHERE f.student_id=? ORDER BY f.id DESC");
$fines->execute([$studentId]);
$fines = $fines->fetchAll();

$payments = $pdo->prepare("SELECT * FROM payments WHERE student_id=? ORDER BY id DESC");
$payments->execute([$studentId]);
$payments = $payments->fetchAll();

$totalPending = 0;
foreach ($fines as $f) if ($f['status'] === 'pending') $totalPending += $f['amount'];

include __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-2" style="margin-bottom:18px;">
    <div class="card stat-card"><div class="icon red"><i class="fa-solid fa-coins"></i></div><div><div class="num">&#8377;<?= number_format($totalPending,2) ?></div><div class="label">Pending Fines</div></div></div>
    <div class="card stat-card"><div class="icon green"><i class="fa-solid fa-receipt"></i></div><div><div class="num"><?= count($payments) ?></div><div class="label">Payments Made</div></div></div>
</div>

<div class="card" style="margin-bottom:18px;">
    <h3>My Fines</h3>
    <?php if (!$fines): ?>
        <div class="empty-state"><i class="fa-solid fa-face-smile"></i>You have no fines. Keep it up!</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Book</th><th>Reason</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($fines as $f): ?>
                <tr>
                    <td><?= e($f['title'] ?? '—') ?></td>
                    <td><?= e($f['reason']) ?></td>
                    <td>&#8377;<?= number_format($f['amount'], 2) ?></td>
                    <td><?= statusBadge($f['status']) ?></td>
                    <td><?= fmtDate($f['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPending > 0): ?>
        <div class="alert alert-info" style="margin-top:14px;"><i class="fa-solid fa-circle-info"></i> Please pay your pending fines at the library counter to keep borrowing books.</div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Payment History</h3>
    <?php if (!$payments): ?>
        <div class="empty-state"><i class="fa-solid fa-credit-card"></i>No payments made yet.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Amount</th><th>Method</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr><td>&#8377;<?= number_format($p['amount'],2) ?></td><td><?= ucfirst(e($p['payment_method'])) ?></td><td><?= date('d M Y, h:i A', strtotime($p['payment_date'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
