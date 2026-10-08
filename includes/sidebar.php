<?php
/**
 * Renders the left sidebar. Expects $_SESSION['role'] to be set
 * and an optional $currentPage variable (basename of the current file)
 * for highlighting the active link.
 */
$role = $_SESSION['role'] ?? '';
$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);
$bp = basePath();

$menus = [
    'admin' => [
        ['Dashboard', 'dashboard.php', 'fa-gauge-high'],
        ['_section' => 'Catalog'],
        ['Physical Books', 'books.php', 'fa-book'],
        ['Digital Books', 'digital-books.php', 'fa-file-pdf'],
        ['Categories', 'categories.php', 'fa-tags'],
        ['Authors', 'authors.php', 'fa-feather'],
        ['Publishers', 'publishers.php', 'fa-building'],
        ['_section' => 'People'],
        ['Students', 'students.php', 'fa-user-graduate'],
        ['Staff', 'staff.php', 'fa-user-tie'],
        ['_section' => 'Circulation'],
        ['Issued Books', 'issues.php', 'fa-right-from-bracket'],
        ['Returns', 'returns.php', 'fa-right-to-bracket'],
        ['Reservations', 'reservations.php', 'fa-bookmark'],
        ['Reminders & Alerts', 'reminders.php', 'fa-bell-concierge'],
        ['Fines', 'fines.php', 'fa-coins'],
        ['Payments', 'payments.php', 'fa-credit-card'],
        ['_section' => 'System'],
        ['Reports', 'reports.php', 'fa-chart-column'],
        ['Settings', 'settings.php', 'fa-gear'],
        ['Admin Profile', 'profile.php', 'fa-user-gear'],
    ],
    'librarian' => [
        ['Dashboard', 'dashboard.php', 'fa-gauge-high'],
        ['_section' => 'Circulation'],
        ['Issue Book', 'issue-book.php', 'fa-right-from-bracket'],
        ['Return Book', 'return-book.php', 'fa-right-to-bracket'],
        ['Reservations', 'reservations.php', 'fa-bookmark'],
        ['Reminders & Alerts', 'reminders.php', 'fa-bell-concierge'],
        ['Fines', 'fines.php', 'fa-coins'],
        ['_section' => 'Catalog'],
        ['Physical Books', 'books.php', 'fa-book'],
        ['Digital Books', 'digital-books.php', 'fa-file-pdf'],
        ['Students', 'students.php', 'fa-user-graduate'],
        ['_section' => 'Reports & Profile'],
        ['Reports', 'reports.php', 'fa-chart-column'],
        ['My Profile', 'profile.php', 'fa-user'],
    ],
    'student' => [
        ['Dashboard', 'dashboard.php', 'fa-gauge-high'],
        ['_section' => 'Explore & Read'],
        ['Browse Catalog', 'books.php', 'fa-book'],
        ['Virtual Library', 'virtual-library.php', 'fa-laptop-code'],
        ['Reading History', 'reading-history.php', 'fa-clock-rotate-left'],
        ['My Favorites', 'favorites.php', 'fa-heart'],
        ['_section' => 'My Circulation'],
        ['My Issued Books', 'issued-books.php', 'fa-right-from-bracket'],
        ['My Reservations', 'reservations.php', 'fa-bookmark'],
        ['My Fines', 'fines.php', 'fa-coins'],
        ['Notifications', 'notifications.php', 'fa-bell'],
        ['My Profile', 'profile.php', 'fa-user'],
    ],
];

$sidebarNavItems = $menus[$role] ?? [];
?>
<aside class="sidebar">
    <div class="brand">
        <i class="fa-solid fa-book-open"></i>
        <span>Smart Library</span>
    </div>
    <nav>
        <?php foreach ($sidebarNavItems as $item): ?>
            <?php if (isset($item['_section'])): ?>
                <?php if ($item['_section'] !== ''): ?>
                    <div class="nav-section-title"><?= e($item['_section']) ?></div>
                <?php endif; ?>
            <?php else: [$label, $file, $icon] = $item; ?>
                <a class="nav-link <?= $currentPage === $file ? 'active' : '' ?>" href="<?= e($file) ?>">
                    <i class="fa-solid <?= e($icon) ?>"></i> <?= e($label) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
        <div class="nav-section-title">&nbsp;</div>
        <a class="nav-link" href="<?= $bp ?>/auth/logout.php">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </nav>
</aside>
