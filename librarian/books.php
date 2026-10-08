<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['librarian']);
$pageTitle = 'Search Books';
$currentPage = 'books.php';

$search = trim($_GET['q'] ?? '');
$where = ''; $params = [];
if ($search !== '') {
    $where = "WHERE b.title LIKE ? OR b.isbn LIKE ? OR a.name LIKE ? OR c.name LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}
$stmt = $pdo->prepare("
    SELECT b.*, a.name author_name, c.name category_name
    FROM books b LEFT JOIN authors a ON a.id=b.author_id LEFT JOIN categories c ON c.id=b.category_id
    $where ORDER BY b.title LIMIT 100");
$stmt->execute($params);
$books = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <form method="GET" class="flex gap-2">
        <input type="text" name="q" class="form-control" style="width:300px;" placeholder="Search by title, ISBN, author, category..." value="<?= e($search) ?>">
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
    </form>
</div>
<div class="card">
    <?php if (!$books): ?>
        <div class="empty-state"><i class="fa-solid fa-book"></i>No books found.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Title</th><th>Author</th><th>Category</th><th>Shelf</th><th>Availability</th></tr></thead>
            <tbody>
            <?php foreach ($books as $b): ?>
                <tr>
                    <td><strong><?= e($b['title']) ?></strong><div class="text-muted" style="font-size:12px;">ISBN: <?= e($b['isbn']) ?></div></td>
                    <td><?= e($b['author_name'] ?? '—') ?></td>
                    <td><?= e($b['category_name'] ?? '—') ?></td>
                    <td><?= e($b['shelf_number']) ?></td>
                    <td><?= (int)$b['available_copies'] ?> / <?= (int)$b['total_copies'] ?> <?= statusBadge($b['available_copies'] > 0 ? 'available' : 'issued') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
