<?php
/**
 * Librarian Digital Book Catalog & Usage Monitor
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['librarian', 'admin']);
$pageTitle = 'Digital Books Catalog';
$currentPage = 'digital-books.php';

// Search and filter parameters
$search = trim($_GET['q'] ?? '');
$catId = trim($_GET['category'] ?? '');
$accessFilter = trim($_GET['access'] ?? '');

$where = ["db.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = "(db.title LIKE ? OR a.name LIKE ? OR db.author_name LIKE ? OR db.description LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term]);
}
if ($catId !== '') {
    $where[] = "db.category_id = ?";
    $params[] = $catId;
}
if ($accessFilter !== '') {
    $where[] = "db.access_type = ?";
    $params[] = $accessFilter;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT db.*, c.name AS category_name, COALESCE(a.name, db.author_name) AS author_name,
           (SELECT COUNT(*) FROM reading_history WHERE digital_book_id = db.id) AS total_readers
    FROM digital_books db
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN authors a ON a.id = db.author_id
    $whereSql
    ORDER BY db.title ASC
");
$stmt->execute($params);
$books = $stmt->fetchAll();

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// Summary stats
$totalDigital = (int)$pdo->query("SELECT COUNT(*) FROM digital_books WHERE status='active'")->fetchColumn();
$totalViews = (int)$pdo->query("SELECT COALESCE(SUM(view_count),0) FROM digital_books")->fetchColumn();
$activeReaders = (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM reading_history")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-3" style="margin-bottom:20px;">
    <div class="card stat-card">
        <div class="icon blue"><i class="fa-solid fa-file-pdf"></i></div>
        <div><div class="num"><?= $totalDigital ?></div><div class="label">Digital Resources</div></div>
    </div>
    <div class="card stat-card">
        <div class="icon green"><i class="fa-solid fa-eye"></i></div>
        <div><div class="num"><?= $totalViews ?></div><div class="label">Online Views</div></div>
    </div>
    <div class="card stat-card">
        <div class="icon yellow"><i class="fa-solid fa-users"></i></div>
        <div><div class="num"><?= $activeReaders ?></div><div class="label">Active Student Readers</div></div>
    </div>
</div>

<div class="table-toolbar">
    <form method="GET" class="flex gap-2" style="flex-wrap:wrap;">
        <input type="text" name="q" class="form-control" style="width:260px;" placeholder="Search title, author, keyword..." value="<?= e($search) ?>">
        <select name="category" class="form-control" style="width:180px;">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $catId == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="access" class="form-control" style="width:150px;">
            <option value="">All Access Types</option>
            <option value="free" <?= $accessFilter === 'free' ? 'selected' : '' ?>>Free</option>
            <option value="open_access" <?= $accessFilter === 'open_access' ? 'selected' : '' ?>>Open Access</option>
            <option value="restricted" <?= $accessFilter === 'restricted' ? 'selected' : '' ?>>Restricted</option>
        </select>
        <button class="btn btn-outline" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        <?php if ($search || $catId || $accessFilter): ?>
            <a href="digital-books.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title & Author</th>
                    <th>Category</th>
                    <th>Pages / Language</th>
                    <th>Access Policy</th>
                    <th>Usage Statistics</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($books)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:30px;" class="text-muted">No digital resources found.</td></tr>
                <?php else: ?>
                    <?php foreach ($books as $b): ?>
                        <tr>
                            <td>
                                <strong><?= e($b['title']) ?></strong>
                                <div class="text-muted" style="font-size:12px;"><?= e($b['author_name']) ?> (<?= e($b['publication_year'] ?: '—') ?>)</div>
                            </td>
                            <td><span class="badge badge-blue"><?= e($b['category_name'] ?: 'General') ?></span></td>
                            <td><?= (int)$b['pages'] ?> pages &middot; <?= e($b['language']) ?></td>
                            <td><span class="badge badge-green"><?= strtoupper(e($b['access_type'])) ?></span></td>
                            <td>
                                <div style="font-size:12px;"><i class="fa-solid fa-eye text-muted"></i> <?= (int)$b['view_count'] ?> views</div>
                                <div style="font-size:12px;"><i class="fa-solid fa-book-reader text-muted"></i> <?= (int)$b['total_readers'] ?> student readers</div>
                            </td>
                            <td>
                                <a href="<?= basePath() ?>/student/read-book.php?id=<?= $b['id'] ?>" target="_blank" class="btn btn-sm btn-primary">
                                    <i class="fa-solid fa-book-open"></i> Read Online
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
