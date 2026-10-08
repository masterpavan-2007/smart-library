<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['librarian']);
$pageTitle = 'Fines & Payments';
$currentPage = 'fines.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['form_action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'pay') {
        $f = $pdo->prepare("SELECT * FROM fines WHERE id=? AND status='pending'");
        $f->execute([$id]);
        $fine = $f->fetch();
        if ($fine) {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE fines SET status='paid' WHERE id=?")->execute([$id]);
            $pdo->prepare("INSERT INTO payments (fine_id, student_id, amount, payment_method, received_by) VALUES (?,?,?,?,?)")
                ->execute([$id, $fine['student_id'], $fine['amount'], $_POST['method'] ?? 'cash', $_SESSION['user_id']]);
            $pdo->commit();
            setFlash('success', 'Payment recorded and receipt generated.');
        }
    }
    redirect('fines.php');
}

$search = trim($_GET['q'] ?? '');
$where = "WHERE f.status='pending'";
$params = [];
if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR s.roll_number LIKE ?)";
    $params = ["%$search%", "%$search%"];
}
$stmt = $pdo->prepare("
    SELECT f.*, u.name student_name, s.roll_number, b.title
    FROM fines f
    JOIN students s ON s.id = f.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN book_issues bi ON bi.id = f.issue_id
    LEFT JOIN books b ON b.id = bi.book_id
    $where ORDER BY f.id DESC");
$stmt->execute($params);
$fines = $stmt->fetchAll();

$overdue = $pdo->query("SELECT COUNT(*) FROM book_issues WHERE status='issued' AND due_date < CURDATE()")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <form method="GET" class="flex gap-2">
        <input type="text" name="q" class="form-control" style="width:260px;" placeholder="Search by student or roll no..." value="<?= e($search) ?>">
        <button class="btn btn-outline" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>
    <div class="text-muted" style="font-size:13px;"><?= (int)$overdue ?> overdue book(s) currently outstanding</div>
</div>
<div class="card">
    <h3>Pending Fines</h3>
    <?php if (!$fines): ?>
        <div class="empty-state"><i class="fa-solid fa-coins"></i>No pending fines.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Student</th><th>Book</th><th>Reason</th><th>Amount</th><th>Date</th><th>Record Payment</th></tr></thead>
            <tbody>
            <?php foreach ($fines as $f): ?>
                <tr>
                    <td><?= e($f['student_name']) ?> <span class="text-muted">(<?= e($f['roll_number']) ?>)</span></td>
                    <td><?= e($f['title'] ?? '—') ?></td>
                    <td><?= e($f['reason']) ?></td>
                    <td>&#8377;<?= number_format($f['amount'], 2) ?></td>
                    <td><?= fmtDate($f['created_at']) ?></td>
                    <td>
                        <form method="POST" class="flex gap-2">
                            <?= csrfField() ?><input type="hidden" name="form_action" value="pay"><input type="hidden" name="id" value="<?= $f['id'] ?>">
                            <select name="method" class="form-control btn-sm" style="width:100px;">
                                <option value="cash">Cash</option><option value="card">Card</option><option value="online">Online</option>
                            </select>
                            <button class="btn btn-sm btn-success" type="submit"><i class="fa-solid fa-credit-card"></i> Pay</button>
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
