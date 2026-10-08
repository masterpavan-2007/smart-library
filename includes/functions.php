<?php
/**
 * Shared helper functions.
 * Included by auth-check.php, so it is available on every protected page.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/reminder-service.php';

/** Escape output safely for HTML. */
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirect helper. */
function redirect($url) {
    header("Location: $url");
    exit;
}

/** Is a user currently logged in? */
function isLoggedIn() {
    return isset($_SESSION['user_id'], $_SESSION['role']);
}

/** Require login, optionally restricted to specific roles. */
function requireRole($roles = []) {
    if (!isLoggedIn()) {
        redirect('/smart-library/auth/login.php');
    }
    if (!empty($roles) && !in_array($_SESSION['role'], $roles, true)) {
        // Logged in, but wrong role trying to access another dashboard by URL.
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;">
                <h2>403 - Access Denied</h2>
                <p>You do not have permission to view this page.</p>
                <a href="/smart-library/index.php">Return to homepage</a>
             </div>');
    }
}

/** CSRF token helpers. */
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        die('Invalid or expired form submission. Please go back and try again.');
    }
}

/** Flash messages (success / error banners shown once after redirect). */
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/** Fetch the library-wide settings row (fine per day, borrow period, etc). */
function getSettings($pdo) {
    static $settings = null;
    if ($settings === null) {
        $stmt = $pdo->query("SELECT * FROM library_settings LIMIT 1");
        $settings = $stmt->fetch() ?: [
            'borrow_period_days' => 7, 'fine_per_day' => 5, 'max_fine' => 200,
            'grace_period_days' => 0, 'max_books_per_student' => 3, 'reservation_valid_days' => 3,
            'library_name' => 'Smart Library',
        ];
    }
    return $settings;
}

/** Calculate a fine amount for a number of late days, respecting grace period and max fine. */
function calculateFine($lateDays, $settings) {
    $grace = (int)($settings['grace_period_days'] ?? 0);
    $billableDays = max(0, $lateDays - $grace);
    $fine = $billableDays * (float)$settings['fine_per_day'];
    $max = (float)$settings['max_fine'];
    return $max > 0 ? min($fine, $max) : $fine;
}

/** Log an action to activity_logs. */
function logActivity($pdo, $userId, $action) {
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action) VALUES (?, ?)");
    $stmt->execute([$userId, $action]);
}

/** Create a notification for a user. */
function notify($pdo, $userId, $title, $message) {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $title, $message]);
}

/** Format a date nicely, or return a dash if empty. */
function fmtDate($date) {
    if (empty($date)) return '&mdash;';
    return date('d M Y', strtotime($date));
}

/** Render a colored status badge span. */
function statusBadge($status) {
    $colors = [
        'active' => 'badge-green', 'available' => 'badge-green', 'paid' => 'badge-green',
        'returned' => 'badge-green', 'completed' => 'badge-green', 'approved' => 'badge-green', 'ready' => 'badge-green',
        'issued' => 'badge-blue', 'pending' => 'badge-yellow', 'partially_paid' => 'badge-yellow',
        'overdue' => 'badge-red', 'unpaid' => 'badge-red', 'inactive' => 'badge-gray', 'cancelled' => 'badge-gray',
        'expired' => 'badge-gray', 'lost' => 'badge-red', 'damaged' => 'badge-red', 'waived' => 'badge-gray',
    ];
    $icons = [
        'unpaid' => 'fa-circle-xmark',
        'pending' => 'fa-clock',
        'paid' => 'fa-circle-check',
        'partially_paid' => 'fa-circle-half-stroke',
        'overdue' => 'fa-triangle-exclamation',
        'waived' => 'fa-ban',
    ];
    $iconHtml = isset($icons[$status]) ? '<i class="fa-solid ' . $icons[$status] . '"></i> ' : '';
    $class = $colors[$status] ?? 'badge-gray';
    $label = match($status) {
        'unpaid' => 'Unpaid',
        'pending' => 'Payment Pending',
        'partially_paid' => 'Partially Paid',
        'paid' => 'Paid',
        'waived' => 'Waived',
        default => ucfirst($status)
    };
    return '<span class="badge ' . $class . '">' . $iconHtml . e($label) . '</span>';
}

/**
 * Automatically synchronize and calculate fines for overdue and late-returned books.
 * Ensures duplicate fine rows are NEVER created for the same issue.
 */
function syncStudentFines($pdo, $studentId = null) {
    $settings = getSettings($pdo);
    $fineRate = (float)($settings['fine_per_day'] ?? 5.00);

    $stuCondition = $studentId ? "AND bi.student_id = " . (int)$studentId : "";

    // 1. Scan currently active overdue issues (return_date IS NULL and due_date < CURDATE())
    $stmtOverdue = $pdo->query("
        SELECT bi.id as issue_id, bi.student_id, bi.book_id, bi.issue_date, bi.due_date,
               DATEDIFF(CURDATE(), bi.due_date) as late_days
        FROM book_issues bi
        WHERE bi.status IN ('issued','overdue')
          AND bi.due_date < CURDATE()
          $stuCondition
    ");
    $overdueIssues = $stmtOverdue->fetchAll();

    foreach ($overdueIssues as $iss) {
        $lateDays = max(0, (int)$iss['late_days']);
        if ($lateDays <= 0) continue;
        $fineAmount = calculateFine($lateDays, $settings);

        // Mark book_issues as overdue
        $pdo->prepare("UPDATE book_issues SET status = 'overdue' WHERE id = ? AND status = 'issued'")
            ->execute([$iss['issue_id']]);

        // Check if fine record already exists for this issue_id
        $chk = $pdo->prepare("SELECT id, status, paid_amount, fine_amount FROM fines WHERE issue_id = ?");
        $chk->execute([$iss['issue_id']]);
        $existingFine = $chk->fetch();

        $reason = "Late return - {$lateDays} day(s)";

        if (!$existingFine) {
            // Insert new fine
            $pdo->prepare("
                INSERT INTO fines (student_id, book_id, issue_id, due_date, return_date, late_days, fine_rate, fine_amount, paid_amount, outstanding_amount, amount, reason, status)
                VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 0.00, ?, ?, ?, 'unpaid')
            ")->execute([
                $iss['student_id'], $iss['book_id'], $iss['issue_id'], $iss['due_date'],
                $lateDays, $fineRate, $fineAmount, $fineAmount, $fineAmount, $reason
            ]);
        } else {
            // Do NOT overwrite if already paid or waived
            if (!in_array($existingFine['status'], ['paid', 'waived'], true)) {
                $paid = (float)$existingFine['paid_amount'];
                $outstanding = max(0.00, $fineAmount - $paid);
                $newStatus = ($outstanding <= 0.00 && $fineAmount > 0) ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid');
                $pdo->prepare("
                    UPDATE fines
                    SET book_id = ?, due_date = ?, late_days = ?, fine_rate = ?, fine_amount = ?,
                        outstanding_amount = ?, amount = ?, reason = ?, status = ?
                    WHERE id = ?
                ")->execute([
                    $iss['book_id'], $iss['due_date'], $lateDays, $fineRate, $fineAmount,
                    $outstanding, $fineAmount, $reason, $newStatus, $existingFine['id']
                ]);
            }
        }
    }

    // 2. Scan returned issues where return_date > due_date
    $stmtReturned = $pdo->query("
        SELECT bi.id as issue_id, bi.student_id, bi.book_id, bi.issue_date, bi.due_date, bi.return_date,
               DATEDIFF(bi.return_date, bi.due_date) as late_days
        FROM book_issues bi
        WHERE bi.status = 'returned'
          AND bi.return_date IS NOT NULL
          AND bi.return_date > bi.due_date
          $stuCondition
    ");
    $returnedIssues = $stmtReturned->fetchAll();

    foreach ($returnedIssues as $iss) {
        $lateDays = max(0, (int)$iss['late_days']);
        if ($lateDays <= 0) continue;
        $fineAmount = calculateFine($lateDays, $settings);

        $chk = $pdo->prepare("SELECT id, status, paid_amount, fine_amount FROM fines WHERE issue_id = ?");
        $chk->execute([$iss['issue_id']]);
        $existingFine = $chk->fetch();

        $reason = "Late return - {$lateDays} day(s)";

        if (!$existingFine) {
            $pdo->prepare("
                INSERT INTO fines (student_id, book_id, issue_id, due_date, return_date, late_days, fine_rate, fine_amount, paid_amount, outstanding_amount, amount, reason, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, 'unpaid')
            ")->execute([
                $iss['student_id'], $iss['book_id'], $iss['issue_id'], $iss['due_date'], $iss['return_date'],
                $lateDays, $fineRate, $fineAmount, $fineAmount, $fineAmount, $reason
            ]);
        } else {
            // Ensure metadata (due_date, return_date, book_id, late_days) is filled if empty
            if (!in_array($existingFine['status'], ['paid', 'waived'], true)) {
                $paid = (float)$existingFine['paid_amount'];
                $outstanding = max(0.00, $fineAmount - $paid);
                $newStatus = ($outstanding <= 0.00 && $fineAmount > 0) ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid');
                $pdo->prepare("
                    UPDATE fines
                    SET book_id = ?, due_date = ?, return_date = ?, late_days = ?, fine_rate = ?, fine_amount = ?,
                        outstanding_amount = ?, amount = ?, reason = ?, status = ?
                    WHERE id = ?
                ")->execute([
                    $iss['book_id'], $iss['due_date'], $iss['return_date'], $lateDays, $fineRate, $fineAmount,
                    $outstanding, $fineAmount, $reason, $newStatus, $existingFine['id']
                ]);
            }
        }
    }

    // 3. Keep book_id updated for any older fines where issue_id is present
    $pdo->exec("
        UPDATE fines f
        JOIN book_issues bi ON bi.id = f.issue_id
        SET f.book_id = bi.book_id,
            f.due_date = COALESCE(f.due_date, bi.due_date),
            f.return_date = COALESCE(f.return_date, bi.return_date)
        WHERE f.book_id IS NULL AND f.issue_id IS NOT NULL
    ");
}

/**
 * Returns the exact outstanding unpaid fine amount for a student.
 */
function getStudentOutstandingFine($pdo, $studentId) {
    syncStudentFines($pdo, $studentId);
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE 
                WHEN outstanding_amount > 0 THEN outstanding_amount 
                ELSE (amount - paid_amount) 
            END
        ), 0)
        FROM fines
        WHERE student_id = ? AND status IN ('unpaid', 'pending', 'partially_paid')
    ");
    $stmt->execute([$studentId]);
    return (float)$stmt->fetchColumn();
}

/**
 * Generate a unique Payment ID (e.g. LIBFINE202610081001)
 */
function generatePaymentId($prefix = 'LIBFINE') {
    return $prefix . date('Ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
}

/**
 * Generate a unique Receipt Number (e.g. RCPT-20261008-A1B2)
 */
function generateReceiptNo($prefix = 'RCPT') {
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
}

/**
 * Format a standard UPI payment URL suitable for Indian UPI Apps
 */
function buildUpiUrl($upiId, $merchantName, $amount, $paymentId, $description) {
    $params = [
        'pa' => $upiId,
        'pn' => $merchantName,
        'am' => number_format((float)$amount, 2, '.', ''),
        'cu' => 'INR',
        'tr' => $paymentId,
        'tn' => substr($description, 0, 80),
    ];
    return 'upi://pay?' . http_build_query($params);
}

/**
 * Send / Queue receipt notifications via registered student phone and in-app alert.
 */
function sendPaymentReceiptNotification($pdo, $paymentRecordId) {
    $stmt = $pdo->prepare("
        SELECT p.*, f.issue_id, f.late_days, f.fine_rate, b.title as book_title,
               s.roll_number, s.department, u.name as student_name, u.phone, u.id as user_id, u.email
        FROM payments p
        JOIN fines f ON f.id = p.fine_id
        JOIN students s ON s.id = p.student_id
        JOIN users u ON u.id = s.user_id
        LEFT JOIN books b ON b.id = f.book_id
        WHERE p.id = ?
    ");
    $stmt->execute([$paymentRecordId]);
    $data = $stmt->fetch();
    if (!$data) return false;

    $settings = getSettings($pdo);
    $libraryName = $settings['library_name'] ?? 'Smart Library';

    // Exact message as required by Section 9
    $message = "Dear {$data['student_name']},\n\n"
             . "Your library fine payment has been successfully received.\n\n"
             . "Amount Paid: ₹" . number_format($data['amount'], 2) . "\n"
             . "Student ID: {$data['roll_number']}\n"
             . "Payment ID: {$data['payment_id']}\n"
             . "Transaction ID: {$data['transaction_reference']}\n"
             . "Status: PAID\n\n"
             . "Your library fine receipt has been generated.\n\n"
             . "Thank you,\n{$libraryName} Management System";

    // 1. Create In-App Notification
    notify($pdo, $data['user_id'], 'Library Fine Payment Received ✓', "Payment of ₹" . number_format($data['amount'], 2) . " received. Receipt No: {$data['receipt_no']}.");

    // 2. Log in reminders_log
    try {
        $stmtLog = $pdo->prepare("
            INSERT INTO reminders_log (student_id, user_id, roll_number, phone, email, type, channel, title, message, status)
            VALUES (?, ?, ?, ?, ?, 'fine_receipt', 'sms_whatsapp', ?, ?, 'sent')
        ");
        $stmtLog->execute([
            $data['student_id'], $data['user_id'], $data['roll_number'],
            $data['phone'], $data['email'], 'Fine Payment Receipt', $message
        ]);
    } catch (Exception $e) {
        // reminders_log table may be optional, ignore if absent
    }

    // Log Activity
    logActivity($pdo, $data['user_id'], "Fine payment of Rs. {$data['amount']} confirmed for {$data['student_name']} (Payment ID: {$data['payment_id']})");

    return [
        'message' => $message,
        'phone' => $data['phone'],
        'wa_url' => function_exists('buildWhatsAppUrl') ? buildWhatsAppUrl($data['phone'], $message) : '#',
        'sms_url' => function_exists('buildSmsUrl') ? buildSmsUrl($data['phone'], $message) : '#',
    ];
}

/** Base path helper for links (adjust if you deploy under a different folder name). */
function basePath() {
    return '/smart-library';
}

