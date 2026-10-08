<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);

$pageTitle = 'Manage Books';
$currentPage = 'books.php';

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$authors    = $pdo->query("SELECT * FROM authors ORDER BY name")->fetchAll();
$publishers = $pdo->query("SELECT * FROM publishers ORDER BY name")->fetchAll();

// ---------- Handle Add / Edit / Delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'isbn' => trim($_POST['isbn'] ?? ''),
            'title' => trim($_POST['title'] ?? ''),
            'author_id' => $_POST['author_id'] ?: null,
            'category_id' => $_POST['category_id'] ?: null,
            'publisher_id' => $_POST['publisher_id'] ?: null,
            'publication_year' => $_POST['publication_year'] ?: null,
            'edition' => trim($_POST['edition'] ?? ''),
            'language' => trim($_POST['language'] ?? 'English'),
            'pages' => $_POST['pages'] ?: null,
            'price' => $_POST['price'] ?: 0,
            'shelf_number' => trim($_POST['shelf_number'] ?? ''),
            'total_copies' => max(1, (int)($_POST['total_copies'] ?? 1)),
            'description' => trim($_POST['description'] ?? ''),
        ];

        if ($data['isbn'] === '' || $data['title'] === '') {
            setFlash('error', 'ISBN and Title are required.');
        } else {
            if ($id > 0) {
                // Edit: adjust available_copies by the same delta as total_copies
                $old = $pdo->prepare("SELECT total_copies, available_copies FROM books WHERE id=?");
                $old->execute([$id]);
                $oldRow = $old->fetch();
                $delta = $data['total_copies'] - (int)$oldRow['total_copies'];
                $newAvailable = max(0, (int)$oldRow['available_copies'] + $delta);

                $stmt = $pdo->prepare("UPDATE books SET isbn=?, title=?, author_id=?, category_id=?, publisher_id=?,
                    publication_year=?, edition=?, language=?, pages=?, price=?, shelf_number=?, total_copies=?,
                    available_copies=?, description=? WHERE id=?");
                $stmt->execute([
                    $data['isbn'], $data['title'], $data['author_id'], $data['category_id'], $data['publisher_id'],
                    $data['publication_year'], $data['edition'], $data['language'], $data['pages'], $data['price'],
                    $data['shelf_number'], $data['total_copies'], $newAvailable, $data['description'], $id,
                ]);
                setFlash('success', 'Book updated successfully.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO books (isbn, title, author_id, category_id, publisher_id,
                    publication_year, edition, language, pages, price, shelf_number, total_copies, available_copies, description)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $data['isbn'], $data['title'], $data['author_id'], $data['category_id'], $data['publisher_id'],
                    $data['publication_year'], $data['edition'], $data['language'], $data['pages'], $data['price'],
                    $data['shelf_number'], $data['total_copies'], $data['total_copies'], $data['description'],
                ]);
                setFlash('success', 'Book added successfully.');
            }
        }
        redirect('books.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $check = $pdo->prepare("SELECT COUNT(*) FROM book_issues WHERE book_id=? AND status IN ('issued','overdue')");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete: this book currently has active issues.');
        } else {
            $pdo->prepare("DELETE FROM books WHERE id=?")->execute([$id]);
            setFlash('success', 'Book deleted.');
        }
        redirect('books.php');
    }
}

// ---------- Search / filter / pagination ----------
$search   = trim($_GET['q'] ?? '');
$catId    = $_GET['category'] ?? '';
$authId   = $_GET['author'] ?? '';
$availOnly = !empty($_GET['available']);
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 10;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(b.title LIKE ? OR b.isbn LIKE ? OR a.name LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($catId !== '') { $where[] = "b.category_id = ?"; $params[] = $catId; }
if ($authId !== '') { $where[] = "b.author_id = ?"; $params[] = $authId; }
if ($availOnly) { $where[] = "b.available_copies > 0"; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM books b LEFT JOIN authors a ON a.id=b.author_id $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

$sql = "SELECT b.*, a.name author_name, c.name category_name, p.name publisher_name
        FROM books b
        LEFT JOIN authors a ON a.id = b.author_id
        LEFT JOIN categories c ON c.id = b.category_id
        LEFT JOIN publishers p ON p.id = b.publisher_id
        $whereSql
        ORDER BY b.id DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();

// Editing?
$editBook = null;
if (!empty($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM books WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $editBook = $s->fetch();
}
$showForm = isset($_GET['action']) && $_GET['action'] === 'add' || $editBook;

include __DIR__ . '/../includes/header.php';
?>

<div class="table-toolbar">
    <form method="GET" class="flex gap-2" style="flex-wrap:wrap;">
        <input type="text" name="q" class="form-control" style="width:220px;" placeholder="Search title, ISBN, author..." value="<?= e($search) ?>">
        <select name="category" class="form-control" style="width:170px;">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $catId == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="author" class="form-control" style="width:170px;">
            <option value="">All Authors</option>
            <?php foreach ($authors as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $authId == $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="flex items-center gap-2" style="font-size:13px;">
            <input type="checkbox" name="available" value="1" <?= $availOnly ? 'checked' : '' ?>> Available only
        </label>
        <button class="btn btn-outline" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
    </form>
    <a href="books.php?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Book</a>
</div>

<div class="card">
    <?php if (!$books): ?>
        <div class="empty-state"><i class="fa-solid fa-book"></i>No books found. Try adjusting your filters or add a new book.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>Title</th><th>Author</th><th>Category</th><th>ISBN</th>
                <th>Shelf</th><th>Copies</th><th>Status</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($books as $b): ?>
                <tr>
                    <td><strong><?= e($b['title']) ?></strong><div class="text-muted" style="font-size:12px;"><?= e($b['publisher_name']) ?> &middot; <?= e($b['publication_year']) ?></div></td>
                    <td><?= e($b['author_name'] ?? '—') ?></td>
                    <td><?= e($b['category_name'] ?? '—') ?></td>
                    <td><?= e($b['isbn']) ?></td>
                    <td><?= e($b['shelf_number']) ?></td>
                    <td><?= (int)$b['available_copies'] ?> / <?= (int)$b['total_copies'] ?></td>
                    <td><?= statusBadge($b['available_copies'] > 0 ? 'available' : 'issued') ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline" href="books.php?edit=<?= $b['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                        <form method="POST" style="display:inline;" data-confirm="Delete this book? This cannot be undone.">
                            <?= csrfField() ?>
                            <input type="hidden" name="form_action" value="delete">
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="flex gap-2" style="margin-top:16px; justify-content:center;">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="btn btn-sm <?= $p == $page ? 'btn-primary' : 'btn-outline' ?>"
                   href="?page=<?= $p ?>&q=<?= urlencode($search) ?>&category=<?= e($catId) ?>&author=<?= e($authId) ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Add / Edit Modal -->
<div class="modal-overlay <?= $showForm ? 'open' : '' ?>" id="bookModal">
    <div class="modal-box">
        <span class="modal-close" onclick="window.location='books.php'">&times;</span>
        <h3><?= $editBook ? 'Edit Book' : 'Add New Book' ?></h3>
        <form method="POST" action="books.php">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="id" value="<?= e($editBook['id'] ?? '') ?>">
            <div class="form-row">
                <div class="form-group"><label>Title *</label><input class="form-control" name="title" required value="<?= e($editBook['title'] ?? '') ?>"></div>
                <div class="form-group"><label>ISBN *</label><input class="form-control" name="isbn" required value="<?= e($editBook['isbn'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Author</label>
                    <select class="form-control" name="author_id">
                        <option value="">-- Select --</option>
                        <?php foreach ($authors as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= (($editBook['author_id'] ?? null) == $a['id']) ? 'selected' : '' ?>><?= e($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Category</label>
                    <select class="form-control" name="category_id">
                        <option value="">-- Select --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= (($editBook['category_id'] ?? null) == $c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Publisher</label>
                    <select class="form-control" name="publisher_id">
                        <option value="">-- Select --</option>
                        <?php foreach ($publishers as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= (($editBook['publisher_id'] ?? null) == $p['id']) ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Publication Year</label><input class="form-control" type="number" name="publication_year" value="<?= e($editBook['publication_year'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Edition</label><input class="form-control" name="edition" value="<?= e($editBook['edition'] ?? '') ?>"></div>
                <div class="form-group"><label>Language</label><input class="form-control" name="language" value="<?= e($editBook['language'] ?? 'English') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Pages</label><input class="form-control" type="number" name="pages" value="<?= e($editBook['pages'] ?? '') ?>"></div>
                <div class="form-group"><label>Price (&#8377;)</label><input class="form-control" type="number" step="0.01" name="price" value="<?= e($editBook['price'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Shelf Number</label><input class="form-control" name="shelf_number" value="<?= e($editBook['shelf_number'] ?? '') ?>"></div>
                <div class="form-group"><label>Total Copies *</label><input class="form-control" type="number" min="1" name="total_copies" required value="<?= e($editBook['total_copies'] ?? 1) ?>"></div>
            </div>
            <div class="form-group"><label>Description</label><textarea class="form-control" name="description" rows="3"><?= e($editBook['description'] ?? '') ?></textarea></div>
            <button class="btn btn-primary" type="submit" style="width:100%; justify-content:center;">
                <i class="fa-solid fa-floppy-disk"></i> <?= $editBook ? 'Update Book' : 'Add Book' ?>
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
