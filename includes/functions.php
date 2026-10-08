<?php
/**
 * Shared helper functions.
 * Included by auth-check.php, so it is available on every protected page.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
        'issued' => 'badge-blue', 'pending' => 'badge-yellow',
        'overdue' => 'badge-red', 'inactive' => 'badge-gray', 'cancelled' => 'badge-gray',
        'expired' => 'badge-gray', 'lost' => 'badge-red', 'damaged' => 'badge-red', 'waived' => 'badge-gray',
    ];
    $class = $colors[$status] ?? 'badge-gray';
    return '<span class="badge ' . $class . '">' . e(ucfirst($status)) . '</span>';
}

/** Base path helper for links (adjust if you deploy under a different folder name). */
function basePath() {
    return '/smart-library';
}
