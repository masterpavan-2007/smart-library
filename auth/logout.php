<?php
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    // no DB write required; just tear down the session
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
setcookie('slms_remember', '', time() - 42000, '/');
session_destroy();

redirect(basePath() . '/auth/login.php');
