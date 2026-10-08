<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);
$pageTitle = 'Categories';
$currentPage = 'categories.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['form_action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '') {
            setFlash('error', 'Category name is required.');
        } elseif ($id) {
            $pdo->prepare("UPDATE categories SET name=?, description=? WHERE id=?")->execute([$name, $desc, $id]);
            setFlash('success', 'Category updated.');
        } else {
            try {
                $pdo->prepare("INSERT INTO categories (name, description) VALUES (?,?)")->execute([$name, $desc]);
                setFlash('success', 'Category added.');
            } catch (PDOException $e) {
                setFlash('error', 'A category with that name already exists.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $check = $pdo->prepare("SELECT COUNT(*) FROM books WHERE category_id=?");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete: books are assigned to this category.');
        } else {
            $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
            setFlash('success', 'Category deleted.');
        }
    }
    redirect('categories.php');
}

$categories = $pdo->query("
    SELECT c.*, COUNT(b.id) book_count FROM categories c
    LEFT JOIN books b ON b.category_id = c.id
    GROUP BY c.id ORDER BY c.name")->fetchAll();

$editRow = null;
if (!empty($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM categories WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $editRow = $s->fetch();
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editRow;

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <input type="text" class="form-control" style="width:260px;" placeholder="Search categories..." data-search-input="catTable">
    <a href="categories.php?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Category</a>
</div>
<div class="card">
    <?php if (!$categories): ?>
        <div class="empty-state"><i class="fa-solid fa-tags"></i>No categories yet.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table" id="catTable">
            <thead><tr><th>Name</th><th>Description</th><th>Books</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><strong><?= e($c['name']) ?></strong></td>
                    <td class="text-muted"><?= e($c['description']) ?></td>
                    <td><?= (int)$c['book_count'] ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline" href="categories.php?edit=<?= $c['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                        <form method="POST" style="display:inline;" data-confirm="Delete this category?">
                            <?= csrfField() ?><input type="hidden" name="form_action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="modal-overlay <?= $showForm ? 'open' : '' ?>">
    <div class="modal-box">
        <span class="modal-close" onclick="window.location='categories.php'">&times;</span>
        <h3><?= $editRow ? 'Edit Category' : 'Add Category' ?></h3>
        <form method="POST" action="categories.php">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="id" value="<?= e($editRow['id'] ?? '') ?>">
            <div class="form-group"><label>Name *</label><input class="form-control" name="name" required value="<?= e($editRow['name'] ?? '') ?>"></div>
            <div class="form-group"><label>Description</label><textarea class="form-control" name="description" rows="3"><?= e($editRow['description'] ?? '') ?></textarea></div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
