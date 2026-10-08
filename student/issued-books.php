<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['student']);
$pageTitle = 'My Issued Books';
$currentPage = 'issued-books.php';
$settings = getSettings($pdo);

$stu = $pdo->prepare("SELECT * FROM students WHERE user_id=?");
$stu->execute([$_SESSION['user_id']]);
$student = $stu->fetch();
$studentId = $student['id'];

// ---------- Self-service renewal (only if not overdue and not already renewed) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $issueId = (int)($_POST['issue_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM book_issues WHERE id=? AND student_id=? AND status='issued'");
    $stmt->execute([$issueId, $studentId]);
    $issue = $stmt->fetch();

    $pendingFines = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fines WHERE student_id=? AND status='pending'");
    $pendingFines->execute([$studentId]);
    $hasFines = (float)$pendingFines->fetchColumn() > 0;

    if (!$issue) {
        setFlash('error', 'That book cannot be renewed (not found or already returned).');
    } elseif (strtotime($issue['due_date']) < strtotime(date('Y-m-d'))) {
        setFlash('error', 'Overdue books are not eligible for renewal. Please return it first.');
    } elseif ($hasFines) {
        setFlash('error', 'Please clear your pending fines before renewing.');
    } else {
        $newDue = date('Y-m-d', strtotime($issue['due_date'] . " +{$settings['borrow_period_days']} days"));
        $pdo->prepare("UPDATE book_issues SET due_date=? WHERE id=?")->execute([$newDue, $issueId]);
        setFlash('success', 'Book renewed. New due date: ' . fmtDate($newDue));
    }
    redirect('issued-books.php');
}

$issued = $pdo->prepare("
    SELECT bi.*, b.title, b.shelf_number
    FROM book_issues bi JOIN books b ON b.id=bi.book_id
    WHERE bi.student_id=? AND bi.status IN ('issued','overdue')
    ORDER BY bi.due_date ASC");
$issued->execute([$studentId]);
$issued = $issued->fetchAll();

$history = $pdo->prepare("
    SELECT bi.*, b.title FROM book_issues bi JOIN books b ON b.id=bi.book_id
    WHERE bi.student_id=? AND bi.status='returned' ORDER BY bi.id DESC LIMIT 20");
$history->execute([$studentId]);
$history = $history->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="margin-bottom:18px;">
    <h3>Currently With Me</h3>
    <?php if (!$issued): ?>
        <div class="empty-state"><i class="fa-solid fa-book"></i>You have no books issued right now.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Book</th><th>Shelf</th><th>Issue Date</th><th>Due Date</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($issued as $r):
                $overdue = strtotime($r['due_date']) < strtotime(date('Y-m-d'));
            ?>
                <tr>
                    <td><?= e($r['title']) ?></td>
                    <td><?= e($r['shelf_number']) ?></td>
                    <td><?= fmtDate($r['issue_date']) ?></td>
                    <td><?= fmtDate($r['due_date']) ?></td>
                    <td><?= statusBadge($overdue ? 'overdue' : 'issued') ?></td>
                    <td>
                        <?php if (!$overdue): ?>
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="issue_id" value="<?= $r['id'] ?>">
                            <button class="btn btn-sm btn-outline" type="submit"><i class="fa-solid fa-rotate"></i> Renew</button>
                        </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Return History</h3>
    <?php if (!$history): ?>
        <div class="empty-state"><i class="fa-solid fa-clock-rotate-left"></i>No return history yet.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Book</th><th>Issue Date</th><th>Return Date</th></tr></thead>
            <tbody>
            <?php foreach ($history as $r): ?>
                <tr><td><?= e($r['title']) ?></td><td><?= fmtDate($r['issue_date']) ?></td><td><?= fmtDate($r['return_date']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
