<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/reminder-service.php';
requireRole(['student']);
$pageTitle = 'Notifications & Reminders';
$currentPage = 'notifications.php';

// Mark in-app notifications as read
$pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$_SESSION['user_id']]);

// Fetch student profile details
$stuStmt = $pdo->prepare("
    SELECT s.id AS student_id, s.roll_number, u.name, u.phone, u.email
    FROM students s
    JOIN users u ON u.id = s.user_id
    WHERE s.user_id = ?
");
$stuStmt->execute([$_SESSION['user_id']]);
$studentInfo = $stuStmt->fetch();

// Fetch In-App notifications
$notifications = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 50");
$notifications->execute([$_SESSION['user_id']]);
$notifications = $notifications->fetchAll();

// Fetch Multi-Channel Reminders Log for this student
$reminders = $pdo->prepare("
    SELECT * FROM reminders_log
    WHERE user_id = ?
    ORDER BY id DESC LIMIT 50
");
$reminders->execute([$_SESSION['user_id']]);
$remindersLog = $reminders->fetchAll();

$tab = $_GET['tab'] ?? 'all';

$icons = [
    'Book Issued' => 'fa-right-from-bracket',
    'Book Returned' => 'fa-right-to-bracket',
    'Due Soon' => 'fa-clock',
    'Due Today Reminder (Within 6 Hours)' => 'fa-clock',
    'Due Today Reminder (Within 6h)' => 'fa-clock',
    'Overdue Book & Fine Notice' => 'fa-triangle-exclamation',
    'Reservation Ready' => 'fa-bookmark',
    'Reserved Book Ready for Pickup' => 'fa-bookmark',
    'Payment Completed' => 'fa-credit-card',
];

include __DIR__ . '/../includes/header.php';
?>

<!-- Registered Details Banner -->
<div class="card" style="margin-bottom:18px; background:linear-gradient(135deg, #1e293b 0%, #334155 100%); color:#fff; border:none;">
    <div class="flex justify-between items-center" style="flex-wrap:wrap; gap:14px;">
        <div>
            <div style="font-size:12px; text-transform:uppercase; letter-spacing:0.5px; opacity:0.8;">Registered Notification Destination</div>
            <h3 style="margin:4px 0 6px 0; color:#fff; font-size:18px;">
                <i class="fa-solid fa-address-card" style="color:#38bdf8;"></i> <?= e($studentInfo['name'] ?? $_SESSION['name']) ?> 
                <span class="badge badge-blue" style="font-size:12px; vertical-align:middle; margin-left:6px;"><?= e($studentInfo['roll_number'] ?? 'N/A') ?></span>
            </h3>
            <div class="flex gap-3" style="flex-wrap:wrap; font-size:13px; opacity:0.9;">
                <div><i class="fa-solid fa-mobile-screen" style="color:#4ade80;"></i> Registered Mobile: <strong><?= e($studentInfo['phone'] ?: 'Not registered') ?></strong></div>
                <div><i class="fa-solid fa-envelope" style="color:#60a5fa;"></i> Registered Email: <strong><?= e($studentInfo['email'] ?: 'Not registered') ?></strong></div>
            </div>
        </div>
        <div class="flex gap-2" style="flex-wrap:wrap;">
            <span class="badge" style="background:#22c55e; color:#fff; padding:6px 12px; font-size:12px;">
                <i class="fa-brands fa-whatsapp"></i> WhatsApp Alerts Active
            </span>
            <span class="badge" style="background:#3b82f6; color:#fff; padding:6px 12px; font-size:12px;">
                <i class="fa-solid fa-comment-sms"></i> SMS Active
            </span>
            <span class="badge" style="background:#8b5cf6; color:#fff; padding:6px 12px; font-size:12px;">
                <i class="fa-solid fa-envelope"></i> Email Active
            </span>
        </div>
    </div>
</div>

<!-- Navigation Tabs -->
<div class="flex gap-2" style="margin-bottom:16px;">
    <a href="notifications.php?tab=all" class="btn <?= $tab === 'all' ? 'btn-primary' : 'btn-outline' ?>">
        <i class="fa-solid fa-bell"></i> In-App Alerts (<?= count($notifications) ?>)
    </a>
    <a href="notifications.php?tab=channels" class="btn <?= $tab === 'channels' ? 'btn-primary' : 'btn-outline' ?>">
        <i class="fa-solid fa-paper-plane"></i> WhatsApp / SMS / Email Reminders (<?= count($remindersLog) ?>)
    </a>
</div>

<?php if ($tab === 'all'): ?>
    <div class="card">
        <h3 style="margin-bottom:16px;"><i class="fa-solid fa-bell text-primary"></i> In-App Notifications</h3>
        <?php if (!$notifications): ?>
            <div class="empty-state"><i class="fa-solid fa-bell-slash"></i>No notifications yet.</div>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
                <div style="display:flex; gap:14px; padding:14px 0; border-bottom:1px solid var(--border);">
                    <div style="width:38px; height:38px; border-radius:50%; background:var(--primary-light); color:var(--primary); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fa-solid <?= $icons[$n['title']] ?? 'fa-bell' ?>"></i>
                    </div>
                    <div style="flex:1;">
                        <div class="flex justify-between items-center">
                            <strong style="font-size:14.5px;"><?= e($n['title']) ?></strong>
                            <span class="text-muted" style="font-size:12px;"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></span>
                        </div>
                        <div style="font-size:13.5px; margin-top:4px; color:var(--text); line-height:1.4;"><?= e($n['message']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($tab === 'channels'): ?>
    <div class="card">
        <div class="flex justify-between items-center" style="margin-bottom:16px;">
            <h3 style="margin:0;"><i class="fa-solid fa-paper-plane text-primary"></i> Dispatched Reminders Log</h3>
            <span class="text-muted" style="font-size:12.5px;">Reminders targeted to your registered mobile and email</span>
        </div>

        <?php if (!$remindersLog): ?>
            <div class="empty-state">
                <i class="fa-solid fa-inbox"></i>
                <p>No multi-channel reminders have been dispatched to your number yet.</p>
            </div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:12px;">
                <?php foreach ($remindersLog as $rem): 
                    $waUrl = buildWhatsAppUrl($rem['phone'], $rem['message']);
                    $smsUrl = buildSmsUrl($rem['phone'], $rem['message']);
                ?>
                    <div style="border:1px solid var(--border); border-radius:8px; padding:14px 16px; background:#fafafa;">
                        <div class="flex justify-between items-center" style="flex-wrap:wrap; gap:8px; margin-bottom:8px;">
                            <div class="flex items-center gap-2">
                                <span class="badge <?= $rem['type'] === 'overdue_fine' ? 'badge-red' : ($rem['type'] === 'due_soon_6h' ? 'badge-yellow' : 'badge-green') ?>">
                                    <?= e($rem['title']) ?>
                                </span>
                                <?php if ($rem['channel'] === 'whatsapp'): ?>
                                    <span class="badge" style="background:#dcfce7; color:#15803d;"><i class="fa-brands fa-whatsapp"></i> WhatsApp</span>
                                <?php elseif ($rem['channel'] === 'sms'): ?>
                                    <span class="badge badge-blue"><i class="fa-solid fa-comment-sms"></i> SMS</span>
                                <?php elseif ($rem['channel'] === 'email'): ?>
                                    <span class="badge" style="background:#e0e7ff; color:#4338ca;"><i class="fa-solid fa-envelope"></i> Email</span>
                                <?php else: ?>
                                    <span class="badge badge-green"><i class="fa-solid fa-paper-plane"></i> Multi-Channel</span>
                                <?php endif; ?>
                            </div>
                            <span class="text-muted" style="font-size:12px;">Dispatched: <?= date('d M Y, h:i A', strtotime($rem['dispatched_at'])) ?></span>
                        </div>
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; font-size:13px; line-height:1.45; white-space:pre-wrap; margin-bottom:10px;"><?= e($rem['message']) ?></div>
                        <div class="flex justify-between items-center" style="flex-wrap:wrap; gap:8px;">
                            <div class="text-muted" style="font-size:12px;">
                                Sent to Phone: <strong><?= e($rem['phone'] ?: 'N/A') ?></strong> | Email: <strong><?= e($rem['email'] ?: 'N/A') ?></strong>
                            </div>
                            <div class="flex gap-2">
                                <?php if (!empty($rem['phone'])): ?>
                                    <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:#fff; border-color:#22c55e;">
                                        <i class="fa-brands fa-whatsapp"></i> WhatsApp
                                    </a>
                                    <a href="<?= e($smsUrl) ?>" class="btn btn-sm" style="background:#3b82f6; color:#fff; border-color:#2563eb;">
                                        <i class="fa-solid fa-comment-sms"></i> SMS
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
