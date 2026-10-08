<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);

$pageTitle = 'Payments & Revenue Ledger';
$currentPage = 'payments.php';

$search = trim($_GET['q'] ?? '');
$methodFilter = $_GET['method'] ?? 'all';
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(p.payment_id LIKE ? OR u.name LIKE ? OR s.roll_number LIKE ? OR p.transaction_reference LIKE ? OR b.title LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($methodFilter !== 'all') {
    $where[] = "p.payment_method = ?";
    $params[] = $methodFilter;
}

if ($dateFrom !== '') {
    $where[] = "DATE(p.payment_date) >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "DATE(p.payment_date) <= ?";
    $params[] = $dateTo;
}

$whereSql = implode(' AND ', $where);

$query = "
    SELECT p.*, u.name as student_name, s.roll_number, s.department,
           rc.name as received_by_name,
           b.title as book_title, f.reason as fine_reason, f.late_days
    FROM payments p
    JOIN students s ON s.id = p.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN fines f ON f.id = p.fine_id
    LEFT JOIN books b ON b.id = f.book_id
    LEFT JOIN users rc ON rc.id = p.received_by
    WHERE $whereSql
    ORDER BY p.id DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$totalCollected = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();
$thisMonth = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn();
$todayCollected = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date)=CURDATE()")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<!-- Revenue Stats -->
<div class="grid grid-3" style="margin-bottom:20px;">
    <div class="card stat-card">
        <div class="icon green"><i class="fa-solid fa-sack-dollar"></i></div>
        <div>
            <div class="num">&#8377;<?= number_format($totalCollected, 2) ?></div>
            <div class="label">Total Collected Revenue</div>
        </div>
    </div>
    <div class="card stat-card">
        <div class="icon blue"><i class="fa-solid fa-calendar-days"></i></div>
        <div>
            <div class="num">&#8377;<?= number_format($thisMonth, 2) ?></div>
            <div class="label">This Month Collections</div>
        </div>
    </div>
    <div class="card stat-card">
        <div class="icon yellow"><i class="fa-solid fa-receipt"></i></div>
        <div>
            <div class="num"><?= count($payments) ?></div>
            <div class="label">Total Transactions Recorded</div>
        </div>
    </div>
</div>

<!-- Search & Filters Toolbar -->
<div class="card" style="margin-bottom:18px; padding:16px 20px;">
    <form method="GET" class="flex items-center gap-3" style="flex-wrap:wrap; justify-content:space-between;">
        <div class="flex items-center gap-2" style="flex:1; min-width:280px;">
            <input type="text" name="q" class="form-control" placeholder="Search Payment ID, Student Name, Roll No, UTR, Book..." value="<?= e($search) ?>" style="flex:1;">
        </div>

        <div class="flex items-center gap-2" style="flex-wrap:wrap;">
            <select name="method" class="form-control btn-sm" style="width:140px;">
                <option value="all" <?= $methodFilter==='all'?'selected':'' ?>>All Methods</option>
                <option value="upi" <?= $methodFilter==='upi'?'selected':'' ?>>UPI / QR</option>
                <option value="cash" <?= $methodFilter==='cash'?'selected':'' ?>>Cash</option>
                <option value="card" <?= $methodFilter==='card'?'selected':'' ?>>Card</option>
                <option value="online" <?= $methodFilter==='online'?'selected':'' ?>>Online</option>
            </select>

            <input type="date" name="date_from" class="form-control btn-sm" value="<?= e($dateFrom) ?>" title="Date From" style="width:130px;">
            <span class="text-muted" style="font-size:12px;">to</span>
            <input type="date" name="date_to" class="form-control btn-sm" value="<?= e($dateTo) ?>" title="Date To" style="width:130px;">

            <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
            <?php if ($search !== '' || $methodFilter !== 'all' || $dateFrom !== '' || $dateTo !== ''): ?>
                <a href="payments.php" class="btn btn-sm btn-outline"><i class="fa-solid fa-rotate-left"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Payments Ledger Table -->
<div class="card">
    <div class="flex justify-between items-center" style="margin-bottom:14px;">
        <h3 style="margin:0;"><i class="fa-solid fa-receipt text-primary"></i> All Students Payment History</h3>
        <span class="text-muted" style="font-size:13px;"><?= count($payments) ?> Payment(s) Found</span>
    </div>

    <?php if (!$payments): ?>
        <div class="empty-state">
            <i class="fa-solid fa-credit-card" style="font-size:36px; color:#9ca3af; margin-bottom:10px;"></i>
            <div>No matching payment records found.</div>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Payment ID</th>
                    <th>Student Details</th>
                    <th>Book / Reason</th>
                    <th>Amount Paid</th>
                    <th>Method</th>
                    <th>Transaction Reference</th>
                    <th>Date & Time</th>
                    <th>Status</th>
                    <th style="text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><strong style="color:#0f766e; font-family:monospace;"><?= e($p['payment_id'] ?: ('#PMT-' . $p['id'])) ?></strong></td>
                    <td>
                        <strong style="color:#1e293b;"><?= e($p['student_name']) ?></strong>
                        <div class="text-muted" style="font-size:11.5px;"><?= e($p['roll_number']) ?> &bull; <?= e($p['department'] ?: 'Student') ?></div>
                    </td>
                    <td>
                        <strong style="font-size:13px;"><?= e($p['book_title'] ?? ($p['fine_reason'] ?? 'Library Late Fine')) ?></strong>
                        <?php if ($p['late_days'] > 0): ?>
                            <div class="text-muted" style="font-size:11px;"><?= (int)$p['late_days'] ?> day(s) overdue</div>
                        <?php endif; ?>
                    </td>
                    <td><strong style="color:#15803d; font-size:15px;">&#8377;<?= number_format($p['amount'], 2) ?></strong></td>
                    <td>
                        <span class="badge badge-blue">
                            <i class="fa-solid <?= str_contains(strtolower($p['payment_method']), 'upi') ? 'fa-mobile-screen-button' : (str_contains(strtolower($p['payment_method']), 'card') ? 'fa-credit-card' : 'fa-money-bill-wave') ?>"></i>
                            <?= ucfirst(e($p['payment_method'])) ?>
                        </span>
                    </td>
                    <td><code><?= e($p['transaction_reference'] ?: 'TXN-DIRECT') ?></code></td>
                    <td><?= date('d M Y, h:i A', strtotime($p['payment_date'])) ?></td>
                    <td><span class="badge badge-green"><i class="fa-solid fa-check"></i> <?= e($p['payment_status'] ?: 'PAID') ?></span></td>
                    <td style="text-align:center;">
                        <a href="../receipt.php?payment_id=<?= urlencode($p['payment_id'] ?: $p['id']) ?>" target="_blank" class="btn btn-sm btn-outline" title="View & Print Official Receipt">
                            <i class="fa-solid fa-file-invoice"></i> Receipt
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
