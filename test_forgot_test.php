<?php
require_once __DIR__ . '/config/database.php';

echo "--- Testing Email Existence Check ---\n";

function checkEmailStatus($pdo, $email) {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'error', 'msg' => 'Please enter a valid email format.'];
    }
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['status' => 'error', 'msg' => 'This email is not registered in our system. Please check the email ID or contact library admin.'];
    }

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $upd = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
    $upd->execute([$token, $expires, $user['id']]);

    return ['status' => 'success', 'msg' => "Reset link generated for {$user['email']}", 'token' => $token];
}

// 1. Non-registered email
$res1 = checkEmailStatus($pdo, 'nonexistent.user12345@gmail.com');
echo "Non-registered test: " . json_encode($res1) . "\n";

// 2. Registered email
$registeredEmail = $pdo->query("SELECT email FROM users LIMIT 1")->fetchColumn();
echo "Found registered email: $registeredEmail\n";
$res2 = checkEmailStatus($pdo, $registeredEmail);
echo "Registered test: " . json_encode($res2) . "\n";
