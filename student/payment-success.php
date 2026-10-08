<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['student']);

$pageTitle = 'Payment Successful';
$currentPage = 'fines.php';
$paymentId = trim($_GET['payment_id'] ?? '');

if ($paymentId === '') {
    redirect('fines.php');
}

// Fetch student details
$stu = $pdo->prepare("SELECT s.*, u.name as student_name, u.phone, u.email FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?");
$stu->execute([$_SESSION['user_id']]);
$student = $stu->fetch();
$studentId = (int)$student['id'];

// Fetch the payment record
$stmt = $pdo->prepare("
    SELECT p.*, f.reason as fine_reason, f.late_days, f.fine_rate, f.issue_id,
           b.title as book_title, b.isbn, s.roll_number, s.department,
           u.name as student_name, u.phone, u.email
    FROM payments p
    JOIN fines f ON f.id = p.fine_id
    JOIN students s ON s.id = p.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN books b ON b.id = f.book_id
    WHERE (p.payment_id = ? OR p.id = ?) AND p.student_id = ?
    ORDER BY p.id DESC LIMIT 1
");
$stmt->execute([$paymentId, is_numeric($paymentId) ? (int)$paymentId : 0, $studentId]);
$payment = $stmt->fetch();

if (!$payment) {
    setFlash('error', 'Payment record not found.');
    redirect('fines.php');
}

$settings = getSettings($pdo);
$libraryName = $settings['library_name'] ?? 'Smart Library';

// Prepare Notification text as specified in Section 9
$notificationMessage = "Dear {$payment['student_name']},\n\n"
                     . "Your library fine payment has been successfully received.\n\n"
                     . "Amount Paid: ₹" . number_format($payment['amount'], 2) . "\n"
                     . "Student ID: {$payment['roll_number']}\n"
                     . "Payment ID: {$payment['payment_id']}\n"
                     . "Transaction ID: {$payment['transaction_reference']}\n"
                     . "Status: PAID\n\n"
                     . "Your library fine receipt has been generated.\n\n"
                     . "Thank you,\n{$libraryName} Management System";

$cleanPhone = preg_replace('/[^0-9]/', '', $payment['phone'] ?? '');
if (strlen($cleanPhone) === 10) $cleanPhone = '91' . $cleanPhone;

$waUrl = !empty($cleanPhone) ? "https://api.whatsapp.com/send?phone=" . $cleanPhone . "&text=" . rawurlencode($notificationMessage) : '#';
$smsUrl = !empty($cleanPhone) ? "sms:" . $cleanPhone . "?body=" . rawurlencode($notificationMessage) : '#';

include __DIR__ . '/../includes/header.php';
?>

<div style="max-width:740px; margin:20px auto 40px auto;">
    <!-- Success Banner Card -->
    <div class="card" style="border-radius:16px; box-shadow:0 10px 25px rgba(22,163,74,0.12); border-top:6px solid #16a34a; padding:32px 28px; text-align:center;">
        <div style="width:72px; height:72px; border-radius:50%; background:#dcfce7; color:#16a34a; display:inline-flex; align-items:center; justify-content:center; font-size:36px; margin-bottom:16px;">
            <i class="fa-solid fa-check"></i>
        </div>
        <h2 style="margin:0 0 6px 0; font-size:26px; color:#15803d; font-weight:800;">
            Payment Successful &check;
        </h2>
        <p class="text-muted" style="margin:0 0 24px 0; font-size:15px;">
            Your library overdue fine has been cleared in full. Official receipt has been generated.
        </p>

        <!-- Transaction Details Grid -->
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; text-align:left; margin-bottom:24px;">
            <div class="grid grid-2" style="gap:14px; font-size:14px;">
                <div>
                    <span class="text-muted" style="display:block; font-size:12.5px;">Payment ID</span>
                    <strong style="color:#0f766e; font-size:15px; font-family:monospace;"><?= e($payment['payment_id']) ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="display:block; font-size:12.5px;">Transaction Reference / UTR</span>
                    <code style="font-weight:700; color:#1e293b;"><?= e($payment['transaction_reference'] ?: 'TXN-SUCCESS') ?></code>
                </div>
                <div>
                    <span class="text-muted" style="display:block; font-size:12.5px;">Student ID (Roll No)</span>
                    <strong style="color:#1e293b;"><?= e($payment['roll_number']) ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="display:block; font-size:12.5px;">Student Name</span>
                    <strong style="color:#1e293b;"><?= e($payment['student_name']) ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="display:block; font-size:12.5px;">Book / Fine Reference</span>
                    <strong style="color:#1e293b;"><?= e($payment['book_title'] ?? $payment['fine_reason']) ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="display:block; font-size:12.5px;">Payment Method</span>
                    <span class="badge badge-blue"><i class="fa-solid fa-mobile-screen"></i> <?= ucfirst(e($payment['payment_method'])) ?></span>
                </div>
                <div>
                    <span class="text-muted" style="display:block; font-size:12.5px;">Date & Time</span>
                    <strong style="color:#1e293b;"><?= date('d M Y, h:i A', strtotime($payment['payment_date'])) ?></strong>
                </div>
                <div>
                    <span class="text-muted" style="display:block; font-size:12.5px;">Status</span>
                    <span class="badge badge-green" style="font-size:12.5px; font-weight:700;"><i class="fa-solid fa-circle-check"></i> PAID ✓</span>
                </div>
            </div>

            <div style="margin-top:16px; padding-top:14px; border-top:1px dashed #cbd5e1; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:15px; font-weight:600; color:#374151;">Amount Paid</span>
                <span style="font-size:26px; font-weight:800; color:#15803d;">&#8377;<?= number_format($payment['amount'], 2) ?></span>
            </div>
        </div>

        <!-- Section 9: Registered Mobile Notification Delivery Status -->
        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:18px; text-align:left; margin-bottom:24px;">
            <div class="flex items-center gap-2" style="margin-bottom:8px;">
                <i class="fa-solid fa-paper-plane text-primary"></i>
                <strong style="color:#1e40af; font-size:14px;">Notification Sent to Registered Phone: <?= e($payment['phone'] ?: 'Registered Contact') ?></strong>
            </div>
            <p style="margin:0 0 12px 0; font-size:13px; color:#1e3a8a;">
                Confirmation SMS & in-app notifications have been logged. You can also view or forward your receipt via WhatsApp:
            </p>
            <div class="flex gap-2" style="flex-wrap:wrap;">
                <?php if (!empty($payment['phone'])): ?>
                    <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:#fff; border-color:#22c55e;">
                        <i class="fa-brands fa-whatsapp"></i> Open Receipt on WhatsApp
                    </a>
                    <a href="<?= e($smsUrl) ?>" class="btn btn-sm" style="background:#3b82f6; color:#fff; border-color:#2563eb;">
                        <i class="fa-solid fa-comment-sms"></i> Open SMS
                    </a>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline" onclick="copyReceiptText()">
                    <i class="fa-solid fa-copy"></i> Copy Receipt Text
                </button>
            </div>
        </div>

        <!-- Action Buttons (Section 8: Print & Download PDF) -->
        <div class="flex justify-center gap-3" style="flex-wrap:wrap;">
            <a href="../receipt.php?payment_id=<?= urlencode($payment['payment_id']) ?>" target="_blank" class="btn btn-primary" style="padding:11px 24px; font-weight:700;">
                <i class="fa-solid fa-file-invoice"></i> View & Print Receipt
            </a>
            <a href="fines.php" class="btn btn-outline" style="padding:11px 20px;">
                <i class="fa-solid fa-list-check"></i> My Fines
            </a>
            <a href="dashboard.php" class="btn btn-outline" style="padding:11px 20px;">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
        </div>
    </div>
</div>

<script>
function copyReceiptText() {
    const text = <?= json_encode($notificationMessage) ?>;
    navigator.clipboard.writeText(text).then(() => {
        alert('Receipt text copied to clipboard!');
    }).catch(() => {
        prompt('Copy receipt text:', text);
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
