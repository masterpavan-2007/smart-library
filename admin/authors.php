<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);
$pageTitle = 'Authors';
$currentPage = 'authors.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['form_action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        if ($name === '') {
            setFlash('error', 'Author name is required.');
        } elseif ($id) {
            $pdo->prepare("UPDATE authors SET name=?, bio=? WHERE id=?")->execute([$name, $bio, $id]);
            setFlash('success', 'Author updated.');
        } else {
            $pdo->prepare("INSERT INTO authors (name, bio) VALUES (?,?)")->execute([$name, $bio]);
            setFlash('success', 'Author added.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $check = $pdo->prepare("SELECT COUNT(*) FROM books WHERE author_id=?");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete: books are assigned to this author.');
        } else {
            $pdo->prepare("DELETE FROM authors WHERE id=?")->execute([$id]);
            setFlash('success', 'Author deleted.');
        }
    }
    redirect('authors.php');
}

$authors = $pdo->query("
    SELECT a.*, COUNT(b.id) book_count FROM authors a
    LEFT JOIN books b ON b.author_id = a.id
    GROUP BY a.id ORDER BY a.name")->fetchAll();

$editRow = null;
if (!empty($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM authors WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $editRow = $s->fetch();
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editRow;

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <input type="text" class="form-control" style="width:260px;" placeholder="Search authors..." data-search-input="authTable">
    <a href="authors.php?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Author</a>
</div>
<div class="card">
    <?php if (!$authors): ?>
        <div class="empty-state"><i class="fa-solid fa-feather"></i>No authors yet.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table" id="authTable">
            <thead><tr><th>Name</th><th>Bio</th><th>Books</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($authors as $a): ?>
                <tr>
                    <td><strong><?= e($a['name']) ?></strong></td>
                    <td class="text-muted"><?= e(mb_strimwidth($a['bio'] ?? '', 0, 60, '...')) ?></td>
                    <td><?= (int)$a['book_count'] ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline" href="authors.php?edit=<?= $a['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                        <form method="POST" style="display:inline;" data-confirm="Delete this author?">
                            <?= csrfField() ?><input type="hidden" name="form_action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>">
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
        <span class="modal-close" onclick="window.location='authors.php'">&times;</span>
        <h3><?= $editRow ? 'Edit Author' : 'Add Author' ?></h3>
        <form method="POST" action="authors.php">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="id" value="<?= e($editRow['id'] ?? '') ?>">
            <div class="form-group"><label>Name *</label><input class="form-control" name="name" required value="<?= e($editRow['name'] ?? '') ?>"></div>
            <div class="form-group"><label>Bio</label><textarea class="form-control" name="bio" rows="3"><?= e($editRow['bio'] ?? '') ?></textarea></div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
