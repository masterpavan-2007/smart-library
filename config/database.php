<?php
/**
 * Database connection settings.
 * Edit these values to match your local XAMPP / MySQL setup.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'smart_library');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Never expose raw DB errors to end users.
    die('Database connection failed. Please check config/database.php and make sure MySQL is running.');
}
