<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);
$pageTitle = 'Publishers';
$currentPage = 'publishers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['form_action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        if ($name === '') {
            setFlash('error', 'Publisher name is required.');
        } elseif ($id) {
            $pdo->prepare("UPDATE publishers SET name=?, address=? WHERE id=?")->execute([$name, $address, $id]);
            setFlash('success', 'Publisher updated.');
        } else {
            $pdo->prepare("INSERT INTO publishers (name, address) VALUES (?,?)")->execute([$name, $address]);
            setFlash('success', 'Publisher added.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $check = $pdo->prepare("SELECT COUNT(*) FROM books WHERE publisher_id=?");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete: books are assigned to this publisher.');
        } else {
            $pdo->prepare("DELETE FROM publishers WHERE id=?")->execute([$id]);
            setFlash('success', 'Publisher deleted.');
        }
    }
    redirect('publishers.php');
}

$publishers = $pdo->query("
    SELECT p.*, COUNT(b.id) book_count FROM publishers p
    LEFT JOIN books b ON b.publisher_id = p.id
    GROUP BY p.id ORDER BY p.name")->fetchAll();

$editRow = null;
if (!empty($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM publishers WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $editRow = $s->fetch();
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editRow;

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <input type="text" class="form-control" style="width:260px;" placeholder="Search publishers..." data-search-input="pubTable">
    <a href="publishers.php?action=add" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Publisher</a>
</div>
<div class="card">
    <?php if (!$publishers): ?>
        <div class="empty-state"><i class="fa-solid fa-building"></i>No publishers yet.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table" id="pubTable">
            <thead><tr><th>Name</th><th>Address</th><th>Books</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($publishers as $p): ?>
                <tr>
                    <td><strong><?= e($p['name']) ?></strong></td>
                    <td class="text-muted"><?= e($p['address']) ?></td>
                    <td><?= (int)$p['book_count'] ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline" href="publishers.php?edit=<?= $p['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                        <form method="POST" style="display:inline;" data-confirm="Delete this publisher?">
                            <?= csrfField() ?><input type="hidden" name="form_action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
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
        <span class="modal-close" onclick="window.location='publishers.php'">&times;</span>
        <h3><?= $editRow ? 'Edit Publisher' : 'Add Publisher' ?></h3>
        <form method="POST" action="publishers.php">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="id" value="<?= e($editRow['id'] ?? '') ?>">
            <div class="form-group"><label>Name *</label><input class="form-control" name="name" required value="<?= e($editRow['name'] ?? '') ?>"></div>
            <div class="form-group"><label>Address</label><input class="form-control" name="address" value="<?= e($editRow['address'] ?? '') ?>"></div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
