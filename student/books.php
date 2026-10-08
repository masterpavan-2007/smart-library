<?php
/**
 * Unified Book Discovery System
 * Supports Physical Books, Digital E-Books, and Combined Hybrid Catalogues.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['student']);
$pageTitle = 'Unified Catalog';
$currentPage = 'books.php';
$settings = getSettings($pdo);

$stu = $pdo->prepare("SELECT * FROM students WHERE user_id=?");
$stu->execute([$_SESSION['user_id']]);
$student = $stu->fetch();
$studentId = $student['id'];

// ---------- Reserve a physical book ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reserve') {
    verifyCsrf();
    $bookId = (int)($_POST['book_id'] ?? 0);
    $bk = $pdo->prepare("SELECT * FROM books WHERE id=?");
    $bk->execute([$bookId]);
    $book = $bk->fetch();

    $existing = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE student_id=? AND book_id=? AND status IN ('pending','approved','ready')");
    $existing->execute([$studentId, $bookId]);

    if (!$book) {
        setFlash('error', 'Book not found.');
    } elseif ($existing->fetchColumn() > 0) {
        setFlash('error', 'You already have an active reservation for this book.');
    } else {
        $expiry = date('Y-m-d', strtotime("+{$settings['reservation_valid_days']} days"));
        $pdo->prepare("INSERT INTO reservations (student_id, book_id, reservation_date, expiry_date, status) VALUES (?,?,CURDATE(),?, 'pending')")
            ->execute([$studentId, $bookId, $expiry]);
        setFlash('success', 'Physical copy reserved. We will notify you when it becomes ready for pickup.');
    }
    redirect('books.php');
}

// Fetch user favorites
$favPhysical = $pdo->prepare("SELECT book_id FROM favorites WHERE student_id = ? AND book_id IS NOT NULL");
$favPhysical->execute([$studentId]);
$favPhysList = $favPhysical->fetchAll(PDO::FETCH_COLUMN);

$favDigital = $pdo->prepare("SELECT digital_book_id FROM favorites WHERE student_id = ? AND digital_book_id IS NOT NULL");
$favDigital->execute([$studentId]);
$favDigList = $favDigital->fetchAll(PDO::FETCH_COLUMN);

// Filter Parameters
$format = $_GET['format'] ?? 'all'; // 'all', 'physical', 'virtual'
$search = trim($_GET['q'] ?? '');
$catId = trim($_GET['category'] ?? '');
$language = trim($_GET['language'] ?? '');
$availOnly = isset($_GET['available_only']) && $_GET['available_only'] === '1';

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

$catalogBooks = [];

// 1. Fetch Physical Books if 'all' or 'physical'
if ($format === 'all' || $format === 'physical') {
    $pWhere = ["b.status = 'active'"];
    $pParams = [];

    if ($search !== '') {
        $pWhere[] = "(b.title LIKE ? OR b.isbn LIKE ? OR a.name LIKE ? OR c.name LIKE ?)";
        $term = "%$search%";
        $pParams = array_merge($pParams, [$term, $term, $term, $term]);
    }
    if ($catId !== '') {
        $pWhere[] = "b.category_id = ?";
        $pParams[] = $catId;
    }
    if ($language !== '') {
        $pWhere[] = "b.language = ?";
        $pParams[] = $language;
    }
    if ($availOnly) {
        $pWhere[] = "b.available_copies > 0";
    }

    $pWhereSql = 'WHERE ' . implode(' AND ', $pWhere);
    $pStmt = $pdo->prepare("
        SELECT b.*, a.name AS author_name, c.name AS category_name, p.name AS publisher_name,
               'physical' AS item_type
        FROM books b
        LEFT JOIN authors a ON a.id = b.author_id
        LEFT JOIN categories c ON c.id = b.category_id
        LEFT JOIN publishers p ON p.id = b.publisher_id
        $pWhereSql
        ORDER BY b.title ASC
        LIMIT 60
    ");
    $pStmt->execute($pParams);
    $physBooks = $pStmt->fetchAll();

    // Check linked digital edition for each physical book
    foreach ($physBooks as &$pb) {
        $linkStmt = $pdo->prepare("SELECT id, pages, file_format, access_type FROM digital_books WHERE (book_id = ? OR title = ?) AND status = 'active' LIMIT 1");
        $linkStmt->execute([$pb['id'], $pb['title']]);
        $pb['linked_digital'] = $linkStmt->fetch();
        $catalogBooks[] = $pb;
    }
    unset($pb);
}

// 2. Fetch Virtual Books if 'all' or 'virtual'
if ($format === 'all' || $format === 'virtual') {
    $vWhere = ["db.status = 'active'"];
    $vParams = [];

    if ($search !== '') {
        $vWhere[] = "(db.title LIKE ? OR db.description LIKE ? OR a.name LIKE ? OR db.author_name LIKE ? OR c.name LIKE ?)";
        $term = "%$search%";
        $vParams = array_merge($vParams, [$term, $term, $term, $term, $term]);
    }
    if ($catId !== '') {
        $vWhere[] = "db.category_id = ?";
        $vParams[] = $catId;
    }
    if ($language !== '') {
        $vWhere[] = "db.language = ?";
        $vParams[] = $language;
    }
    // If format is 'all', only include standalone digital books (not already shown via linked_digital on physical copy) to prevent duplicate cards
    if ($format === 'all') {
        $vWhere[] = "db.book_id IS NULL AND db.title NOT IN (SELECT title FROM books WHERE status = 'active')";
    }

    $vWhereSql = 'WHERE ' . implode(' AND ', $vWhere);
    $vStmt = $pdo->prepare("
        SELECT db.id AS digital_id, db.title, db.pages, db.language, db.access_type, db.file_format,
               db.cover_image, db.description, db.view_count, db.read_count,
               COALESCE(a.name, db.author_name) AS author_name,
               c.name AS category_name, p.name AS publisher_name,
               'virtual' AS item_type
        FROM digital_books db
        LEFT JOIN authors a ON a.id = db.author_id
        LEFT JOIN categories c ON c.id = db.category_id
        LEFT JOIN publishers p ON p.id = db.publisher_id
        $vWhereSql
        ORDER BY db.title ASC
        LIMIT 60
    ");
    $vStmt->execute($vParams);
    $virtBooks = $vStmt->fetchAll();

    foreach ($virtBooks as $vb) {
        $catalogBooks[] = $vb;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Format Segmented Navigation Tabs -->
<div class="flex justify-between items-center" style="margin-bottom:20px; flex-wrap:wrap; gap:12px;">
    <div class="flex gap-2">
        <a href="books.php?format=all<?= $search ? '&q=' . urlencode($search) : '' ?><?= $catId ? '&category=' . urlencode($catId) : '' ?>" class="btn <?= $format === 'all' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-shapes"></i> All Resources
        </a>
        <a href="books.php?format=physical<?= $search ? '&q=' . urlencode($search) : '' ?><?= $catId ? '&category=' . urlencode($catId) : '' ?>" class="btn <?= $format === 'physical' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-book"></i> Physical Books Only
        </a>
        <a href="books.php?format=virtual<?= $search ? '&q=' . urlencode($search) : '' ?><?= $catId ? '&category=' . urlencode($catId) : '' ?>" class="btn <?= $format === 'virtual' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-file-pdf"></i> Digital E-Books Only
        </a>
    </div>

    <div>
        <a href="virtual-library.php" class="btn btn-outline" style="color:var(--primary);">
            <i class="fa-solid fa-laptop-code"></i> Open Virtual Library Portal &rarr;
        </a>
    </div>
</div>

<!-- Search & Filtering Toolbar -->
<div class="card" style="margin-bottom:24px; padding:16px;">
    <form method="GET" class="flex gap-2" style="flex-wrap:wrap; align-items:center;">
        <input type="hidden" name="format" value="<?= e($format) ?>">
        <div style="position:relative; flex:1; min-width:240px;">
            <i class="fa-solid fa-magnifying-glass text-muted" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:14px;"></i>
            <input type="text" name="q" class="form-control" style="padding-left:36px; width:100%;" placeholder="Search title, author, ISBN, category..." value="<?= e($search) ?>">
        </div>

        <select name="category" class="form-control" style="width:170px;">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $catId == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($format !== 'virtual'): ?>
        <label class="flex items-center gap-1 text-muted" style="font-size:13px; cursor:pointer;">
            <input type="checkbox" name="available_only" value="1" <?= $availOnly ? 'checked' : '' ?>>
            <span>Available Copies Only</span>
        </label>
        <?php endif; ?>

        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Search</button>
        <?php if ($search !== '' || $catId !== '' || $availOnly): ?>
            <a href="books.php?format=<?= e($format) ?>" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Results Grid -->
<?php if (empty($catalogBooks)): ?>
    <div class="card">
        <div class="empty-state" style="padding:40px 20px;">
            <i class="fa-solid fa-book-open" style="font-size:36px; margin-bottom:12px;"></i>
            <h4>No items match your catalog query</h4>
            <p class="text-muted">Try adjusting your keywords or clearing selected filters.</p>
            <a href="books.php?format=<?= e($format) ?>" class="btn btn-outline">Reset Search</a>
        </div>
    </div>
<?php else: ?>
    <div class="grid grid-3">
        <?php foreach ($catalogBooks as $b): 
            $isPhysical = (($b['item_type'] ?? '') === 'physical');
            $hasDigital = !empty($b['linked_digital']);
            $isFav = $isPhysical ? in_array($b['id'], $favPhysList) : in_array($b['digital_id'] ?? 0, $favDigList);
        ?>
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between; position:relative;">
                <div>
                    <!-- Top badges -->
                    <div class="flex justify-between items-start" style="margin-bottom:10px;">
                        <div class="flex gap-1" style="flex-wrap:wrap;">
                            <?php if ($isPhysical): ?>
                                <span class="badge badge-blue"><i class="fa-solid fa-book"></i> Physical</span>
                            <?php endif; ?>
                            <?php if (!$isPhysical || $hasDigital): ?>
                                <span class="badge badge-green"><i class="fa-solid fa-file-pdf"></i> Digital PDF</span>
                            <?php endif; ?>
                        </div>
                        <button class="fav-btn" onclick="toggleCatalogFav(this, '<?= $isPhysical ? 'physical' : 'digital' ?>', <?= $isPhysical ? $b['id'] : $b['digital_id'] ?>)" title="Favorite" style="background:none; border:none; cursor:pointer; font-size:16px; color:<?= $isFav ? '#ef4444' : '#94a3b8' ?>;">
                            <i class="<?= $isFav ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i>
                        </button>
                    </div>

                    <!-- Book Title & Author -->
                    <div style="display:flex; gap:12px; margin-bottom:12px;">
                        <div style="width:52px; height:70px; background:<?= $isPhysical ? 'var(--primary-light)' : '#e0f2fe' ?>; border-radius:6px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <i class="fa-solid <?= $isPhysical ? 'fa-book' : 'fa-file-pdf' ?>" style="color:<?= $isPhysical ? 'var(--primary)' : '#0284c7' ?>; font-size:24px;"></i>
                        </div>
                        <div style="flex:1; min-width:0;">
                            <strong style="font-size:15px; display:block; line-height:1.3; margin-bottom:3px;"><?= e($b['title']) ?></strong>
                            <div class="text-muted" style="font-size:12.5px;"><?= e($b['author_name'] ?? 'Unknown Author') ?></div>
                            <div class="text-muted" style="font-size:12px;"><?= e($b['category_name'] ?: 'General') ?></div>
                        </div>
                    </div>

                    <!-- Separate Detailed Specifications -->
                    <div style="background:var(--gray-light); border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:12.5px; line-height:1.5;">
                        <?php if ($isPhysical): ?>
                            <div><strong>Physical Copy:</strong> Shelf <?= e($b['shelf_number']) ?> &middot; ISBN: <?= e($b['isbn']) ?></div>
                            <div class="text-muted">Total: <?= (int)$b['total_copies'] ?> copies &middot; Available: <strong><?= (int)$b['available_copies'] ?></strong></div>
                        <?php endif; ?>

                        <?php if (!$isPhysical): ?>
                            <div><strong>Digital Copy:</strong> PDF Document &middot; <?= (int)$b['pages'] ?> pages</div>
                            <div class="text-muted">Access Policy: <span class="badge badge-green" style="font-size:10px;"><?= strtoupper(e($b['access_type'])) ?></span></div>
                        <?php endif; ?>

                        <?php if ($hasDigital): ?>
                            <div style="margin-top:6px; padding-top:6px; border-top:1px dashed var(--border); color:#0369a1;">
                                <i class="fa-solid fa-cloud-bolt"></i> <strong>Digital Edition Available:</strong> <?= (int)$b['linked_digital']['pages'] ?> pages (Instant Read)
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Action Buttons: Reserve Physical / Read Digital -->
                <div>
                    <div class="flex items-center justify-between" style="border-top:1px solid var(--border); padding-top:10px;">
                        <?php if ($isPhysical): ?>
                            <div>
                                <?= statusBadge($b['available_copies'] > 0 ? 'available' : 'issued') ?>
                                <span class="text-muted" style="font-size:12px; margin-left:4px;"><?= (int)$b['available_copies'] ?> left</span>
                            </div>
                            <?php if ($b['available_copies'] <= 0): ?>
                                <form method="POST" style="margin:0;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="reserve">
                                    <input type="hidden" name="book_id" value="<?= $b['id'] ?>">
                                    <button class="btn btn-sm btn-outline" type="submit"><i class="fa-solid fa-bookmark"></i> Reserve</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-green"><i class="fa-solid fa-check"></i> Available Now</span>
                            <a href="read-book.php?id=<?= $b['digital_id'] ?>" class="btn btn-sm btn-primary">
                                <i class="fa-solid fa-book-open"></i> Read Online
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($hasDigital): ?>
                        <div style="margin-top:8px;">
                            <a href="read-book.php?id=<?= $b['linked_digital']['id'] ?>" class="btn btn-sm btn-outline" style="width:100%; justify-content:center; color:var(--primary); border-color:var(--primary);">
                                <i class="fa-solid fa-book-open-reader"></i> Read Digital Copy Online
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
function toggleCatalogFav(btn, type, id) {
    const payload = (type === 'physical') ? { action: 'toggle_favorite', book_id: id } : { action: 'toggle_favorite', digital_book_id: id };
    fetch('save-progress.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const icon = btn.querySelector('i');
            if (data.is_favorite) {
                btn.style.color = '#ef4444';
                icon.className = 'fa-solid fa-heart';
            } else {
                btn.style.color = '#94a3b8';
                icon.className = 'fa-regular fa-heart';
            }
        }
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
