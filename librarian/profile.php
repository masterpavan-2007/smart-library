<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['librarian']);

$pageTitle = 'Librarian Profile';
$currentPage = 'profile.php';

// Fetch current librarian user with staff record
$stmt = $pdo->prepare("SELECT u.*, s.designation, s.shift, s.joining_date 
                       FROM users u 
                       LEFT JOIN staff s ON s.user_id = u.id 
                       WHERE u.id = ? AND u.role = 'librarian' LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    redirect(basePath() . '/auth/login.php');
}

// Compute initials
$initials = '';
foreach (explode(' ', $user['name'] ?? 'Librarian') as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}
$initials = substr($initials, 0, 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '') {
            setFlash('error', 'Name cannot be empty.');
        } else {
            $up = $pdo->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?");
            $up->execute([$name, $phone, $user['id']]);

            $_SESSION['name'] = $name;

            logActivity($pdo, $user['id'], 'Updated profile details');
            setFlash('success', 'Profile details updated successfully.');
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
            <span class="badge badge-blue"><?= e($user['designation'] ?? 'Librarian') ?></span>
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
                <span class="text-muted"><i class="fa-solid fa-clock" style="width:18px;"></i> Shift:</span>
                <span style="font-weight: 600;"><?= e($user['shift'] ?: 'General') ?></span>
            </div>
            <div style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                <span class="text-muted"><i class="fa-solid fa-circle-check" style="width:18px;"></i> Status:</span>
                <?= statusBadge($user['status']) ?>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span class="text-muted"><i class="fa-solid fa-calendar" style="width:18px;"></i> Joined:</span>
                <span style="font-weight: 600;"><?= fmtDate($user['joining_date'] ?: $user['created_at']) ?></span>
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
                        <label>Email Address</label>
                        <input class="form-control" value="<?= e($user['email']) ?>" disabled style="background:var(--gray-light); cursor:not-allowed;">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input class="form-control" name="phone" value="<?= e($user['phone']) ?>" placeholder="e.g. 9990000002">
                    </div>
                    <div class="form-group">
                        <label>Designation</label>
                        <input class="form-control" value="<?= e($user['designation'] ?? 'Librarian') ?>" disabled style="background:var(--gray-light); cursor:not-allowed;">
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
