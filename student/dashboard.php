<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/reminder-service.php';
requireRole(['student']);
$pageTitle = 'My Dashboard';
$currentPage = 'dashboard.php';
$settings = getSettings($pdo);

$stu = $pdo->prepare("SELECT * FROM students WHERE user_id=?");
$stu->execute([$_SESSION['user_id']]);
$student = $stu->fetch();
$studentId = $student['id'];
$myAlerts = scanPendingLibraryAlerts($pdo, $studentId);

// Physical Library Stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM book_issues WHERE student_id=? AND status IN ('issued','overdue')");
$stmt->execute([$studentId]); $activeBooks = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM book_issues WHERE student_id=?");
$stmt->execute([$studentId]); $totalBorrowed = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fines WHERE student_id=? AND status='pending'");
$stmt->execute([$studentId]); $pendingFines = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE student_id=? AND status IN ('pending','approved','ready')");
$stmt->execute([$studentId]); $activeReservations = (int)$stmt->fetchColumn();

// Virtual Library Stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM reading_history WHERE student_id=?");
$stmt->execute([$studentId]); $digitalReadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT AVG(progress_percent) FROM reading_history WHERE student_id=?");
$stmt->execute([$studentId]); $avgProgress = round((float)$stmt->fetchColumn());

// Continue Reading (Active sessions)
$crStmt = $pdo->prepare("
    SELECT rh.*, db.title, db.pages, c.name AS category_name, COALESCE(a.name, db.author_name) AS author_name
    FROM reading_history rh
    JOIN digital_books db ON db.id = rh.digital_book_id
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN authors a ON a.id = db.author_id
    WHERE rh.student_id = ? AND rh.reading_status = 'reading' AND db.status = 'active'
    ORDER BY rh.last_accessed_at DESC LIMIT 2
");
$crStmt->execute([$studentId]);
$continueReading = $crStmt->fetchAll();

// Popular Digital Books
$popDigital = $pdo->query("
    SELECT db.*, c.name AS category_name, COALESCE(a.name, db.author_name) AS author_name
    FROM digital_books db
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN authors a ON a.id = db.author_id
    WHERE db.status = 'active'
    ORDER BY db.read_count DESC, db.view_count DESC LIMIT 3
")->fetchAll();

// Reading statistics: favourite category across physical & digital
$stmt = $pdo->prepare("
    SELECT c.name, COUNT(*) c FROM book_issues bi
    JOIN books b ON b.id=bi.book_id LEFT JOIN categories c ON c.id=b.category_id
    WHERE bi.student_id=? GROUP BY c.id ORDER BY c DESC LIMIT 1");
$stmt->execute([$studentId]);
$favCategory = $stmt->fetch();

// Recommendations based on favourite category
$recommendations = [];
if ($favCategory) {
    $stmt = $pdo->prepare("
        SELECT b.* FROM books b
        JOIN categories c ON c.id = b.category_id
        WHERE c.name = ? AND b.available_copies > 0
        AND b.id NOT IN (SELECT book_id FROM book_issues WHERE student_id = ?)
        ORDER BY RAND() LIMIT 3");
    $stmt->execute([$favCategory['name'], $studentId]);
    $recommendations = $stmt->fetchAll();
}
if (!$recommendations) {
    $recommendations = $pdo->query("SELECT * FROM books WHERE available_copies > 0 ORDER BY RAND() LIMIT 3")->fetchAll();
}

$myIssued = $pdo->prepare("
    SELECT bi.*, b.title FROM book_issues bi JOIN books b ON b.id=bi.book_id
    WHERE bi.student_id=? AND bi.status IN ('issued','overdue') ORDER BY bi.due_date ASC LIMIT 5");
$myIssued->execute([$studentId]);
$myIssued = $myIssued->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- ================== URGENT REMINDERS & ALERTS ================== -->
<?php if (!empty($myAlerts['due_soon_6h'])): ?>
    <div style="background:#fffbeb; border:1px solid #fde68a; border-left:5px solid #f59e0b; border-radius:10px; padding:16px 20px; margin-bottom:20px; box-shadow:0 2px 8px rgba(245, 158, 11, 0.08);">
        <div class="flex items-center justify-between" style="flex-wrap:wrap; gap:10px; margin-bottom:10px;">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-clock text-warning" style="font-size:22px;"></i>
                <h3 style="margin:0; font-size:16px; color:#b45309;">⚠️ URGENT REMINDER: Book Due Today (Within 6 Hours)!</h3>
            </div>
            <span class="badge badge-yellow" style="font-size:12px;"><i class="fa-solid fa-id-card"></i> Reg No: <?= e($student['roll_number']) ?></span>
        </div>
        <p style="margin:0 0 12px 0; font-size:13.5px; color:#78350f;">
            An automated return reminder has been queued for your registered mobile and email. Please return or renew your borrowed book before closing hours to prevent overdue fines.
        </p>
        <div style="display:flex; flex-direction:column; gap:8px;">
            <?php foreach ($myAlerts['due_soon_6h'] as $item): 
                $msg = getReminderText('due_soon_6h', $item['student_name'], $item['roll_number'], $item['book_title'], [
                    'library_name' => $settings['library_name'],
                    'due_date' => fmtDate($item['due_date'])
                ]);
                $waUrl = buildWhatsAppUrl($item['phone'], $msg);
                $smsUrl = buildSmsUrl($item['phone'], $msg);
            ?>
                <div style="background:#fff; border:1px solid #fef3c7; border-radius:6px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div>
                        <strong style="color:#1e293b; font-size:14px;"><?= e($item['book_title']) ?></strong>
                        <div class="text-muted" style="font-size:12px; margin-top:2px;">
                            Due: <strong style="color:#b45309;">TODAY (Less than 6h)</strong> &bull; Register No: <strong><?= e($item['roll_number']) ?></strong> &bull; Registered Mobile: <strong><?= e($item['phone'] ?: 'None') ?></strong>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <?php if (!empty($item['phone'])): ?>
                            <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:#fff; border-color:#22c55e;" title="Open WhatsApp Reminder">
                                <i class="fa-brands fa-whatsapp"></i> WhatsApp Reminder
                            </a>
                            <a href="<?= e($smsUrl) ?>" class="btn btn-sm" style="background:#3b82f6; color:#fff; border-color:#2563eb;" title="Open SMS Reminder">
                                <i class="fa-solid fa-comment-sms"></i> SMS
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($myAlerts['overdue_fines'])): ?>
    <div style="background:#fef2f2; border:1px solid #fecaca; border-left:5px solid #ef4444; border-radius:10px; padding:16px 20px; margin-bottom:20px; box-shadow:0 2px 8px rgba(239, 68, 68, 0.08);">
        <div class="flex items-center justify-between" style="flex-wrap:wrap; gap:10px; margin-bottom:10px;">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation text-danger" style="font-size:22px;"></i>
                <h3 style="margin:0; font-size:16px; color:#b91c1c;">🚨 OVERDUE BOOK & FINE NOTICE</h3>
            </div>
            <span class="badge badge-red" style="font-size:12px;"><i class="fa-solid fa-id-card"></i> Reg No: <?= e($student['roll_number']) ?></span>
        </div>
        <p style="margin:0 0 12px 0; font-size:13.5px; color:#7f1d1d;">
            You have physical books that are overdue. Late return charges are actively accumulating at ₹<?= number_format((float)$settings['fine_per_day'], 2) ?>/day. Multi-channel notices have been sent to your registered contact.
        </p>
        <div style="display:flex; flex-direction:column; gap:8px;">
            <?php foreach ($myAlerts['overdue_fines'] as $item): 
                $msg = getReminderText('overdue_fine', $item['student_name'], $item['roll_number'], $item['book_title'], [
                    'library_name' => $settings['library_name'],
                    'late_days' => $item['late_days'],
                    'fine_amount' => $item['fine_amount']
                ]);
                $waUrl = buildWhatsAppUrl($item['phone'], $msg);
                $smsUrl = buildSmsUrl($item['phone'], $msg);
            ?>
                <div style="background:#fff; border:1px solid #fee2e2; border-radius:6px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div>
                        <strong style="color:#1e293b; font-size:14px;"><?= e($item['book_title']) ?></strong>
                        <div style="font-size:12px; margin-top:2px;">
                            <span class="badge badge-red"><?= (int)$item['late_days'] ?> Days Late</span>
                            <span style="color:#b91c1c; font-weight:700; margin-left:8px;">Accrued Fine: ₹<?= number_format((float)$item['fine_amount'], 2) ?></span>
                            <span class="text-muted" style="margin-left:8px;">&bull; Reg No: <?= e($item['roll_number']) ?></span>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <?php if (!empty($item['phone'])): ?>
                            <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:#fff; border-color:#22c55e;" title="Open WhatsApp Notice">
                                <i class="fa-brands fa-whatsapp"></i> WhatsApp Notice
                            </a>
                            <a href="<?= e($smsUrl) ?>" class="btn btn-sm" style="background:#3b82f6; color:#fff; border-color:#2563eb;" title="Open SMS Notice">
                                <i class="fa-solid fa-comment-sms"></i> SMS
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($myAlerts['ready_reservations'])): ?>
    <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-left:5px solid #10b981; border-radius:10px; padding:16px 20px; margin-bottom:20px; box-shadow:0 2px 8px rgba(16, 185, 129, 0.08);">
        <div class="flex items-center justify-between" style="flex-wrap:wrap; gap:10px; margin-bottom:10px;">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-bookmark text-success" style="font-size:22px;"></i>
                <h3 style="margin:0; font-size:16px; color:#15803d;">🎉 Reserved Book is Ready for Pickup!</h3>
            </div>
            <span class="badge badge-green" style="font-size:12px;"><i class="fa-solid fa-id-card"></i> Reg No: <?= e($student['roll_number']) ?></span>
        </div>
        <p style="margin:0 0 12px 0; font-size:13.5px; color:#14532d;">
            Good news! A physical copy of your reserved book has become available at the Library Reception counter. Please collect it before the expiry deadline.
        </p>
        <div style="display:flex; flex-direction:column; gap:8px;">
            <?php foreach ($myAlerts['ready_reservations'] as $item): 
                $msg = getReminderText('reservation_ready', $item['student_name'], $item['roll_number'], $item['book_title'], [
                    'library_name' => $settings['library_name'],
                    'expiry_date' => fmtDate($item['expiry_date'])
                ]);
                $waUrl = buildWhatsAppUrl($item['phone'], $msg);
                $smsUrl = buildSmsUrl($item['phone'], $msg);
            ?>
                <div style="background:#fff; border:1px solid #dcfce7; border-radius:6px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div>
                        <strong style="color:#1e293b; font-size:14px;"><?= e($item['book_title']) ?></strong>
                        <div class="text-muted" style="font-size:12px; margin-top:2px;">
                            Available at reception &bull; Pickup Expiry: <strong style="color:#15803d;"><?= fmtDate($item['expiry_date']) ?></strong>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <?php if (!empty($item['phone'])): ?>
                            <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:#fff; border-color:#22c55e;">
                                <i class="fa-brands fa-whatsapp"></i> Pickup Alert
                            </a>
                            <a href="<?= e($smsUrl) ?>" class="btn btn-sm" style="background:#3b82f6; color:#fff; border-color:#2563eb;">
                                <i class="fa-solid fa-comment-sms"></i> SMS
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Physical & Virtual Unified Stat Cards -->
<div class="grid grid-4">
    <div class="card stat-card"><div class="icon blue"><i class="fa-solid fa-book"></i></div><div><div class="num"><?= $activeBooks ?></div><div class="label">Physical Books with Me</div></div></div>
    <div class="card stat-card"><div class="icon green"><i class="fa-solid fa-book-open-reader"></i></div><div><div class="num"><?= $totalBorrowed ?></div><div class="label">Total Physical Borrowed</div></div></div>
    <div class="card stat-card"><div class="icon yellow"><i class="fa-solid fa-laptop-code"></i></div><div><div class="num"><?= $digitalReadCount ?></div><div class="label">Digital E-Books Read</div></div></div>
    <div class="card stat-card"><div class="icon red"><i class="fa-solid fa-coins"></i></div><div><div class="num">&#8377;<?= number_format($pendingFines,2) ?></div><div class="label">Pending Fines</div></div></div>
</div>

<!-- Continue Reading Banner (If In Progress) -->
<?php if (!empty($continueReading)): ?>
<div class="card" style="margin-top:18px; border-left:4px solid var(--primary); background:linear-gradient(to right, #f8fafc, #ffffff);">
    <div class="flex justify-between items-center" style="margin-bottom:12px;">
        <h3 style="margin:0; font-size:16px;"><i class="fa-solid fa-clock-rotate-left text-primary"></i> Continue Reading</h3>
        <a href="virtual-library.php" style="font-size:13px; font-weight:600;">Go to Virtual Library &rarr;</a>
    </div>
    <div class="grid grid-2">
        <?php foreach ($continueReading as $cr): ?>
            <div style="background:#fff; border:1px solid var(--border); border-radius:8px; padding:12px; display:flex; justify-content:space-between; align-items:center;">
                <div style="flex:1; min-width:0; margin-right:12px;">
                    <span class="badge badge-blue" style="font-size:10.5px;"><?= e($cr['category_name']) ?></span>
                    <strong style="display:block; font-size:14px; margin:4px 0 2px 0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= e($cr['title']) ?></strong>
                    <div class="text-muted" style="font-size:12px; margin-bottom:8px;">Page <?= (int)$cr['last_page'] ?> of <?= (int)$cr['pages'] ?> (<?= round((float)$cr['progress_percent']) ?>%)</div>
                    <div style="background:var(--border); border-radius:6px; height:5px; overflow:hidden; width:100%;">
                        <div style="background:var(--primary); width:<?= (float)$cr['progress_percent'] ?>%; height:100%;"></div>
                    </div>
                </div>
                <a href="read-book.php?id=<?= $cr['digital_book_id'] ?>" class="btn btn-sm btn-primary">
                    <i class="fa-solid fa-book-open"></i> Resume
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-2" style="margin-top:18px;">
    <!-- Issued Physical Books -->
    <div class="card">
        <div class="flex justify-between items-center" style="margin-bottom:12px;">
            <h3 style="margin:0;">My Current Physical Books</h3>
            <a href="issued-books.php" style="font-size:12.5px;">All Issued &rarr;</a>
        </div>
        <?php if (!$myIssued): ?>
            <div class="empty-state"><i class="fa-solid fa-book"></i>You don't have any books issued right now.<br><a href="books.php">Browse the catalog &rarr;</a></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Book</th><th>Due Date</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($myIssued as $r):
                $isDueToday = ($r['due_date'] === date('Y-m-d'));
                $displayStatus = strtotime($r['due_date']) < strtotime(date('Y-m-d')) ? 'overdue' : 'issued';
            ?>
                <tr>
                    <td><?= e($r['title']) ?></td>
                    <td>
                        <?= fmtDate($r['due_date']) ?>
                        <?php if ($isDueToday): ?>
                            <span class="badge badge-yellow" style="font-size:11px; margin-left:4px;"><i class="fa-solid fa-clock"></i> Due in < 6h</span>
                        <?php endif; ?>
                    </td>
                    <td><?= statusBadge($displayStatus) ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <!-- Popular Virtual Library E-Books -->
    <div class="card">
        <div class="flex justify-between items-center" style="margin-bottom:12px;">
            <h3 style="margin:0;">Popular in Virtual Library</h3>
            <a href="virtual-library.php" style="font-size:12.5px;">Explore All &rarr;</a>
        </div>
        <?php foreach ($popDigital as $dbk): ?>
            <div style="padding:10px 0; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                <div style="flex:1; min-width:0; margin-right:10px;">
                    <strong style="font-size:13.5px; display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= e($dbk['title']) ?></strong>
                    <div class="text-muted" style="font-size:12px;"><?= e($dbk['author_name']) ?> &middot; <span class="badge badge-blue" style="font-size:10px;"><?= e($dbk['category_name']) ?></span></div>
                </div>
                <a href="read-book.php?id=<?= $dbk['id'] ?>" class="btn btn-sm btn-outline" style="flex-shrink:0;">
                    <i class="fa-solid fa-book-open"></i> Read
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card" style="margin-top:18px;">
    <h3>Recommended Physical Books</h3>
    <?php if ($favCategory): ?><p class="text-muted" style="margin-top:-8px; font-size:13px;">Based on your interest in <?= e($favCategory['name']) ?></p><?php endif; ?>
    <?php if (!$recommendations): ?>
        <div class="empty-state"><i class="fa-solid fa-wand-magic-sparkles"></i>No recommendations available yet.</div>
    <?php else: ?>
    <div class="grid grid-3" style="margin-top:10px;">
        <?php foreach ($recommendations as $b): ?>
            <div style="padding:12px; border:1px solid var(--border); border-radius:8px; background:var(--bg);">
                <strong style="display:block; font-size:14px; margin-bottom:4px;"><?= e($b['title']) ?></strong>
                <div class="text-muted" style="font-size:12.5px; margin-bottom:10px;">Shelf <?= e($b['shelf_number']) ?> &middot; <?= (int)$b['available_copies'] ?> copies available</div>
                <a href="books.php?q=<?= urlencode($b['title']) ?>" class="btn btn-sm btn-outline" style="width:100%; justify-content:center;">View in Catalog</a>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
