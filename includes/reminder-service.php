<?php
/**
 * Smart Multi-Channel Reminder Service
 * Handles 6-hour due alerts, overdue fine warnings, and reservation pickup alerts
 * across WhatsApp, SMS, Email, and In-App Notifications on registered numbers.
 */

if (!function_exists('cleanPhoneNumber')) {
    function cleanPhoneNumber($phone) {
        $digits = preg_replace('/[^0-9]/', '', $phone ?? '');
        if (strlen($digits) === 10) {
            // Default India country code prefix (91) for standard 10-digit mobile numbers
            return '91' . $digits;
        }
        return $digits;
    }
}

if (!function_exists('buildWhatsAppUrl')) {
    function buildWhatsAppUrl($phone, $message) {
        $clean = cleanPhoneNumber($phone);
        if (empty($clean)) return '#';
        return 'https://api.whatsapp.com/send?phone=' . $clean . '&text=' . rawurlencode($message);
    }
}

if (!function_exists('buildSmsUrl')) {
    function buildSmsUrl($phone, $message) {
        $digits = preg_replace('/[^0-9]/', '', $phone ?? '');
        if (empty($digits)) return '#';
        return 'sms:' . $digits . '?body=' . rawurlencode($message);
    }
}

if (!function_exists('buildMailtoUrl')) {
    function buildMailtoUrl($email, $subject, $message) {
        if (empty($email)) return '#';
        return 'mailto:' . rawurlencode($email) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($message);
    }
}

/**
 * Returns customized alert text tailored for WhatsApp, SMS, and Email
 */
function getReminderText($type, $studentName, $rollNumber, $bookTitle, $details = []) {
    $libraryName = $details['library_name'] ?? 'Smart Library';

    switch ($type) {
        case 'due_soon_6h':
            $hours = $details['hours'] ?? '6';
            $dueDate = $details['due_date'] ?? date('d M Y');
            return "🔔 *[{$libraryName} Due Alert]*\nHello {$studentName} (Reg/Roll: {$rollNumber}),\nYour borrowed book *\"{$bookTitle}\"* is due TODAY ({$dueDate}) within less than {$hours} hours.\nPlease return or renew it at the library counter before closing hours to avoid overdue fines.\nThank you!";

        case 'overdue_fine':
            $days = $details['late_days'] ?? 1;
            $fine = number_format((float)($details['fine_amount'] ?? 0), 2);
            return "⚠️ *[{$libraryName} Overdue & Fine Notice]*\nHello {$studentName} (Reg/Roll: {$rollNumber}),\nYour borrowed book *\"{$bookTitle}\"* is currently *{$days} day(s) OVERDUE*.\nAn overdue fine of *₹{$fine}* has been charged on your account.\nPlease return the book immediately to avoid additional daily penalty accrual.\nThank you!";

        case 'reservation_ready':
            $expiry = $details['expiry_date'] ?? '3 days';
            return "🎉 *[{$libraryName} Reservation Available]*\nHello {$studentName} (Reg/Roll: {$rollNumber}),\nGreat news! Your reserved book *\"{$bookTitle}\"* is now AVAILABLE and ready for pickup at the Library Reception counter.\nPlease collect it by {$expiry}.\nThank you!";

        default:
            return "Notification from {$libraryName} for {$studentName} ({$rollNumber}): {$bookTitle}";
    }
}

/**
 * Scans active checkouts and reservations to identify triggers:
 * 1. Due within 6 hours / Due today
 * 2. Overdue with accrued fines
 * 3. Ready reservations
 */
function scanPendingLibraryAlerts($pdo, $studentId = null) {
    $settings = getSettings($pdo);
    $finePerDay = (float)($settings['fine_per_day'] ?? 5.00);

    $stuCondition = $studentId ? "AND s.id = " . (int)$studentId : "";

    // 1. Due within 6 hours (due date is today CURDATE())
    $stmtDue = $pdo->prepare("
        SELECT bi.id AS issue_id, bi.issue_date, bi.due_date, b.id AS book_id, b.title AS book_title,
               s.id AS student_id, s.roll_number, u.id AS user_id, u.name AS student_name,
               u.phone, u.email, 'due_soon_6h' AS alert_type
        FROM book_issues bi
        JOIN books b ON b.id = bi.book_id
        JOIN students s ON s.id = bi.student_id
        JOIN users u ON u.id = s.user_id
        WHERE bi.status = 'issued' 
          AND bi.due_date = CURDATE()
          $stuCondition
        ORDER BY bi.due_date ASC
    ");
    $stmtDue->execute();
    $dueSoon = $stmtDue->fetchAll();

    // 2. Overdue with fines
    $stmtOverdue = $pdo->prepare("
        SELECT bi.id AS issue_id, bi.issue_date, bi.due_date, b.id AS book_id, b.title AS book_title,
               s.id AS student_id, s.roll_number, u.id AS user_id, u.name AS student_name,
               u.phone, u.email, DATEDIFF(CURDATE(), bi.due_date) AS late_days,
               'overdue_fine' AS alert_type
        FROM book_issues bi
        JOIN books b ON b.id = bi.book_id
        JOIN students s ON s.id = bi.student_id
        JOIN users u ON u.id = s.user_id
        WHERE bi.status IN ('issued', 'overdue')
          AND bi.due_date < CURDATE()
          $stuCondition
        ORDER BY bi.due_date ASC
    ");
    $stmtOverdue->execute();
    $overdue = $stmtOverdue->fetchAll();

    foreach ($overdue as &$item) {
        $item['fine_amount'] = calculateFine((int)$item['late_days'], $settings);
    }
    unset($item);

    // 3. Ready reservations
    $stmtReady = $pdo->prepare("
        SELECT rv.id AS reservation_id, rv.reservation_date, rv.expiry_date,
               b.id AS book_id, b.title AS book_title,
               s.id AS student_id, s.roll_number, u.id AS user_id, u.name AS student_name,
               u.phone, u.email, 'reservation_ready' AS alert_type
        FROM reservations rv
        JOIN books b ON b.id = rv.book_id
        JOIN students s ON s.id = rv.student_id
        JOIN users u ON u.id = s.user_id
        WHERE rv.status = 'ready'
          $stuCondition
        ORDER BY rv.id DESC
    ");
    $stmtReady->execute();
    $readyReservations = $stmtReady->fetchAll();

    return [
        'due_soon_6h' => $dueSoon,
        'overdue_fines' => $overdue,
        'ready_reservations' => $readyReservations,
        'total_alerts' => count($dueSoon) + count($overdue) + count($readyReservations)
    ];
}

/**
 * Dispatches and logs an alert
 */
function recordDispatchedReminder($pdo, $data) {
    $stmt = $pdo->prepare("
        INSERT INTO reminders_log 
        (student_id, user_id, roll_number, phone, email, type, channel, title, message, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent')
    ");
    $stmt->execute([
        $data['student_id'],
        $data['user_id'],
        $data['roll_number'],
        $data['phone'],
        $data['email'],
        $data['type'],
        $data['channel'],
        $data['title'],
        $data['message']
    ]);

    // Also add to in-app notifications
    notify($pdo, $data['user_id'], $data['title'], $data['message']);
}
