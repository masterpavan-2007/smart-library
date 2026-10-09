<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$sent = false;
$error = '';
$emailInput = '';
$sentEmail = '';
$resetUrl = '';
$mailSent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $emailInput = trim($_POST['email'] ?? '');

    if (empty($emailInput)) {
        $error = 'Please enter your registered email address.';
    } elseif (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email format (e.g. name@example.com).';
    } else {
        // Check if the email exists in the users table
        $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$emailInput]);
        $user = $stmt->fetch();

        if (!$user) {
            // Requirement: if we put non-registered email id show error this email is not registered
            $error = 'This email is not registered in our system. Please check the email ID or contact library admin.';
        } else {
            // Generate unique secure token and 1-hour expiration
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $upd = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $upd->execute([$token, $expires, $user['id']]);

            // Construct full reset password URL
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
            $protocol = $isHttps ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $resetUrl = $protocol . $host . basePath() . '/auth/reset-password.php?token=' . urlencode($token);

            $settings = getSettings($pdo);
            $libraryName = $settings['library_name'] ?? 'Smart Library';

            // Prepare Email Content
            $subject = "Password Reset Request · " . $libraryName;
            
            $messageHtml = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f3f4f6; margin: 0; padding: 24px; color: #1f2937; }
    .email-container { max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    .header { background: #4f46e5; padding: 24px 32px; text-align: center; color: #ffffff; }
    .header h1 { margin: 0; font-size: 22px; font-weight: 700; }
    .content { padding: 32px; font-size: 15px; line-height: 1.6; color: #374151; }
    .btn-wrap { text-align: center; margin: 28px 0; }
    .reset-btn { display: inline-block; background: #4f46e5; color: #ffffff !important; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; }
    .link-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; word-break: break-all; font-family: monospace; font-size: 13px; color: #4b5563; }
    .footer { padding: 20px 32px; background: #f9fafb; border-top: 1px solid #e5e7eb; font-size: 12.5px; color: #6b7280; text-align: center; }
</style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <h1>' . htmlspecialchars($libraryName) . '</h1>
    </div>
    <div class="content">
        <p>Dear <strong>' . htmlspecialchars($user['name']) . '</strong>,</p>
        <p>We received a request to reset the password for your account associated with <strong>' . htmlspecialchars($user['email']) . '</strong>.</p>
        <p>Click the button below to reset your password:</p>
        <div class="btn-wrap">
            <a href="' . htmlspecialchars($resetUrl) . '" class="reset-btn" target="_blank">Reset Your Password</a>
        </div>
        <p style="font-size: 13.5px; color: #6b7280;">If the button above does not work, copy and paste this link into your web browser:</p>
        <div class="link-box">' . htmlspecialchars($resetUrl) . '</div>
        <p style="margin-top: 24px; font-size: 13.5px; color: #b91c1c;"><strong>Note:</strong> This link is strictly valid for 1 hour. If you did not request a password reset, please ignore this email and your password will remain unchanged.</p>
    </div>
    <div class="footer">
        <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($libraryName) . ' Management System. All rights reserved.</p>
    </div>
</div>
</body>
</html>';

            $messagePlain = "Hello {$user['name']},\n\n"
                          . "We received a request to reset your password for your {$libraryName} account ({$user['email']}).\n\n"
                          . "Click the link below to set a new password:\n"
                          . "{$resetUrl}\n\n"
                          . "This reset link is valid for 1 hour.\n"
                          . "If you did not request this, you can safely ignore this email.\n\n"
                          . "Thank you,\n{$libraryName} Management Team";

            // Headers for HTML email dispatch
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: ' . $libraryName . ' <noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '>',
                'Reply-To: noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
                'X-Mailer: PHP/' . phpversion()
            ];

            // Send to registered email ID via PHP mail()
            $mailSent = @mail($user['email'], $subject, $messageHtml, implode("\r\n", $headers));

            // Record in-app notification & system activity
            notify($pdo, $user['id'], 'Password Reset Requested', 'A password reset link was generated and sent to ' . $user['email']);
            logActivity($pdo, $user['id'], 'Password reset link sent to ' . $user['email']);

            // Record in reminders_log if table exists
            try {
                $stmtLog = $pdo->prepare("
                    INSERT INTO reminders_log (user_id, email, type, channel, title, message, status)
                    VALUES (?, ?, 'password_reset', 'email', ?, ?, 'sent')
                ");
                $stmtLog->execute([$user['id'], $user['email'], 'Password Reset Link', $messagePlain]);
            } catch (Exception $e) {
                // Table is optional
            }

            $sent = true;
            $sentEmail = $user['email'];
            $_SESSION['demo_reset_link'] = $resetUrl;
        }
    }
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
<style>
.auth-card {
    max-width: 440px;
}
.reset-link-preview {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    padding: 14px;
    margin-top: 14px;
    font-size: 13px;
    text-align: left;
}
.reset-link-preview a {
    word-break: break-all;
    font-weight: 600;
}
.copy-btn {
    margin-top: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    padding: 4px 10px;
    background: #e2e8f0;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}
.copy-btn:hover {
    background: #cbd5e1;
}
</style>
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="logo"><i class="fa-solid fa-key"></i></div>
        <h2 class="text-center">Forgot Password</h2>
        <p class="sub">Enter your registered email and we'll send you a reset link</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert">
                <i class="fa-solid fa-circle-exclamation" style="flex-shrink:0;"></i>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($sent): ?>
            <div class="alert alert-success" role="alert">
                <i class="fa-solid fa-circle-check" style="flex-shrink:0;"></i>
                <div>
                    <strong>Reset link sent!</strong><br>
                    A password reset link has been sent to your registered email ID: <strong><?= e($sentEmail) ?></strong>.
                </div>
            </div>

            <div class="alert alert-info" style="font-size:13px; line-height:1.5;">
                <i class="fa-solid fa-envelope-circle-check" style="flex-shrink:0; font-size:18px;"></i>
                <div>
                    Please check your email inbox and spam folder. The link is valid for <strong>1 hour</strong>.
                </div>
            </div>

            <?php if (!empty($_SESSION['demo_reset_link'])): ?>
                <div class="reset-link-preview">
                    <div style="font-weight:600; color:#475569; margin-bottom:4px;">
                        <i class="fa-solid fa-laptop-code"></i> Direct Link (Local / Testing Mode):
                    </div>
                    <div style="color:#64748b; font-size:12px; margin-bottom:8px;">
                        If your local XAMPP does not have an active SMTP server configured, you can click below:
                    </div>
                    <div>
                        <a href="<?= e($_SESSION['demo_reset_link']) ?>" class="btn btn-primary btn-sm" style="display:inline-flex; align-items:center; gap:6px; text-decoration:none;">
                            <i class="fa-solid fa-arrow-right"></i> Open Reset Password Page
                        </a>
                    </div>
                </div>
                <?php unset($_SESSION['demo_reset_link']); ?>
            <?php endif; ?>

            <p class="text-center" style="margin-top:20px;">
                <a href="forgot-password.php" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-rotate-left"></i> Send to another email
                </a>
            </p>
        <?php else: ?>
            <form method="POST" action="forgot-password.php" autocomplete="off">
                <?= csrfField() ?>
                <div class="form-group">
                    <label>Registered Email Address</label>
                    <div class="input-icon">
                        <i class="fa-solid fa-envelope"></i>
                        <input type="email" name="email" class="form-control" placeholder="you@example.com" value="<?= e($emailInput) ?>" required autofocus>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">
                    <i class="fa-solid fa-paper-plane" style="margin-right:6px;"></i> Send Reset Link
                </button>
            </form>
        <?php endif; ?>

        <p class="text-center" style="margin-top:20px;">
            <a href="login.php" style="font-size:14px;"><i class="fa-solid fa-arrow-left"></i> Back to login</a>
        </p>
    </div>
</div>
</body>
</html>
