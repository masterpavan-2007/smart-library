<?php
require_once __DIR__ . '/config/database.php';
echo "=== TABLES ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);

echo "\n=== FINES COLUMNS ===\n";
$cols = $pdo->query("DESCRIBE fines")->fetchAll();
print_r($cols);

echo "\n=== PAYMENTS COLUMNS ===\n";
$cols = $pdo->query("DESCRIBE payments")->fetchAll();
print_r($cols);

echo "\n=== BOOK_ISSUES SAMPLE ===\n";
$issues = $pdo->query("SELECT * FROM book_issues LIMIT 10")->fetchAll();
print_r($issues);

echo "\n=== FINES SAMPLE ===\n";
$fines = $pdo->query("SELECT * FROM fines LIMIT 10")->fetchAll();
print_r($fines);

echo "\n=== PAYMENTS SAMPLE ===\n";
$pmts = $pdo->query("SELECT * FROM payments LIMIT 10")->fetchAll();
print_r($pmts);

echo "\n=== STUDENTS WITH PAVAN ===\n";
$pavan = $pdo->query("SELECT s.*, u.name, u.email, u.phone FROM students s JOIN users u ON u.id = s.user_id WHERE u.name LIKE '%Pavan%' OR s.roll_number LIKE '%2161%'")->fetchAll();
print_r($pavan);
