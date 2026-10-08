<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['librarian']);
$pageTitle = 'Reservations';
$currentPage = 'reservations.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    $valid = ['pending','approved','ready','completed','cancelled','expired'];
    if (in_array($newStatus, $valid, true)) {
        $r = $pdo->prepare("SELECT rv.*, u.id user_id, b.title FROM reservations rv
            JOIN students s ON s.id=rv.student_id JOIN users u ON u.id=s.user_id
            JOIN books b ON b.id=rv.book_id WHERE rv.id=?");
        $r->execute([$id]);
        $res = $r->fetch();
        if ($res) {
            $pdo->prepare("UPDATE reservations SET status=? WHERE id=?")->execute([$newStatus, $id]);
            if ($newStatus === 'ready') {
                notify($pdo, $res['user_id'], 'Reservation Ready', 'Your reserved book "' . $res['title'] . '" is now ready for pickup.');
            }
            setFlash('success', 'Reservation updated.');
        }
    }
    redirect('reservations.php');
}

$statusFilter = $_GET['status'] ?? 'pending';
$where = '';
if (in_array($statusFilter, ['pending','approved','ready','completed','cancelled','expired'], true)) {
    $where = "WHERE rv.status = " . $pdo->quote($statusFilter);
}
$reservations = $pdo->query("
    SELECT rv.*, b.title, u.name student_name, s.roll_number
    FROM reservations rv
    JOIN books b ON b.id = rv.book_id
    JOIN students s ON s.id = rv.student_id
    JOIN users u ON u.id = s.user_id
    $where ORDER BY rv.id DESC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar">
    <div class="filters">
        <a class="btn btn-sm <?= $statusFilter==='pending'?'btn-primary':'btn-outline' ?>" href="?status=pending">Pending</a>
        <a class="btn btn-sm <?= $statusFilter==='ready'?'btn-primary':'btn-outline' ?>" href="?status=ready">Ready</a>
        <a class="btn btn-sm <?= $statusFilter==='completed'?'btn-primary':'btn-outline' ?>" href="?status=completed">Completed</a>
        <a class="btn btn-sm <?= $statusFilter==='all'?'btn-primary':'btn-outline' ?>" href="?status=all">All</a>
    </div>
</div>
<div class="card">
    <?php if (!$reservations): ?>
        <div class="empty-state"><i class="fa-solid fa-bookmark"></i>No reservations found.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Student</th><th>Book</th><th>Reserved</th><th>Expires</th><th>Status</th><th>Update</th></tr></thead>
            <tbody>
            <?php foreach ($reservations as $r): ?>
                <tr>
                    <td><?= e($r['student_name']) ?> <span class="text-muted">(<?= e($r['roll_number']) ?>)</span></td>
                    <td><?= e($r['title']) ?></td>
                    <td><?= fmtDate($r['reservation_date']) ?></td>
                    <td><?= fmtDate($r['expiry_date']) ?></td>
                    <td><?= statusBadge($r['status']) ?></td>
                    <td>
                        <form method="POST" class="flex gap-2">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <select name="status" class="form-control btn-sm" style="width:130px;">
                                <?php foreach (['pending','approved','ready','completed','cancelled','expired'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $r['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline" type="submit"><i class="fa-solid fa-check"></i></button>
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
