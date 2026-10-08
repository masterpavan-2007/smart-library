<?php
/**
 * Smart Virtual Library - Digital Book Discovery, Category Browsing & E-Reader
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['student']);
$pageTitle = 'Virtual Library';
$currentPage = 'virtual-library.php';

// Get current student record
$stu = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
$stu->execute([$_SESSION['user_id']]);
$studentId = (int)$stu->fetchColumn();

// Fetch all categories for filter chips
$allCategories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

// Fetch continue reading records for this student
$continueReading = [];
if ($studentId) {
    $crStmt = $pdo->prepare("
        SELECT rh.*, db.title, db.pages, db.language, db.cover_image, c.name AS category_name,
               COALESCE(a.name, db.author_name) AS author_name
        FROM reading_history rh
        JOIN digital_books db ON db.id = rh.digital_book_id
        LEFT JOIN categories c ON c.id = db.category_id
        LEFT JOIN authors a ON a.id = db.author_id
        WHERE rh.student_id = ? AND rh.reading_status = 'reading' AND db.status = 'active'
        ORDER BY rh.last_accessed_at DESC
        LIMIT 4
    ");
    $crStmt->execute([$studentId]);
    $continueReading = $crStmt->fetchAll();
}

// Fetch favorite book IDs for this student
$userFavorites = [];
if ($studentId) {
    $favStmt = $pdo->prepare("SELECT digital_book_id FROM favorites WHERE student_id = ? AND digital_book_id IS NOT NULL");
    $favStmt->execute([$studentId]);
    $userFavorites = $favStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Filters
$search = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$languageFilter = trim($_GET['language'] ?? '');
$accessFilter = trim($_GET['access'] ?? '');
$sortBy = trim($_GET['sort'] ?? 'popular');

$where = ["db.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = "(db.title LIKE ? OR a.name LIKE ? OR db.author_name LIKE ? OR db.description LIKE ? OR c.name LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

if ($categoryFilter !== '') {
    $where[] = "(c.name = ? OR c.id = ?)";
    $params[] = $categoryFilter;
    $params[] = $categoryFilter;
}

if ($languageFilter !== '') {
    $where[] = "db.language = ?";
    $params[] = $languageFilter;
}

if ($accessFilter !== '') {
    $where[] = "db.access_type = ?";
    $params[] = $accessFilter;
}

// Sorting logic
$orderSql = match($sortBy) {
    'recent' => 'db.id DESC',
    'title'  => 'db.title ASC',
    'views'  => 'db.view_count DESC, db.read_count DESC',
    'pages'  => 'db.pages ASC',
    default  => 'db.read_count DESC, db.view_count DESC', // most popular
};

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Count total matching
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM digital_books db
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN authors a ON a.id = db.author_id
    $whereSql
");
$countStmt->execute($params);
$totalMatching = (int)$countStmt->fetchColumn();

// Fetch books
$stmt = $pdo->prepare("
    SELECT db.*, c.name AS category_name, COALESCE(a.name, db.author_name) AS author_display_name,
           p.name AS publisher_name
    FROM digital_books db
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN authors a ON a.id = db.author_id
    LEFT JOIN publishers p ON p.id = db.publisher_id
    $whereSql
    ORDER BY $orderSql
    LIMIT 60
");
$stmt->execute($params);
$digitalBooks = $stmt->fetchAll();

// Distinct languages available in database
$languages = $pdo->query("SELECT DISTINCT language FROM digital_books WHERE status='active' AND language IS NOT NULL ORDER BY language")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../includes/header.php';
?>

<!-- Virtual Library Hero Banner -->
<div class="virtual-hero card" style="background: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #1e293b 100%); color:#fff; border:none; margin-bottom:24px; padding:28px 32px; position:relative; overflow:hidden;">
    <div style="position:relative; z-index:2; max-width:700px;">
        <div style="display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,0.12); padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:12px;">
            <i class="fa-solid fa-cloud-bolt text-warning"></i> Campus Virtual Library
        </div>
        <h2 style="font-size:26px; font-weight:800; margin:0 0 10px 0; color:#fff;">Digital Archives & Interactive E-Books</h2>
        <p style="font-size:14.5px; opacity:0.85; margin:0 0 18px 0; line-height:1.5;">
            Access free curriculum textbooks, academic papers, manga, sci-fi and literature online. Real-time bookmarking and automatic reading progress tracking included.
        </p>
        <div class="flex gap-3" style="flex-wrap:wrap;">
            <a href="#browseSection" class="btn btn-primary" style="background:#6366f1; border-color:#6366f1;"><i class="fa-solid fa-magnifying-glass"></i> Explore All Digital Books</a>
            <a href="reading-history.php" class="btn btn-hero-glass"><i class="fa-solid fa-clock-rotate-left"></i> My Reading History</a>
            <a href="favorites.php" class="btn btn-hero-glass"><i class="fa-solid fa-heart"></i> My Favorites</a>
        </div>
    </div>
    <i class="fa-solid fa-book-journal-whills" style="position:absolute; right:20px; bottom:-30px; font-size:220px; color:rgba(255,255,255,0.04); pointer-events:none;"></i>
</div>

<!-- Continue Reading Shelf (If In Progress) -->
<?php if (!empty($continueReading)): ?>
<div style="margin-bottom:28px;">
    <div class="flex items-center justify-between" style="margin-bottom:12px;">
        <h3 style="font-size:18px; margin:0; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-bookmark text-primary"></i> Continue Reading
        </h3>
        <a href="reading-history.php" style="font-size:13px; font-weight:600;">View All History &rarr;</a>
    </div>
    <div class="grid grid-4">
        <?php foreach ($continueReading as $cr): ?>
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between; border-left:4px solid var(--primary);">
                <div>
                    <div class="flex justify-between items-center" style="margin-bottom:8px;">
                        <span class="badge badge-blue" style="font-size:11px;"><?= e($cr['category_name']) ?></span>
                        <span class="text-muted" style="font-size:11.5px;"><i class="fa-regular fa-clock"></i> <?= date('M d', strtotime($cr['last_accessed_at'])) ?></span>
                    </div>
                    <strong style="font-size:14px; display:block; margin-bottom:4px; line-height:1.3;"><?= e($cr['title']) ?></strong>
                    <div class="text-muted" style="font-size:12px; margin-bottom:12px;"><?= e($cr['author_name']) ?></div>
                    
                    <!-- Progress bar -->
                    <div style="background:var(--border); border-radius:10px; height:6px; overflow:hidden; margin-bottom:6px;">
                        <div style="background:var(--primary); width:<?= (float)$cr['progress_percent'] ?>%; height:100%;"></div>
                    </div>
                    <div class="flex justify-between text-muted" style="font-size:11.5px; margin-bottom:14px;">
                        <span>Page <?= (int)$cr['last_page'] ?> of <?= (int)$cr['total_pages'] ?></span>
                        <strong><?= round((float)$cr['progress_percent']) ?>%</strong>
                    </div>
                </div>
                <a href="read-book.php?id=<?= $cr['digital_book_id'] ?>" class="btn btn-sm btn-primary" style="width:100%; justify-content:center;">
                    <i class="fa-solid fa-book-open-reader"></i> Resume Reading
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Category Genre Filter Carousel / Pills -->
<div style="margin-bottom:20px;" id="browseSection">
    <div class="category-chips-wrap" style="display:flex; gap:8px; overflow-x:auto; padding-bottom:8px; -webkit-overflow-scrolling:touch;">
        <a href="virtual-library.php" class="chip <?= empty($categoryFilter) ? 'active' : '' ?>">
            <i class="fa-solid fa-border-all"></i> All Genres
        </a>
        <?php foreach ($allCategories as $c): ?>
            <a href="virtual-library.php?category=<?= urlencode($c['name']) ?>" class="chip <?= $categoryFilter === $c['name'] ? 'active' : '' ?>">
                <?= e($c['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Search & Advanced Filter Toolbar -->
<div class="card" style="margin-bottom:24px; padding:16px;">
    <form method="GET" action="virtual-library.php" class="flex gap-2" style="flex-wrap:wrap; align-items:center;">
        <?php if (!empty($categoryFilter)): ?>
            <input type="hidden" name="category" value="<?= e($categoryFilter) ?>">
        <?php endif; ?>
        
        <!-- Search bar -->
        <div style="position:relative; flex:1; min-width:260px;">
            <i class="fa-solid fa-magnifying-glass text-muted" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:14px;"></i>
            <input type="text" name="q" class="form-control" style="padding-left:36px; width:100%;" placeholder="Search digital titles, authors, keywords..." value="<?= e($search) ?>">
        </div>

        <!-- Language Filter -->
        <select name="language" class="form-control" style="width:150px;">
            <option value="">All Languages</option>
            <?php foreach ($languages as $lang): ?>
                <option value="<?= e($lang) ?>" <?= $languageFilter === $lang ? 'selected' : '' ?>><?= e($lang) ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Access Type Filter -->
        <select name="access" class="form-control" style="width:150px;">
            <option value="">Access Policy</option>
            <option value="free" <?= $accessFilter === 'free' ? 'selected' : '' ?>>Free / Student</option>
            <option value="open_access" <?= $accessFilter === 'open_access' ? 'selected' : '' ?>>Open Access</option>
            <option value="restricted" <?= $accessFilter === 'restricted' ? 'selected' : '' ?>>Restricted</option>
        </select>

        <!-- Sort By -->
        <select name="sort" class="form-control" style="width:160px;">
            <option value="popular" <?= $sortBy === 'popular' ? 'selected' : '' ?>>Most Popular</option>
            <option value="views" <?= $sortBy === 'views' ? 'selected' : '' ?>>Most Viewed</option>
            <option value="recent" <?= $sortBy === 'recent' ? 'selected' : '' ?>>Recently Added</option>
            <option value="title" <?= $sortBy === 'title' ? 'selected' : '' ?>>Title (A-Z)</option>
            <option value="pages" <?= $sortBy === 'pages' ? 'selected' : '' ?>>Quick Reads</option>
        </select>

        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        <?php if ($search !== '' || $categoryFilter !== '' || $languageFilter !== '' || $accessFilter !== '' || $sortBy !== 'popular'): ?>
            <a href="virtual-library.php" class="btn btn-outline" title="Clear all filters"><i class="fa-solid fa-xmark"></i> Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Results Header -->
<div class="flex justify-between items-center" style="margin-bottom:16px;">
    <div style="font-size:15px; font-weight:600; color:var(--text);">
        Available Digital Resources <span class="text-muted" style="font-weight:400;">(<?= $totalMatching ?> <?= $totalMatching === 1 ? 'book' : 'books' ?> found)</span>
    </div>
    <?php if (!empty($categoryFilter)): ?>
        <div>Active Category: <span class="badge badge-blue"><?= e($categoryFilter) ?></span></div>
    <?php endif; ?>
</div>

<!-- Digital Book Cards Grid -->
<?php if (empty($digitalBooks)): ?>
    <div class="card" style="text-align:center; padding:48px 20px;">
        <div class="empty-state">
            <i class="fa-solid fa-book-open" style="font-size:42px; margin-bottom:12px; color:var(--text-muted);"></i>
            <h4 style="margin:0 0 6px 0;">No digital books found</h4>
            <p class="text-muted" style="margin:0 0 16px 0;">Try adjusting your keyword search, clearing category filters, or exploring other genres.</p>
            <a href="virtual-library.php" class="btn btn-primary">Reset Filters</a>
        </div>
    </div>
<?php else: ?>
    <div class="grid grid-3">
        <?php foreach ($digitalBooks as $b): 
            $isFav = in_array($b['id'], $userFavorites);
            $gradientColors = [
                'Computer Science' => 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)',
                'Technology'       => 'linear-gradient(135deg, #06b6d4 0%, #0e7490 100%)',
                'Science Fiction'  => 'linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%)',
                'Manga'            => 'linear-gradient(135deg, #ec4899 0%, #be185d 100%)',
                'Horror'           => 'linear-gradient(135deg, #475569 0%, #1e293b 100%)',
                'Education'        => 'linear-gradient(135deg, #10b981 0%, #047857 100%)',
                'Mathematics'      => 'linear-gradient(135deg, #f59e0b 0%, #b45309 100%)',
                'Self-Help'        => 'linear-gradient(135deg, #14b8a6 0%, #0f766e 100%)',
                'Biography'        => 'linear-gradient(135deg, #6366f1 0%, #4338ca 100%)',
                'Mystery'          => 'linear-gradient(135deg, #64748b 0%, #334155 100%)',
                'Fantasy'          => 'linear-gradient(135deg, #a855f7 0%, #7e22ce 100%)',
            ];
            $coverBg = $gradientColors[$b['category_name']] ?? 'linear-gradient(135deg, #4f46e5 0%, #3730a3 100%)';
        ?>
        <div class="card digital-book-card" style="display:flex; flex-direction:column; justify-content:space-between; padding:18px; transition:transform 0.2s, box-shadow 0.2s;">
            <div>
                <!-- Book Card Header: Cover Icon & Info -->
                <div style="display:flex; gap:14px; margin-bottom:12px;">
                    <div style="width:72px; height:96px; background:<?= $coverBg ?>; border-radius:8px; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#fff; flex-shrink:0; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); position:relative; overflow:hidden;">
                        <i class="fa-solid fa-file-pdf" style="font-size:26px; margin-bottom:4px;"></i>
                        <span style="font-size:10px; font-weight:700; letter-spacing:0.04em;">PDF</span>
                        <div style="position:absolute; bottom:0; left:0; right:0; background:rgba(0,0,0,0.25); font-size:9px; text-align:center; padding:2px 0;">
                            <?= (int)$b['pages'] ?> pgs
                        </div>
                    </div>

                    <div style="flex:1; min-width:0;">
                        <div class="flex justify-between items-start" style="gap:6px;">
                            <span class="badge badge-blue" style="font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:130px;">
                                <?= e($b['category_name'] ?: 'General') ?>
                            </span>
                            <button class="fav-toggle-btn <?= $isFav ? 'active' : '' ?>" data-id="<?= $b['id'] ?>" onclick="toggleFav(this, <?= $b['id'] ?>)" title="Save to Favorites" style="background:none; border:none; cursor:pointer; font-size:16px; color:<?= $isFav ? '#ef4444' : '#94a3b8' ?>; padding:0;">
                                <i class="<?= $isFav ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i>
                            </button>
                        </div>
                        <h4 style="margin:6px 0 2px 0; font-size:15px; font-weight:700; line-height:1.3; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">
                            <a href="read-book.php?id=<?= $b['id'] ?>" style="color:var(--text);"><?= e($b['title']) ?></a>
                        </h4>
                        <div class="text-muted" style="font-size:12.5px;"><?= e($b['author_display_name']) ?></div>
                    </div>
                </div>

                <!-- Description snippet -->
                <p class="text-muted" style="font-size:13px; line-height:1.45; margin:0 0 14px 0; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">
                    <?= e($b['description'] ?: 'Open access digital resource for college curriculum study and reference reading.') ?>
                </p>
            </div>

            <!-- Card Footer: Stats & Read Button -->
            <div>
                <div class="flex justify-between items-center text-muted" style="font-size:11.5px; border-top:1px solid var(--border); padding-top:10px; margin-bottom:12px;">
                    <span><i class="fa-solid fa-globe"></i> <?= e($b['language']) ?></span>
                    <span><i class="fa-solid fa-book-reader"></i> <?= (int)$b['read_count'] ?> readers</span>
                    <span class="badge badge-green" style="font-size:10px;"><?= e(strtoupper($b['access_type'])) ?></span>
                </div>

                <div class="flex gap-2">
                    <a href="read-book.php?id=<?= $b['id'] ?>" class="btn btn-primary" style="flex:1; justify-content:center;">
                        <i class="fa-solid fa-book-open"></i> Read Online
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
function toggleFav(btn, bookId) {
    fetch('save-progress.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'toggle_favorite',
            digital_book_id: bookId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const icon = btn.querySelector('i');
            if (data.is_favorite) {
                btn.classList.add('active');
                icon.className = 'fa-solid fa-heart';
                btn.style.color = '#ef4444';
            } else {
                btn.classList.remove('active');
                icon.className = 'fa-regular fa-heart';
                btn.style.color = '#94a3b8';
            }
        }
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
