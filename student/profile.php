<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['student']);
$pageTitle = 'My Profile';
$currentPage = 'profile.php';

$stu = $pdo->prepare("SELECT s.*, u.name, u.email, u.phone FROM students s JOIN users u ON u.id=s.user_id WHERE u.id=?");
$stu->execute([$_SESSION['user_id']]);
$student = $stu->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '') {
            setFlash('error', 'Name cannot be empty.');
        } else {
            $pdo->prepare("UPDATE users SET name=?, phone=? WHERE id=?")->execute([$name, $phone, $_SESSION['user_id']]);
            $pdo->prepare("UPDATE students SET address=? WHERE user_id=?")->execute([$address, $_SESSION['user_id']]);
            $_SESSION['name'] = $name;
            setFlash('success', 'Profile updated successfully.');
        }
    } elseif ($formAction === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $u = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $u->execute([$_SESSION['user_id']]);
        $hash = $u->fetchColumn();

        if (!password_verify($current, $hash)) {
            setFlash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            setFlash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            setFlash('error', 'New passwords do not match.');
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            setFlash('success', 'Password changed successfully.');
        }
    }
    redirect('profile.php');
}

include __DIR__ . '/../includes/header.php';
?>
<div class="grid grid-2">
    <div class="card">
        <h3>Profile Details</h3>
        <form method="POST" action="profile.php">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="update_profile">
            <div class="form-group"><label>Full Name</label><input class="form-control" name="name" value="<?= e($student['name']) ?>" required></div>
            <div class="form-group"><label>Email</label><input class="form-control" value="<?= e($student['email']) ?>" disabled></div>
            <div class="form-group"><label>Roll Number</label><input class="form-control" value="<?= e($student['roll_number']) ?>" disabled></div>
            <div class="form-group"><label>Mobile Number</label><input class="form-control" name="phone" value="<?= e($student['phone']) ?>"></div>
            <div class="form-group"><label>Address</label><input class="form-control" name="address" value="<?= e($student['address']) ?>"></div>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
        </form>
    </div>

    <div class="card">
        <h3>Change Password</h3>
        <form method="POST" action="profile.php">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="change_password">
            <div class="form-group"><label>Current Password</label><input class="form-control" type="password" name="current_password" required></div>
            <div class="form-group"><label>New Password</label><input class="form-control" type="password" name="new_password" minlength="8" required></div>
            <div class="form-group"><label>Confirm New Password</label><input class="form-control" type="password" name="confirm_password" minlength="8" required></div>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-key"></i> Change Password</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
