<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/reminder-service.php';

header('Content-Type: text/plain');

$alerts = scanPendingLibraryAlerts($pdo);
echo "=== PENDING ALERTS ===\n";
echo "Due Soon (6h/today): " . count($alerts['due_soon_6h']) . "\n";
echo "Overdue Fines: " . count($alerts['overdue_fines']) . "\n";
echo "Ready Reservations: " . count($alerts['ready_reservations']) . "\n";

echo "\n--- Due Soon (6h) ---\n";
print_r($alerts['due_soon_6h']);

echo "\n--- Overdue ---\n";
print_r($alerts['overdue_fines']);

echo "\n--- Ready Reservations ---\n";
print_r($alerts['ready_reservations']);

$issues = $pdo->query("SELECT bi.id, bi.due_date, bi.status, s.roll_number, u.name, u.phone FROM book_issues bi JOIN students s ON s.id=bi.student_id JOIN users u ON u.id=s.user_id ORDER BY bi.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "\n--- Recent Issues ---\n";
print_r($issues);

$res = $pdo->query("SELECT rv.id, rv.status, rv.expiry_date, s.roll_number, u.name, b.title FROM reservations rv JOIN students s ON s.id=rv.student_id JOIN users u ON u.id=s.user_id JOIN books b ON b.id=rv.book_id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "\n--- Reservations ---\n";
print_r($res);
