<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);

$pageTitle = 'Admin Profile';
$currentPage = 'profile.php';

// Fetch current admin user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    redirect(basePath() . '/auth/login.php');
}

// Compute initials
$initials = '';
foreach (explode(' ', $user['name'] ?? 'Admin') as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}
$initials = substr($initials, 0, 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '' || $email === '') {
            setFlash('error', 'Name and Email are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Please enter a valid email address.');
        } elseif ($phone !== '' && !preg_match('/^[0-9]+$/', $phone)) {
            setFlash('error', 'Invalid number: Characters or symbols are not allowed. Only numbers (0-9) are permitted.');
        } elseif ($phone !== '' && strlen($phone) > 10) {
            setFlash('error', 'Invalid number: More than 10 numbers entered (' . strlen($phone) . ' digits). Mobile number must be exactly 10 integers.');
        } elseif ($phone !== '' && strlen($phone) < 10) {
            setFlash('error', 'Invalid number: Mobile number must be exactly 10 integers (currently ' . strlen($phone) . ' digits).');
        } else {
            // Check email uniqueness among other users
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $chk->execute([$email, $user['id']]);
            if ($chk->fetch()) {
                setFlash('error', 'That email address is already registered to another account.');
            } else {
                $up = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?");
                $up->execute([$name, $email, $phone, $user['id']]);

                $_SESSION['name'] = $name;
                $_SESSION['email'] = $email;

                logActivity($pdo, $user['id'], 'Updated profile details');
                setFlash('success', 'Profile details updated successfully.');
            }
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password'])) {
            setFlash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            setFlash('error', 'New password must be at least 8 characters long.');
        } elseif ($new !== $confirm) {
            setFlash('error', 'New password and confirmation do not match.');
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $up = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $up->execute([$hash, $user['id']]);

            logActivity($pdo, $user['id'], 'Changed account password');
            setFlash('success', 'Password changed successfully.');
        }
    }

    redirect('profile.php');
}

include __DIR__ . '/../includes/header.php';
?>

<div class="grid" style="grid-template-columns: 300px 1fr; gap: 24px; align-items: start;">
    <!-- Profile Card (Left) -->
    <div class="card" style="text-align: center;">
        <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: inline-flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 700; margin-bottom: 14px;">
            <?= e($initials) ?>
        </div>
        <h3 style="margin-bottom: 4px;"><?= e($user['name']) ?></h3>
        <div style="margin-bottom: 18px;">
            <span class="badge badge-blue"><i class="fa-solid fa-shield-halved"></i> Administrator</span>
        </div>

        <div style="border-top: 1px solid var(--border); padding-top: 16px; text-align: left; font-size: 13.5px;">
            <div style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                <span class="text-muted"><i class="fa-solid fa-envelope" style="width:18px;"></i> Email:</span>
                <span style="font-weight: 600;"><?= e($user['email']) ?></span>
            </div>
            <div style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                <span class="text-muted"><i class="fa-solid fa-phone" style="width:18px;"></i> Phone:</span>
                <span style="font-weight: 600;"><?= e($user['phone'] ?: '—') ?></span>
            </div>
            <div style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                <span class="text-muted"><i class="fa-solid fa-circle-check" style="width:18px;"></i> Status:</span>
                <?= statusBadge($user['status']) ?>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span class="text-muted"><i class="fa-solid fa-calendar" style="width:18px;"></i> Registered:</span>
                <span style="font-weight: 600;"><?= fmtDate($user['created_at']) ?></span>
            </div>
        </div>
    </div>

    <!-- Forms (Right) -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        <!-- Card 1: Edit Profile Details -->
        <div class="card">
            <h3><i class="fa-solid fa-user-pen" style="color:var(--primary);margin-right:8px;"></i> Edit Profile Details</h3>
            <form method="POST" action="profile.php">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="update_profile">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name <span style="color:var(--danger)">*</span></label>
                        <input class="form-control" name="name" value="<?= e($user['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email Address <span style="color:var(--danger)">*</span></label>
                        <input class="form-control" type="email" name="email" value="<?= e($user['email']) ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input class="form-control" name="phone" value="<?= e($user['phone']) ?>" placeholder="e.g. 9990000001">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <input class="form-control" value="Administrator" disabled style="background:var(--gray-light); cursor:not-allowed;">
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="fa-solid fa-floppy-disk"></i> Save Profile Details
                </button>
            </form>
        </div>

        <!-- Card 2: Security & Password -->
        <div class="card">
            <h3><i class="fa-solid fa-lock" style="color:var(--primary);margin-right:8px;"></i> Security & Change Password</h3>
            <form method="POST" action="profile.php">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="change_password">
                <div class="form-group">
                    <label>Current Password <span style="color:var(--danger)">*</span></label>
                    <div class="input-icon">
                        <i class="fa-solid fa-key"></i>
                        <input class="form-control" type="password" id="curPass" name="current_password" required placeholder="Enter current password">
                        <i class="fa-solid fa-eye password-toggle" data-target="curPass"></i>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>New Password <span style="color:var(--danger)">*</span></label>
                        <div class="input-icon">
                            <i class="fa-solid fa-lock"></i>
                            <input class="form-control" type="password" id="newPass" name="new_password" minlength="8" required placeholder="Minimum 8 characters">
                            <i class="fa-solid fa-eye password-toggle" data-target="newPass"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password <span style="color:var(--danger)">*</span></label>
                        <div class="input-icon">
                            <i class="fa-solid fa-shield-halved"></i>
                            <input class="form-control" type="password" id="confPass" name="confirm_password" minlength="8" required placeholder="Re-enter new password">
                            <i class="fa-solid fa-eye password-toggle" data-target="confPass"></i>
                        </div>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="fa-solid fa-key"></i> Update Password
                </button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
