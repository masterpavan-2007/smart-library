<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['student']);
$pageTitle = 'My Fines';
$currentPage = 'fines.php';

$stu = $pdo->prepare("SELECT s.*, u.name as student_name, u.phone, u.email FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id=?");
$stu->execute([$_SESSION['user_id']]);
$student = $stu->fetch();
$studentId = $student['id'];

// Synchronize all fines to ensure any overdue or late return is calculated accurately
syncStudentFines($pdo, $studentId);

// Filter tab
$tab = $_GET['tab'] ?? 'all';
$search = trim($_GET['q'] ?? '');

// Fetch all student fines with book and issue details
$sql = "
    SELECT f.*, b.title as book_title, b.isbn, b.id as book_ref_id,
           COALESCE(f.due_date, bi.due_date) as final_due_date,
           COALESCE(f.return_date, bi.return_date) as final_return_date,
           bi.issue_date as final_issue_date,
           p.payment_id as pmt_payment_id,
           p.payment_date as pmt_payment_date,
           p.id as pmt_id,
           p.transaction_reference as pmt_tx_ref
    FROM fines f
    LEFT JOIN books b ON b.id = f.book_id
    LEFT JOIN book_issues bi ON bi.id = f.issue_id
    LEFT JOIN payments p ON p.fine_id = f.id
    WHERE f.student_id = ?
";
$params = [$studentId];

if ($tab === 'unpaid') {
    $sql .= " AND f.status IN ('unpaid', 'pending', 'partially_paid')";
} elseif ($tab === 'paid') {
    $sql .= " AND f.status = 'paid'";
}

if ($search !== '') {
    $sql .= " AND (b.title LIKE ? OR b.isbn LIKE ? OR f.reason LIKE ? OR p.payment_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY f.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$fines = $stmt->fetchAll();

// Fetch payment history for Section 10
$pmtStmt = $pdo->prepare("
    SELECT p.*, f.reason as fine_reason, f.late_days, b.title as book_title, b.isbn
    FROM payments p
    LEFT JOIN fines f ON f.id = p.fine_id
    LEFT JOIN books b ON b.id = f.book_id
    WHERE p.student_id = ?
    ORDER BY p.id DESC
");
$pmtStmt->execute([$studentId]);
$payments = $pmtStmt->fetchAll();

// Financial Summaries
$totalOutstanding = getStudentOutstandingFine($pdo, $studentId);

$stmtTotalPaid = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = ?");
$stmtTotalPaid->execute([$studentId]);
$totalPaid = (float)$stmtTotalPaid->fetchColumn();

$countUnpaid = 0;
$countPaid = 0;
foreach ($fines as $f) {
    if (in_array($f['status'], ['unpaid', 'pending', 'partially_paid'])) $countUnpaid++;
    if ($f['status'] === 'paid') $countPaid++;
}

include __DIR__ . '/../includes/header.php';
?>

<!-- ================== STAT SUMMARY CARDS ================== -->
<div class="grid grid-4" style="margin-bottom:20px;">
    <!-- Outstanding Fine Card -->
    <div class="card stat-card" style="border: <?= $totalOutstanding > 0 ? '2px solid #f87171' : '1px solid var(--border)' ?>; background: <?= $totalOutstanding > 0 ? '#fffbfb' : '#fff' ?>;">
        <div class="icon red"><i class="fa-solid fa-coins"></i></div>
        <div style="flex:1;">
            <div class="num" style="color: <?= $totalOutstanding > 0 ? '#dc2626' : 'var(--text)' ?>;">
                &#8377;<?= number_format($totalOutstanding, 2) ?>
            </div>
            <div class="label" style="font-weight:600;">Total Outstanding Fine</div>
            <?php if ($totalOutstanding > 0): ?>
                <div style="margin-top:6px;">
                    <a href="pay-fine.php?fine_id=all" class="btn btn-sm btn-primary" style="background:#dc2626; border-color:#dc2626; padding:4px 12px; font-size:12px;">
                        <i class="fa-solid fa-qrcode"></i> Pay Now
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Total Paid Card -->
    <div class="card stat-card">
        <div class="icon green"><i class="fa-solid fa-circle-check"></i></div>
        <div>
            <div class="num">&#8377;<?= number_format($totalPaid, 2) ?></div>
            <div class="label">Total Amount Paid</div>
        </div>
    </div>

    <!-- Payments Made Count -->
    <div class="card stat-card">
        <div class="icon blue"><i class="fa-solid fa-receipt"></i></div>
        <div>
            <div class="num"><?= count($payments) ?></div>
            <div class="label">Payment Receipts</div>
        </div>
    </div>

    <!-- Student Details Summary -->
    <div class="card stat-card">
        <div class="icon yellow"><i class="fa-solid fa-id-card"></i></div>
        <div>
            <div class="num" style="font-size:16px; font-weight:700;"><?= e($student['roll_number']) ?></div>
            <div class="label"><?= e($student['student_name']) ?> (<?= e($student['department'] ?: 'Student') ?>)</div>
        </div>
    </div>
</div>

<!-- ================== OUTSTANDING BANNER IF APPLICABLE ================== -->
<?php if ($totalOutstanding > 0): ?>
<div style="background:linear-gradient(135deg, #fef2f2 0%, #fff 100%); border:1px solid #fecaca; border-left:5px solid #ef4444; border-radius:10px; padding:16px 20px; margin-bottom:20px; box-shadow:0 2px 10px rgba(239,68,68,0.06); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <h4 style="margin:0 0 4px 0; font-size:16px; color:#991b1b; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-circle-exclamation text-danger"></i> Outstanding Library Balance: &#8377;<?= number_format($totalOutstanding, 2) ?>
        </h4>
        <div style="font-size:13.5px; color:#4b5563;">
            You have unpaid library fines. Pay instantly via dynamic UPI QR code or clear pending charges at the library counter.
        </div>
    </div>
    <div>
        <a href="pay-fine.php?fine_id=all" class="btn btn-primary" style="background:#dc2626; border-color:#dc2626; padding:8px 20px; font-weight:700; font-size:14px; box-shadow:0 2px 8px rgba(220,38,38,0.3);">
            <i class="fa-solid fa-qrcode"></i> PAY NOW WITH UPI QR
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ================== SECTION: MY FINES TABLE ================== -->
<div class="card" style="margin-bottom:24px;">
    <div class="table-toolbar" style="margin-bottom:16px;">
        <div class="flex items-center gap-2" style="flex-wrap:wrap;">
            <h3 style="margin:0; font-size:17px; margin-right:12px;"><i class="fa-solid fa-coins text-primary"></i> My Fines</h3>
            <div class="filters">
                <a class="btn btn-sm <?= $tab === 'all' ? 'btn-primary' : 'btn-outline' ?>" href="?tab=all">All Fines</a>
                <a class="btn btn-sm <?= $tab === 'unpaid' ? 'btn-primary' : 'btn-outline' ?>" href="?tab=unpaid">
                    Unpaid <?= $totalOutstanding > 0 ? '<span class="badge badge-red" style="font-size:10px; margin-left:4px;">Action Req.</span>' : '' ?>
                </a>
                <a class="btn btn-sm <?= $tab === 'paid' ? 'btn-primary' : 'btn-outline' ?>" href="?tab=paid">Paid</a>
            </div>
        </div>

        <form method="GET" class="flex gap-2">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="text" name="q" class="form-control" style="width:230px;" placeholder="Search book, ID..." value="<?= e($search) ?>">
            <button class="btn btn-outline btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
            <?php if ($search !== ''): ?>
                <a href="?tab=<?= e($tab) ?>" class="btn btn-outline btn-sm" title="Clear Search"><i class="fa-solid fa-xmark"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$fines): ?>
        <div class="empty-state">
            <i class="fa-solid fa-face-smile" style="font-size:36px; color:#10b981; margin-bottom:10px;"></i>
            <div style="font-size:15px; font-weight:600; color:#374151;">No fines found!</div>
            <div class="text-muted" style="font-size:13px; margin-top:4px;">You have no library penalty records under this filter.</div>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Book Details</th>
                    <th>Issue Date</th>
                    <th>Due Date</th>
                    <th>Return Date</th>
                    <th>Overdue Calc</th>
                    <th>Fine Amount</th>
                    <th>Payment Status</th>
                    <th>Payment Date & ID</th>
                    <th style="text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($fines as $f): 
                $lateDays = (int)$f['late_days'];
                $fineRate = (float)($f['fine_rate'] > 0 ? $f['fine_rate'] : 5.00);
                $isPayable = in_array($f['status'], ['unpaid', 'pending', 'partially_paid']);
                $hasReceipt = ($f['status'] === 'paid' && !empty($f['pmt_payment_id']));
            ?>
                <tr>
                    <!-- Book Details -->
                    <td>
                        <strong style="color:#1e293b; font-size:14px;"><?= e($f['book_title'] ?? 'General Fine') ?></strong>
                        <div class="text-muted" style="font-size:12px; margin-top:2px;">
                            <?php if (!empty($f['isbn'])): ?>
                                ISBN: <?= e($f['isbn']) ?> &bull; 
                            <?php endif; ?>
                            Book ID: #<?= e($f['book_id'] ?: ($f['book_ref_id'] ?: '—')) ?>
                        </div>
                    </td>

                    <!-- Dates -->
                    <td><?= fmtDate($f['final_issue_date']) ?></td>
                    <td><strong style="color:#b45309;"><?= fmtDate($f['final_due_date']) ?></strong></td>
                    <td><?= fmtDate($f['final_return_date']) ?></td>

                    <!-- Overdue Calc -->
                    <td>
                        <?php if ($lateDays > 0): ?>
                            <span class="badge badge-red"><?= $lateDays ?> day(s)</span>
                            <div class="text-muted" style="font-size:11px; margin-top:2px;">@ &#8377;<?= number_format($fineRate, 2) ?>/day</div>
                        <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>

                    <!-- Fine Amount -->
                    <td>
                        <strong style="font-size:15px; color:#111827;">&#8377;<?= number_format($f['fine_amount'] > 0 ? $f['fine_amount'] : $f['amount'], 2) ?></strong>
                        <?php if ($f['outstanding_amount'] > 0 && $f['status'] === 'partially_paid'): ?>
                            <div style="font-size:11px; color:#dc2626;">Outstanding: &#8377;<?= number_format($f['outstanding_amount'], 2) ?></div>
                        <?php endif; ?>
                    </td>

                    <!-- Payment Status Badge -->
                    <td><?= statusBadge($f['status']) ?></td>

                    <!-- Payment Date & ID -->
                    <td>
                        <?php if (!empty($f['pmt_payment_id'])): ?>
                            <div style="font-weight:600; font-size:12.5px; color:#0f766e;"><?= e($f['pmt_payment_id']) ?></div>
                            <div class="text-muted" style="font-size:11px;"><?= date('d M Y, h:i A', strtotime($f['pmt_payment_date'])) ?></div>
                        <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>

                    <!-- Actions -->
                    <td style="text-align:center;">
                        <?php if ($isPayable): ?>
                            <a href="pay-fine.php?fine_id=<?= $f['id'] ?>" class="btn btn-sm btn-primary" style="background:#dc2626; border-color:#dc2626; font-weight:700;">
                                <i class="fa-solid fa-qrcode"></i> Pay Now
                            </a>
                        <?php elseif ($hasReceipt): ?>
                            <a href="../receipt.php?payment_id=<?= urlencode($f['pmt_payment_id']) ?>" target="_blank" class="btn btn-sm btn-outline" title="View & Download Receipt">
                                <i class="fa-solid fa-file-invoice"></i> Receipt
                            </a>
                        <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ================== SECTION: PAYMENT HISTORY (Section 10) ================== -->
<div class="card">
    <div class="flex justify-between items-center" style="margin-bottom:14px;">
        <h3 style="margin:0; font-size:17px;"><i class="fa-solid fa-clock-rotate-left text-success"></i> Payment History</h3>
        <span class="badge badge-green"><?= count($payments) ?> Recorded Transaction(s)</span>
    </div>

    <?php if (!$payments): ?>
        <div class="empty-state">
            <i class="fa-solid fa-receipt" style="font-size:32px; color:#9ca3af; margin-bottom:8px;"></i>
            <div>No fine payments recorded yet.</div>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Payment ID</th>
                    <th>Date & Time</th>
                    <th>Fine Reference / Book</th>
                    <th>Amount Paid</th>
                    <th>Payment Method</th>
                    <th>Transaction ID</th>
                    <th>Status</th>
                    <th style="text-align:center;">Receipt</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><strong style="color:#0f766e;"><?= e($p['payment_id'] ?: ('#PMT-' . $p['id'])) ?></strong></td>
                    <td><?= date('d M Y, h:i A', strtotime($p['payment_date'])) ?></td>
                    <td><?= e($p['book_title'] ?? ($p['fine_reason'] ?? 'Library Fine')) ?></td>
                    <td><strong style="color:#15803d; font-size:15px;">&#8377;<?= number_format($p['amount'], 2) ?></strong></td>
                    <td>
                        <span class="badge badge-blue">
                            <i class="fa-solid <?= str_contains(strtolower($p['payment_method']), 'upi') ? 'fa-mobile-screen-button' : (str_contains(strtolower($p['payment_method']), 'card') ? 'fa-credit-card' : 'fa-money-bill-wave') ?>"></i>
                            <?= ucfirst(e($p['payment_method'])) ?>
                        </span>
                    </td>
                    <td><code><?= e($p['transaction_reference'] ?: 'TXN-DIRECT') ?></code></td>
                    <td><span class="badge badge-green"><i class="fa-solid fa-check"></i> <?= e($p['payment_status'] ?: 'PAID') ?></span></td>
                    <td style="text-align:center;">
                        <a href="../receipt.php?payment_id=<?= urlencode($p['payment_id'] ?: $p['id']) ?>" target="_blank" class="btn btn-sm btn-outline">
                            <i class="fa-solid fa-file-invoice"></i> View / Print
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
