<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['student']);
$pageTitle = 'My Reservations';
$currentPage = 'reservations.php';

$stu = $pdo->prepare("SELECT * FROM students WHERE user_id=?");
$stu->execute([$_SESSION['user_id']]);
$student = $stu->fetch();
$studentId = $student['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE reservations SET status='cancelled' WHERE id=? AND student_id=? AND status IN ('pending','approved')");
    $stmt->execute([$id, $studentId]);
    setFlash('success', 'Reservation cancelled.');
    redirect('reservations.php');
}

$reservations = $pdo->prepare("
    SELECT rv.*, b.title, b.shelf_number FROM reservations rv JOIN books b ON b.id=rv.book_id
    WHERE rv.student_id=? ORDER BY rv.id DESC");
$reservations->execute([$studentId]);
$reservations = $reservations->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <?php if (!$reservations): ?>
        <div class="empty-state"><i class="fa-solid fa-bookmark"></i>You haven't reserved any books yet.<br><a href="books.php">Browse the catalog &rarr;</a></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Book</th><th>Shelf</th><th>Reserved</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($reservations as $r): ?>
                <tr>
                    <td><?= e($r['title']) ?></td>
                    <td><?= e($r['shelf_number']) ?></td>
                    <td><?= fmtDate($r['reservation_date']) ?></td>
                    <td><?= fmtDate($r['expiry_date']) ?></td>
                    <td><?= statusBadge($r['status']) ?></td>
                    <td>
                        <?php if (in_array($r['status'], ['pending','approved'], true)): ?>
                        <form method="POST" data-confirm="Cancel this reservation?">
                            <?= csrfField() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit"><i class="fa-solid fa-xmark"></i> Cancel</button>
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
