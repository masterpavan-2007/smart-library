<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['librarian', 'admin']);
$pageTitle = 'Return Book';
$currentPage = 'return-book.php';
$settings = getSettings($pdo);

// ---------- Process a return ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $issueId = (int)($_POST['issue_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    $stmt = $pdo->prepare("SELECT bi.*, b.title, b.id book_id, s.id student_pk, u.name student_name, u.id user_id
        FROM book_issues bi
        JOIN books b ON b.id = bi.book_id
        JOIN students s ON s.id = bi.student_id
        JOIN users u ON u.id = s.user_id
        WHERE bi.id = ? AND bi.status IN ('issued','overdue')");
    $stmt->execute([$issueId]);
    $issue = $stmt->fetch();

    if (!$issue) {
        setFlash('error', 'That issue record was not found or has already been returned.');
    } else {
        $today = date('Y-m-d');
        $lateDays = max(0, (strtotime($today) - strtotime($issue['due_date'])) / 86400);
        $lateDays = (int)$lateDays;
        $fine = calculateFine($lateDays, $settings);

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE book_issues SET status='returned', return_date=? WHERE id=?")->execute([$today, $issueId]);
        $pdo->prepare("UPDATE books SET available_copies = available_copies + 1 WHERE id=?")->execute([$issue['book_id']]);
        $pdo->prepare("INSERT INTO book_returns (issue_id, return_date, late_days, fine_amount, received_by, remarks) VALUES (?,?,?,?,?,?)")
            ->execute([$issueId, $today, $lateDays, $fine, $_SESSION['user_id'], $remarks]);

        if ($fine > 0) {
            $pdo->prepare("INSERT INTO fines (student_id, issue_id, amount, reason, status) VALUES (?,?,?,?, 'pending')")
                ->execute([$issue['student_pk'], $issueId, $fine, "Late return - $lateDays day(s)"]);
        }
        $pdo->commit();

        notify($pdo, $issue['user_id'], 'Book Returned', '"' . $issue['title'] . '" has been marked as returned.' . ($fine > 0 ? ' A fine of Rs. ' . number_format($fine, 2) . ' was applied.' : ''));
        logActivity($pdo, $_SESSION['user_id'], $_SESSION['name'] . ' processed return of "' . $issue['title'] . '" for ' . $issue['student_name']);

        setFlash('success', 'Book returned successfully.' . ($fine > 0 ? ' Fine generated: Rs. ' . number_format($fine, 2) . ' (' . $lateDays . ' day(s) late).' : ' Returned on time.'));
    }
    redirect('return-book.php');
}

// ---------- Search active issues ----------
$search = trim($_GET['q'] ?? '');
$where = "WHERE bi.status IN ('issued','overdue')";
$params = [];
if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR s.roll_number LIKE ? OR b.title LIKE ? OR bi.id = ?)";
    $params = ["%$search%", "%$search%", "%$search%", is_numeric($search) ? (int)$search : 0];
}
$stmt = $pdo->prepare("
    SELECT bi.*, b.title, s.roll_number, u.name student_name,
        DATEDIFF(CURDATE(), bi.due_date) late_days
    FROM book_issues bi
    JOIN books b ON b.id = bi.book_id
    JOIN students s ON s.id = bi.student_id
    JOIN users u ON u.id = s.user_id
    $where ORDER BY bi.due_date ASC");
$stmt->execute($params);
$activeIssues = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <form method="GET" class="flex gap-2">
        <input type="text" name="q" class="form-control" style="width:280px;" placeholder="Search by student, roll no, book, or issue ID..." value="<?= e($search) ?>">
        <button class="btn btn-outline" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
    </form>
</div>

<div class="card">
    <h3>Currently Issued Books</h3>
    <?php if (!$activeIssues): ?>
        <div class="empty-state"><i class="fa-solid fa-circle-check"></i>No matching active issues found.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Issue ID</th><th>Student</th><th>Book</th><th>Issue Date</th><th>Due Date</th><th>Late Days</th><th>Est. Fine</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($activeIssues as $r):
                $late = max(0, (int)$r['late_days']);
                $estFine = calculateFine($late, $settings);
            ?>
                <tr>
                    <td>#<?= $r['id'] ?></td>
                    <td><?= e($r['student_name']) ?><br><span class="text-muted"><?= e($r['roll_number']) ?></span></td>
                    <td><?= e($r['title']) ?></td>
                    <td><?= fmtDate($r['issue_date']) ?></td>
                    <td><?= fmtDate($r['due_date']) ?></td>
                    <td><?= $late > 0 ? '<span class="badge badge-red">' . $late . ' days</span>' : '<span class="badge badge-green">On time</span>' ?></td>
                    <td><?= $estFine > 0 ? 'Rs. ' . number_format($estFine, 2) : '—' ?></td>
                    <td>
                        <form method="POST" style="display:flex; gap:6px;">
                            <?= csrfField() ?>
                            <input type="hidden" name="issue_id" value="<?= $r['id'] ?>">
                            <input type="text" name="remarks" class="form-control btn-sm" placeholder="Remarks (optional)" style="width:130px;">
                            <button class="btn btn-sm btn-success" type="submit"><i class="fa-solid fa-right-to-bracket"></i> Return</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
