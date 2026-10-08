<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);
$pageTitle = 'Payments';
$currentPage = 'payments.php';

$payments = $pdo->query("
    SELECT p.*, u.name student_name, s.roll_number, rc.name received_by_name
    FROM payments p
    JOIN students s ON s.id = p.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN users rc ON rc.id = p.received_by
    ORDER BY p.id DESC")->fetchAll();

$totalCollected = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();
$thisMonth = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-3" style="margin-bottom:18px;">
    <div class="card stat-card">
        <div class="icon green"><i class="fa-solid fa-sack-dollar"></i></div>
        <div><div class="num">&#8377;<?= number_format($totalCollected, 2) ?></div><div class="label">Total Collected</div></div>
    </div>
    <div class="card stat-card">
        <div class="icon blue"><i class="fa-solid fa-calendar-days"></i></div>
        <div><div class="num">&#8377;<?= number_format($thisMonth, 2) ?></div><div class="label">This Month</div></div>
    </div>
    <div class="card stat-card">
        <div class="icon yellow"><i class="fa-solid fa-receipt"></i></div>
        <div><div class="num"><?= count($payments) ?></div><div class="label">Total Transactions</div></div>
    </div>
</div>
<div class="card">
    <div class="table-toolbar"><h3 class="mt-0">Payment History</h3><div></div></div>
    <?php if (!$payments): ?>
        <div class="empty-state"><i class="fa-solid fa-credit-card"></i>No payments recorded yet.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Student</th><th>Amount</th><th>Method</th><th>Received By</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= e($p['student_name']) ?> <span class="text-muted">(<?= e($p['roll_number']) ?>)</span></td>
                    <td>&#8377;<?= number_format($p['amount'], 2) ?></td>
                    <td><?= ucfirst(e($p['payment_method'])) ?></td>
                    <td><?= e($p['received_by_name'] ?? '—') ?></td>
                    <td><?= date('d M Y, h:i A', strtotime($p['payment_date'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
