<?php
/**
 * Database Migration for Real UPI Digital Book Rentals
 */
require_once __DIR__ . '/../config/database.php';

try {
    echo "Running digital book rentals migration...\n";

    // 1. Add columns to library_settings if missing
    $cols = $pdo->query("SHOW COLUMNS FROM library_settings")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('rental_price_per_day', $cols)) {
        $pdo->exec("ALTER TABLE library_settings ADD COLUMN rental_price_per_day DECIMAL(8,2) NOT NULL DEFAULT 5.00");
        echo "- Added rental_price_per_day to library_settings\n";
    }
    if (!in_array('upi_id', $cols)) {
        $pdo->exec("ALTER TABLE library_settings ADD COLUMN upi_id VARCHAR(100) NOT NULL DEFAULT '8766087273@ptyes'");
        echo "- Added upi_id to library_settings\n";
    }
    if (!in_array('payment_qr_path', $cols)) {
        $pdo->exec("ALTER TABLE library_settings ADD COLUMN payment_qr_path VARCHAR(255) NOT NULL DEFAULT 'assets/images/payment-qr.png'");
        echo "- Added payment_qr_path to library_settings\n";
    }
    if (!in_array('upi_account_name', $cols)) {
        $pdo->exec("ALTER TABLE library_settings ADD COLUMN upi_account_name VARCHAR(150) NOT NULL DEFAULT 'Smart Library'");
        echo "- Added upi_account_name to library_settings\n";
    }

    // Ensure settings row has the user's UPI details
    $pdo->exec("UPDATE library_settings SET upi_id = '8766087273@ptyes', rental_price_per_day = 5.00, payment_qr_path = 'assets/images/payment-qr.png' WHERE id = 1");

    // 2. Expand digital_books access_type enum
    $pdo->exec("ALTER TABLE digital_books MODIFY COLUMN access_type ENUM('free','restricted','open_access','rental') NOT NULL DEFAULT 'free'");
    echo "- Updated digital_books.access_type enum to include 'rental'\n";

    // 3. Create book_rentals table
    $sql = "
    CREATE TABLE IF NOT EXISTS book_rentals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rental_code VARCHAR(30) NOT NULL UNIQUE,
        student_id INT NOT NULL,
        digital_book_id INT NOT NULL,
        rental_days INT NOT NULL DEFAULT 1,
        price_per_day DECIMAL(8,2) NOT NULL DEFAULT 5.00,
        total_amount DECIMAL(8,2) NOT NULL,
        payment_method VARCHAR(50) NOT NULL DEFAULT 'UPI_QR',
        upi_id VARCHAR(100) NOT NULL DEFAULT '8766087273@ptyes',
        transaction_reference VARCHAR(100) DEFAULT NULL,
        payment_status ENUM('PENDING','PAID','FAILED','REJECTED','REFUNDED') NOT NULL DEFAULT 'PENDING',
        rental_status ENUM('pending','active','expired','cancelled') NOT NULL DEFAULT 'pending',
        start_date DATETIME DEFAULT NULL,
        expiry_date DATETIME DEFAULT NULL,
        verified_by INT DEFAULT NULL,
        verified_at DATETIME DEFAULT NULL,
        rejection_reason VARCHAR(255) DEFAULT NULL,
        admin_notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        FOREIGN KEY (digital_book_id) REFERENCES digital_books(id) ON DELETE CASCADE,
        FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_br_student (student_id),
        INDEX idx_br_book (digital_book_id),
        INDEX idx_br_pay_status (payment_status),
        INDEX idx_br_rent_status (rental_status),
        INDEX idx_br_expiry (expiry_date),
        INDEX idx_br_utr (transaction_reference)
    ) ENGINE=InnoDB;
    ";
    $pdo->exec($sql);
    echo "- Created book_rentals table successfully\n";

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
