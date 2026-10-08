<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$success = false;

$stmt = $pdo->prepare("SELECT id, reset_expires FROM users WHERE reset_token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();

$validToken = $user && strtotime($user['reset_expires']) > time();

if (!$token || !$validToken) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    verifyCsrf();
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $upd->execute([$hash, $user['id']]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password · Smart Library</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= basePath() ?>/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="logo"><i class="fa-solid fa-lock-open"></i></div>
        <h2 class="text-center">Reset Password</h2>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Your password has been reset.</div>
            <p class="text-center"><a href="login.php" class="btn btn-primary">Go to login</a></p>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($validToken): ?>
            <form method="POST" action="reset-password.php?token=<?= e($token) ?>">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="password" class="form-control" minlength="8" required>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Reset Password</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
        <p class="text-center" style="margin-top:16px;"><a href="login.php">&larr; Back to login</a></p>
    </div>
</div>
</body>
</html>
