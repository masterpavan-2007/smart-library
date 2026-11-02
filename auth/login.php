<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Already logged in? Send them straight to their dashboard.
if (isLoggedIn()) {
    redirect(basePath() . '/' . $_SESSION['role'] . '/dashboard.php');
}

// "Remember me" auto-login via cookie token (very simple demo implementation)
if (!isLoggedIn() && !empty($_COOKIE['slms_remember'])) {
    [$uid, $token] = array_pad(explode(':', $_COOKIE['slms_remember'], 2), 2, '');
    if ($uid && $token) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$uid]);
        $u = $stmt->fetch();
        if ($u && hash_equals(hash('sha256', $u['password']), $token)) {
            $_SESSION['user_id'] = $u['id'];
            $_SESSION['name']    = $u['name'];
            $_SESSION['role']    = $u['role'];
            redirect(basePath() . '/' . $u['role'] . '/dashboard.php');
        }
    }
}

$errors = [];
$emailOld = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? '';
    $remember = !empty($_POST['remember']);
    $emailOld = $email;

    if ($email === '' || $password === '' || $role === '') {
        $errors[] = 'Please fill in all fields, including role.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = ? LIMIT 1");
        $stmt->execute([$email, $role]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'Invalid email, password, or role.';
        } elseif ($user['status'] !== 'active') {
            $errors[] = 'This account has been deactivated. Please contact the library admin.';
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role'];

            if ($remember) {
                $token = hash('sha256', $user['password']);
                setcookie('slms_remember', $user['id'] . ':' . $token, time() + (30 * 86400), '/');
            }

            logActivity($pdo, $user['id'], $user['name'] . ' logged in');
            redirect(basePath() . '/' . $user['role'] . '/dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · Smart Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= basePath() ?>/assets/css/style.css">
<script>
(function() {
    try {
        var saved = localStorage.getItem('smart_library_theme');
        var theme = saved || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
    } catch(e) {}
})();
</script>
</head>
<body class="auth-page-body">
<div class="auth-wrap">
    <div class="auth-theme-toggle">
        <button type="button" class="theme-switch-pill" id="themeToggleBtn" aria-label="Toggle Dark / Light Mode" title="Toggle Dark / Light Mode" onclick="toggleTheme()">
            <span class="theme-switch-track-icon track-sun"><i class="fa-solid fa-sun"></i></span>
            <span class="theme-switch-track-icon track-moon"><i class="fa-solid fa-moon"></i></span>
            <span class="theme-switch-thumb">
                <i class="fa-solid fa-sun thumb-icon-sun"></i>
                <i class="fa-solid fa-moon thumb-icon-moon"></i>
            </span>
        </button>
    </div>
    <div class="auth-card">
        <div class="auth-logo-squircle">
            <i class="fa-solid fa-book-open"></i>
        </div>
        <h2 class="auth-title">Smart Library</h2>
        <p class="auth-subtitle">Sign in to manage or use the library</p>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="login.php" autocomplete="off" class="auth-form">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Email / Username</label>
                <div class="input-icon">
                    <i class="fa-regular fa-envelope"></i>
                    <input type="text" name="email" class="form-control pill-input" placeholder="you@example.com" value="<?= e($emailOld) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="input-icon">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" id="loginPassword" name="password" class="form-control pill-input" placeholder="••••••" required>
                    <i class="fa-solid fa-eye password-toggle" data-target="loginPassword"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Login as</label>
                <div class="input-icon select-with-icon">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <select name="role" class="form-control pill-input" required>
                        <option value="">-- Select role --</option>
                        <option value="student" selected>Student</option>
                        <option value="librarian">Staff / Librarian</option>
                        <option value="admin">Admin / Library Owner</option>
                    </select>
                </div>
            </div>
            <div class="auth-links">
                <label class="remember-checkbox"><input type="checkbox" name="remember"> <span>Remember me</span></label>
                <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-auth-login">
                Login
            </button>
        </form>

    </div>
</div>
<script src="<?= basePath() ?>/assets/js/script.js"></script>
</body>
</html>
