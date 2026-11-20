<?php
/**
 * Smart Library - Admin & Staff Digital Book Rental & UPI Payment Management
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['admin', 'librarian']);
$pageTitle = 'Digital Book Rentals & Payments';
$currentPage = 'rentals.php';

// Auto-expire past active rentals
try {
    $pdo->query("UPDATE book_rentals SET rental_status = 'expired' WHERE rental_status = 'active' AND expiry_date < NOW()");
} catch (Exception $e) {}

// Fetch current settings
$settings = getSettings($pdo);
$pricePerDay = !empty($settings['rental_price_per_day']) ? (float)$settings['rental_price_per_day'] : 5.00;
$upiId = !empty($settings['upi_id']) ? $settings['upi_id'] : '8766087273@ptyes';
$qrPath = !empty($settings['payment_qr_path']) ? $settings['payment_qr_path'] : 'assets/images/payment-qr.png';

// -------------------------------------------------------------
// POST ACTIONS (Approve, Reject, Extend, Update QR/Settings)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ---------- 1. APPROVE PENDING PAYMENT ----------
    if ($action === 'approve_rental') {
        $rentalId = (int)($_POST['rental_id'] ?? 0);
        $adminNotes = trim($_POST['admin_notes'] ?? '');

        $stmt = $pdo->prepare("
            SELECT br.*, db.title as book_title, s.user_id as student_user_id, u.name as student_name, s.roll_number
            FROM book_rentals br
            JOIN digital_books db ON db.id = br.digital_book_id
            JOIN students s ON s.id = br.student_id
            JOIN users u ON u.id = s.user_id
            WHERE br.id = ?
        ");
        $stmt->execute([$rentalId]);
        $r = $stmt->fetch();

        if (!$r) {
            setFlash('error', 'Rental record not found.');
            redirect('rentals.php');
        }

        if ($r['payment_status'] === 'PAID') {
            setFlash('error', 'This rental payment has already been approved and activated.');
            redirect('rentals.php');
        }

        $days = (int)$r['rental_days'];

        // If student already has another active rental on this book, extend from current expiry
        $checkActive = $pdo->prepare("
            SELECT expiry_date FROM book_rentals 
            WHERE student_id = ? AND digital_book_id = ? AND payment_status = 'PAID' AND rental_status = 'active' AND expiry_date >= NOW()
            ORDER BY expiry_date DESC LIMIT 1
        ");
        $checkActive->execute([$r['student_id'], $r['digital_book_id']]);
        $activeExp = $checkActive->fetchColumn();

        if ($activeExp && strtotime($activeExp) > time()) {
            $startDate = date('Y-m-d H:i:s');
            $expiryDate = date('Y-m-d H:i:s', strtotime($activeExp . " + $days days"));
        } else {
            $startDate = date('Y-m-d H:i:s');
            $expiryDate = date('Y-m-d H:i:s', strtotime("+ $days days"));
        }

        $upd = $pdo->prepare("
            UPDATE book_rentals 
            SET payment_status = 'PAID',
                rental_status = 'active',
                start_date = ?,
                expiry_date = ?,
                verified_by = ?,
                verified_at = NOW(),
                admin_notes = ?
            WHERE id = ?
        ");
        $upd->execute([$startDate, $expiryDate, $_SESSION['user_id'], $adminNotes, $rentalId]);

        // Notify student
        $expFmt = date('d M Y, h:i A', strtotime($expiryDate));
        notify($pdo, $r['student_user_id'], 'Book Rental Activated ✓', "Your payment of ₹{$r['total_amount']} (UTR: {$r['transaction_reference']}) for \"{$r['book_title']}\" has been verified and approved. Reading access is active until $expFmt.");

        // Log activity
        logActivity($pdo, $_SESSION['user_id'], "Approved rental payment of Rs. {$r['total_amount']} for student {$r['student_name']} ({$r['roll_number']}) on book \"{$r['book_title']}\" (UTR: {$r['transaction_reference']})");

        setFlash('success', "Rental payment approved successfully! Reading access for {$r['student_name']} has been activated until $expFmt.");
        redirect('rentals.php');
    }

    // ---------- 2. REJECT PAYMENT ----------
    if ($action === 'reject_rental') {
        $rentalId = (int)($_POST['rental_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? 'Payment transaction could not be verified in merchant records.');

        $stmt = $pdo->prepare("
            SELECT br.*, db.title as book_title, s.user_id as student_user_id, u.name as student_name, s.roll_number
            FROM book_rentals br
            JOIN digital_books db ON db.id = br.digital_book_id
            JOIN students s ON s.id = br.student_id
            JOIN users u ON u.id = s.user_id
            WHERE br.id = ?
        ");
        $stmt->execute([$rentalId]);
        $r = $stmt->fetch();

        if (!$r) {
            setFlash('error', 'Rental record not found.');
            redirect('rentals.php');
        }

        $upd = $pdo->prepare("
            UPDATE book_rentals 
            SET payment_status = 'REJECTED',
                rental_status = 'cancelled',
                rejection_reason = ?,
                verified_by = ?,
                verified_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$reason, $_SESSION['user_id'], $rentalId]);

        notify($pdo, $r['student_user_id'], 'Rental Payment Rejected ✕', "Your rental payment of ₹{$r['total_amount']} (UTR: {$r['transaction_reference']}) for \"{$r['book_title']}\" was rejected. Reason: $reason");

        logActivity($pdo, $_SESSION['user_id'], "Rejected rental payment for student {$r['student_name']} (UTR: {$r['transaction_reference']}). Reason: $reason");

        setFlash('success', "Rental payment marked as REJECTED. Student notification sent.");
        redirect('rentals.php');
    }

    // ---------- 3. EXTEND RENTAL DURATION ----------
    if ($action === 'extend_rental') {
        $rentalId = (int)($_POST['rental_id'] ?? 0);
        $extraDays = max(1, (int)($_POST['extra_days'] ?? 1));

        $stmt = $pdo->prepare("SELECT * FROM book_rentals WHERE id = ?");
        $stmt->execute([$rentalId]);
        $r = $stmt->fetch();

        if (!$r || $r['payment_status'] !== 'PAID') {
            setFlash('error', 'Only active/paid rentals can be extended.');
            redirect('rentals.php');
        }

        $baseTime = strtotime($r['expiry_date']) > time() ? strtotime($r['expiry_date']) : time();
        $newExpiry = date('Y-m-d H:i:s', strtotime("+ $extraDays days", $baseTime));

        $upd = $pdo->prepare("
            UPDATE book_rentals 
            SET expiry_date = ?, rental_days = rental_days + ?, rental_status = 'active'
            WHERE id = ?
        ");
        $upd->execute([$newExpiry, $extraDays, $rentalId]);

        logActivity($pdo, $_SESSION['user_id'], "Extended rental #{$r['rental_code']} by $extraDays days (New Expiry: $newExpiry)");
        setFlash('success', "Rental extended by $extraDays days. New expiry: " . date('d M Y, h:i A', strtotime($newExpiry)));
        redirect('rentals.php');
    }

    // ---------- 4. UPDATE QR CONFIGURATION & UPI SETTINGS ----------
    if ($action === 'update_payment_settings' && $_SESSION['role'] === 'admin') {
        $newUpi = trim($_POST['upi_id'] ?? '8766087273@ptyes');
        $newPrice = max(1.0, (float)($_POST['rental_price_per_day'] ?? 5.00));
        $newAccountName = trim($_POST['upi_account_name'] ?? 'Smart Library');

        // Check if a new QR code image was uploaded
        $updatedQrPath = $qrPath;
        if (!empty($_FILES['qr_image']['name']) && $_FILES['qr_image']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['qr_image']['tmp_name'];
            $origName = $_FILES['qr_image']['name'];
            $size = $_FILES['qr_image']['size'];

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
                setFlash('error', 'Only PNG or JPG images are allowed for payment QR.');
                redirect('rentals.php');
            }

            if ($size > 5 * 1024 * 1024) {
                setFlash('error', 'QR image must be under 5MB.');
                redirect('rentals.php');
            }

            // Save to assets/images/payment-qr.png or timestamped
            $targetPath = __DIR__ . '/../assets/images/payment-qr.png';
            if (move_uploaded_file($tmp, $targetPath)) {
                $updatedQrPath = 'assets/images/payment-qr.png';
            }
        }

        $upd = $pdo->prepare("
            UPDATE library_settings 
            SET upi_id = ?, rental_price_per_day = ?, payment_qr_path = ?, upi_account_name = ?
            WHERE id = 1
        ");
        $upd->execute([$newUpi, $newPrice, $updatedQrPath, $newAccountName]);

        logActivity($pdo, $_SESSION['user_id'], "Updated UPI payment configuration (UPI ID: $newUpi, Rate: Rs. $newPrice/day)");
        setFlash('success', 'UPI payment and QR configuration updated successfully.');
        redirect('rentals.php');
    }
}

// -------------------------------------------------------------
// FILTER & SEARCH QUERY
// -------------------------------------------------------------
$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');
$tab = trim($_GET['tab'] ?? ($statusFilter !== 'all' ? $statusFilter : 'all'));

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(br.rental_code LIKE ? OR u.name LIKE ? OR s.roll_number LIKE ? OR br.transaction_reference LIKE ? OR db.title LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

if ($tab === 'pending') {
    $where[] = "br.payment_status = 'PENDING'";
} elseif ($tab === 'paid' || $tab === 'active') {
    $where[] = "br.payment_status = 'PAID' AND br.rental_status = 'active' AND br.expiry_date >= NOW()";
} elseif ($tab === 'expired') {
    $where[] = "(br.rental_status = 'expired' OR (br.expiry_date IS NOT NULL AND br.expiry_date < NOW()))";
} elseif ($tab === 'rejected') {
    $where[] = "br.payment_status = 'REJECTED'";
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Fetch rental list
$stmt = $pdo->prepare("
    SELECT br.*, 
           db.title AS book_title, db.pages, db.cover_image, c.name AS category_name,
           u.name AS student_name, u.email AS student_email, u.phone AS student_phone,
           s.roll_number, s.department,
           v.name AS verifier_name
    FROM book_rentals br
    JOIN digital_books db ON db.id = br.digital_book_id
    JOIN students s ON s.id = br.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN users v ON v.id = br.verified_by
    $whereSql
    ORDER BY 
        CASE WHEN br.payment_status = 'PENDING' THEN 1 ELSE 2 END,
        br.id DESC
");
$stmt->execute($params);
$rentals = $stmt->fetchAll();

// Overall Metrics
$totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM book_rentals WHERE payment_status = 'PAID'")->fetchColumn();
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM book_rentals WHERE payment_status = 'PENDING'")->fetchColumn();
$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM book_rentals WHERE payment_status = 'PAID' AND rental_status = 'active' AND expiry_date >= NOW()")->fetchColumn();
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM book_rentals")->fetchColumn();
$rejectedCount = (int)$pdo->query("SELECT COUNT(*) FROM book_rentals WHERE payment_status = 'REJECTED'")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header flex justify-between items-center" style="margin-bottom: 24px; flex-wrap: wrap; gap: 14px;">
    <div>
        <h2 style="margin: 0 0 4px 0; font-size: 24px; font-weight: 700;">Digital Book Rentals & UPI Payments</h2>
        <div class="text-muted" style="font-size: 13.5px;">
            Verify student UPI payments, approve digital subscriptions, and manage QR code billing.
        </div>
    </div>
    <div class="flex gap-2">
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <button class="btn btn-outline" onclick="toggleConfigPanel()">
                <i class="fa-solid fa-qrcode text-primary"></i> Payment QR & UPI Settings
            </button>
        <?php endif; ?>
        <a href="digital-books.php" class="btn btn-primary">
            <i class="fa-solid fa-file-pdf"></i> Digital Books Catalog
        </a>
    </div>
</div>

<!-- Revenue & Rental Metrics Cards -->
<div class="grid grid-4" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
            <i class="fa-solid fa-sack-dollar"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value">₹<?= number_format($totalRevenue, 2) ?></div>
            <div class="stat-label">Total Verified Rental Revenue</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
            <i class="fa-solid fa-hourglass-half"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value" style="color: <?= $pendingCount > 0 ? '#d97706' : 'var(--text)' ?>;">
                <?= $pendingCount ?>
            </div>
            <div class="stat-label">
                Pending Verifications <?= $pendingCount > 0 ? '<span class="badge badge-yellow" style="font-size:10px;">Action Needed</span>' : '' ?>
            </div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(99, 102, 241, 0.15); color: #6366f1;">
            <i class="fa-solid fa-book-bookmark"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?= $activeCount ?></div>
            <div class="stat-label">Active Reader Access Passes</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(234, 88, 12, 0.15); color: #ea580c;">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?= $totalCount ?></div>
            <div class="stat-label">Total Subscriptions All-Time</div>
        </div>
    </div>
</div>

<!-- UPI QR & Merchant Configuration Drawer / Panel (Toggleable) -->
<div id="paymentConfigCard" class="card" style="display: none; padding: 24px; margin-bottom: 24px; border: 2px solid var(--primary); background: var(--bg-card);">
    <div class="flex justify-between items-start" style="margin-bottom: 18px;">
        <div>
            <h3 style="margin: 0 0 4px 0; font-size: 18px; font-weight: 700; color: var(--text);">
                <i class="fa-solid fa-gear text-primary"></i> UPI Payment & QR Code Configuration
            </h3>
            <p class="text-muted" style="margin: 0; font-size: 13px;">
                Manage the authentic UPI ID and payment QR code image displayed to students during checkout.
            </p>
        </div>
        <button type="button" class="btn btn-sm btn-outline" onclick="toggleConfigPanel()">
            <i class="fa-solid fa-xmark"></i> Close
        </button>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_payment_settings">

        <div class="grid grid-3" style="gap: 24px; align-items: flex-start;">
            <!-- Current QR Image Preview -->
            <div style="text-align: center; background: rgba(0,0,0,0.02); border: 1px solid var(--border); border-radius: 12px; padding: 16px;">
                <div class="text-muted" style="font-size: 12px; font-weight: 600; margin-bottom: 8px;">ACTIVE QR CODE IMAGE</div>
                <div style="background: #ffffff; padding: 10px; border-radius: 10px; display: inline-block; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 10px;">
                    <img src="<?= basePath() ?>/<?= e($qrPath) ?>?v=<?= file_exists(__DIR__ . '/../' . $qrPath) ? filemtime(__DIR__ . '/../' . $qrPath) : time() ?>" 
                         alt="Current Payment QR" 
                         style="width: 140px; height: 140px; object-fit: contain; display: block;">
                </div>
                <div style="font-size: 11.5px; color: var(--text-muted);">
                    File: <code><?= e($qrPath) ?></code>
                </div>
            </div>

            <!-- Configuration Inputs -->
            <div style="grid-column: span 2;">
                <div class="grid grid-2" style="gap: 16px; margin-bottom: 16px;">
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 13.5px; margin-bottom: 6px;">
                            Merchant UPI ID <span style="color:#ef4444;">*</span>
                        </label>
                        <input type="text" name="upi_id" required value="<?= e($upiId) ?>" class="form-control" style="font-family: monospace; font-weight: 700; font-size: 14px;">
                        <span class="text-muted" style="font-size: 11.5px;">Current authentic ID: <code>8766087273@ptyes</code></span>
                    </div>

                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 13.5px; margin-bottom: 6px;">
                            Rental Price Per Day (₹) <span style="color:#ef4444;">*</span>
                        </label>
                        <input type="number" name="rental_price_per_day" step="0.50" min="1.00" required value="<?= number_format($pricePerDay, 2, '.', '') ?>" class="form-control" style="font-weight: 700;">
                        <span class="text-muted" style="font-size: 11.5px;">Standard rate: <strong>₹5.00 / day</strong></span>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; font-size: 13.5px; margin-bottom: 6px;">
                        Upload Replacement QR Code Image (Optional)
                    </label>
                    <input type="file" name="qr_image" accept=".png,.jpg,.jpeg" class="form-control">
                    <span class="text-muted" style="font-size: 11.5px;">Upload only if you want to replace the current QR code image with a new merchant QR. Accepted: PNG, JPG (max 5MB).</span>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Save Payment Configuration
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Architecture & Verification Note Card -->
<div class="card" style="padding: 14px 18px; margin-bottom: 24px; background: rgba(59, 130, 246, 0.05); border-left: 4px solid #3b82f6;">
    <div class="flex items-center gap-3">
        <i class="fa-solid fa-circle-info text-primary" style="font-size: 20px;"></i>
        <div style="font-size: 13px; line-height: 1.45; color: var(--text);">
            <strong>Real Payment Verification Workflow:</strong> Students scan the authentic UPI QR code (<code><?= e($upiId) ?></code>) using any UPI app and submit their 12-digit UPI Reference No. (UTR). When you cross-check and click <strong>"Verify & Approve"</strong>, the backend immediately activates the digital reader pass, records the timestamp, and unlocks full PDF access for the selected days.
        </div>
    </div>
</div>

<!-- Search & Filter Controls -->
<div class="card" style="padding: 18px; margin-bottom: 20px;">
    <form method="GET" class="flex gap-2 items-center" style="flex-wrap: wrap;">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">

        <div style="position: relative; flex: 1; min-width: 260px;">
            <i class="fa-solid fa-magnifying-glass text-muted" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px;"></i>
            <input type="text" name="q" class="form-control" style="padding-left: 36px;" placeholder="Search UTR / Transaction ID, Student, Roll No, Book Title, Code..." value="<?= e($search) ?>">
        </div>

        <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-filter"></i> Search
        </button>
        <?php if ($search !== ''): ?>
            <a href="rentals.php?tab=<?= e($tab) ?>" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Filter Tabs -->
<div class="flex gap-2" style="margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">
    <a href="rentals.php?tab=all<?= $search ? '&q='.urlencode($search) : '' ?>" class="btn <?= $tab === 'all' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 13px;">
        All Transactions (<?= $totalCount ?>)
    </a>
    <a href="rentals.php?tab=pending<?= $search ? '&q='.urlencode($search) : '' ?>" class="btn <?= $tab === 'pending' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 13px;">
        <i class="fa-solid fa-hourglass-half"></i> Pending Verifications
        <?php if ($pendingCount > 0): ?>
            <span class="badge badge-yellow" style="margin-left: 4px;"><?= $pendingCount ?></span>
        <?php endif; ?>
    </a>
    <a href="rentals.php?tab=paid<?= $search ? '&q='.urlencode($search) : '' ?>" class="btn <?= $tab === 'paid' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 13px;">
        <i class="fa-solid fa-circle-check text-green"></i> Active Passes (<?= $activeCount ?>)
    </a>
    <a href="rentals.php?tab=expired<?= $search ? '&q='.urlencode($search) : '' ?>" class="btn <?= $tab === 'expired' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 13px;">
        <i class="fa-solid fa-clock-rotate-left"></i> Expired
    </a>
    <a href="rentals.php?tab=rejected<?= $search ? '&q='.urlencode($search) : '' ?>" class="btn <?= $tab === 'rejected' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 13px;">
        <i class="fa-solid fa-ban text-red"></i> Rejected (<?= $rejectedCount ?>)
    </a>
</div>

<!-- Transactions Table -->
<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rental Code & Date</th>
                    <th>Student Details</th>
                    <th>Digital Book</th>
                    <th>Duration & Amount</th>
                    <th>Transaction Ref (UTR)</th>
                    <th>Payment Status</th>
                    <th>Access Validity</th>
                    <th style="text-align: right;">Verification Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rentals)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px 20px; color: var(--text-muted);">
                            <i class="fa-solid fa-receipt" style="font-size: 32px; margin-bottom: 8px; display: block;"></i>
                            No rental records found matching the active filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rentals as $r): 
                        $isActive = ($r['payment_status'] === 'PAID' && $r['rental_status'] === 'active' && strtotime($r['expiry_date']) >= time());
                        $isPending = ($r['payment_status'] === 'PENDING');
                        $isExpired = ($r['rental_status'] === 'expired' || ($r['expiry_date'] && strtotime($r['expiry_date']) < time()));
                        $isRejected = ($r['payment_status'] === 'REJECTED');
                    ?>
                    <tr style="<?= $isPending ? 'background: rgba(245, 158, 11, 0.04);' : '' ?>">
                        <!-- Rental Code & Created At -->
                        <td>
                            <strong style="font-family: monospace; font-size: 13px; color: var(--text);"><?= e($r['rental_code']) ?></strong>
                            <div class="text-muted" style="font-size: 11px;">
                                <?= date('d M Y, h:i A', strtotime($r['created_at'])) ?>
                            </div>
                        </td>

                        <!-- Student -->
                        <td>
                            <strong style="color: var(--text); font-size: 13.5px;"><?= e($r['student_name']) ?></strong>
                            <div class="text-muted" style="font-size: 11.5px;">
                                <i class="fa-solid fa-id-card"></i> <?= e($r['roll_number']) ?> &middot; <?= e($r['department'] ?: 'Student') ?>
                            </div>
                            <?php if (!empty($r['student_phone'])): ?>
                                <div class="text-muted" style="font-size: 11px;">
                                    <i class="fa-solid fa-phone"></i> <?= e($r['student_phone']) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Book -->
                        <td>
                            <strong style="color: var(--text); font-size: 13.5px; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; overflow:hidden;" title="<?= e($r['book_title']) ?>">
                                <?= e($r['book_title']) ?>
                            </strong>
                            <div class="text-muted" style="font-size: 11.5px;">
                                <span class="badge badge-blue" style="font-size: 10px;"><?= e($r['category_name'] ?: 'Digital') ?></span>
                                &middot; <?= (int)$r['pages'] ?> pages
                            </div>
                        </td>

                        <!-- Duration & Amount -->
                        <td>
                            <div style="font-weight: 700; color: #ea580c; font-size: 14.5px;">
                                ₹<?= number_format($r['total_amount'], 2) ?>
                            </div>
                            <div class="text-muted" style="font-size: 11.5px;">
                                <?= (int)$r['rental_days'] ?> Days &middot; (₹<?= number_format($r['price_per_day'], 2) ?>/d)
                            </div>
                        </td>

                        <!-- UTR -->
                        <td>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <code style="font-size: 13px; font-weight: 700; background: rgba(0,0,0,0.04); padding: 3px 6px; border-radius: 4px; color: var(--text);">
                                    <?= e($r['transaction_reference'] ?: 'N/A') ?>
                                </code>
                                <?php if (!empty($r['transaction_reference'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline" style="padding: 2px 6px; font-size: 10.5px;" title="Copy UTR" onclick="copyText('<?= e($r['transaction_reference']) ?>')">
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted" style="font-size: 10.5px; margin-top: 2px;">
                                Pay Mode: <?= e($r['payment_method']) ?>
                            </div>
                        </td>

                        <!-- Payment & Rental Status -->
                        <td>
                            <?php if ($r['payment_status'] === 'PAID'): ?>
                                <span class="badge badge-green"><i class="fa-solid fa-circle-check"></i> PAID</span>
                            <?php elseif ($r['payment_status'] === 'PENDING'): ?>
                                <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.3); font-weight: 700;">
                                    <i class="fa-solid fa-hourglass-half"></i> PENDING
                                </span>
                            <?php elseif ($r['payment_status'] === 'REJECTED'): ?>
                                <span class="badge badge-red"><i class="fa-solid fa-circle-xmark"></i> REJECTED</span>
                            <?php else: ?>
                                <span class="badge badge-gray"><?= e($r['payment_status']) ?></span>
                            <?php endif; ?>

                            <?php if ($r['verifier_name']): ?>
                                <div class="text-muted" style="font-size: 10.5px; margin-top: 3px;">
                                    By: <?= e($r['verifier_name']) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Validity Period -->
                        <td>
                            <?php if ($isActive): 
                                $daysLeft = max(0, ceil((strtotime($r['expiry_date']) - time()) / 86400));
                            ?>
                                <div style="color: #059669; font-weight: 700; font-size: 12px;">
                                    <i class="fa-solid fa-clock"></i> <?= $daysLeft ?> days left
                                </div>
                                <div class="text-muted" style="font-size: 11px;">
                                    Exp: <?= date('d M Y, h:i A', strtotime($r['expiry_date'])) ?>
                                </div>
                            <?php elseif ($isPending): ?>
                                <span class="text-muted" style="font-size: 12px;">Awaiting verification</span>
                            <?php elseif ($isExpired): ?>
                                <span class="text-muted" style="font-size: 12px;">Expired on <?= date('d M Y', strtotime($r['expiry_date'])) ?></span>
                            <?php elseif ($isRejected): ?>
                                <span class="text-danger" style="font-size: 11.5px;" title="<?= e($r['rejection_reason']) ?>">
                                    <?= e(substr($r['rejection_reason'] ?: 'Rejected', 0, 24)) ?>...
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Actions -->
                        <td style="text-align: right;">
                            <div class="flex gap-1 justify-end" style="flex-wrap: wrap;">
                                <?php if ($isPending): ?>
                                    <!-- Approve Form -->
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Confirm that payment of ₹<?= number_format($r['total_amount'], 2) ?> with UTR <?= e($r['transaction_reference']) ?> has been verified in your merchant account?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="approve_rental">
                                        <input type="hidden" name="rental_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-primary" style="background: #10b981; border-color: #059669;" title="Approve & Activate Access">
                                            <i class="fa-solid fa-check"></i> Approve
                                        </button>
                                    </form>

                                    <!-- Reject Modal Trigger -->
                                    <button type="button" class="btn btn-sm btn-outline" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.4);" onclick="openRejectModal(<?= $r['id'] ?>, '<?= e(addslashes($r['student_name'])) ?>', '<?= e(addslashes($r['transaction_reference'])) ?>', '<?= number_format($r['total_amount'], 2) ?>')" title="Reject Payment">
                                        <i class="fa-solid fa-xmark"></i> Reject
                                    </button>
                                <?php elseif ($isActive): ?>
                                    <a href="<?= basePath() ?>/student/read-book.php?id=<?= $r['digital_book_id'] ?>" target="_blank" class="btn btn-sm btn-outline" title="Open E-Reader">
                                        <i class="fa-solid fa-book-open"></i> Read
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline" onclick="openExtendModal(<?= $r['id'] ?>, '<?= e(addslashes($r['student_name'])) ?>', '<?= e(addslashes($r['book_title'])) ?>')" title="Extend duration">
                                        <i class="fa-solid fa-calendar-plus"></i> Extend
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 12px;">--</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
    <div class="card" style="max-width:480px; width:90%; padding:24px; animation: fadeIn 0.2s;">
        <div class="flex justify-between items-center" style="margin-bottom:16px;">
            <h3 style="margin:0; font-size:18px; color:#ef4444;"><i class="fa-solid fa-circle-xmark"></i> Reject Rental Payment</h3>
            <button type="button" class="btn btn-sm btn-outline" onclick="closeRejectModal()">&times;</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject_rental">
            <input type="hidden" name="rental_id" id="rejectRentalId">

            <p style="font-size:13.5px; color:var(--text); margin-bottom:14px;">
                Reject payment from <strong id="rejectStudentName"></strong> (UTR: <strong id="rejectUtr"></strong>, Amount: ₹<span id="rejectAmt"></span>)?
            </p>

            <div class="form-group" style="margin-bottom:18px;">
                <label style="font-weight:600; font-size:13px; margin-bottom:6px; display:block;">Rejection Reason (Sent to Student)</label>
                <select name="rejection_reason_select" class="form-control" style="margin-bottom:8px;" onchange="if(this.value) document.getElementById('rejectReasonText').value = this.value;">
                    <option value="">-- Choose Common Reason --</option>
                    <option value="Payment transaction / UTR not found in merchant bank records.">Payment transaction / UTR not found in bank records</option>
                    <option value="Transferred amount does not match the required rental price.">Incorrect / insufficient amount paid</option>
                    <option value="Duplicate or already used transaction reference.">Duplicate or already used UTR reference</option>
                    <option value="Payment failed or was refunded by sender bank.">Payment was refunded or failed</option>
                </select>
                <textarea name="rejection_reason" id="rejectReasonText" rows="3" class="form-control" placeholder="Explain why this payment cannot be approved..."></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-outline" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#dc2626; border-color:#b91c1c;">
                    <i class="fa-solid fa-ban"></i> Confirm Rejection
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Extend Modal -->
<div id="extendModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
    <div class="card" style="max-width:440px; width:90%; padding:24px; animation: fadeIn 0.2s;">
        <div class="flex justify-between items-center" style="margin-bottom:16px;">
            <h3 style="margin:0; font-size:18px; color:var(--text);"><i class="fa-solid fa-calendar-plus text-primary"></i> Extend Rental Pass</h3>
            <button type="button" class="btn btn-sm btn-outline" onclick="closeExtendModal()">&times;</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="extend_rental">
            <input type="hidden" name="rental_id" id="extendRentalId">

            <p style="font-size:13.5px; color:var(--text); margin-bottom:14px;">
                Grant additional reading access for <strong id="extendStudentName"></strong> on <em id="extendBookTitle"></em>.
            </p>

            <div class="form-group" style="margin-bottom:18px;">
                <label style="font-weight:600; font-size:13px; margin-bottom:6px; display:block;">Extra Days to Add</label>
                <select name="extra_days" class="form-control">
                    <option value="1">+ 1 Day</option>
                    <option value="3">+ 3 Days</option>
                    <option value="7" selected>+ 7 Days</option>
                    <option value="15">+ 15 Days</option>
                    <option value="30">+ 30 Days</option>
                </select>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-outline" onclick="closeExtendModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i> Apply Extension
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleConfigPanel() {
    const p = document.getElementById('paymentConfigCard');
    p.style.display = (p.style.display === 'none' || p.style.display === '') ? 'block' : 'none';
    if (p.style.display === 'block') {
        p.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function openRejectModal(id, student, utr, amt) {
    document.getElementById('rejectRentalId').value = id;
    document.getElementById('rejectStudentName').textContent = student;
    document.getElementById('rejectUtr').textContent = utr;
    document.getElementById('rejectAmt').textContent = amt;
    document.getElementById('rejectReasonText').value = 'Payment transaction / UTR could not be verified in merchant bank records.';
    const modal = document.getElementById('rejectModal');
    modal.style.display = 'flex';
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}

function openExtendModal(id, student, title) {
    document.getElementById('extendRentalId').value = id;
    document.getElementById('extendStudentName').textContent = student;
    document.getElementById('extendBookTitle').textContent = title;
    const modal = document.getElementById('extendModal');
    modal.style.display = 'flex';
}

function closeExtendModal() {
    document.getElementById('extendModal').style.display = 'none';
}

function copyText(str) {
    navigator.clipboard.writeText(str).then(() => {
        alert('Copied UTR to clipboard: ' + str);
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
