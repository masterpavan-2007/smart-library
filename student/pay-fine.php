<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['student']);

$pageTitle = 'Pay Library Fine';
$currentPage = 'fines.php';
$settings = getSettings($pdo);

// 1. Resolve Student Information
$stu = $pdo->prepare("SELECT s.*, u.name as student_name, u.phone, u.email FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?");
$stu->execute([$_SESSION['user_id']]);
$student = $stu->fetch();
$studentId = (int)$student['id'];

// Always synchronize fines first
syncStudentFines($pdo, $studentId);

// 2. Identify fine(s) to be paid
$fineParam = $_GET['fine_id'] ?? ($_POST['fine_id'] ?? 'all');
$selectedFines = [];
$totalPayable = 0.00;

if ($fineParam === 'all' || empty($fineParam)) {
    // Pay all outstanding fines for this student
    $stmt = $pdo->prepare("
        SELECT f.*, b.title as book_title, b.isbn, b.id as book_ref_id
        FROM fines f
        LEFT JOIN books b ON b.id = f.book_id
        WHERE f.student_id = ? AND f.status IN ('unpaid', 'pending', 'partially_paid')
        ORDER BY f.id ASC
    ");
    $stmt->execute([$studentId]);
    $selectedFines = $stmt->fetchAll();
} else {
    // Pay specific fine
    $stmt = $pdo->prepare("
        SELECT f.*, b.title as book_title, b.isbn, b.id as book_ref_id
        FROM fines f
        LEFT JOIN books b ON b.id = f.book_id
        WHERE f.id = ? AND f.student_id = ?
    ");
    $stmt->execute([(int)$fineParam, $studentId]);
    $singleFine = $stmt->fetch();
    if ($singleFine) {
        if ($singleFine['status'] === 'paid') {
            setFlash('info', 'This fine has already been paid in full.');
            redirect('fines.php');
        }
        $selectedFines = [$singleFine];
    }
}

if (empty($selectedFines)) {
    setFlash('info', 'You have no outstanding fines to pay at this moment.');
    redirect('fines.php');
}

// Calculate server-side enforced total payable amount
foreach ($selectedFines as $sf) {
    $out = (float)$sf['outstanding_amount'];
    if ($out <= 0) {
        $out = (float)$sf['fine_amount'] - (float)$sf['paid_amount'];
    }
    if ($out <= 0) {
        $out = (float)$sf['amount'] - (float)$sf['paid_amount'];
    }
    $totalPayable += max(0.00, $out);
}

if ($totalPayable <= 0) {
    setFlash('info', 'You have zero outstanding fine balance.');
    redirect('fines.php');
}

// 3. Generate or retrieve unique Payment ID for this session
if (empty($_SESSION['active_payment_id']) || empty($_SESSION['active_payment_fine']) || $_SESSION['active_payment_fine'] !== $fineParam) {
    $_SESSION['active_payment_id'] = generatePaymentId();
    $_SESSION['active_payment_fine'] = $fineParam;
}
$paymentId = $_SESSION['active_payment_id'];

// 4. Build UPI payment parameters
$upiId = !empty($settings['upi_id']) ? $settings['upi_id'] : 'smartlibrary@upi';
$merchantName = !empty($settings['upi_merchant_name']) ? $settings['upi_merchant_name'] : ($settings['library_name'] ?? 'Smart Library');
$upiDescription = "Library Late Fine - Student ID " . $student['roll_number'];
$upiUrl = buildUpiUrl($upiId, $merchantName, $totalPayable, $paymentId, $upiDescription);

// ================== PROCESS PAYMENT SUBMISSION ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $enteredPaymentId = trim($_POST['payment_id'] ?? '');
    $txRef = trim($_POST['transaction_reference'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? 'upi_demo');

    // Strict validation
    if ($enteredPaymentId !== $paymentId) {
        // regenerate if expired
        $paymentId = generatePaymentId();
        $_SESSION['active_payment_id'] = $paymentId;
        setFlash('error', 'Payment session expired. Please verify with the updated Payment ID.');
        redirect("pay-fine.php?fine_id=" . urlencode($fineParam));
    }

    if ($txRef === '') {
        $txRef = 'UPI-' . strtoupper(bin2hex(random_bytes(6)));
    }

    // Server-side database update within transaction
    $pdo->beginTransaction();
    try {
        $firstPaymentInsertId = 0;
        $primaryReceiptNo = generateReceiptNo();

        foreach ($selectedFines as $idx => $fRow) {
            $fId = (int)$fRow['id'];
            // Re-fetch current database fine to prevent race condition or double pay
            $rf = $pdo->prepare("SELECT * FROM fines WHERE id = ? FOR UPDATE");
            $rf->execute([$fId]);
            $currentFine = $rf->fetch();

            if (!$currentFine || $currentFine['status'] === 'paid') {
                continue;
            }

            $dueForThisFine = (float)$currentFine['outstanding_amount'];
            if ($dueForThisFine <= 0) {
                $dueForThisFine = (float)$currentFine['fine_amount'] - (float)$currentFine['paid_amount'];
            }
            if ($dueForThisFine <= 0) {
                $dueForThisFine = (float)$currentFine['amount'] - (float)$currentFine['paid_amount'];
            }
            $dueForThisFine = max(0.00, $dueForThisFine);

            $fineTotal = (float)$currentFine['fine_amount'] > 0 ? (float)$currentFine['fine_amount'] : (float)$currentFine['amount'];

            // Update fine: status = paid, paid_amount = fine_amount, outstanding = 0
            $pdo->prepare("
                UPDATE fines
                SET status = 'paid',
                    paid_amount = ?,
                    outstanding_amount = 0.00
                WHERE id = ?
            ")->execute([$fineTotal, $fId]);

            // Create individual payment ID / receipt per fine item
            $itemPaymentId = ($idx === 0) ? $paymentId : generatePaymentId();
            $itemReceiptNo = ($idx === 0) ? $primaryReceiptNo : generateReceiptNo();

            // Insert payments record
            $insPmt = $pdo->prepare("
                INSERT INTO payments 
                (payment_id, fine_id, student_id, amount, payment_method, transaction_reference, payment_status, receipt_no, payment_date)
                VALUES (?, ?, ?, ?, ?, ?, 'PAID', ?, NOW())
            ");
            $insPmt->execute([
                $itemPaymentId,
                $fId,
                $studentId,
                $dueForThisFine,
                $paymentMethod,
                $txRef,
                $itemReceiptNo
            ]);

            if ($firstPaymentInsertId === 0) {
                $firstPaymentInsertId = (int)$pdo->lastInsertId();
            }
        }

        $pdo->commit();

        // Clear active session payment id
        unset($_SESSION['active_payment_id'], $_SESSION['active_payment_fine']);

        // Send notifications (In-app + simulated SMS / WhatsApp link)
        if ($firstPaymentInsertId > 0) {
            sendPaymentReceiptNotification($pdo, $firstPaymentInsertId);
        }

        setFlash('success', 'Payment verified successfully! Receipt has been generated.');
        redirect("payment-success.php?payment_id=" . urlencode($paymentId));
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('error', 'Payment processing failed: ' . $e->getMessage());
        redirect("pay-fine.php?fine_id=" . urlencode($fineParam));
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="table-toolbar" style="margin-bottom:16px;">
    <a href="fines.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to My Fines</a>
    <div style="font-size:13px; color:#6b7280;">Secure Library Online Fine Payment Gateway</div>
</div>

<div class="grid grid-2" style="align-items:start; gap:24px;">
    <!-- ================== CARD 1: LIBRARY FINE SUMMARY ================== -->
    <div class="card" style="border-radius:14px; box-shadow:0 4px 16px rgba(0,0,0,0.06); padding:24px;">
        <div class="flex items-center gap-3" style="margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:14px;">
            <div style="width:44px; height:44px; border-radius:10px; background:var(--primary-light); color:var(--primary); display:flex; align-items:center; justify-content:center; font-size:20px;">
                <i class="fa-solid fa-receipt"></i>
            </div>
            <div>
                <h3 style="margin:0; font-size:18px;">Pay Library Fine</h3>
                <div class="text-muted" style="font-size:13px;">Review your outstanding penalty details</div>
            </div>
        </div>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:18px;">
            <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                <span class="text-muted" style="font-size:13.5px;">Student Name</span>
                <strong style="color:#1e293b; font-size:14px;"><?= e($student['student_name']) ?></strong>
            </div>
            <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                <span class="text-muted" style="font-size:13.5px;">Student ID (Roll No)</span>
                <strong style="color:#1e293b; font-size:14px;"><?= e($student['roll_number']) ?></strong>
            </div>
            <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                <span class="text-muted" style="font-size:13.5px;">Department</span>
                <strong style="color:#1e293b; font-size:14px;"><?= e($student['department'] ?: 'B.Tech') ?></strong>
            </div>
            <div style="display:flex; justify-content:space-between;">
                <span class="text-muted" style="font-size:13.5px;">Registered Mobile</span>
                <strong style="color:#1e293b; font-size:14px;"><?= e($student['phone'] ?: '—') ?></strong>
            </div>
        </div>

        <h4 style="font-size:14px; text-transform:uppercase; letter-spacing:0.04em; color:#6b7280; margin:0 0 10px 0;">Books Included in this Payment</h4>
        <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:20px;">
            <?php foreach ($selectedFines as $f): 
                $itemFine = (float)$f['fine_amount'] > 0 ? (float)$f['fine_amount'] : (float)$f['amount'];
                $itemOut = (float)$f['outstanding_amount'] > 0 ? (float)$f['outstanding_amount'] : $itemFine;
            ?>
                <div style="background:#fff; border:1px solid var(--border); border-radius:8px; padding:12px 14px; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <strong style="font-size:14px; color:#111827; display:block;"><?= e($f['book_title'] ?? 'Library Overdue Book') ?></strong>
                        <div class="text-muted" style="font-size:12px; margin-top:2px;">
                            Late: <strong><?= (int)$f['late_days'] ?> day(s)</strong> &bull; Rate: &#8377;<?= number_format((float)($f['fine_rate'] ?: 5), 2) ?>/day
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <span style="font-size:15px; font-weight:700; color:#dc2626;">&#8377;<?= number_format($itemOut, 2) ?></span>
                        <div style="font-size:11px; color:#6b7280;">Unpaid</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="border-top:2px dashed var(--border); padding-top:16px;">
            <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:14px;">
                <span class="text-muted">Total Late Fee Accrued</span>
                <span>&#8377;<?= number_format($totalPayable, 2) ?></span>
            </div>
            <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:14px;">
                <span class="text-muted">Processing & Platform Fee</span>
                <span class="badge badge-green" style="font-size:11px;">FREE (&#8377;0.00)</span>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:12px; padding-top:12px; border-top:1px solid var(--border); font-size:18px;">
                <strong style="color:#111827;">Total Payable</strong>
                <strong style="color:#dc2626; font-size:24px;">&#8377;<?= number_format($totalPayable, 2) ?></strong>
            </div>
        </div>
    </div>

    <!-- ================== CARD 2: SCAN & PAY WITH UPI QR ================== -->
    <div class="card" style="border-radius:14px; box-shadow:0 4px 16px rgba(0,0,0,0.06); padding:24px; text-align:center;">
        <div style="display:inline-flex; align-items:center; gap:6px; background:#eff6ff; color:#2563eb; padding:6px 14px; border-radius:20px; font-size:12px; font-weight:700; margin-bottom:14px;">
            <i class="fa-solid fa-shield-halved"></i> 100% SECURE UPI QR PAYMENT
        </div>
        <h3 style="margin:0 0 6px 0; font-size:20px;">Scan & Pay with Any UPI App</h3>
        <p class="text-muted" style="margin:0 0 16px 0; font-size:13.5px;">
            Scan using Google Pay, PhonePe, Paytm, BHIM, or your mobile banking app.
        </p>

        <!-- QR Code Container -->
        <div style="background:#fff; border:2px dashed #93c5fd; border-radius:16px; padding:18px; display:inline-block; margin-bottom:14px; box-shadow:0 4px 12px rgba(59,130,246,0.08);">
            <div id="qrcode" style="display:flex; justify-content:center; align-items:center; width:220px; height:220px; margin:0 auto;"></div>
            <div style="margin-top:10px; font-size:12px; color:#475569; font-weight:600;">
                Library UPI ID: <span style="color:#2563eb;"><?= e($upiId) ?></span>
            </div>
        </div>

        <!-- Payment Meta Details -->
        <div style="background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:12px; margin-bottom:16px; font-size:13px; text-align:left;">
            <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                <span class="text-muted">Unique Payment ID</span>
                <code style="font-weight:700; color:#0f766e;"><?= e($paymentId) ?></code>
            </div>
            <div style="display:flex; justify-content:space-between;">
                <span class="text-muted">Payable Amount</span>
                <strong style="color:#dc2626; font-size:15px;">&#8377;<?= number_format($totalPayable, 2) ?></strong>
            </div>
        </div>

        <!-- Pay Using UPI App Button for Mobile -->
        <div style="margin-bottom:16px;">
            <a href="<?= e($upiUrl) ?>" class="btn btn-outline" style="width:100%; justify-content:center; border-color:#2563eb; color:#2563eb; font-weight:600; padding:10px;">
                <i class="fa-solid fa-mobile-screen-button"></i> Pay using UPI App Directly
            </a>
        </div>

        <!-- App Logos / Badges -->
        <div style="display:flex; justify-content:center; gap:10px; margin-bottom:20px; flex-wrap:wrap;">
            <span class="badge badge-gray" style="font-size:11px;"><i class="fa-brands fa-google-pay" style="font-size:14px;"></i> Google Pay</span>
            <span class="badge badge-gray" style="font-size:11px;">PhonePe</span>
            <span class="badge badge-gray" style="font-size:11px;">Paytm</span>
            <span class="badge badge-gray" style="font-size:11px;">BHIM UPI</span>
        </div>

        <!-- DEMO PAYMENT GATEWAY NOTIFICATION & CONTROLS -->
        <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px; text-align:left; margin-bottom:18px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                <i class="fa-solid fa-flask text-warning" style="font-size:16px;"></i>
                <strong style="color:#92400e; font-size:13px;">DEMO PAYMENT MODE (NO REAL MONEY DEDUCTED)</strong>
            </div>
            <div style="font-size:12px; color:#78350f; line-height:1.4;">
                This system is running in demonstration mode. You can simulate the complete payment and verification flow instantly.
            </div>
        </div>

        <!-- VERIFICATION FORM (I HAVE PAID) -->
        <form method="POST" id="verifyForm">
            <?= csrfField() ?>
            <input type="hidden" name="fine_id" value="<?= e($fineParam) ?>">
            <input type="hidden" name="payment_id" value="<?= e($paymentId) ?>">
            <input type="hidden" name="payment_method" value="upi">

            <div style="margin-bottom:14px; text-align:left;">
                <label style="display:block; font-size:12.5px; font-weight:600; margin-bottom:6px; color:#374151;">
                    UPI Reference / UTR Number (12 digits)
                </label>
                <div class="flex gap-2">
                    <input type="text" name="transaction_reference" id="txRefInput" class="form-control" placeholder="e.g. 428901238910" value="" required style="font-family:monospace; letter-spacing:0.04em;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="fillDemoUtr()" title="Generate Demo UTR" style="flex-shrink:0;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Demo UTR
                    </button>
                </div>
                <div class="text-muted" style="font-size:11px; margin-top:4px;">
                    Enter the UTR reference from your UPI app receipt or click "Demo UTR".
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:12px; font-size:15px; font-weight:700; background:#16a34a; border-color:#16a34a; box-shadow:0 3px 10px rgba(22,163,74,0.3);">
                <i class="fa-solid fa-circle-check"></i> I HAVE PAID &mdash; VERIFY PAYMENT
            </button>
        </form>
    </div>
</div>

<!-- Load Standalone QRCode Generator Script -->
<script src="../assets/js/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const upiString = <?= json_encode($upiUrl) ?>;
    const qrContainer = document.getElementById('qrcode');

    try {
        if (typeof QRCode !== 'undefined' && qrContainer) {
            new QRCode(qrContainer, {
                text: upiString,
                width: 200,
                height: 200,
                colorDark: '#0f172a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        } else {
            // Fallback to QR API image if offline script failed
            qrContainer.innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(upiString) + '" alt="UPI QR Code" style="width:200px; height:200px; border-radius:8px;" />';
        }
    } catch (e) {
        qrContainer.innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(upiString) + '" alt="UPI QR Code" style="width:200px; height:200px; border-radius:8px;" />';
    }

    // Auto-generate a Demo UTR on page load for convenience
    fillDemoUtr();
});

function fillDemoUtr() {
    const input = document.getElementById('txRefInput');
    if (!input) return;
    const randomDigits = Math.floor(100000000000 + Math.random() * 900000000000);
    input.value = 'DEMO' + randomDigits.toString().substring(0, 8);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
