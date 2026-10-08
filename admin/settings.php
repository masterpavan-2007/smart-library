<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);
$pageTitle = 'Library Settings';
$currentPage = 'settings.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $stmt = $pdo->prepare("
        UPDATE library_settings 
        SET library_name = ?,
            borrow_period_days = ?,
            fine_per_day = ?,
            max_fine = ?,
            grace_period_days = ?,
            max_books_per_student = ?,
            reservation_valid_days = ?,
            upi_id = ?,
            upi_merchant_name = ?,
            library_address = ?,
            library_phone = ?,
            sms_provider = ?,
            sms_api_key = ?,
            whatsapp_api_key = ?
        WHERE id = 1
    ");
    $stmt->execute([
        trim($_POST['library_name'] ?? 'Smart Library'),
        max(1, (int)($_POST['borrow_period_days'] ?? 7)),
        max(0, (float)($_POST['fine_per_day'] ?? 5)),
        max(0, (float)($_POST['max_fine'] ?? 200)),
        max(0, (int)($_POST['grace_period_days'] ?? 0)),
        max(1, (int)($_POST['max_books_per_student'] ?? 3)),
        max(1, (int)($_POST['reservation_valid_days'] ?? 3)),
        trim($_POST['upi_id'] ?? 'smartlibrary@upi'),
        trim($_POST['upi_merchant_name'] ?? 'Smart Library'),
        trim($_POST['library_address'] ?? 'Knowledge Hub, Central Campus, Pune - 411001'),
        trim($_POST['library_phone'] ?? '+91 98765 43210'),
        trim($_POST['sms_provider'] ?? 'demo'),
        trim($_POST['sms_api_key'] ?? ''),
        trim($_POST['whatsapp_api_key'] ?? '')
    ]);
    setFlash('success', 'Library settings and online payment configuration saved.');
    redirect('settings.php');
}

$settings = $pdo->query("SELECT * FROM library_settings LIMIT 1")->fetch();

include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:780px; margin-bottom:24px;">
    <h3><i class="fa-solid fa-gear text-primary"></i> Library Circulation & Fine Settings</h3>
    <p class="text-muted" style="margin-top:-6px; font-size:13px; margin-bottom:18px;">
        Configure loan rules, overdue fine rates, UPI payment parameters, and receipt details.
    </p>

    <form method="POST" action="settings.php">
        <?= csrfField() ?>
        
        <div class="form-group">
            <label class="form-label">Library / College Name</label>
            <input class="form-control" name="library_name" value="<?= e($settings['library_name'] ?? 'Smart Library') ?>" required>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Fine Rate per Day (&#8377;)</label>
                <input class="form-control" type="number" step="0.50" name="fine_per_day" min="0" value="<?= e($settings['fine_per_day'] ?? 5.00) ?>" required>
                <div class="text-muted" style="font-size:11.5px; margin-top:2px;">Standard daily charge for overdue books (default: &#8377;5.00/day)</div>
            </div>
            <div class="form-group">
                <label class="form-label">Maximum Fine Cap (&#8377;)</label>
                <input class="form-control" type="number" step="1.00" name="max_fine" min="0" value="<?= e($settings['max_fine'] ?? 200.00) ?>" required>
                <div class="text-muted" style="font-size:11.5px; margin-top:2px;">Set to 0 for unlimited late fine accumulation</div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Default Borrowing Period (Days)</label>
                <input class="form-control" type="number" name="borrow_period_days" min="1" value="<?= e($settings['borrow_period_days'] ?? 7) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Grace Period (Days)</label>
                <input class="form-control" type="number" name="grace_period_days" min="0" value="<?= e($settings['grace_period_days'] ?? 0) ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Max Books per Student</label>
                <input class="form-control" type="number" name="max_books_per_student" min="1" value="<?= e($settings['max_books_per_student'] ?? 3) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Reservation Validity (Days)</label>
                <input class="form-control" type="number" name="reservation_valid_days" min="1" value="<?= e($settings['reservation_valid_days'] ?? 3) ?>" required>
            </div>
        </div>

        <hr style="border:0; border-top:1px solid var(--border); margin:24px 0;">

        <!-- Online UPI Payment Settings (Section 5, 6) -->
        <h4 style="margin:0 0 14px 0; font-size:15px; color:#1e293b; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-qrcode text-primary"></i> Online UPI Fine Payment & QR Configuration
        </h4>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Institution UPI ID (VPA)</label>
                <input class="form-control" name="upi_id" placeholder="e.g. library@okhdfcbank" value="<?= e($settings['upi_id'] ?? 'smartlibrary@upi') ?>" required>
                <div class="text-muted" style="font-size:11.5px; margin-top:2px;">Scanned by student UPI apps (Google Pay, PhonePe, Paytm, BHIM)</div>
            </div>
            <div class="form-group">
                <label class="form-label">Payee / Merchant Display Name</label>
                <input class="form-control" name="upi_merchant_name" placeholder="e.g. Smart Library Central" value="<?= e($settings['upi_merchant_name'] ?? 'Smart Library') ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Library Address (Printed on Receipts)</label>
                <input class="form-control" name="library_address" value="<?= e($settings['library_address'] ?? 'Central Campus, Main Academic Block, Pune - 411001') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Official Contact Phone Number</label>
                <input class="form-control" name="library_phone" value="<?= e($settings['library_phone'] ?? '+91 98765 43210') ?>">
            </div>
        </div>

        <hr style="border:0; border-top:1px solid var(--border); margin:24px 0;">

        <!-- SMS / WhatsApp Gateway Settings (Section 9) -->
        <h4 style="margin:0 0 14px 0; font-size:15px; color:#1e293b; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-bell text-success"></i> SMS & WhatsApp Multi-Channel Notification Provider
        </h4>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Gateway Mode</label>
                <select name="sms_provider" class="form-control">
                    <option value="demo" <?= ($settings['sms_provider'] ?? 'demo') === 'demo' ? 'selected' : '' ?>>Demo Mode (Simulated multi-channel logging + Direct WhatsApp/SMS links)</option>
                    <option value="live_fast2sms" <?= ($settings['sms_provider'] ?? '') === 'live_fast2sms' ? 'selected' : '' ?>>Fast2SMS Live API (India)</option>
                    <option value="live_msg91" <?= ($settings['sms_provider'] ?? '') === 'live_msg91' ? 'selected' : '' ?>>MSG91 Live API</option>
                    <option value="live_twilio" <?= ($settings['sms_provider'] ?? '') === 'live_twilio' ? 'selected' : '' ?>>Twilio SMS API</option>
                </select>
                <div class="text-muted" style="font-size:11.5px; margin-top:2px;">In Demo Mode, receipts and reminders provide one-click direct WhatsApp & SMS links.</div>
            </div>
            <div class="form-group">
                <label class="form-label">SMS Provider API Key (Stored Server-Side)</label>
                <input type="password" class="form-control" name="sms_api_key" value="<?= e($settings['sms_api_key'] ?? '') ?>" placeholder="Optional API Key">
            </div>
        </div>

        <div style="margin-top:20px;">
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Configuration</button>
        </div>
    </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
