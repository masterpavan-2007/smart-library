<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);
$pageTitle = 'Library Settings';
$currentPage = 'settings.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $stmt = $pdo->prepare("UPDATE library_settings SET library_name=?, borrow_period_days=?, fine_per_day=?, max_fine=?, grace_period_days=?, max_books_per_student=?, reservation_valid_days=? WHERE id=1");
    $stmt->execute([
        trim($_POST['library_name'] ?? 'Smart Library'),
        max(1, (int)($_POST['borrow_period_days'] ?? 7)),
        max(0, (float)($_POST['fine_per_day'] ?? 5)),
        max(0, (float)($_POST['max_fine'] ?? 200)),
        max(0, (int)($_POST['grace_period_days'] ?? 0)),
        max(1, (int)($_POST['max_books_per_student'] ?? 3)),
        max(1, (int)($_POST['reservation_valid_days'] ?? 3)),
    ]);
    setFlash('success', 'Settings updated.');
    redirect('settings.php');
}

$settings = $pdo->query("SELECT * FROM library_settings LIMIT 1")->fetch();

include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:640px;">
    <h3>Library Configuration</h3>
    <form method="POST" action="settings.php">
        <?= csrfField() ?>
        <div class="form-group"><label>Library Name</label><input class="form-control" name="library_name" value="<?= e($settings['library_name']) ?>"></div>
        <div class="form-row">
            <div class="form-group"><label>Default Borrowing Period (days)</label><input class="form-control" type="number" name="borrow_period_days" min="1" value="<?= e($settings['borrow_period_days']) ?>"></div>
            <div class="form-group"><label>Max Books per Student</label><input class="form-control" type="number" name="max_books_per_student" min="1" value="<?= e($settings['max_books_per_student']) ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Fine per Day (&#8377;)</label><input class="form-control" type="number" step="0.01" name="fine_per_day" min="0" value="<?= e($settings['fine_per_day']) ?>"></div>
            <div class="form-group"><label>Maximum Fine (&#8377;)</label><input class="form-control" type="number" step="0.01" name="max_fine" min="0" value="<?= e($settings['max_fine']) ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Grace Period (days)</label><input class="form-control" type="number" name="grace_period_days" min="0" value="<?= e($settings['grace_period_days']) ?>"></div>
            <div class="form-group"><label>Reservation Validity (days)</label><input class="form-control" type="number" name="reservation_valid_days" min="1" value="<?= e($settings['reservation_valid_days']) ?>"></div>
        </div>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
    </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
