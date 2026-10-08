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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= basePath() ?>/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="logo"><i class="fa-solid fa-book-open"></i></div>
        <h2 class="text-center">Smart Library</h2>
        <p class="sub">Sign in to manage or use the library</p>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="login.php" autocomplete="off">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Email / Username</label>
                <div class="input-icon">
                    <i class="fa-solid fa-envelope"></i>
                    <input type="text" name="email" class="form-control" placeholder="you@example.com" value="<?= e($emailOld) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="input-icon">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" id="loginPassword" name="password" class="form-control" placeholder="Your password" required>
                    <i class="fa-solid fa-eye password-toggle" data-target="loginPassword"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Login as</label>
                <select name="role" class="form-control" required>
                    <option value="">-- Select role --</option>
                    <option value="admin">Library Owner / Admin</option>
                    <option value="librarian">Reception / Librarian</option>
                    <option value="student">Student</option>
                </select>
            </div>
            <div class="auth-links">
                <label><input type="checkbox" name="remember"> Remember me</label>
                <a href="forgot-password.php">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">
                <i class="fa-solid fa-right-to-bracket"></i> Login
            </button>
        </form>

    </div>
</div>
</body>
</html>
