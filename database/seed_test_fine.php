<?php
require_once __DIR__ . '/../config/database.php';

// 1. Update user name to "Pavan Ramgude"
$pdo->prepare("UPDATE users SET name='Pavan Ramgude', phone='8766087273' WHERE id=14")->execute();

// 2. Ensure book "Java Programming" exists
$stmt = $pdo->prepare("SELECT id FROM books WHERE title='Java Programming'");
$stmt->execute();
$javaBookId = $stmt->fetchColumn();

if (!$javaBookId) {
    // Check if book 31 can be updated or if we insert new
    $stmt = $pdo->prepare("SELECT id FROM books WHERE id=31");
    $stmt->execute();
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE books SET title='Java Programming' WHERE id=31")->execute();
        $javaBookId = 31;
    } else {
        $pdo->prepare("INSERT INTO books (isbn, title, category_id, total_copies, available_copies, price) VALUES ('9780134685999', 'Java Programming', 1, 5, 5, 599.00)")->execute();
        $javaBookId = $pdo->lastInsertId();
    }
} else {
    $pdo->prepare("UPDATE books SET title='Java Programming' WHERE id=?")->execute([$javaBookId]);
}

// 3. Find or create the issue record for Pavan (student_id=11)
$stmt = $pdo->prepare("SELECT id FROM book_issues WHERE student_id=11 AND book_id=? AND issue_date='2026-09-25'");
$stmt->execute([$javaBookId]);
$issueId = $stmt->fetchColumn();

if (!$issueId) {
    // Check issue 12 if we can update it or insert new
    $stmt = $pdo->prepare("SELECT id FROM book_issues WHERE id=12 AND student_id=11");
    $stmt->execute();
    if ($stmt->fetch()) {
        $issueId = 12;
        $pdo->prepare("UPDATE book_issues SET book_id=?, issue_date='2026-09-25', due_date='2026-10-01', return_date='2026-10-07', status='returned' WHERE id=12")
            ->execute([$javaBookId]);
    } else {
        $pdo->prepare("INSERT INTO book_issues (student_id, book_id, issued_by, issue_date, due_date, return_date, status) VALUES (11, ?, 17, '2026-09-25', '2026-10-01', '2026-10-07', 'returned')")
            ->execute([$javaBookId]);
        $issueId = $pdo->lastInsertId();
    }
} else {
    $pdo->prepare("UPDATE book_issues SET due_date='2026-10-01', return_date='2026-10-07', status='returned' WHERE id=?")->execute([$issueId]);
}

// 4. Ensure book_returns has the return record
$stmt = $pdo->prepare("SELECT id FROM book_returns WHERE issue_id=?");
$stmt->execute([$issueId]);
$returnId = $stmt->fetchColumn();
if (!$returnId) {
    $pdo->prepare("INSERT INTO book_returns (issue_id, return_date, late_days, fine_amount, received_by, remarks) VALUES (?, '2026-10-07', 6, 30.00, 17, 'Late return - 6 day(s)')")
        ->execute([$issueId]);
} else {
    $pdo->prepare("UPDATE book_returns SET return_date='2026-10-07', late_days=6, fine_amount=30.00, remarks='Late return - 6 day(s)' WHERE id=?")
        ->execute([$returnId]);
}

// 5. Ensure fine record for this issue exists with status 'unpaid'
$stmt = $pdo->prepare("SELECT id FROM fines WHERE issue_id=?");
$stmt->execute([$issueId]);
$fineId = $stmt->fetchColumn();

if (!$fineId) {
    $pdo->prepare("INSERT INTO fines (student_id, book_id, issue_id, due_date, return_date, late_days, fine_rate, fine_amount, paid_amount, outstanding_amount, amount, reason, status)
                   VALUES (11, ?, ?, '2026-10-01', '2026-10-07', 6, 5.00, 30.00, 0.00, 30.00, 30.00, 'Late return - 6 day(s)', 'unpaid')")
        ->execute([$javaBookId, $issueId]);
    $fineId = $pdo->lastInsertId();
} else {
    $pdo->prepare("UPDATE fines SET student_id=11, book_id=?, due_date='2026-10-01', return_date='2026-10-07', late_days=6, fine_rate=5.00, fine_amount=30.00, paid_amount=0.00, outstanding_amount=30.00, amount=30.00, reason='Late return - 6 day(s)', status='unpaid' WHERE id=?")
        ->execute([$javaBookId, $fineId]);
}

echo "Test Data Created Successfully!\n";
echo "Student: Pavan Ramgude (ID: IT2161, User ID: 14, Student ID: 11)\n";
echo "Book: Java Programming (Book ID: $javaBookId)\n";
echo "Issue ID: $issueId (Issued: 25/09/2026, Due: 01/10/2026, Returned: 07/10/2026)\n";
echo "Fine ID: $fineId (Late: 6 days, Rate: Rs. 5/day, Amount: Rs. 30.00, Status: UNPAID)\n";
