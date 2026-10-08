<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/reminder-service.php';
requireRole(['librarian', 'admin']);

$pageTitle = 'Fines & Payments';
$currentPage = 'fines.php';
$settings = getSettings($pdo);

// Synchronize all overdue and late-returned fines system-wide
syncStudentFines($pdo);

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['form_action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'waive') {
        $stmt = $pdo->prepare("SELECT * FROM fines WHERE id = ? AND status IN ('unpaid','pending','partially_paid')");
        $stmt->execute([$id]);
        $fine = $stmt->fetch();
        if ($fine) {
            $pdo->prepare("UPDATE fines SET status = 'waived', outstanding_amount = 0.00 WHERE id = ?")->execute([$id]);
            logActivity($pdo, $_SESSION['user_id'], "Librarian waived fine #$id (Rs. {$fine['amount']})");
            setFlash('success', 'Fine has been marked as waived.');
        }
    } elseif ($action === 'pay') {
        $stmt = $pdo->prepare("SELECT * FROM fines WHERE id = ? AND status IN ('unpaid','pending','partially_paid')");
        $stmt->execute([$id]);
        $fine = $stmt->fetch();

        if ($fine) {
            $method = trim($_POST['method'] ?? 'cash');
            $customTxRef = trim($_POST['transaction_ref'] ?? ('OFFLINE-' . strtoupper(bin2hex(random_bytes(4)))));
            $amountToPay = (float)$fine['outstanding_amount'] > 0 ? (float)$fine['outstanding_amount'] : ((float)$fine['fine_amount'] > 0 ? (float)$fine['fine_amount'] : (float)$fine['amount']);

            $newPaymentId = generatePaymentId();
            $newReceiptNo = generateReceiptNo();

            $pdo->beginTransaction();
            try {
                // Update fine record
                $pdo->prepare("
                    UPDATE fines 
                    SET status = 'paid', paid_amount = (paid_amount + ?), outstanding_amount = 0.00 
                    WHERE id = ?
                ")->execute([$amountToPay, $id]);

                // Record payment
                $pdo->prepare("
                    INSERT INTO payments 
                    (payment_id, fine_id, student_id, amount, payment_method, transaction_reference, payment_status, receipt_no, received_by, payment_date)
                    VALUES (?, ?, ?, ?, ?, ?, 'PAID', ?, ?, NOW())
                ")->execute([
                    $newPaymentId,
                    $id,
                    $fine['student_id'],
                    $amountToPay,
                    $method,
                    $customTxRef,
                    $newReceiptNo,
                    $_SESSION['user_id']
                ]);
                $paymentInsertId = (int)$pdo->lastInsertId();

                $pdo->commit();

                // Send receipt alert to student
                sendPaymentReceiptNotification($pdo, $paymentInsertId);

                setFlash('success', "Payment of Rs. " . number_format($amountToPay, 2) . " successfully recorded. Receipt: $newReceiptNo");
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('error', "Error recording payment: " . $e->getMessage());
            }
        }
    }
    redirect('fines.php');
}

// Search & Filters
$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$sortBy = $_GET['sort'] ?? 'highest';

$whereClauses = ["1=1"];
$params = [];

if ($statusFilter === 'unpaid') {
    $whereClauses[] = "f.status IN ('unpaid', 'pending', 'partially_paid')";
} elseif (in_array($statusFilter, ['paid', 'waived'], true)) {
    $whereClauses[] = "f.status = ?";
    $params[] = $statusFilter;
}

if ($search !== '') {
    $whereClauses[] = "(u.name LIKE ? OR s.roll_number LIKE ? OR b.title LIKE ? OR u.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($dateFrom !== '') {
    $whereClauses[] = "DATE(f.created_at) >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $whereClauses[] = "DATE(f.created_at) <= ?";
    $params[] = $dateTo;
}

$orderBy = match($sortBy) {
    'lowest'    => 'f.fine_amount ASC, f.id DESC',
    'due_date'  => 'COALESCE(f.due_date, bi.due_date) ASC',
    'recent'    => 'f.id DESC',
    default     => 'f.fine_amount DESC, f.id DESC',
};

$whereSql = implode(' AND ', $whereClauses);

$query = "
    SELECT f.*, u.name as student_name, u.phone as student_phone, u.email as student_email,
           s.roll_number, s.department, s.course, s.year as student_year,
           b.title as book_title, b.isbn,
           COALESCE(f.due_date, bi.due_date) as final_due_date,
           COALESCE(f.return_date, bi.return_date) as final_return_date,
           p.payment_id as pmt_payment_id,
           p.payment_date as pmt_payment_date
    FROM fines f
    JOIN students s ON s.id = f.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN books b ON b.id = f.book_id
    LEFT JOIN book_issues bi ON bi.id = f.issue_id
    LEFT JOIN payments p ON p.fine_id = f.id
    WHERE $whereSql
    ORDER BY $orderBy
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$fines = $stmt->fetchAll();

// Statistics
$totalOutstandingStat = (float)$pdo->query("
    SELECT COALESCE(SUM(CASE WHEN outstanding_amount > 0 THEN outstanding_amount ELSE (fine_amount - paid_amount) END), 0)
    FROM fines WHERE status IN ('unpaid', 'pending', 'partially_paid')
")->fetchColumn();

$totalCollectedStat = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments")->fetchColumn();

$pendingFinesCount = (int)$pdo->query("SELECT COUNT(*) FROM fines WHERE status IN ('unpaid', 'pending', 'partially_paid')")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<!-- Statistics Summary -->
<div class="grid grid-3" style="margin-bottom:20px;">
    <div class="card stat-card">
        <div class="icon red"><i class="fa-solid fa-coins"></i></div>
        <div>
            <div class="num" style="color:#dc2626;">&#8377;<?= number_format($totalOutstandingStat, 2) ?></div>
            <div class="label">Total Outstanding Fines</div>
        </div>
    </div>
    <div class="card stat-card">
        <div class="icon green"><i class="fa-solid fa-sack-dollar"></i></div>
        <div>
            <div class="num">&#8377;<?= number_format($totalCollectedStat, 2) ?></div>
            <div class="label">Total Fine Collections</div>
        </div>
    </div>
    <div class="card stat-card">
        <div class="icon yellow"><i class="fa-solid fa-clock"></i></div>
        <div>
            <div class="num"><?= $pendingFinesCount ?></div>
            <div class="label">Pending Fines Count</div>
        </div>
    </div>
</div>

<!-- Search & Filters -->
<div class="card" style="margin-bottom:18px; padding:16px 20px;">
    <form method="GET" class="flex items-center gap-3" style="flex-wrap:wrap; justify-content:space-between;">
        <div class="flex items-center gap-2" style="flex:1; min-width:260px;">
            <input type="text" name="q" class="form-control" placeholder="Search by Student ID, Name, Book..." value="<?= e($search) ?>" style="flex:1;">
        </div>

        <div class="filters">
            <a class="btn btn-sm <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-outline' ?>" href="?status=all">All</a>
            <a class="btn btn-sm <?= $statusFilter === 'unpaid' ? 'btn-primary' : 'btn-outline' ?>" href="?status=unpaid">Unpaid</a>
            <a class="btn btn-sm <?= $statusFilter === 'paid' ? 'btn-primary' : 'btn-outline' ?>" href="?status=paid">Paid</a>
        </div>

        <div class="flex items-center gap-2">
            <select name="sort" class="form-control btn-sm" style="width:140px;">
                <option value="highest" <?= $sortBy === 'highest' ? 'selected' : '' ?>>Highest Fine</option>
                <option value="recent" <?= $sortBy === 'recent' ? 'selected' : '' ?>>Most Recent</option>
                <option value="due_date" <?= $sortBy === 'due_date' ? 'selected' : '' ?>>Due Date</option>
            </select>
            <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
        </div>
    </form>
</div>

<!-- Fines Table -->
<div class="card">
    <div class="flex justify-between items-center" style="margin-bottom:14px;">
        <h3 style="margin:0;"><i class="fa-solid fa-coins text-primary"></i> Circulation Fines & Collections</h3>
        <span class="text-muted" style="font-size:13px;"><?= count($fines) ?> record(s)</span>
    </div>

    <?php if (!$fines): ?>
        <div class="empty-state">
            <i class="fa-solid fa-circle-check" style="font-size:36px; color:#10b981; margin-bottom:10px;"></i>
            <div style="font-size:15px; font-weight:600;">No fines found.</div>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Student Name</th>
                    <th>Phone</th>
                    <th>Book</th>
                    <th>Due Date</th>
                    <th style="text-align:center;">Late Days</th>
                    <th>Fine</th>
                    <th>Status</th>
                    <th style="text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($fines as $f): 
                $isPending = in_array($f['status'], ['unpaid', 'pending', 'partially_paid']);
                $fineAmt = (float)$f['fine_amount'] > 0 ? (float)$f['fine_amount'] : (float)$f['amount'];
                $outAmt = (float)$f['outstanding_amount'] > 0 ? (float)$f['outstanding_amount'] : $fineAmt;
                $lateDays = (int)$f['late_days'];

                $reminderMsg = getReminderText('overdue_fine', $f['student_name'], $f['roll_number'], $f['book_title'] ?? 'Overdue Book', [
                    'library_name' => $settings['library_name'],
                    'late_days' => $lateDays ?: 1,
                    'fine_amount' => $outAmt
                ]);
                $waUrl = !empty($f['student_phone']) ? buildWhatsAppUrl($f['student_phone'], $reminderMsg) : '#';
                $smsUrl = !empty($f['student_phone']) ? buildSmsUrl($f['student_phone'], $reminderMsg) : '#';
            ?>
                <tr>
                    <td><strong style="color:#0f766e;"><?= e($f['roll_number']) ?></strong></td>
                    <td>
                        <strong style="color:#1e293b;"><?= e($f['student_name']) ?></strong>
                        <div class="text-muted" style="font-size:11.5px;"><?= e($f['department'] ?: 'Department') ?></div>
                    </td>
                    <td><?= e($f['student_phone'] ?: '—') ?></td>
                    <td>
                        <strong style="font-size:13.5px; display:block; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            <?= e($f['book_title'] ?? 'General Fine') ?>
                        </strong>
                        <span class="text-muted" style="font-size:11px;"><?= e($f['reason']) ?></span>
                    </td>
                    <td><span style="color:#b45309; font-weight:600;"><?= fmtDate($f['final_due_date']) ?></span></td>
                    <td style="text-align:center;">
                        <?= $lateDays > 0 ? '<span class="badge badge-red">' . $lateDays . ' day(s)</span>' : '&mdash;' ?>
                    </td>
                    <td><strong>&#8377;<?= number_format($fineAmt, 2) ?></strong></td>
                    <td><?= statusBadge($f['status']) ?></td>
                    <td style="text-align:center;">
                        <div class="flex gap-1" style="justify-content:center; flex-wrap:wrap;">
                            <?php if ($isPending): ?>
                                <button type="button" class="btn btn-sm btn-success" onclick='openPayModal(<?= (int)$f['id'] ?>, <?= json_encode($f['student_name']) ?>, <?= json_encode($f['roll_number']) ?>, <?= (float)$outAmt ?>)'>
                                    <i class="fa-solid fa-credit-card"></i> Pay
                                </button>
                                <?php if (!empty($f['student_phone'])): ?>
                                    <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:#fff;" title="WhatsApp Reminder">
                                        <i class="fa-brands fa-whatsapp"></i>
                                    </a>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;" data-confirm="Waive fine for <?= e($f['student_name']) ?>?">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="form_action" value="waive">
                                    <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                    <button class="btn btn-sm btn-outline" type="submit">Waive</button>
                                </form>
                            <?php elseif ($f['status'] === 'paid'): ?>
                                <a href="../receipt.php?payment_id=<?= urlencode($f['pmt_payment_id'] ?: $f['id']) ?>" target="_blank" class="btn btn-sm btn-outline">
                                    <i class="fa-solid fa-file-invoice"></i> Receipt
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal: Manual Mark Paid -->
<div id="payModal" class="modal-overlay">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-credit-card text-success"></i> Collect Fine Payment</h3>
            <button class="modal-close" onclick="closePayModal()">&times;</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="pay">
            <input type="hidden" name="id" id="modalFineId" value="">

            <div style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:12px; margin-bottom:14px; font-size:13.5px;">
                <div style="margin-bottom:4px;">Student: <strong id="modalStudentName">—</strong> (<span id="modalRollNo">—</span>)</div>
                <div>Amount: <strong style="color:#16a34a; font-size:16px;">&#8377;<span id="modalAmount">0.00</span></strong></div>
            </div>

            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label">Payment Method</label>
                <select name="method" class="form-control" required>
                    <option value="cash">Cash (Counter Collection)</option>
                    <option value="upi">UPI / QR Code</option>
                    <option value="card">Debit / Credit Card</option>
                    <option value="online">Online Gateway</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Transaction Reference (Optional)</label>
                <input type="text" name="transaction_ref" class="form-control" placeholder="e.g. Cash Receipt / UTR">
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-outline" onclick="closePayModal()">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> Collect Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPayModal(fineId, studentName, rollNo, amount) {
    document.getElementById('modalFineId').value = fineId;
    document.getElementById('modalStudentName').textContent = studentName;
    document.getElementById('modalRollNo').textContent = rollNo;
    document.getElementById('modalAmount').textContent = amount.toFixed(2);
    document.getElementById('payModal').classList.add('open');
}
function closePayModal() {
    document.getElementById('payModal').classList.remove('open');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
