<?php
/**
 * Renders the top bar. Expects $pageTitle to be set by the calling page.
 * Uses $pdo (already available since header.php is included after DB connect).
 */
$unreadCount = 0;
if (isset($pdo, $_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $unreadCount = (int)$stmt->fetchColumn();
}
$initials = '';
foreach (explode(' ', $_SESSION['name'] ?? 'U') as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}
$initials = substr($initials, 0, 2);
$role = $_SESSION['role'] ?? '';
$profileUrl = match($role) {
    'admin'     => basePath() . '/admin/profile.php',
    'librarian' => basePath() . '/librarian/profile.php',
    'student'   => basePath() . '/student/profile.php',
    default     => '#',
};
$profileLabel = match($role) {
    'admin'     => 'Admin Profile',
    'librarian' => 'Librarian Profile',
    'student'   => 'Student Profile',
    default     => 'Profile',
};
$profileIcon = match($role) {
    'admin'     => 'fa-solid fa-user-shield',
    'librarian' => 'fa-solid fa-user-tie',
    'student'   => 'fa-solid fa-user-graduate',
    default     => 'fa-solid fa-user',
};
$notifLink = match($role) {
    'student' => basePath() . '/student/notifications.php',
    default   => '#',
};
$userEmail = $_SESSION['email'] ?? '';
if ($userEmail === '' && isset($pdo, $_SESSION['user_id'])) {
    $stEmail = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stEmail->execute([$_SESSION['user_id']]);
    $userEmail = $stEmail->fetchColumn() ?: '';
    $_SESSION['email'] = $userEmail;
}
?>
<div class="topbar">
    <div class="flex items-center gap-2">
        <button class="menu-toggle"><i class="fa-solid fa-bars"></i></button>
        <div class="page-title"><?= e($pageTitle ?? 'Dashboard') ?></div>
    </div>
    <div class="topbar-right">
        <a href="<?= e($notifLink) ?>" class="bell" title="Notifications">
            <i class="fa-solid fa-bell"></i>
            <?php if ($unreadCount > 0): ?>
                <span class="dot"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
            <?php endif; ?>
        </a>
        <div class="user-dropdown">
            <button type="button" class="user-chip" id="userMenuBtn" onclick="toggleUserDropdown(event)" aria-expanded="false" aria-haspopup="true">
                <div class="avatar"><?= e($initials) ?></div>
                <div class="user-chip-text">
                    <div class="user-chip-name"><?= e($_SESSION['name'] ?? '') ?></div>
                    <div class="user-chip-role text-muted"><?= e(ucfirst($role)) ?></div>
                </div>
                <i class="fa-solid fa-chevron-down user-chip-arrow"></i>
            </button>
            <div class="user-dropdown-menu" id="userDropdownMenu" role="menu" aria-labelledby="userMenuBtn">
                <div class="dropdown-header">
                    <div class="dropdown-header-name"><?= e($_SESSION['name'] ?? '') ?></div>
                    <?php if (!empty($userEmail)): ?>
                        <div class="dropdown-header-email"><?= e($userEmail) ?></div>
                    <?php endif; ?>
                    <div class="dropdown-header-badge">
                        <span class="badge badge-blue"><?= e(ucfirst($role)) ?></span>
                    </div>
                </div>
                <div class="dropdown-divider"></div>
                <a href="<?= e($profileUrl) ?>" class="dropdown-item" role="menuitem">
                    <i class="<?= e($profileIcon) ?>"></i>
                    <span><?= e($profileLabel) ?></span>
                </a>
                <div class="dropdown-divider"></div>
                <a href="<?= basePath() ?>/auth/logout.php" class="dropdown-item dropdown-item-danger" role="menuitem">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>
</div>

