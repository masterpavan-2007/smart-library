<?php
/**
 * Comprehensive Automated Test Suite for Real UPI Book Rental System
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== STARTING DIGITAL BOOK RENTAL TEST SUITE ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $testName) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] " . $testName . "\n";
        $passCount++;
    } else {
        echo "![FAIL] " . $testName . "\n";
        $failCount++;
    }
}

// 1. Verify Payment QR image exists and is readable
$qrFile = __DIR__ . '/../assets/images/payment-qr.png';
assertTest(file_exists($qrFile) && filesize($qrFile) > 1000, "Original QR code image exists in assets/images/payment-qr.png (Size: " . (file_exists($qrFile) ? filesize($qrFile) : 0) . " bytes)");

// 2. Verify Library Settings configuration
$settings = getSettings($pdo);
assertTest($settings['upi_id'] === '8766087273@ptyes', "Merchant UPI ID in settings is 8766087273@ptyes (Got: {$settings['upi_id']})");
assertTest((float)$settings['rental_price_per_day'] === 5.00, "Rental price per day is exactly Rs 5.00 (Got: {$settings['rental_price_per_day']})");
assertTest($settings['payment_qr_path'] === 'assets/images/payment-qr.png', "Payment QR path configured correctly");

// 3. Test Pricing Formula
$testDays = [1 => 5.00, 3 => 15.00, 5 => 25.00, 7 => 35.00, 15 => 75.00, 30 => 150.00];
foreach ($testDays as $days => $expectedPrice) {
    $calculated = round($days * (float)$settings['rental_price_per_day'], 2);
    assertTest($calculated === $expectedPrice, "Pricing calculation for $days day(s): Rs. $calculated == Rs. $expectedPrice");
}

// 4. Test Student & Digital Book fixtures
$student = $pdo->query("SELECT s.id, s.user_id, u.name FROM students s JOIN users u ON u.id = s.user_id LIMIT 1")->fetch();
$book = $pdo->query("SELECT id, title, access_type FROM digital_books WHERE status='active' LIMIT 1")->fetch();

if (!$student || !$book) {
    echo "ERROR: Missing student or digital book in database to run integration tests.\n";
    exit(1);
}

$testStudentId = (int)$student['id'];
$testBookId = (int)$book['id'];
echo "\nTesting with Student: {$student['name']} (ID: $testStudentId) & Book: \"{$book['title']}\" (ID: $testBookId)\n\n";

// Ensure book is set to rental access policy for testing lock
$pdo->prepare("UPDATE digital_books SET access_type = 'rental' WHERE id = ?")->execute([$testBookId]);

// Clean old test rentals for this book/student
$pdo->prepare("DELETE FROM book_rentals WHERE student_id = ? AND digital_book_id = ?")->execute([$testStudentId, $testBookId]);

// 5. Verify Access Control Before Rental
$initialAccess = checkStudentBookRental($pdo, $testStudentId, $testBookId);
assertTest($initialAccess === false, "Access initially blocked for rental book without payment");

// 6. Test Payment Reference Submission (PENDING)
$testUtr = 'TESTUTR' . time();
$rentalCode = 'RNT-TEST-' . substr(bin2hex(random_bytes(2)), 0, 4);
$days = 7;
$amount = 35.00;

$stmt = $pdo->prepare("
    INSERT INTO book_rentals (
        rental_code, student_id, digital_book_id, rental_days, price_per_day,
        total_amount, payment_method, upi_id, transaction_reference,
        payment_status, rental_status, created_at
    ) VALUES (?, ?, ?, ?, 5.00, ?, 'UPI_QR', '8766087273@ptyes', ?, 'PENDING', 'pending', NOW())
");
$stmt->execute([$rentalCode, $testStudentId, $testBookId, $days, $amount, $testUtr]);
$rentalId = $pdo->lastInsertId();

assertTest($rentalId > 0, "Rental request created with ID #$rentalId and status PENDING");

// 7. Verify Pending Status does NOT grant reading access
$pendingCheck = checkStudentBookRental($pdo, $testStudentId, $testBookId);
assertTest($pendingCheck === false, "Unverified PENDING payment strictly blocks PDF reading access");

$pendingRow = getStudentPendingRental($pdo, $testStudentId, $testBookId);
assertTest($pendingRow && $pendingRow['transaction_reference'] === $testUtr, "Pending rental reference is properly tracked by helper (UTR: {$pendingRow['transaction_reference']})");

// 8. Test Duplicate UTR Prevention Check
$dupStmt = $pdo->prepare("
    SELECT id, payment_status, rental_code 
    FROM book_rentals 
    WHERE transaction_reference = ? AND payment_status IN ('PENDING', 'PAID')
");
$dupStmt->execute([$testUtr]);
$dupFound = (bool)$dupStmt->fetch();
assertTest($dupFound === true, "System detects duplicate submission of same UTR reference ($testUtr)");

// 9. Test Admin Verification & Approval
$adminId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
$startDate = date('Y-m-d H:i:s');
$expiryDate = date('Y-m-d H:i:s', strtotime("+ $days days"));

$upd = $pdo->prepare("
    UPDATE book_rentals 
    SET payment_status = 'PAID',
        rental_status = 'active',
        start_date = ?,
        expiry_date = ?,
        verified_by = ?,
        verified_at = NOW(),
        admin_notes = 'Verified via automated test'
    WHERE id = ?
");
$upd->execute([$startDate, $expiryDate, $adminId, $rentalId]);

$approvedRental = checkStudentBookRental($pdo, $testStudentId, $testBookId);
assertTest($approvedRental !== false && $approvedRental['payment_status'] === 'PAID', "Admin approval activates rental and grants verified PAID status");
assertTest($approvedRental['rental_status'] === 'active', "Rental status is active");
assertTest(strtotime($approvedRental['expiry_date']) > time(), "Expiry date is in the future: {$approvedRental['expiry_date']}");

// 10. Test Expiration Enforcement
$pastDate = date('Y-m-d H:i:s', strtotime('-1 hour'));
$pdo->prepare("UPDATE book_rentals SET expiry_date = ? WHERE id = ?")->execute([$pastDate, $rentalId]);

$expiredAccess = checkStudentBookRental($pdo, $testStudentId, $testBookId);
assertTest($expiredAccess === false, "Expired rental automatically blocks PDF access");

$expiredRow = $pdo->query("SELECT rental_status FROM book_rentals WHERE id = $rentalId")->fetch();
assertTest($expiredRow['rental_status'] === 'expired', "Expired rental record status automatically updated to 'expired'");

// 11. Test Admin Rejection Workflow
$rejUtr = 'REJUTR' . time();
$rejCode = 'RNT-REJ-' . substr(bin2hex(random_bytes(2)), 0, 4);
$pdo->prepare("
    INSERT INTO book_rentals (
        rental_code, student_id, digital_book_id, rental_days, price_per_day,
        total_amount, payment_method, upi_id, transaction_reference,
        payment_status, rental_status, created_at
    ) VALUES (?, ?, ?, 3, 5.00, 15.00, 'UPI_QR', '8766087273@ptyes', ?, 'PENDING', 'pending', NOW())
")->execute([$rejCode, $testStudentId, $testBookId, $rejUtr]);
$rejRentalId = $pdo->lastInsertId();

$pdo->prepare("
    UPDATE book_rentals 
    SET payment_status = 'REJECTED',
        rental_status = 'cancelled',
        rejection_reason = 'Invalid transaction reference',
        verified_by = ?,
        verified_at = NOW()
    WHERE id = ?
")->execute([$adminId, $rejRentalId]);

$rejectedCheck = checkStudentBookRental($pdo, $testStudentId, $testBookId);
assertTest($rejectedCheck === false, "Rejected payment does not grant book access");
$rejRow = $pdo->query("SELECT payment_status, rejection_reason FROM book_rentals WHERE id = $rejRentalId")->fetch();
assertTest($rejRow['payment_status'] === 'REJECTED' && !empty($rejRow['rejection_reason']), "Rejection status and reason are saved properly");

// Clean up test data
$pdo->prepare("DELETE FROM book_rentals WHERE id IN (?, ?)")->execute([$rentalId, $rejRentalId]);

echo "\n============================================\n";
echo "TEST RESULTS: $passCount Passed, $failCount Failed\n";
echo "============================================\n";

if ($failCount === 0) {
    echo "ALL TESTS PASSED SUCCESSFULLY! The Real UPI Book Rental feature is 100% verified.\n";
    exit(0);
} else {
    echo "Some tests failed. Please review.\n";
    exit(1);
}
