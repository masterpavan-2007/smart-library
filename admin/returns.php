<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);
$pageTitle = 'Book Returns';
$currentPage = 'returns.php';
$settings = getSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $issueId = (int)($_POST['issue_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT bi.*, b.title, b.id book_id, s.id student_pk, u.name student_name, u.id user_id
        FROM book_issues bi
        JOIN books b ON b.id = bi.book_id
        JOIN students s ON s.id = bi.student_id
        JOIN users u ON u.id = s.user_id
        WHERE bi.id = ? AND bi.status IN ('issued','overdue')");
    $stmt->execute([$issueId]);
    $issue = $stmt->fetch();

    if (!$issue) {
        setFlash('error', 'Issue record not found or already returned.');
    } else {
        $today = date('Y-m-d');
        $lateDays = max(0, (int)((strtotime($today) - strtotime($issue['due_date'])) / 86400));
        $fine = calculateFine($lateDays, $settings);

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE book_issues SET status='returned', return_date=? WHERE id=?")->execute([$today, $issueId]);
        $pdo->prepare("UPDATE books SET available_copies = available_copies + 1 WHERE id=?")->execute([$issue['book_id']]);
        $pdo->prepare("INSERT INTO book_returns (issue_id, return_date, late_days, fine_amount, received_by) VALUES (?,?,?,?,?)")
            ->execute([$issueId, $today, $lateDays, $fine, $_SESSION['user_id']]);
        if ($fine > 0) {
            $pdo->prepare("INSERT INTO fines (student_id, issue_id, amount, reason, status) VALUES (?,?,?,?, 'pending')")
                ->execute([$issue['student_pk'], $issueId, $fine, "Late return - $lateDays day(s)"]);
        }
        $pdo->commit();
        notify($pdo, $issue['user_id'], 'Book Returned', '"' . $issue['title'] . '" marked as returned.');
        setFlash('success', 'Return processed.' . ($fine > 0 ? ' Fine: Rs. ' . number_format($fine, 2) : ''));
    }
    redirect('returns.php');
}

$activeIssues = $pdo->query("
    SELECT bi.*, b.title, s.roll_number, u.name student_name, DATEDIFF(CURDATE(), bi.due_date) late_days
    FROM book_issues bi
    JOIN books b ON b.id=bi.book_id
    JOIN students s ON s.id=bi.student_id
    JOIN users u ON u.id=s.user_id
    WHERE bi.status IN ('issued','overdue')
    ORDER BY bi.due_date ASC")->fetchAll();

$history = $pdo->query("
    SELECT br.*, bi.issue_date, bi.due_date, b.title, u.name student_name, s.roll_number
    FROM book_returns br
    JOIN book_issues bi ON bi.id = br.issue_id
    JOIN books b ON b.id = bi.book_id
    JOIN students s ON s.id = bi.student_id
    JOIN users u ON u.id = s.user_id
    ORDER BY br.id DESC LIMIT 50")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="margin-bottom:18px;">
    <h3>Pending Returns</h3>
    <?php if (!$activeIssues): ?>
        <div class="empty-state"><i class="fa-solid fa-circle-check"></i>Nothing currently outstanding.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>ID</th><th>Student</th><th>Book</th><th>Due Date</th><th>Late Days</th><th>Est. Fine</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($activeIssues as $r): $late = max(0,(int)$r['late_days']); $estFine = calculateFine($late, $settings); ?>
                <tr>
                    <td>#<?= $r['id'] ?></td>
                    <td><?= e($r['student_name']) ?> <span class="text-muted">(<?= e($r['roll_number']) ?>)</span></td>
                    <td><?= e($r['title']) ?></td>
                    <td><?= fmtDate($r['due_date']) ?></td>
                    <td><?= $late > 0 ? '<span class="badge badge-red">'.$late.' days</span>' : '<span class="badge badge-green">On time</span>' ?></td>
                    <td><?= $estFine > 0 ? 'Rs. '.number_format($estFine,2) : '—' ?></td>
                    <td>
                        <form method="POST" data-confirm="Mark this book as returned?">
                            <?= csrfField() ?><input type="hidden" name="issue_id" value="<?= $r['id'] ?>">
                            <button class="btn btn-sm btn-success" type="submit"><i class="fa-solid fa-right-to-bracket"></i> Mark Returned</button>
                        </form>
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
        <div class="empty-state"><i class="fa-solid fa-clock-rotate-left"></i>No returns recorded yet.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Student</th><th>Book</th><th>Issue Date</th><th>Return Date</th><th>Late Days</th><th>Fine</th></tr></thead>
            <tbody>
            <?php foreach ($history as $r): ?>
                <tr>
                    <td><?= e($r['student_name']) ?> <span class="text-muted">(<?= e($r['roll_number']) ?>)</span></td>
                    <td><?= e($r['title']) ?></td>
                    <td><?= fmtDate($r['issue_date']) ?></td>
                    <td><?= fmtDate($r['return_date']) ?></td>
                    <td><?= (int)$r['late_days'] ?></td>
                    <td><?= $r['fine_amount'] > 0 ? 'Rs. '.number_format($r['fine_amount'],2) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
