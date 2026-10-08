<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim($_POST['email'] ?? '');
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $upd = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
        $upd->execute([$token, $expires, $user['id']]);

        // NOTE: In production this link would be emailed to the user via
        // PHPMailer / SMTP. For this demo we simply show the link on screen.
        $_SESSION['demo_reset_link'] = 'reset-password.php?token=' . $token;
    }
    // Always show the same success message, whether or not the email existed,
    // so the form can't be used to check which emails are registered.
    $sent = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password · Smart Library</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= basePath() ?>/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="logo"><i class="fa-solid fa-key"></i></div>
        <h2 class="text-center">Forgot Password</h2>
        <p class="sub">Enter your email and we'll send you a reset link</p>

        <?php if ($sent): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> If that email exists in our system, a reset link has been generated.</div>
            <?php if (!empty($_SESSION['demo_reset_link'])): ?>
                <div class="alert alert-info">
                    Demo mode (no mail server configured): 
                    <a href="<?= e($_SESSION['demo_reset_link']) ?>">Click here to reset your password</a>
                </div>
                <?php unset($_SESSION['demo_reset_link']); ?>
            <?php endif; ?>
        <?php endif; ?>

        <form method="POST" action="forgot-password.php">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Email address</label>
                <input type="email" name="email" class="form-control" placeholder="you@example.com" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Send reset link</button>
        </form>
        <p class="text-center" style="margin-top:16px;"><a href="login.php">&larr; Back to login</a></p>
    </div>
</div>
</body>
</html>
