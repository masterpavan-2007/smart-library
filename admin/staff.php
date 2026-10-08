<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);
$pageTitle = 'Manage Staff';
$currentPage = 'staff.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $designation = trim($_POST['designation'] ?? 'Librarian');
        $shift = trim($_POST['shift'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if ($name === '' || $email === '') {
            setFlash('error', 'Name and email are required.');
            redirect('staff.php');
        }

        try {
            if ($id) {
                $st = $pdo->prepare("SELECT user_id FROM staff WHERE id=?");
                $st->execute([$id]);
                $userId = $st->fetchColumn();
                $pdo->prepare("UPDATE users SET name=?, email=?, phone=?, status=? WHERE id=?")->execute([$name, $email, $phone, $status, $userId]);
                $pdo->prepare("UPDATE staff SET designation=?, shift=? WHERE id=?")->execute([$designation, $shift, $id]);
                setFlash('success', 'Staff member updated.');
            } else {
                $password = $_POST['password'] ?: 'Password@123';
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO users (name, email, password, role, phone, status) VALUES (?,?,?,'librarian',?,?)")
                    ->execute([$name, $email, $hash, $phone, $status]);
                $userId = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO staff (user_id, designation, shift, joining_date) VALUES (?,?,?,CURDATE())")
                    ->execute([$userId, $designation, $shift]);
                $pdo->commit();
                setFlash('success', 'Staff member added.');
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setFlash('error', 'That email is already registered.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT user_id FROM staff WHERE id=?");
        $st->execute([$id]);
        $userId = $st->fetchColumn();
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
        setFlash('success', 'Staff member removed.');
    }
    redirect('staff.php');
}

$staff = $pdo->query("SELECT st.*, u.name, u.email, u.phone, u.status FROM staff st JOIN users u ON u.id=st.user_id ORDER BY st.id DESC")->fetchAll();

$editRow = null;
if (!empty($_GET['edit'])) {
    $s = $pdo->prepare("SELECT st.*, u.name, u.email, u.phone, u.status FROM staff st JOIN users u ON u.id=st.user_id WHERE st.id=?");
    $s->execute([(int)$_GET['edit']]);
    $editRow = $s->fetch();
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editRow;

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <div></div>
    <a href="staff.php?action=add" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Add Staff</a>
</div>
<div class="card">
    <?php if (!$staff): ?>
        <div class="empty-state"><i class="fa-solid fa-user-tie"></i>No staff members yet.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Designation</th><th>Shift</th><th>Contact</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($staff as $s): ?>
                <tr>
                    <td><strong><?= e($s['name']) ?></strong></td>
                    <td><?= e($s['designation']) ?></td>
                    <td><?= e($s['shift']) ?></td>
                    <td><?= e($s['email']) ?><br><span class="text-muted"><?= e($s['phone']) ?></span></td>
                    <td><?= statusBadge($s['status']) ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline" href="staff.php?edit=<?= $s['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                        <form method="POST" style="display:inline;" data-confirm="Remove this staff member?">
                            <?= csrfField() ?><input type="hidden" name="form_action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
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
        <span class="modal-close" onclick="window.location='staff.php'">&times;</span>
        <h3><?= $editRow ? 'Edit Staff Member' : 'Add Staff Member' ?></h3>
        <form method="POST" action="staff.php">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="id" value="<?= e($editRow['id'] ?? '') ?>">
            <div class="form-row">
                <div class="form-group"><label>Full Name *</label><input class="form-control" name="name" required value="<?= e($editRow['name'] ?? '') ?>"></div>
                <div class="form-group"><label>Email *</label><input class="form-control" type="email" name="email" required value="<?= e($editRow['email'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Mobile Number</label><input class="form-control" name="phone" value="<?= e($editRow['phone'] ?? '') ?>"></div>
                <div class="form-group"><label>Designation</label><input class="form-control" name="designation" value="<?= e($editRow['designation'] ?? 'Librarian') ?>"></div>
            </div>
            <div class="form-group"><label>Shift</label><input class="form-control" name="shift" value="<?= e($editRow['shift'] ?? '') ?>"></div>
            <?php if (!$editRow): ?>
            <div class="form-group"><label>Password (optional — defaults to Password@123)</label><input class="form-control" type="text" name="password"></div>
            <?php else: ?>
            <div class="form-group"><label>Status</label>
                <select class="form-control" name="status">
                    <option value="active" <?= ($editRow['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($editRow['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
