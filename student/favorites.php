<?php
/**
 * Student Saved Favorites (Digital Books & Physical Books)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['student']);
$pageTitle = 'My Favorites';
$currentPage = 'favorites.php';

// Find student ID
$stu = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
$stu->execute([$_SESSION['user_id']]);
$studentId = (int)$stu->fetchColumn();

// Handle remove favorite via POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove') {
    verifyCsrf();
    $favId = (int)($_POST['fav_id'] ?? 0);
    $pdo->prepare("DELETE FROM favorites WHERE id = ? AND student_id = ?")->execute([$favId, $studentId]);
    setFlash('success', 'Book removed from favorites.');
    redirect('favorites.php');
}

// Fetch all digital favorites
$stmtDig = $pdo->prepare("
    SELECT f.id AS fav_id, f.created_at AS fav_date, db.*,
           c.name AS category_name, COALESCE(a.name, db.author_name) AS author_name
    FROM favorites f
    JOIN digital_books db ON db.id = f.digital_book_id
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN authors a ON a.id = db.author_id
    WHERE f.student_id = ?
    ORDER BY f.created_at DESC
");
$stmtDig->execute([$studentId]);
$digitalFavs = $stmtDig->fetchAll();

// Fetch all physical favorites
$stmtPhys = $pdo->prepare("
    SELECT f.id AS fav_id, f.created_at AS fav_date, b.*,
           c.name AS category_name, a.name AS author_name
    FROM favorites f
    JOIN books b ON b.id = f.book_id
    LEFT JOIN categories c ON c.id = b.category_id
    LEFT JOIN authors a ON a.id = b.author_id
    WHERE f.student_id = ?
    ORDER BY f.created_at DESC
");
$stmtPhys->execute([$studentId]);
$physicalFavs = $stmtPhys->fetchAll();

$tab = $_GET['tab'] ?? 'all';

include __DIR__ . '/../includes/header.php';
?>

<!-- Tab Navigation -->
<div class="flex justify-between items-center" style="margin-bottom:20px;">
    <div class="flex gap-2">
        <a href="favorites.php?tab=all" class="btn <?= $tab === 'all' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-heart"></i> All Favorites (<?= count($digitalFavs) + count($physicalFavs) ?>)
        </a>
        <a href="favorites.php?tab=digital" class="btn <?= $tab === 'digital' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-file-pdf"></i> Digital E-Books (<?= count($digitalFavs) ?>)
        </a>
        <a href="favorites.php?tab=physical" class="btn <?= $tab === 'physical' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-book"></i> Physical Books (<?= count($physicalFavs) ?>)
        </a>
    </div>
    <a href="virtual-library.php" class="btn btn-outline"><i class="fa-solid fa-magnifying-glass"></i> Browse Catalog</a>
</div>

<?php if (empty($digitalFavs) && empty($physicalFavs)): ?>
    <div class="card" style="text-align:center; padding:48px 20px;">
        <div class="empty-state">
            <i class="fa-regular fa-heart" style="font-size:42px; margin-bottom:12px; color:#ef4444;"></i>
            <h4>No favorite books saved yet</h4>
            <p class="text-muted">Click the heart icon on any digital book or physical catalog entry to save it to your personal favorites collection.</p>
            <div class="flex gap-2 justify-center" style="margin-top:14px;">
                <a href="virtual-library.php" class="btn btn-primary">Browse Virtual Library</a>
                <a href="books.php" class="btn btn-outline">Browse Physical Catalog</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Digital Favorites Section -->
<?php if (($tab === 'all' || $tab === 'digital') && !empty($digitalFavs)): ?>
<div style="margin-bottom:28px;">
    <h3 style="font-size:17px; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-file-pdf text-primary"></i> Saved Digital Books & PDFs (<?= count($digitalFavs) ?>)
    </h3>
    <div class="grid grid-3">
        <?php foreach ($digitalFavs as $b): ?>
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                    <div class="flex justify-between items-start" style="margin-bottom:10px;">
                        <span class="badge badge-blue"><?= e($b['category_name'] ?: 'General') ?></span>
                        <form method="POST" style="margin:0;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="fav_id" value="<?= $b['fav_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline" title="Remove from favorites" style="padding:2px 8px; color:#ef4444; border-color:#fee2e2;">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </form>
                    </div>
                    <strong style="font-size:15px; display:block; margin-bottom:4px; line-height:1.3;">
                        <a href="read-book.php?id=<?= $b['id'] ?>"><?= e($b['title']) ?></a>
                    </strong>
                    <div class="text-muted" style="font-size:12.5px; margin-bottom:10px;"><?= e($b['author_name']) ?></div>
                    <p class="text-muted" style="font-size:13px; line-height:1.4; margin:0 0 14px 0; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">
                        <?= e($b['description'] ?: 'Digital book resource.') ?>
                    </p>
                </div>
                <div>
                    <div class="flex justify-between items-center text-muted" style="font-size:11.5px; margin-bottom:10px; border-top:1px solid var(--border); padding-top:8px;">
                        <span><i class="fa-solid fa-file"></i> <?= (int)$b['pages'] ?> pages</span>
                        <span><span class="badge badge-green"><?= strtoupper(e($b['access_type'])) ?></span></span>
                    </div>
                    <a href="read-book.php?id=<?= $b['id'] ?>" class="btn btn-primary" style="width:100%; justify-content:center;">
                        <i class="fa-solid fa-book-open"></i> Read Online
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Physical Favorites Section -->
<?php if (($tab === 'all' || $tab === 'physical') && !empty($physicalFavs)): ?>
<div style="margin-bottom:28px;">
    <h3 style="font-size:17px; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-book text-primary"></i> Saved Physical Books (<?= count($physicalFavs) ?>)
    </h3>
    <div class="grid grid-3">
        <?php foreach ($physicalFavs as $b): ?>
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                    <div class="flex justify-between items-start" style="margin-bottom:10px;">
                        <span class="badge badge-blue"><?= e($b['category_name'] ?: 'General') ?></span>
                        <form method="POST" style="margin:0;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="fav_id" value="<?= $b['fav_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline" title="Remove from favorites" style="padding:2px 8px; color:#ef4444; border-color:#fee2e2;">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </form>
                    </div>
                    <strong style="font-size:15px; display:block; margin-bottom:4px; line-height:1.3;"><?= e($b['title']) ?></strong>
                    <div class="text-muted" style="font-size:12.5px; margin-bottom:6px;"><?= e($b['author_name']) ?></div>
                    <div class="text-muted" style="font-size:12px; margin-bottom:12px;">Shelf <?= e($b['shelf_number']) ?> &middot; ISBN: <?= e($b['isbn']) ?></div>
                </div>
                <div>
                    <div class="flex justify-between items-center" style="margin-bottom:10px; border-top:1px solid var(--border); padding-top:8px;">
                        <?= statusBadge($b['available_copies'] > 0 ? 'available' : 'issued') ?>
                        <span class="text-muted" style="font-size:12px;"><?= (int)$b['available_copies'] ?> copies left</span>
                    </div>
                    <a href="books.php?q=<?= urlencode($b['title']) ?>" class="btn btn-outline" style="width:100%; justify-content:center;">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> View in Catalog
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
