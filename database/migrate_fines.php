<?php
/**
 * Database Migration Script for Smart Library Fine Management System
 */
require_once __DIR__ . '/../config/database.php';

echo "Starting DB Migration...\n";

// 1. Upgrade `fines` table
function columnExists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

if (!columnExists($pdo, 'fines', 'book_id')) {
    echo "Adding book_id to fines...\n";
    $pdo->exec("ALTER TABLE fines ADD COLUMN book_id INT NULL AFTER student_id");
    $pdo->exec("ALTER TABLE fines ADD CONSTRAINT fk_fines_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL");
}

if (!columnExists($pdo, 'fines', 'due_date')) {
    echo "Adding due_date to fines...\n";
    $pdo->exec("ALTER TABLE fines ADD COLUMN due_date DATE NULL AFTER issue_id");
}

if (!columnExists($pdo, 'fines', 'return_date')) {
    echo "Adding return_date to fines...\n";
    $pdo->exec("ALTER TABLE fines ADD COLUMN return_date DATE NULL AFTER due_date");
}

if (!columnExists($pdo, 'fines', 'late_days')) {
    echo "Adding late_days to fines...\n";
    $pdo->exec("ALTER TABLE fines ADD COLUMN late_days INT NOT NULL DEFAULT 0 AFTER return_date");
}

if (!columnExists($pdo, 'fines', 'fine_rate')) {
    echo "Adding fine_rate to fines...\n";
    $pdo->exec("ALTER TABLE fines ADD COLUMN fine_rate DECIMAL(10,2) NOT NULL DEFAULT 5.00 AFTER late_days");
}

if (!columnExists($pdo, 'fines', 'fine_amount')) {
    echo "Adding fine_amount to fines...\n";
    $pdo->exec("ALTER TABLE fines ADD COLUMN fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER fine_rate");
}

if (!columnExists($pdo, 'fines', 'paid_amount')) {
    echo "Adding paid_amount to fines...\n";
    $pdo->exec("ALTER TABLE fines ADD COLUMN paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER fine_amount");
}

if (!columnExists($pdo, 'fines', 'outstanding_amount')) {
    echo "Adding outstanding_amount to fines...\n";
    $pdo->exec("ALTER TABLE fines ADD COLUMN outstanding_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER paid_amount");
}

// Modify status enum to include unpaid, pending, paid, partially_paid, waived
echo "Updating status column in fines...\n";
$pdo->exec("ALTER TABLE fines MODIFY COLUMN status ENUM('unpaid','pending','paid','partially_paid','waived') NOT NULL DEFAULT 'unpaid'");

if (!columnExists($pdo, 'fines', 'updated_at')) {
    echo "Adding updated_at to fines...\n";
    $pdo->exec("ALTER TABLE fines ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
}

// Sync existing fines: set fine_amount = amount, outstanding_amount = amount (if not paid)
$pdo->exec("UPDATE fines SET fine_amount = amount WHERE fine_amount = 0 AND amount > 0");
$pdo->exec("UPDATE fines SET paid_amount = amount, outstanding_amount = 0 WHERE status = 'paid' AND paid_amount = 0");
$pdo->exec("UPDATE fines SET outstanding_amount = fine_amount - paid_amount WHERE status IN ('unpaid','pending','partially_paid')");

// Populate book_id, due_date, return_date on existing fines where issue_id is present
$pdo->exec("
    UPDATE fines f
    JOIN book_issues bi ON bi.id = f.issue_id
    SET f.book_id = bi.book_id,
        f.due_date = bi.due_date,
        f.return_date = bi.return_date
    WHERE f.book_id IS NULL AND f.issue_id IS NOT NULL
");

// 2. Upgrade `payments` table
if (!columnExists($pdo, 'payments', 'payment_id')) {
    echo "Adding payment_id to payments...\n";
    $pdo->exec("ALTER TABLE payments ADD COLUMN payment_id VARCHAR(64) NULL AFTER id");
    $pdo->exec("ALTER TABLE payments ADD INDEX idx_pmt_id (payment_id)");
}

if (!columnExists($pdo, 'payments', 'transaction_reference')) {
    echo "Adding transaction_reference to payments...\n";
    $pdo->exec("ALTER TABLE payments ADD COLUMN transaction_reference VARCHAR(100) NULL AFTER payment_method");
}

if (!columnExists($pdo, 'payments', 'payment_status')) {
    echo "Adding payment_status to payments...\n";
    $pdo->exec("ALTER TABLE payments ADD COLUMN payment_status VARCHAR(50) NOT NULL DEFAULT 'PAID' AFTER transaction_reference");
}

if (!columnExists($pdo, 'payments', 'receipt_no')) {
    echo "Adding receipt_no to payments...\n";
    $pdo->exec("ALTER TABLE payments ADD COLUMN receipt_no VARCHAR(64) NULL AFTER payment_status");
}

if (!columnExists($pdo, 'payments', 'notes')) {
    echo "Adding notes to payments...\n";
    $pdo->exec("ALTER TABLE payments ADD COLUMN notes TEXT NULL AFTER receipt_no");
}

if (!columnExists($pdo, 'payments', 'created_at')) {
    echo "Adding created_at to payments...\n";
    $pdo->exec("ALTER TABLE payments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER payment_date");
}

// Change payment_method to VARCHAR so it supports 'upi', 'demo_upi', 'cash', etc.
$pdo->exec("ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'cash'");

// Backfill payment_id and receipt_no for existing payments
$existingPmts = $pdo->query("SELECT id, payment_date FROM payments WHERE payment_id IS NULL OR payment_id = ''")->fetchAll();
foreach ($existingPmts as $pmt) {
    $datePart = date('Ymd', strtotime($pmt['payment_date']));
    $pid = sprintf("LIBFINE%s%04d", $datePart, $pmt['id']);
    $rcpt = sprintf("RCPT-%s-%04d", $datePart, $pmt['id']);
    $pdo->prepare("UPDATE payments SET payment_id=?, receipt_no=? WHERE id=?")->execute([$pid, $rcpt, $pmt['id']]);
}

// 3. Upgrade `library_settings` table
if (!columnExists($pdo, 'library_settings', 'upi_id')) {
    echo "Adding UPI fields to library_settings...\n";
    $pdo->exec("ALTER TABLE library_settings ADD COLUMN upi_id VARCHAR(100) NOT NULL DEFAULT 'smartlibrary@upi'");
}
if (!columnExists($pdo, 'library_settings', 'upi_merchant_name')) {
    $pdo->exec("ALTER TABLE library_settings ADD COLUMN upi_merchant_name VARCHAR(150) NOT NULL DEFAULT 'Smart Library'");
}
if (!columnExists($pdo, 'library_settings', 'library_address')) {
    $pdo->exec("ALTER TABLE library_settings ADD COLUMN library_address VARCHAR(255) NOT NULL DEFAULT 'Central Campus Library, Main Academic Block, Pune - 411001'");
}
if (!columnExists($pdo, 'library_settings', 'library_phone')) {
    $pdo->exec("ALTER TABLE library_settings ADD COLUMN library_phone VARCHAR(50) NOT NULL DEFAULT '+91 98765 43210'");
}
if (!columnExists($pdo, 'library_settings', 'sms_provider')) {
    $pdo->exec("ALTER TABLE library_settings ADD COLUMN sms_provider VARCHAR(50) NOT NULL DEFAULT 'demo'");
}
if (!columnExists($pdo, 'library_settings', 'sms_api_key')) {
    $pdo->exec("ALTER TABLE library_settings ADD COLUMN sms_api_key VARCHAR(255) DEFAULT ''");
}
if (!columnExists($pdo, 'library_settings', 'whatsapp_api_key')) {
    $pdo->exec("ALTER TABLE library_settings ADD COLUMN whatsapp_api_key VARCHAR(255) DEFAULT ''");
}

echo "DB Schema Migration Completed Successfully!\n";
