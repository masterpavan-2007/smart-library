<?php
/**
 * Smart Virtual Library - Student Rented Books & Subscriptions
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['student']);
$pageTitle = 'My Rented Books';
$currentPage = 'rentals.php';

// Fetch current student
$st = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
$st->execute([$_SESSION['user_id']]);
$studentId = (int)$st->fetchColumn();

// Auto-expire past rentals
try {
    $pdo->query("UPDATE book_rentals SET rental_status = 'expired' WHERE rental_status = 'active' AND expiry_date < NOW()");
} catch (Exception $e) {}

// Filter tab
$tab = trim($_GET['tab'] ?? 'all');

$where = ["br.student_id = ?"];
$params = [$studentId];

if ($tab === 'active') {
    $where[] = "br.rental_status = 'active' AND br.payment_status = 'PAID' AND br.expiry_date >= NOW()";
} elseif ($tab === 'pending') {
    $where[] = "br.payment_status = 'PENDING'";
} elseif ($tab === 'expired') {
    $where[] = "(br.rental_status = 'expired' OR (br.expiry_date IS NOT NULL AND br.expiry_date < NOW()))";
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Fetch rentals
$stmt = $pdo->prepare("
    SELECT br.*, db.title, db.pages, db.language, db.cover_image, db.access_type,
           c.name AS category_name, COALESCE(a.name, db.author_name) AS author_name,
           u_ver.name AS verifier_name
    FROM book_rentals br
    JOIN digital_books db ON db.id = br.digital_book_id
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN authors a ON a.id = db.author_id
    LEFT JOIN users u_ver ON u_ver.id = br.verified_by
    $whereSql
    ORDER BY br.id DESC
");
$stmt->execute($params);
$rentals = $stmt->fetchAll();

// Statistics
$statActive = (int)$pdo->query("SELECT COUNT(*) FROM book_rentals WHERE student_id = $studentId AND payment_status = 'PAID' AND rental_status = 'active' AND expiry_date >= NOW()")->fetchColumn();
$statPending = (int)$pdo->query("SELECT COUNT(*) FROM book_rentals WHERE student_id = $studentId AND payment_status = 'PENDING'")->fetchColumn();
$statTotal = (int)$pdo->query("SELECT COUNT(*) FROM book_rentals WHERE student_id = $studentId")->fetchColumn();
$statSpent = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM book_rentals WHERE student_id = $studentId AND payment_status = 'PAID'")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header flex justify-between items-center" style="margin-bottom: 24px;">
    <div>
        <h2 style="margin: 0 0 4px 0; font-size: 24px; font-weight: 700;">My Rented Books</h2>
        <div class="text-muted" style="font-size: 13.5px;">
            Track your digital book subscriptions, active reading passes, and UPI payment receipts.
        </div>
    </div>
    <div>
        <a href="virtual-library.php" class="btn btn-primary">
            <i class="fa-solid fa-book-open"></i> Browse Virtual Library
        </a>
    </div>
</div>

<!-- Stats Overview -->
<div class="grid grid-4" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
            <i class="fa-solid fa-book-bookmark"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?= $statActive ?></div>
            <div class="stat-label">Active Rentals</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?= $statPending ?></div>
            <div class="stat-label">Pending Verification</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(99, 102, 241, 0.15); color: #6366f1;">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?= $statTotal ?></div>
            <div class="stat-label">Total Subscriptions</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(234, 88, 12, 0.15); color: #ea580c;">
            <i class="fa-solid fa-indian-rupee-sign"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value">₹<?= number_format($statSpent, 2) ?></div>
            <div class="stat-label">Total Amount Paid</div>
        </div>
    </div>
</div>

<!-- Filter Tabs -->
<div class="flex gap-2" style="margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 12px;">
    <a href="rentals.php?tab=all" class="btn <?= $tab === 'all' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 13px;">
        All Rentals (<?= $statTotal ?>)
    </a>
    <a href="rentals.php?tab=active" class="btn <?= $tab === 'active' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 13px;">
        <i class="fa-solid fa-circle-check text-green"></i> Active Rentals (<?= $statActive ?>)
    </a>
    <a href="rentals.php?tab=pending" class="btn <?= $tab === 'pending' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 13px;">
        <i class="fa-solid fa-hourglass-half text-yellow"></i> Pending Verification (<?= $statPending ?>)
    </a>
    <a href="rentals.php?tab=expired" class="btn <?= $tab === 'expired' ? 'btn-primary' : 'btn-outline' ?>" style="font-size: 13px;">
        <i class="fa-solid fa-clock-rotate-left"></i> Expired / Past
    </a>
</div>

<!-- Rentals List / Grid -->
<?php if (empty($rentals)): ?>
    <div class="card" style="text-align: center; padding: 48px 20px;">
        <div class="empty-state">
            <i class="fa-solid fa-receipt" style="font-size: 44px; margin-bottom: 12px; color: var(--text-muted);"></i>
            <h4 style="margin: 0 0 6px 0;">No rental subscriptions found</h4>
            <p class="text-muted" style="margin: 0 0 16px 0;">
                <?php if ($tab === 'active'): ?>
                    You don't have any active book rentals right now. Rent a digital book for ₹5/day to start reading.
                <?php elseif ($tab === 'pending'): ?>
                    No pending rental payments awaiting verification.
                <?php else: ?>
                    You have not rented any digital books yet. Choose from our digital library catalog.
                <?php endif; ?>
            </p>
            <a href="virtual-library.php" class="btn btn-primary">
                <i class="fa-solid fa-book-open"></i> Browse Virtual Library
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="grid grid-2" style="gap: 20px;">
        <?php foreach ($rentals as $r): 
            $isActive = ($r['payment_status'] === 'PAID' && $r['rental_status'] === 'active' && strtotime($r['expiry_date']) >= time());
            $isPending = ($r['payment_status'] === 'PENDING');
            $isExpired = ($r['rental_status'] === 'expired' || ($r['expiry_date'] && strtotime($r['expiry_date']) < time()));
            $isRejected = ($r['payment_status'] === 'REJECTED');

            $expTimestamp = $r['expiry_date'] ? strtotime($r['expiry_date']) : null;
            $daysLeft = $expTimestamp ? ceil(($expTimestamp - time()) / 86400) : 0;
        ?>
        <div class="card" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between; border-left: 4px solid <?= $isActive ? '#10b981' : ($isPending ? '#f59e0b' : ($isRejected ? '#ef4444' : 'var(--border)')) ?>;">
            <div>
                <!-- Top Row: Code & Status -->
                <div class="flex justify-between items-center" style="margin-bottom: 12px;">
                    <span style="font-family: monospace; font-size: 12.5px; font-weight: 700; color: var(--text-muted);">
                        <?= e($r['rental_code']) ?>
                    </span>
                    <div>
                        <?php if ($r['payment_status'] === 'PAID'): ?>
                            <span class="badge badge-green"><i class="fa-solid fa-circle-check"></i> PAID</span>
                        <?php elseif ($r['payment_status'] === 'PENDING'): ?>
                            <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.3);">
                                <i class="fa-solid fa-clock"></i> PENDING VERIFICATION
                            </span>
                        <?php elseif ($r['payment_status'] === 'REJECTED'): ?>
                            <span class="badge badge-red"><i class="fa-solid fa-circle-xmark"></i> REJECTED</span>
                        <?php else: ?>
                            <span class="badge badge-gray"><?= e($r['payment_status']) ?></span>
                        <?php endif; ?>

                        <?php if ($isActive): ?>
                            <span class="badge badge-green" style="margin-left: 4px;">ACTIVE</span>
                        <?php elseif ($isExpired): ?>
                            <span class="badge badge-gray" style="margin-left: 4px;">EXPIRED</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Book Details -->
                <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                    <div style="width: 70px; height: 95px; background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); border-radius: 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                        <i class="fa-solid fa-file-pdf" style="font-size: 26px; margin-bottom: 4px;"></i>
                        <span style="font-size: 9px; font-weight: 700;">PDF</span>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <span class="badge badge-blue" style="font-size: 11px; margin-bottom: 4px; display: inline-block;">
                            <?= e($r['category_name'] ?: 'Digital Resource') ?>
                        </span>
                        <h4 style="margin: 2px 0 4px 0; font-size: 16px; font-weight: 700; color: var(--text); line-height: 1.3;">
                            <?= e($r['title']) ?>
                        </h4>
                        <div class="text-muted" style="font-size: 13px;">
                            By <?= e($r['author_name']) ?> &middot; <?= (int)$r['pages'] ?> pages
                        </div>
                    </div>
                </div>

                <!-- Rental & Payment Ledger -->
                <div style="background: rgba(0,0,0,0.02); border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; font-size: 12.5px; margin-bottom: 16px;">
                    <div class="flex justify-between items-center" style="margin-bottom: 6px;">
                        <span class="text-muted">Rental Duration:</span>
                        <strong style="color: var(--text);"><?= (int)$r['rental_days'] ?> Days (₹<?= number_format($r['price_per_day'], 2) ?>/day)</strong>
                    </div>
                    <div class="flex justify-between items-center" style="margin-bottom: 6px;">
                        <span class="text-muted">Amount Paid:</span>
                        <strong style="color: #ea580c; font-size: 14px;">₹<?= number_format($r['total_amount'], 2) ?></strong>
                    </div>
                    <div class="flex justify-between items-center" style="margin-bottom: 6px;">
                        <span class="text-muted">Transaction Ref (UTR):</span>
                        <span style="font-family: monospace; font-weight: 600; color: var(--text);"><?= e($r['transaction_reference'] ?: 'N/A') ?></span>
                    </div>

                    <?php if ($r['start_date']): ?>
                        <div class="flex justify-between items-center" style="margin-bottom: 6px;">
                            <span class="text-muted">Rental Start Date:</span>
                            <span><?= date('d M Y, h:i A', strtotime($r['start_date'])) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($r['expiry_date']): ?>
                        <div class="flex justify-between items-center" style="margin-bottom: 6px;">
                            <span class="text-muted">Expiry Date:</span>
                            <strong style="color: <?= $isActive ? '#059669' : '#dc2626' ?>;">
                                <?= date('d M Y, h:i A', strtotime($r['expiry_date'])) ?>
                            </strong>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-muted">Remaining Time:</span>
                            <?php if ($isActive): ?>
                                <span class="badge badge-green" style="font-weight: 700;">
                                    <i class="fa-solid fa-clock"></i> <?= max(0, $daysLeft) ?> <?= $daysLeft === 1 ? 'day' : 'days' ?> remaining
                                </span>
                            <?php else: ?>
                                <span class="text-muted"><i class="fa-solid fa-ban"></i> Expired</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isRejected && !empty($r['rejection_reason'])): ?>
                        <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed rgba(239, 68, 68, 0.3); color: #dc2626;">
                            <strong>Rejection Note:</strong> <?= e($r['rejection_reason']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-2" style="border-top: 1px solid var(--border); padding-top: 14px;">
                <?php if ($isActive): ?>
                    <a href="read-book.php?id=<?= $r['digital_book_id'] ?>" class="btn btn-primary" style="flex: 1; justify-content: center;">
                        <i class="fa-solid fa-book-open"></i> Read Book
                    </a>
                    <a href="rent-book.php?id=<?= $r['digital_book_id'] ?>&renew=1" class="btn btn-outline" style="justify-content: center;" title="Extend rental duration">
                        <i class="fa-solid fa-arrows-rotate"></i> Renew
                    </a>
                <?php elseif ($isPending): ?>
                    <button class="btn btn-outline" disabled style="flex: 1; justify-content: center; opacity: 0.85; border-color: #f59e0b; color: #d97706;">
                        <i class="fa-solid fa-spinner fa-spin"></i> Awaiting Admin Verification
                    </button>
                <?php elseif ($isRejected): ?>
                    <a href="rent-book.php?id=<?= $r['digital_book_id'] ?>" class="btn btn-primary" style="flex: 1; justify-content: center; background: #dc2626; border-color: #b91c1c;">
                        <i class="fa-solid fa-arrow-rotate-left"></i> Submit New Payment
                    </a>
                <?php elseif ($isExpired): ?>
                    <a href="rent-book.php?id=<?= $r['digital_book_id'] ?>&renew=1" class="btn btn-primary" style="flex: 1; justify-content: center; background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); border: none;">
                        <i class="fa-solid fa-arrows-rotate"></i> Renew Rental (₹5/day)
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
