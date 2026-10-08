<?php
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    redirect(basePath() . '/' . $_SESSION['role'] . '/dashboard.php');
}
redirect(basePath() . '/auth/login.php');
