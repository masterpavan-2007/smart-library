<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['librarian']);
$pageTitle = 'Students';
$currentPage = 'students.php';
$settings = getSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $roll = trim($_POST['roll_number'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $dept = trim($_POST['department'] ?? '');
    $year = trim($_POST['year'] ?? '');

    if ($name === '' || $email === '' || $roll === '') {
        setFlash('error', 'Name, email and roll number are required.');
    } else {
        try {
            $hash = password_hash('Password@123', PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?,?,?,'student','active')")->execute([$name, $email, $hash]);
            $userId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO students (user_id, roll_number, course, department, year, registration_date, max_books) VALUES (?,?,?,?,?,CURDATE(),?)")
                ->execute([$userId, $roll, $course, $dept, $year, $settings['max_books_per_student']]);
            $pdo->commit();
            setFlash('success', 'Student registered. Default password: Password@123');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setFlash('error', 'That email or roll number is already registered.');
        }
    }
    redirect('students.php');
}

$search = trim($_GET['q'] ?? '');
$where = ''; $params = [];
if ($search !== '') {
    $where = "WHERE u.name LIKE ? OR s.roll_number LIKE ?";
    $params = ["%$search%", "%$search%"];
}
$stmt = $pdo->prepare("
    SELECT s.*, u.name, u.email,
        (SELECT COUNT(*) FROM book_issues bi WHERE bi.student_id=s.id AND bi.status IN ('issued','overdue')) active_books,
        (SELECT COUNT(*) FROM book_issues bi WHERE bi.student_id=s.id) total_borrowed
    FROM students s JOIN users u ON u.id = s.user_id
    $where ORDER BY u.name");
$stmt->execute($params);
$students = $stmt->fetchAll();

$historyFor = null;
$history = [];
if (!empty($_GET['history'])) {
    $sid = (int)$_GET['history'];
    $s = $pdo->prepare("SELECT s.*, u.name FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
    $s->execute([$sid]);
    $historyFor = $s->fetch();
    $h = $pdo->prepare("
        SELECT bi.*, b.title FROM book_issues bi JOIN books b ON b.id=bi.book_id
        WHERE bi.student_id=? ORDER BY bi.id DESC");
    $h->execute([$sid]);
    $history = $h->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <form method="GET" class="flex gap-2">
        <input type="text" name="q" class="form-control" style="width:260px;" placeholder="Search name or roll no..." value="<?= e($search) ?>">
        <button class="btn btn-outline" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>
    <button class="btn btn-primary" data-modal-open="regModal"><i class="fa-solid fa-user-plus"></i> Register Student</button>
</div>

<?php if ($historyFor): ?>
<div class="card" style="margin-bottom:18px;">
    <h3>Borrowing History — <?= e($historyFor['name']) ?></h3>
    <?php if (!$history): ?>
        <div class="empty-state"><i class="fa-solid fa-book"></i>No borrowing history yet.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Book</th><th>Issue Date</th><th>Due Date</th><th>Return Date</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr><td><?= e($h['title']) ?></td><td><?= fmtDate($h['issue_date']) ?></td><td><?= fmtDate($h['due_date']) ?></td><td><?= fmtDate($h['return_date']) ?></td><td><?= statusBadge($h['status']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <?php if (!$students): ?>
        <div class="empty-state"><i class="fa-solid fa-user-graduate"></i>No students found.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Roll No.</th><th>Course</th><th>Active Books</th><th>Total Borrowed</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td><strong><?= e($s['name']) ?></strong><br><span class="text-muted"><?= e($s['email']) ?></span></td>
                    <td><?= e($s['roll_number']) ?></td>
                    <td><?= e($s['course']) ?></td>
                    <td><?= (int)$s['active_books'] ?></td>
                    <td><?= (int)$s['total_borrowed'] ?></td>
                    <td><a class="btn btn-sm btn-outline" href="students.php?history=<?= $s['id'] ?>"><i class="fa-solid fa-clock-rotate-left"></i> History</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="regModal">
    <div class="modal-box">
        <span class="modal-close" data-modal-close>&times;</span>
        <h3>Register Student</h3>
        <form method="POST" action="students.php">
            <?= csrfField() ?>
            <div class="form-group"><label>Full Name *</label><input class="form-control" name="name" required></div>
            <div class="form-group"><label>Email *</label><input class="form-control" type="email" name="email" required></div>
            <div class="form-group"><label>Roll Number *</label><input class="form-control" name="roll_number" required></div>
            <div class="form-row">
                <div class="form-group"><label>Course</label><input class="form-control" name="course"></div>
                <div class="form-group"><label>Department</label><input class="form-control" name="department"></div>
            </div>
            <div class="form-group"><label>Year</label><input class="form-control" name="year"></div>
            <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> Default password will be <strong>Password@123</strong></div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;">Register</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
