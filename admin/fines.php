<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);
$pageTitle = 'Fines';
$currentPage = 'fines.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['form_action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'waive') {
        $pdo->prepare("UPDATE fines SET status='waived' WHERE id=? AND status='pending'")->execute([$id]);
        setFlash('success', 'Fine waived.');
    } elseif ($action === 'pay') {
        $f = $pdo->prepare("SELECT * FROM fines WHERE id=? AND status='pending'");
        $f->execute([$id]);
        $fine = $f->fetch();
        if ($fine) {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE fines SET status='paid' WHERE id=?")->execute([$id]);
            $pdo->prepare("INSERT INTO payments (fine_id, student_id, amount, payment_method, received_by) VALUES (?,?,?,?,?)")
                ->execute([$id, $fine['student_id'], $fine['amount'], $_POST['method'] ?? 'cash', $_SESSION['user_id']]);
            $pdo->commit();
            setFlash('success', 'Payment recorded.');
        }
    }
    redirect('fines.php');
}

$statusFilter = $_GET['status'] ?? 'pending';
$where = '';
if (in_array($statusFilter, ['pending','paid','waived'], true)) {
    $where = "WHERE f.status = " . $pdo->quote($statusFilter);
}
$fines = $pdo->query("
    SELECT f.*, u.name student_name, s.roll_number, b.title
    FROM fines f
    JOIN students s ON s.id = f.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN book_issues bi ON bi.id = f.issue_id
    LEFT JOIN books b ON b.id = bi.book_id
    $where ORDER BY f.id DESC")->fetchAll();

$settings = getSettings($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'update_settings') {
    // handled in settings.php instead; placeholder left intentionally blank
}

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <div class="filters">
        <a class="btn btn-sm <?= $statusFilter==='pending'?'btn-primary':'btn-outline' ?>" href="?status=pending">Pending</a>
        <a class="btn btn-sm <?= $statusFilter==='paid'?'btn-primary':'btn-outline' ?>" href="?status=paid">Paid</a>
        <a class="btn btn-sm <?= $statusFilter==='waived'?'btn-primary':'btn-outline' ?>" href="?status=waived">Waived</a>
    </div>
    <div class="text-muted" style="font-size:13px;">Fine per day: &#8377;<?= number_format($settings['fine_per_day'],2) ?> &middot; Max fine: &#8377;<?= number_format($settings['max_fine'],2) ?></div>
</div>
<div class="card">
    <?php if (!$fines): ?>
        <div class="empty-state"><i class="fa-solid fa-coins"></i>No fines found for this filter.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Student</th><th>Book</th><th>Reason</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($fines as $f): ?>
                <tr>
                    <td><?= e($f['student_name']) ?> <span class="text-muted">(<?= e($f['roll_number']) ?>)</span></td>
                    <td><?= e($f['title'] ?? '—') ?></td>
                    <td><?= e($f['reason']) ?></td>
                    <td>&#8377;<?= number_format($f['amount'], 2) ?></td>
                    <td><?= statusBadge($f['status']) ?></td>
                    <td><?= fmtDate($f['created_at']) ?></td>
                    <td>
                        <?php if ($f['status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?><input type="hidden" name="form_action" value="pay"><input type="hidden" name="id" value="<?= $f['id'] ?>">
                            <input type="hidden" name="method" value="cash">
                            <button class="btn btn-sm btn-success" type="submit"><i class="fa-solid fa-credit-card"></i> Mark Paid</button>
                        </form>
                        <form method="POST" style="display:inline;" data-confirm="Waive this fine?">
                            <?= csrfField() ?><input type="hidden" name="form_action" value="waive"><input type="hidden" name="id" value="<?= $f['id'] ?>">
                            <button class="btn btn-sm btn-outline" type="submit">Waive</button>
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
