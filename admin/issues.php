<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);
$pageTitle = 'Issued Books';
$currentPage = 'issues.php';
$settings = getSettings($pdo);

// ---------- Issue a new book (admin can also do this directly) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $studentId = (int)($_POST['student_id'] ?? 0);
    $bookId    = (int)($_POST['book_id'] ?? 0);

    $stu = $pdo->prepare("SELECT s.*, u.name FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
    $stu->execute([$studentId]);
    $student = $stu->fetch();
    $bk = $pdo->prepare("SELECT * FROM books WHERE id=?");
    $bk->execute([$bookId]);
    $book = $bk->fetch();

    if (!$student || !$book) {
        setFlash('error', 'Please select a valid student and book.');
    } elseif ((int)$book['available_copies'] <= 0) {
        setFlash('error', 'Book is currently unavailable.');
    } else {
        $activeStmt = $pdo->prepare("SELECT COUNT(*) FROM book_issues WHERE student_id=? AND status IN ('issued','overdue')");
        $activeStmt->execute([$studentId]);
        if ((int)$activeStmt->fetchColumn() >= (int)$student['max_books']) {
            setFlash('error', $student['name'] . ' has reached their borrowing limit.');
        } else {
            $issueDate = date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime("+{$settings['borrow_period_days']} days"));
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO book_issues (student_id, book_id, issued_by, issue_date, due_date, status) VALUES (?,?,?,?,?, 'issued')")
                ->execute([$studentId, $bookId, $_SESSION['user_id'], $issueDate, $dueDate]);
            $pdo->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE id=?")->execute([$bookId]);
            $pdo->commit();
            notify($pdo, $student['user_id'], 'Book Issued', '"' . $book['title'] . '" has been issued to you. Due on ' . fmtDate($dueDate) . '.');
            setFlash('success', 'Book issued successfully.');
        }
    }
    redirect('issues.php');
}

$statusFilter = $_GET['status'] ?? 'all';
$where = '';
if ($statusFilter === 'overdue') {
    $where = "WHERE bi.status='issued' AND bi.due_date < CURDATE()";
} elseif (in_array($statusFilter, ['issued','returned','lost'], true)) {
    $where = "WHERE bi.status = " . $pdo->quote($statusFilter);
}

$issues = $pdo->query("
    SELECT bi.*, b.title, s.roll_number, u.name student_name,
        DATEDIFF(CURDATE(), bi.due_date) late_days
    FROM book_issues bi
    JOIN books b ON b.id=bi.book_id
    JOIN students s ON s.id=bi.student_id
    JOIN users u ON u.id=s.user_id
    $where
    ORDER BY bi.id DESC LIMIT 100")->fetchAll();

$students = $pdo->query("SELECT s.id, s.roll_number, u.name FROM students s JOIN users u ON u.id=s.user_id ORDER BY u.name")->fetchAll();
$availableBooks = $pdo->query("SELECT id, title, available_copies FROM books WHERE available_copies > 0 ORDER BY title")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <div class="filters">
        <a class="btn btn-sm <?= $statusFilter==='all'?'btn-primary':'btn-outline' ?>" href="?status=all">All</a>
        <a class="btn btn-sm <?= $statusFilter==='issued'?'btn-primary':'btn-outline' ?>" href="?status=issued">Issued</a>
        <a class="btn btn-sm <?= $statusFilter==='overdue'?'btn-primary':'btn-outline' ?>" href="?status=overdue">Overdue</a>
        <a class="btn btn-sm <?= $statusFilter==='returned'?'btn-primary':'btn-outline' ?>" href="?status=returned">Returned</a>
    </div>
    <button class="btn btn-primary" data-modal-open="issueModal"><i class="fa-solid fa-plus"></i> Issue Book</button>
</div>

<div class="card">
    <?php if (!$issues): ?>
        <div class="empty-state"><i class="fa-solid fa-book"></i>No records found for this filter.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>ID</th><th>Student</th><th>Book</th><th>Issue Date</th><th>Due Date</th><th>Return Date</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($issues as $r):
                $displayStatus = ($r['status'] === 'issued' && strtotime($r['due_date']) < strtotime(date('Y-m-d'))) ? 'overdue' : $r['status'];
            ?>
                <tr>
                    <td>#<?= $r['id'] ?></td>
                    <td><?= e($r['student_name']) ?> <span class="text-muted">(<?= e($r['roll_number']) ?>)</span></td>
                    <td><?= e($r['title']) ?></td>
                    <td><?= fmtDate($r['issue_date']) ?></td>
                    <td><?= fmtDate($r['due_date']) ?></td>
                    <td><?= fmtDate($r['return_date']) ?></td>
                    <td><?= statusBadge($displayStatus) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="issueModal">
    <div class="modal-box">
        <span class="modal-close" data-modal-close>&times;</span>
        <h3>Issue a Book</h3>
        <form method="POST" action="issues.php">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Student *</label>
                <select class="form-control" name="student_id" required>
                    <option value="">-- Select Student --</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= e($s['name']) ?> (<?= e($s['roll_number']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Book *</label>
                <select class="form-control" name="book_id" required>
                    <option value="">-- Select Book --</option>
                    <?php foreach ($availableBooks as $b): ?>
                        <option value="<?= $b['id'] ?>"><?= e($b['title']) ?> (<?= (int)$b['available_copies'] ?> available)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;">Issue Book</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
