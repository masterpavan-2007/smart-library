<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);
$pageTitle = 'Manage Students';
$currentPage = 'students.php';
$settings = getSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $roll = trim($_POST['roll_number'] ?? '');
        $course = trim($_POST['course'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        $year = trim($_POST['year'] ?? '');
        $division = trim($_POST['division'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if ($name === '' || $email === '' || $roll === '') {
            setFlash('error', 'Name, email and roll number are required.');
            redirect('students.php');
        }

        try {
            if ($id) {
                $stu = $pdo->prepare("SELECT user_id FROM students WHERE id=?");
                $stu->execute([$id]);
                $userId = $stu->fetchColumn();
                $pdo->prepare("UPDATE users SET name=?, email=?, phone=?, status=? WHERE id=?")
                    ->execute([$name, $email, $phone, $status, $userId]);
                $pdo->prepare("UPDATE students SET roll_number=?, course=?, department=?, year=?, division=?, address=? WHERE id=?")
                    ->execute([$roll, $course, $dept, $year, $division, $address, $id]);
                setFlash('success', 'Student updated.');
            } else {
                $password = $_POST['password'] ?: 'Password@123';
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO users (name, email, password, role, phone, status) VALUES (?,?,?,'student',?,?)")
                    ->execute([$name, $email, $hash, $phone, $status]);
                $userId = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO students (user_id, roll_number, course, department, year, division, address, registration_date, max_books)
                    VALUES (?,?,?,?,?,?,?,CURDATE(),?)")
                    ->execute([$userId, $roll, $course, $dept, $year, $division, $address, $settings['max_books_per_student']]);
                $pdo->commit();
                setFlash('success', 'Student registered. Default password: ' . ($_POST['password'] ? '(custom)' : 'Password@123'));
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setFlash('error', 'That email or roll number is already registered.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stu = $pdo->prepare("SELECT user_id FROM students WHERE id=?");
        $stu->execute([$id]);
        $userId = $stu->fetchColumn();
        $check = $pdo->prepare("SELECT COUNT(*) FROM book_issues WHERE student_id=? AND status IN ('issued','overdue')");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete: student has active book issues.');
        } else {
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userId]); // cascades to students
            setFlash('success', 'Student removed.');
        }
    }
    redirect('students.php');
}

$search = trim($_GET['q'] ?? '');
$where = ''; $params = [];
if ($search !== '') {
    $where = "WHERE u.name LIKE ? OR u.email LIKE ? OR s.roll_number LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}
$stmt = $pdo->prepare("
    SELECT s.*, u.name, u.email, u.phone, u.status,
        (SELECT COUNT(*) FROM book_issues bi WHERE bi.student_id=s.id AND bi.status IN ('issued','overdue')) active_books,
        (SELECT COALESCE(SUM(amount),0) FROM fines f WHERE f.student_id=s.id AND f.status='pending') pending_fines
    FROM students s JOIN users u ON u.id = s.user_id
    $where ORDER BY s.id DESC");
$stmt->execute($params);
$students = $stmt->fetchAll();

$editRow = null;
if (!empty($_GET['edit'])) {
    $s = $pdo->prepare("SELECT s.*, u.name, u.email, u.phone, u.status FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
    $s->execute([(int)$_GET['edit']]);
    $editRow = $s->fetch();
}
$showForm = (isset($_GET['action']) && $_GET['action'] === 'add') || $editRow;

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <form method="GET" class="flex gap-2">
        <input type="text" name="q" class="form-control" style="width:260px;" placeholder="Search name, email, roll no..." value="<?= e($search) ?>">
        <button class="btn btn-outline" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>
    <a href="students.php?action=add" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Add Student</a>
</div>

<div class="card">
    <?php if (!$students): ?>
        <div class="empty-state"><i class="fa-solid fa-user-graduate"></i>No students found.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Roll No.</th><th>Course</th><th>Contact</th><th>Active Books</th><th>Pending Fines</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td><strong><?= e($s['name']) ?></strong></td>
                    <td><?= e($s['roll_number']) ?></td>
                    <td><?= e($s['course']) ?> <span class="text-muted">(<?= e($s['year']) ?> <?= e($s['division']) ?>)</span></td>
                    <td><?= e($s['email']) ?><br><span class="text-muted"><?= e($s['phone']) ?></span></td>
                    <td><?= (int)$s['active_books'] ?></td>
                    <td><?= $s['pending_fines'] > 0 ? '&#8377;' . number_format($s['pending_fines'], 2) : '—' ?></td>
                    <td><?= statusBadge($s['status']) ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline" href="students.php?edit=<?= $s['id'] ?>"><i class="fa-solid fa-pen"></i></a>
                        <form method="POST" style="display:inline;" data-confirm="Remove this student?">
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
        <span class="modal-close" onclick="window.location='students.php'">&times;</span>
        <h3><?= $editRow ? 'Edit Student' : 'Register Student' ?></h3>
        <form method="POST" action="students.php">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="id" value="<?= e($editRow['id'] ?? '') ?>">
            <div class="form-row">
                <div class="form-group"><label>Full Name *</label><input class="form-control" name="name" required value="<?= e($editRow['name'] ?? '') ?>"></div>
                <div class="form-group"><label>Email *</label><input class="form-control" type="email" name="email" required value="<?= e($editRow['email'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Roll Number *</label><input class="form-control" name="roll_number" required value="<?= e($editRow['roll_number'] ?? '') ?>"></div>
                <div class="form-group"><label>Mobile Number</label><input class="form-control" name="phone" value="<?= e($editRow['phone'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Course</label><input class="form-control" name="course" value="<?= e($editRow['course'] ?? '') ?>"></div>
                <div class="form-group"><label>Department</label><input class="form-control" name="department" value="<?= e($editRow['department'] ?? '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Year</label><input class="form-control" name="year" value="<?= e($editRow['year'] ?? '') ?>"></div>
                <div class="form-group"><label>Division</label><input class="form-control" name="division" value="<?= e($editRow['division'] ?? '') ?>"></div>
            </div>
            <div class="form-group"><label>Address</label><input class="form-control" name="address" value="<?= e($editRow['address'] ?? '') ?>"></div>
            <?php if (!$editRow): ?>
            <div class="form-group"><label>Password (optional — defaults to Password@123)</label><input class="form-control" type="text" name="password" placeholder="Leave blank for default"></div>
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
