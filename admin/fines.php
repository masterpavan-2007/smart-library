<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/reminder-service.php';
requireRole(['admin']);

$pageTitle = 'Fine Management';
$currentPage = 'fines.php';
$settings = getSettings($pdo);

// 1. Synchronize all overdue and late-returned fines system-wide
syncStudentFines($pdo);

// 2. Handle POST Actions (Manual Payment, Waive)
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
            logActivity($pdo, $_SESSION['user_id'], "Waived fine #$id (Rs. {$fine['amount']})");
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

// 3. Search & Filters
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
    default     => 'f.fine_amount DESC, f.id DESC', // Highest fine first
};

$whereSql = implode(' AND ', $whereClauses);

$query = "
    SELECT f.*, u.name as student_name, u.phone as student_phone, u.email as student_email,
           s.roll_number, s.department, s.course, s.year as student_year,
           b.title as book_title, b.isbn,
           COALESCE(f.due_date, bi.due_date) as final_due_date,
           COALESCE(f.return_date, bi.return_date) as final_return_date,
           p.payment_id as pmt_payment_id,
           p.payment_date as pmt_payment_date,
           p.transaction_reference as pmt_tx_ref
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

// 4. Fine Statistics (Section 11)
$totalOutstandingStat = (float)$pdo->query("
    SELECT COALESCE(SUM(CASE WHEN outstanding_amount > 0 THEN outstanding_amount ELSE (fine_amount - paid_amount) END), 0)
    FROM fines WHERE status IN ('unpaid', 'pending', 'partially_paid')
")->fetchColumn();

$totalCollectedStat = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments")->fetchColumn();

$pendingFinesCount = (int)$pdo->query("SELECT COUNT(*) FROM fines WHERE status IN ('unpaid', 'pending', 'partially_paid')")->fetchColumn();

$paidFinesCount = (int)$pdo->query("SELECT COUNT(*) FROM fines WHERE status = 'paid'")->fetchColumn();

$studentsWithFineCount = (int)$pdo->query("
    SELECT COUNT(DISTINCT student_id) FROM fines WHERE status IN ('unpaid', 'pending', 'partially_paid')
")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<!-- ================== STAT CARDS (Section 11: Fine Dashboard) ================== -->
<div class="grid grid-5" style="margin-bottom:20px;">
    <div class="card stat-card">
        <div class="icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div>
            <div class="num" style="color:#dc2626;">&#8377;<?= number_format($totalOutstandingStat, 2) ?></div>
            <div class="label">Total Outstanding</div>
        </div>
    </div>
    <div class="card stat-card">
        <div class="icon green"><i class="fa-solid fa-sack-dollar"></i></div>
        <div>
            <div class="num">&#8377;<?= number_format($totalCollectedStat, 2) ?></div>
            <div class="label">Total Collected</div>
        </div>
    </div>
    <div class="card stat-card">
        <div class="icon yellow"><i class="fa-solid fa-clock"></i></div>
        <div>
            <div class="num"><?= $pendingFinesCount ?></div>
            <div class="label">Pending Payments</div>
        </div>
    </div>
    <div class="card stat-card">
        <div class="icon blue"><i class="fa-solid fa-circle-check"></i></div>
        <div>
            <div class="num"><?= $paidFinesCount ?></div>
            <div class="label">Paid Fines</div>
        </div>
    </div>
    <div class="card stat-card">
        <div class="icon red"><i class="fa-solid fa-user-xmark"></i></div>
        <div>
            <div class="num"><?= $studentsWithFineCount ?></div>
            <div class="label">Students with Fine</div>
        </div>
    </div>
</div>

<!-- ================== SEARCH & FILTERS BAR (Section 3) ================== -->
<div class="card" style="margin-bottom:18px; padding:16px 20px;">
    <form method="GET" class="flex items-center gap-3" style="flex-wrap:wrap; justify-content:space-between;">
        <!-- Left: Search Box -->
        <div class="flex items-center gap-2" style="flex:1; min-width:260px;">
            <input type="text" name="q" class="form-control" placeholder="Search by Student ID, Name, Book, Phone..." value="<?= e($search) ?>" style="flex:1;">
        </div>

        <!-- Center: Status Filters -->
        <div class="flex items-center gap-2" style="flex-wrap:wrap;">
            <div class="filters">
                <a class="btn btn-sm <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-outline' ?>" href="?status=all<?= $search ? '&q='.urlencode($search) : '' ?>">All</a>
                <a class="btn btn-sm <?= $statusFilter === 'unpaid' ? 'btn-primary' : 'btn-outline' ?>" href="?status=unpaid<?= $search ? '&q='.urlencode($search) : '' ?>">Unpaid / Pending</a>
                <a class="btn btn-sm <?= $statusFilter === 'paid' ? 'btn-primary' : 'btn-outline' ?>" href="?status=paid<?= $search ? '&q='.urlencode($search) : '' ?>">Paid</a>
                <a class="btn btn-sm <?= $statusFilter === 'waived' ? 'btn-primary' : 'btn-outline' ?>" href="?status=waived<?= $search ? '&q='.urlencode($search) : '' ?>">Waived</a>
            </div>
        </div>

        <!-- Right: Dates and Sort -->
        <div class="flex items-center gap-2" style="flex-wrap:wrap;">
            <input type="date" name="date_from" class="form-control btn-sm" value="<?= e($dateFrom) ?>" title="Date From" style="width:130px;">
            <span class="text-muted" style="font-size:12px;">to</span>
            <input type="date" name="date_to" class="form-control btn-sm" value="<?= e($dateTo) ?>" title="Date To" style="width:130px;">
            
            <select name="sort" class="form-control btn-sm" style="width:150px;">
                <option value="highest" <?= $sortBy === 'highest' ? 'selected' : '' ?>>Highest Fine</option>
                <option value="recent" <?= $sortBy === 'recent' ? 'selected' : '' ?>>Most Recent</option>
                <option value="due_date" <?= $sortBy === 'due_date' ? 'selected' : '' ?>>Due Date</option>
                <option value="lowest" <?= $sortBy === 'lowest' ? 'selected' : '' ?>>Lowest Fine</option>
            </select>

            <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
            <?php if ($search !== '' || $statusFilter !== 'all' || $dateFrom !== '' || $dateTo !== '' || $sortBy !== 'highest'): ?>
                <a href="fines.php" class="btn btn-sm btn-outline" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ================== SECTION: ALL STUDENTS FINE MANAGEMENT TABLE ================== -->
<div class="card">
    <div class="flex justify-between items-center" style="margin-bottom:14px;">
        <h3 style="margin:0;"><i class="fa-solid fa-list-check text-primary"></i> All Students Fine Ledger</h3>
        <span class="text-muted" style="font-size:13px;">Showing <?= count($fines) ?> penalty record(s)</span>
    </div>

    <?php if (!$fines): ?>
        <div class="empty-state">
            <i class="fa-solid fa-circle-check" style="font-size:36px; color:#10b981; margin-bottom:10px;"></i>
            <div style="font-size:15px; font-weight:600;">No fines match your search criteria.</div>
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

                // Reminder message for WhatsApp/SMS
                $reminderMsg = getReminderText('overdue_fine', $f['student_name'], $f['roll_number'], $f['book_title'] ?? 'Overdue Book', [
                    'library_name' => $settings['library_name'],
                    'late_days' => $lateDays ?: 1,
                    'fine_amount' => $outAmt
                ]);
                $waUrl = !empty($f['student_phone']) ? buildWhatsAppUrl($f['student_phone'], $reminderMsg) : '#';
                $smsUrl = !empty($f['student_phone']) ? buildSmsUrl($f['student_phone'], $reminderMsg) : '#';
            ?>
                <tr>
                    <!-- Student ID -->
                    <td>
                        <strong style="color:#0f766e;"><?= e($f['roll_number']) ?></strong>
                    </td>

                    <!-- Student Name + Details Link -->
                    <td>
                        <a href="javascript:void(0)" onclick='openStudentModal(<?= json_encode($f) ?>)' style="font-weight:600; color:#1e293b;" title="Click to view full student details">
                            <?= e($f['student_name']) ?> <i class="fa-solid fa-circle-info" style="font-size:11px; color:#6366f1;"></i>
                        </a>
                        <div class="text-muted" style="font-size:11.5px;"><?= e($f['department'] ?: 'Department') ?></div>
                    </td>

                    <!-- Phone -->
                    <td>
                        <?php if (!empty($f['student_phone'])): ?>
                            <a href="tel:<?= e($f['student_phone']) ?>" style="color:#334155; font-family:monospace;"><?= e($f['student_phone']) ?></a>
                        <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>

                    <!-- Book -->
                    <td>
                        <strong style="font-size:13.5px; display:block; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= e($f['book_title'] ?? 'Library Book') ?>">
                            <?= e($f['book_title'] ?? 'General Fine') ?>
                        </strong>
                        <span class="text-muted" style="font-size:11px;"><?= e($f['reason']) ?></span>
                    </td>

                    <!-- Due Date -->
                    <td>
                        <span style="color:#b45309; font-weight:600;"><?= fmtDate($f['final_due_date']) ?></span>
                    </td>

                    <!-- Late Days -->
                    <td style="text-align:center;">
                        <?php if ($lateDays > 0): ?>
                            <span class="badge badge-red"><?= $lateDays ?> day(s)</span>
                        <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>

                    <!-- Fine Amount -->
                    <td>
                        <strong style="font-size:15px; color:#111827;">&#8377;<?= number_format($fineAmt, 2) ?></strong>
                        <?php if ($f['status'] === 'partially_paid' && $outAmt > 0): ?>
                            <div style="font-size:11px; color:#dc2626;">Due: &#8377;<?= number_format($outAmt, 2) ?></div>
                        <?php endif; ?>
                    </td>

                    <!-- Status -->
                    <td><?= statusBadge($f['status']) ?></td>

                    <!-- Actions -->
                    <td style="text-align:center;">
                        <div class="flex gap-1" style="justify-content:center; flex-wrap:wrap;">
                            <?php if ($isPending): ?>
                                <!-- Mark Paid Button / Modal Trigger -->
                                <button type="button" class="btn btn-sm btn-success" onclick='openPayModal(<?= (int)$f['id'] ?>, <?= json_encode($f['student_name']) ?>, <?= json_encode($f['roll_number']) ?>, <?= (float)$outAmt ?>)' title="Manually Mark Paid">
                                    <i class="fa-solid fa-credit-card"></i> Pay
                                </button>

                                <!-- Send Payment Reminder -->
                                <?php if (!empty($f['student_phone'])): ?>
                                    <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:#fff; border-color:#22c55e;" title="Send WhatsApp Fine Reminder">
                                        <i class="fa-brands fa-whatsapp"></i>
                                    </a>
                                    <a href="<?= e($smsUrl) ?>" class="btn btn-sm" style="background:#3b82f6; color:#fff; border-color:#2563eb;" title="Send SMS Reminder">
                                        <i class="fa-solid fa-comment-sms"></i>
                                    </a>
                                <?php endif; ?>

                                <!-- Waive Button -->
                                <form method="POST" style="display:inline;" data-confirm="Waive this fine of Rs. <?= number_format($outAmt, 2) ?> for <?= e($f['student_name']) ?>?">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="form_action" value="waive">
                                    <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                    <button class="btn btn-sm btn-outline" type="submit" title="Waive Fine">Waive</button>
                                </form>
                            <?php elseif ($f['status'] === 'paid'): ?>
                                <!-- View Receipt Button -->
                                <a href="../receipt.php?payment_id=<?= urlencode($f['pmt_payment_id'] ?: $f['id']) ?>" target="_blank" class="btn btn-sm btn-outline" title="Generate / View Official Receipt">
                                    <i class="fa-solid fa-file-invoice"></i> Receipt
                                </a>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
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

<!-- ================== MODAL: MANUAL MARK PAID ================== -->
<div id="payModal" class="modal-overlay">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-credit-card text-success"></i> Record Fine Payment</h3>
            <button class="modal-close" onclick="closePayModal()">&times;</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="pay">
            <input type="hidden" name="id" id="modalFineId" value="">

            <div style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:12px; margin-bottom:14px; font-size:13.5px;">
                <div style="margin-bottom:4px;">Student: <strong id="modalStudentName">—</strong> (<span id="modalRollNo">—</span>)</div>
                <div>Amount to Collect: <strong style="color:#16a34a; font-size:16px;">&#8377;<span id="modalAmount">0.00</span></strong></div>
            </div>

            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label">Payment Method</label>
                <select name="method" class="form-control" required>
                    <option value="cash">Cash (Counter Collection)</option>
                    <option value="upi">UPI / QR Code</option>
                    <option value="card">Debit / Credit Card</option>
                    <option value="online">Net Banking / Online Gateway</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Transaction Reference (Optional)</label>
                <input type="text" name="transaction_ref" class="form-control" placeholder="e.g. UTR / Receipt / Cash Slip No.">
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-outline" onclick="closePayModal()">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> Confirm Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- ================== MODAL: STUDENT DETAILS ================== -->
<div id="studentModal" class="modal-overlay">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-user-graduate text-primary"></i> Student Information</h3>
            <button class="modal-close" onclick="closeStudentModal()">&times;</button>
        </div>
        <div id="studentModalBody" style="font-size:14px;">
            <!-- Loaded dynamically by JS -->
        </div>
        <div class="flex justify-end" style="margin-top:16px;">
            <button type="button" class="btn btn-outline" onclick="closeStudentModal()">Close</button>
        </div>
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

function openStudentModal(item) {
    const body = document.getElementById('studentModalBody');
    body.innerHTML = `
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-bottom:14px;">
            <div style="font-size:16px; font-weight:700; color:#1e293b; margin-bottom:4px;">\${item.student_name}</div>
            <div class="text-muted">Register / Roll Number: <strong>\${item.roll_number}</strong></div>
        </div>
        <div style="display:flex; flex-direction:column; gap:8px;">
            <div class="info-row" style="display:flex; justify-content:space-between; border-bottom:1px solid #f1f5f9; padding-bottom:6px;">
                <span class="text-muted">Department:</span>
                <strong>\${item.department || '—'}</strong>
            </div>
            <div class="info-row" style="display:flex; justify-content:space-between; border-bottom:1px solid #f1f5f9; padding-bottom:6px;">
                <span class="text-muted">Course / Year:</span>
                <strong>\${item.course || ''} \${item.student_year ? '(' + item.student_year + ')' : ''}</strong>
            </div>
            <div class="info-row" style="display:flex; justify-content:space-between; border-bottom:1px solid #f1f5f9; padding-bottom:6px;">
                <span class="text-muted">Phone Number:</span>
                <strong>\${item.student_phone || 'None'}</strong>
            </div>
            <div class="info-row" style="display:flex; justify-content:space-between; border-bottom:1px solid #f1f5f9; padding-bottom:6px;">
                <span class="text-muted">Email Address:</span>
                <strong>\${item.student_email || 'None'}</strong>
            </div>
            <div class="info-row" style="display:flex; justify-content:space-between; padding-top:6px;">
                <span class="text-muted">Book Involved:</span>
                <strong>\${item.book_title || 'General Fine'}</strong>
            </div>
        </div>
    `;
    document.getElementById('studentModal').classList.add('open');
}
function closeStudentModal() {
    document.getElementById('studentModal').classList.remove('open');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
