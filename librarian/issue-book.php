<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['librarian', 'admin']);
$pageTitle = 'Issue Book';
$currentPage = 'issue-book.php';
$settings = getSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $studentId = (int)($_POST['student_id'] ?? 0);
    $bookId    = (int)($_POST['book_id'] ?? 0);

    // 1. Student exists?
    $stu = $pdo->prepare("SELECT s.*, u.name FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
    $stu->execute([$studentId]);
    $student = $stu->fetch();

    // 2. Book available?
    $bk = $pdo->prepare("SELECT * FROM books WHERE id=?");
    $bk->execute([$bookId]);
    $book = $bk->fetch();

    if (!$student) {
        setFlash('error', 'Please select a valid student ID.');
    } elseif (!$book) {
        setFlash('error', 'Please select a valid book.');
    } elseif ((int)$book['available_copies'] <= 0) {
        setFlash('error', 'Book is currently unavailable.');
    } else {
        // 3. Borrowing limit
        $activeStmt = $pdo->prepare("SELECT COUNT(*) FROM book_issues WHERE student_id=? AND status IN ('issued','overdue')");
        $activeStmt->execute([$studentId]);
        $activeCount = (int)$activeStmt->fetchColumn();

        // 4. Unpaid fines
        $fineStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fines WHERE student_id=? AND status='pending'");
        $fineStmt->execute([$studentId]);
        $unpaidFine = (float)$fineStmt->fetchColumn();

        if ($activeCount >= (int)$student['max_books']) {
            setFlash('error', $student['name'] . ' has already reached the borrowing limit of ' . $student['max_books'] . ' books.');
        } elseif ($unpaidFine > 0) {
            setFlash('error', $student['name'] . ' has an unpaid fine of Rs. ' . number_format($unpaidFine, 2) . '. Please clear it before issuing new books.');
        } else {
            $issueDate = date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime("+{$settings['borrow_period_days']} days"));

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO book_issues (student_id, book_id, issued_by, issue_date, due_date, status) VALUES (?,?,?,?,?, 'issued')")
                ->execute([$studentId, $bookId, $_SESSION['user_id'], $issueDate, $dueDate]);
            $pdo->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE id=?")->execute([$bookId]);
            $pdo->commit();

            notify($pdo, $student['user_id'], 'Book Issued', '"' . $book['title'] . '" has been issued to you. Due on ' . fmtDate($dueDate) . '.');
            logActivity($pdo, $_SESSION['user_id'], $_SESSION['name'] . ' issued "' . $book['title'] . '" to ' . $student['name']);
            setFlash('success', 'Book issued successfully. Due date: ' . fmtDate($dueDate));
        }
    }
    redirect('issue-book.php');
}

$students = $pdo->query("SELECT s.id, s.roll_number, u.name FROM students s JOIN users u ON u.id=s.user_id ORDER BY u.name")->fetchAll();
$availableBooks = $pdo->query("SELECT id, title, isbn, available_copies FROM books WHERE available_copies > 0 ORDER BY title")->fetchAll();

$recentIssues = $pdo->query("
    SELECT bi.*, b.title, u.name student_name, s.roll_number
    FROM book_issues bi
    JOIN books b ON b.id=bi.book_id
    JOIN students s ON s.id=bi.student_id
    JOIN users u ON u.id=s.user_id
    ORDER BY bi.id DESC LIMIT 8")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-2">
    <div class="card">
        <h3><i class="fa-solid fa-right-from-bracket"></i> Issue a Book</h3>
        <form method="POST" action="issue-book.php">
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
            <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> Borrowing period: <?= (int)$settings['borrow_period_days'] ?> days from today.</div>
            <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;"><i class="fa-solid fa-right-from-bracket"></i> Issue Book</button>
        </form>
    </div>

    <div class="card">
        <h3>Recently Issued</h3>
        <?php if (!$recentIssues): ?>
            <div class="empty-state"><i class="fa-solid fa-inbox"></i>No books issued yet.</div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Student</th><th>Book</th><th>Issue Date</th><th>Due Date</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($recentIssues as $r): ?>
                    <tr>
                        <td><?= e($r['student_name']) ?><br><span class="text-muted"><?= e($r['roll_number']) ?></span></td>
                        <td><?= e($r['title']) ?></td>
                        <td><?= fmtDate($r['issue_date']) ?></td>
                        <td><?= fmtDate($r['due_date']) ?></td>
                        <td><?= statusBadge($r['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
