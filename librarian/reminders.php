<?php
/**
 * Librarian Reminders & Alerts Management Portal
 * Send Email, SMS, and WhatsApp alerts for 6-hour due dates, overdue fines, and available reservations on register numbers.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/reminder-service.php';

requireRole(['librarian', 'admin']);
$pageTitle = 'Reminders & Alerts';
$currentPage = 'reminders.php';
$settings = getSettings($pdo);

// Handle manual or bulk dispatch
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'dispatch_single') {
        $studentId = (int)$_POST['student_id'];
        $userId = (int)$_POST['user_id'];
        $rollNumber = trim($_POST['roll_number']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $type = $_POST['type'];
        $channel = $_POST['channel'];
        $bookTitle = trim($_POST['book_title']);

        $message = getReminderText($type, $_POST['student_name'], $rollNumber, $bookTitle, [
            'library_name' => $settings['library_name'],
            'due_date' => $_POST['due_date'] ?? date('d M Y'),
            'late_days' => $_POST['late_days'] ?? 1,
            'fine_amount' => $_POST['fine_amount'] ?? 0,
            'expiry_date' => $_POST['expiry_date'] ?? '3 days'
        ]);

        $title = match($type) {
            'due_soon_6h' => 'Due Today Reminder (Within 6 Hours)',
            'overdue_fine' => 'Overdue Book & Fine Notice',
            'reservation_ready' => 'Reserved Book Ready for Pickup',
            default => 'Library Reminder'
        };

        recordDispatchedReminder($pdo, [
            'student_id' => $studentId,
            'user_id' => $userId,
            'roll_number' => $rollNumber,
            'phone' => $phone,
            'email' => $email,
            'type' => $type,
            'channel' => $channel,
            'title' => $title,
            'message' => $message
        ]);

        setFlash('success', "Reminder successfully logged and dispatched via {$channel} to {$rollNumber} ({$phone}).");
        redirect('reminders.php');
    }

    if ($action === 'dispatch_all_due') {
        $alerts = scanPendingLibraryAlerts($pdo);
        $count = 0;
        foreach ($alerts['due_soon_6h'] as $item) {
            $msg = getReminderText('due_soon_6h', $item['student_name'], $item['roll_number'], $item['book_title'], [
                'library_name' => $settings['library_name'],
                'due_date' => fmtDate($item['due_date'])
            ]);
            recordDispatchedReminder($pdo, [
                'student_id' => $item['student_id'],
                'user_id' => $item['user_id'],
                'roll_number' => $item['roll_number'],
                'phone' => $item['phone'],
                'email' => $item['email'],
                'type' => 'due_soon_6h',
                'channel' => 'all',
                'title' => 'Due Today Reminder (Within 6h)',
                'message' => $msg
            ]);
            $count++;
        }
        setFlash('success', "Dispatched multi-channel 6-hour due alerts to {$count} student(s).");
        redirect('reminders.php');
    }
}

$alerts = scanPendingLibraryAlerts($pdo);
$dueSoon = $alerts['due_soon_6h'];
$overdue = $alerts['overdue_fines'];
$readyReservations = $alerts['ready_reservations'];

// Fetch recent dispatch logs
$logs = $pdo->query("
    SELECT r.*, u.name AS student_name
    FROM reminders_log r
    JOIN users u ON u.id = r.user_id
    ORDER BY r.id DESC LIMIT 50
")->fetchAll();

$activeTab = $_GET['tab'] ?? 'due_soon';

include __DIR__ . '/../includes/header.php';
?>

<!-- Reminder Overview Cards -->
<div class="grid grid-3" style="margin-bottom:20px;">
    <div class="card stat-card" style="border-left:4px solid #f59e0b;">
        <div class="icon yellow"><i class="fa-solid fa-hourglass-half"></i></div>
        <div>
            <div class="num"><?= count($dueSoon) ?></div>
            <div class="label">Due Within 6 Hours / Today</div>
        </div>
    </div>
    <div class="card stat-card" style="border-left:4px solid #ef4444;">
        <div class="icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div>
            <div class="num"><?= count($overdue) ?></div>
            <div class="label">Overdue Books (Fines Accruing)</div>
        </div>
    </div>
    <div class="card stat-card" style="border-left:4px solid #10b981;">
        <div class="icon green"><i class="fa-solid fa-box-open"></i></div>
        <div>
            <div class="num"><?= count($readyReservations) ?></div>
            <div class="label">Reservations Ready for Pickup</div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<div class="flex justify-between items-center" style="margin-bottom:16px; flex-wrap:wrap; gap:10px;">
    <div class="flex gap-2">
        <a href="reminders.php?tab=due_soon" class="btn <?= $activeTab === 'due_soon' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-clock"></i> Due Today / 6h (<?= count($dueSoon) ?>)
        </a>
        <a href="reminders.php?tab=overdue" class="btn <?= $activeTab === 'overdue' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-triangle-exclamation"></i> Overdue & Fines (<?= count($overdue) ?>)
        </a>
        <a href="reminders.php?tab=reservations" class="btn <?= $activeTab === 'reservations' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-bookmark"></i> Ready Reservations (<?= count($readyReservations) ?>)
        </a>
        <a href="reminders.php?tab=logs" class="btn <?= $activeTab === 'logs' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-list-check"></i> Dispatch Logs (<?= count($logs) ?>)
        </a>
    </div>

    <?php if ($activeTab === 'due_soon' && !empty($dueSoon)): ?>
        <form method="POST" style="margin:0;" onsubmit="return confirm('Send automated WhatsApp, SMS, and Email due reminders to all eligible students?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="dispatch_all_due">
            <button type="submit" class="btn btn-primary" style="background:#10b981; border-color:#059669;">
                <i class="fa-solid fa-paper-plane"></i> Dispatch All 6h Due Reminders
            </button>
        </form>
    <?php endif; ?>
</div>

<!-- TAB 1: Due Within 6 Hours -->
<?php if ($activeTab === 'due_soon'): ?>
<div class="card">
    <div class="flex justify-between items-center" style="margin-bottom:12px;">
        <h3 style="margin:0;"><i class="fa-solid fa-clock text-warning"></i> Books Due Today / In Less Than 6 Hours</h3>
        <span class="text-muted" style="font-size:13px;">Reminders targeted to registered mobile and email</span>
    </div>

    <?php if (empty($dueSoon)): ?>
        <div class="empty-state" style="padding:40px 20px;">
            <i class="fa-solid fa-circle-check" style="color:#10b981; font-size:36px; margin-bottom:10px;"></i>
            <h4>No checkouts due in the next 6 hours</h4>
            <p class="text-muted">All current physical book borrowings are within safe return dates.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Reg / Roll No</th>
                        <th>Registered Phone</th>
                        <th>Book Borrowed</th>
                        <th>Due Deadline</th>
                        <th>Multi-Channel Reminders</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dueSoon as $row): 
                        $msg = getReminderText('due_soon_6h', $row['student_name'], $row['roll_number'], $row['book_title'], [
                            'library_name' => $settings['library_name'],
                            'due_date' => fmtDate($row['due_date'])
                        ]);
                        $waUrl = buildWhatsAppUrl($row['phone'], $msg);
                        $smsUrl = buildSmsUrl($row['phone'], $msg);
                        $mailUrl = buildMailtoUrl($row['email'], "Urgent: Book Due Today - " . $row['book_title'], $msg);
                    ?>
                        <tr>
                            <td><strong><?= e($row['student_name']) ?></strong></td>
                            <td><span class="badge badge-blue"><?= e($row['roll_number']) ?></span></td>
                            <td>
                                <div><i class="fa-solid fa-phone text-muted" style="font-size:11px;"></i> <?= e($row['phone'] ?: 'No phone recorded') ?></div>
                                <div class="text-muted" style="font-size:11px;"><i class="fa-solid fa-envelope"></i> <?= e($row['email']) ?></div>
                            </td>
                            <td><strong><?= e($row['book_title']) ?></strong></td>
                            <td>
                                <span class="badge badge-yellow"><i class="fa-solid fa-clock"></i> Today (Due in < 6h)</span>
                            </td>
                            <td>
                                <div class="flex gap-1">
                                    <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:#fff; border-color:#22c55e;" title="Send WhatsApp Message">
                                        <i class="fa-brands fa-whatsapp"></i> WhatsApp
                                    </a>
                                    <a href="<?= e($smsUrl) ?>" class="btn btn-sm" style="background:#3b82f6; color:#fff; border-color:#2563eb;" title="Send SMS">
                                        <i class="fa-solid fa-comment-sms"></i> SMS
                                    </a>
                                    <a href="<?= e($mailUrl) ?>" class="btn btn-sm btn-outline" title="Send Email">
                                        <i class="fa-solid fa-envelope"></i> Email
                                    </a>
                                    <form method="POST" style="margin:0;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="dispatch_single">
                                        <input type="hidden" name="student_id" value="<?= $row['student_id'] ?>">
                                        <input type="hidden" name="user_id" value="<?= $row['user_id'] ?>">
                                        <input type="hidden" name="student_name" value="<?= e($row['student_name']) ?>">
                                        <input type="hidden" name="roll_number" value="<?= e($row['roll_number']) ?>">
                                        <input type="hidden" name="phone" value="<?= e($row['phone']) ?>">
                                        <input type="hidden" name="email" value="<?= e($row['email']) ?>">
                                        <input type="hidden" name="book_title" value="<?= e($row['book_title']) ?>">
                                        <input type="hidden" name="due_date" value="<?= e($row['due_date']) ?>">
                                        <input type="hidden" name="type" value="due_soon_6h">
                                        <input type="hidden" name="channel" value="all">
                                        <button type="submit" class="btn btn-sm btn-outline" title="Log & Dispatch In-App Alert">
                                            <i class="fa-solid fa-paper-plane"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- TAB 2: Overdue Books & Fines -->
<?php if ($activeTab === 'overdue'): ?>
<div class="card">
    <div class="flex justify-between items-center" style="margin-bottom:12px;">
        <h3 style="margin:0;"><i class="fa-solid fa-triangle-exclamation text-danger"></i> Overdue Borrowings & Fines Notice</h3>
        <span class="text-muted" style="font-size:13px;">Late fees automatically calculated at ₹<?= number_format($settings['fine_per_day'], 2) ?>/day</span>
    </div>

    <?php if (empty($overdue)): ?>
        <div class="empty-state" style="padding:40px 20px;">
            <i class="fa-solid fa-circle-check" style="color:#10b981; font-size:36px; margin-bottom:10px;"></i>
            <h4>No overdue borrowings</h4>
            <p class="text-muted">All books have been returned on schedule.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Reg / Roll No</th>
                        <th>Registered Phone</th>
                        <th>Overdue Book</th>
                        <th>Late Days</th>
                        <th>Fine Accrued</th>
                        <th>Send Overdue Notice</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overdue as $row): 
                        $msg = getReminderText('overdue_fine', $row['student_name'], $row['roll_number'], $row['book_title'], [
                            'library_name' => $settings['library_name'],
                            'late_days' => $row['late_days'],
                            'fine_amount' => $row['fine_amount']
                        ]);
                        $waUrl = buildWhatsAppUrl($row['phone'], $msg);
                        $smsUrl = buildSmsUrl($row['phone'], $msg);
                        $mailUrl = buildMailtoUrl($row['email'], "Urgent: Overdue Book Fine Notice - " . $row['book_title'], $msg);
                    ?>
                        <tr>
                            <td><strong><?= e($row['student_name']) ?></strong></td>
                            <td><span class="badge badge-blue"><?= e($row['roll_number']) ?></span></td>
                            <td>
                                <div><i class="fa-solid fa-phone text-muted" style="font-size:11px;"></i> <?= e($row['phone'] ?: 'N/A') ?></div>
                                <div class="text-muted" style="font-size:11px;"><i class="fa-solid fa-envelope"></i> <?= e($row['email']) ?></div>
                            </td>
                            <td><strong><?= e($row['book_title']) ?></strong></td>
                            <td><span class="badge badge-red"><?= (int)$row['late_days'] ?> Days Late</span></td>
                            <td><strong style="color:var(--danger); font-size:14px;">₹<?= number_format((float)$row['fine_amount'], 2) ?></strong></td>
                            <td>
                                <div class="flex gap-1">
                                    <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:#fff; border-color:#22c55e;" title="Send WhatsApp Overdue Notice">
                                        <i class="fa-brands fa-whatsapp"></i> WhatsApp
                                    </a>
                                    <a href="<?= e($smsUrl) ?>" class="btn btn-sm" style="background:#3b82f6; color:#fff; border-color:#2563eb;" title="Send SMS Notice">
                                        <i class="fa-solid fa-comment-sms"></i> SMS
                                    </a>
                                    <a href="<?= e($mailUrl) ?>" class="btn btn-sm btn-outline" title="Send Email Notice">
                                        <i class="fa-solid fa-envelope"></i> Email
                                    </a>
                                    <form method="POST" style="margin:0;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="dispatch_single">
                                        <input type="hidden" name="student_id" value="<?= $row['student_id'] ?>">
                                        <input type="hidden" name="user_id" value="<?= $row['user_id'] ?>">
                                        <input type="hidden" name="student_name" value="<?= e($row['student_name']) ?>">
                                        <input type="hidden" name="roll_number" value="<?= e($row['roll_number']) ?>">
                                        <input type="hidden" name="phone" value="<?= e($row['phone']) ?>">
                                        <input type="hidden" name="email" value="<?= e($row['email']) ?>">
                                        <input type="hidden" name="book_title" value="<?= e($row['book_title']) ?>">
                                        <input type="hidden" name="late_days" value="<?= (int)$row['late_days'] ?>">
                                        <input type="hidden" name="fine_amount" value="<?= (float)$row['fine_amount'] ?>">
                                        <input type="hidden" name="type" value="overdue_fine">
                                        <input type="hidden" name="channel" value="all">
                                        <button type="submit" class="btn btn-sm btn-outline" title="Log & Dispatch In-App Notification">
                                            <i class="fa-solid fa-paper-plane"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- TAB 3: Ready Reservations -->
<?php if ($activeTab === 'reservations'): ?>
<div class="card">
    <div class="flex justify-between items-center" style="margin-bottom:12px;">
        <h3 style="margin:0;"><i class="fa-solid fa-bookmark text-success"></i> Reserved Books Ready for Pickup</h3>
        <span class="text-muted" style="font-size:13px;">Physical copies currently waiting at the reception</span>
    </div>

    <?php if (empty($readyReservations)): ?>
        <div class="empty-state" style="padding:40px 20px;">
            <i class="fa-solid fa-bookmark" style="font-size:36px; margin-bottom:10px;"></i>
            <h4>No reservations awaiting pickup</h4>
            <p class="text-muted">When a returned physical book fulfills a student reservation, it will appear here.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Reg / Roll No</th>
                        <th>Registered Phone</th>
                        <th>Book Available</th>
                        <th>Pickup Expiry</th>
                        <th>Send Pickup Notification</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($readyReservations as $row): 
                        $msg = getReminderText('reservation_ready', $row['student_name'], $row['roll_number'], $row['book_title'], [
                            'library_name' => $settings['library_name'],
                            'expiry_date' => fmtDate($row['expiry_date'])
                        ]);
                        $waUrl = buildWhatsAppUrl($row['phone'], $msg);
                        $smsUrl = buildSmsUrl($row['phone'], $msg);
                        $mailUrl = buildMailtoUrl($row['email'], "Good News: Reserved Book Ready for Pickup - " . $row['book_title'], $msg);
                    ?>
                        <tr>
                            <td><strong><?= e($row['student_name']) ?></strong></td>
                            <td><span class="badge badge-blue"><?= e($row['roll_number']) ?></span></td>
                            <td>
                                <div><i class="fa-solid fa-phone text-muted" style="font-size:11px;"></i> <?= e($row['phone'] ?: 'N/A') ?></div>
                                <div class="text-muted" style="font-size:11px;"><i class="fa-solid fa-envelope"></i> <?= e($row['email']) ?></div>
                            </td>
                            <td><strong><?= e($row['book_title']) ?></strong></td>
                            <td><span class="badge badge-green">Ready (Expires <?= fmtDate($row['expiry_date']) ?>)</span></td>
                            <td>
                                <div class="flex gap-1">
                                    <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:#fff; border-color:#22c55e;" title="WhatsApp Pickup Notice">
                                        <i class="fa-brands fa-whatsapp"></i> WhatsApp
                                    </a>
                                    <a href="<?= e($smsUrl) ?>" class="btn btn-sm" style="background:#3b82f6; color:#fff; border-color:#2563eb;" title="SMS Pickup Notice">
                                        <i class="fa-solid fa-comment-sms"></i> SMS
                                    </a>
                                    <a href="<?= e($mailUrl) ?>" class="btn btn-sm btn-outline" title="Email Pickup Notice">
                                        <i class="fa-solid fa-envelope"></i> Email
                                    </a>
                                    <form method="POST" style="margin:0;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="dispatch_single">
                                        <input type="hidden" name="student_id" value="<?= $row['student_id'] ?>">
                                        <input type="hidden" name="user_id" value="<?= $row['user_id'] ?>">
                                        <input type="hidden" name="student_name" value="<?= e($row['student_name']) ?>">
                                        <input type="hidden" name="roll_number" value="<?= e($row['roll_number']) ?>">
                                        <input type="hidden" name="phone" value="<?= e($row['phone']) ?>">
                                        <input type="hidden" name="email" value="<?= e($row['email']) ?>">
                                        <input type="hidden" name="book_title" value="<?= e($row['book_title']) ?>">
                                        <input type="hidden" name="expiry_date" value="<?= fmtDate($row['expiry_date']) ?>">
                                        <input type="hidden" name="type" value="reservation_ready">
                                        <input type="hidden" name="channel" value="all">
                                        <button type="submit" class="btn btn-sm btn-outline" title="Log & Dispatch In-App Notification">
                                            <i class="fa-solid fa-paper-plane"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- TAB 4: Dispatched Logs -->
<?php if ($activeTab === 'logs'): ?>
<div class="card">
    <div class="flex justify-between items-center" style="margin-bottom:12px;">
        <h3 style="margin:0;"><i class="fa-solid fa-list-check text-primary"></i> Multi-Channel Dispatched Reminders Log</h3>
        <span class="text-muted" style="font-size:13px;">Recent automated and manual alerts sent</span>
    </div>

    <?php if (empty($logs)): ?>
        <div class="empty-state" style="padding:30px 20px;">
            <i class="fa-solid fa-inbox" style="font-size:32px; margin-bottom:8px;"></i>
            <p class="text-muted" style="margin:0;">No reminders logged yet.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Student Name</th>
                        <th>Reg / Roll No</th>
                        <th>Channel</th>
                        <th>Alert Type</th>
                        <th>Destination (Phone / Email)</th>
                        <th>Message Content</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td class="text-muted" style="font-size:12px;"><?= date('d M Y, h:i A', strtotime($l['dispatched_at'])) ?></td>
                            <td><strong><?= e($l['student_name']) ?></strong></td>
                            <td><span class="badge badge-blue"><?= e($l['roll_number']) ?></span></td>
                            <td>
                                <?php if ($l['channel'] === 'whatsapp'): ?>
                                    <span class="badge" style="background:#dcfce7; color:#15803d;"><i class="fa-brands fa-whatsapp"></i> WhatsApp</span>
                                <?php elseif ($l['channel'] === 'sms'): ?>
                                    <span class="badge badge-blue"><i class="fa-solid fa-comment-sms"></i> SMS</span>
                                <?php elseif ($l['channel'] === 'email'): ?>
                                    <span class="badge" style="background:#e0e7ff; color:#4338ca;"><i class="fa-solid fa-envelope"></i> Email</span>
                                <?php else: ?>
                                    <span class="badge badge-green"><i class="fa-solid fa-paper-plane"></i> Multi-Channel</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $l['type'] === 'overdue_fine' ? 'badge-red' : ($l['type'] === 'due_soon_6h' ? 'badge-yellow' : 'badge-green') ?>">
                                    <?= e($l['title']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-size:12px;"><?= e($l['phone'] ?: 'N/A') ?></div>
                                <div class="text-muted" style="font-size:11px;"><?= e($l['email'] ?: '') ?></div>
                            </td>
                            <td style="max-width:300px; font-size:12px; line-height:1.4;">
                                <div style="white-space:pre-wrap; max-height:60px; overflow-y:auto;"><?= e($l['message']) ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
