<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);

$reportType = $_GET['type'] ?? 'issued';

$reportQueries = [
    // Physical Library Reports
    'total_books'        => "SELECT b.title, a.name author, c.name category, b.total_copies, b.available_copies FROM books b LEFT JOIN authors a ON a.id=b.author_id LEFT JOIN categories c ON c.id=b.category_id ORDER BY b.title",
    'available'          => "SELECT b.title, a.name author, b.available_copies, b.shelf_number FROM books b LEFT JOIN authors a ON a.id=b.author_id WHERE b.available_copies > 0 ORDER BY b.title",
    'issued'             => "SELECT u.name student, s.roll_number, b.title, bi.issue_date, bi.due_date FROM book_issues bi JOIN students s ON s.id=bi.student_id JOIN users u ON u.id=s.user_id JOIN books b ON b.id=bi.book_id WHERE bi.status IN ('issued','overdue') ORDER BY bi.due_date",
    'returned'           => "SELECT u.name student, s.roll_number, b.title, br.return_date, br.late_days, br.fine_amount FROM book_returns br JOIN book_issues bi ON bi.id=br.issue_id JOIN students s ON s.id=bi.student_id JOIN users u ON u.id=s.user_id JOIN books b ON b.id=bi.book_id ORDER BY br.return_date DESC",
    'overdue'            => "SELECT u.name student, s.roll_number, b.title, bi.due_date, DATEDIFF(CURDATE(), bi.due_date) late_days FROM book_issues bi JOIN students s ON s.id=bi.student_id JOIN users u ON u.id=s.user_id JOIN books b ON b.id=bi.book_id WHERE bi.status='issued' AND bi.due_date < CURDATE() ORDER BY late_days DESC",
    'fines'              => "SELECT u.name student, s.roll_number, f.amount, f.reason, f.status, f.created_at FROM fines f JOIN students s ON s.id=f.student_id JOIN users u ON u.id=s.user_id ORDER BY f.created_at DESC",
    'payments'           => "SELECT u.name student, s.roll_number, p.amount, p.payment_method, p.payment_date FROM payments p JOIN students s ON s.id=p.student_id JOIN users u ON u.id=s.user_id ORDER BY p.payment_date DESC",
    'student_borrow'     => "SELECT u.name student, s.roll_number, COUNT(bi.id) total_borrowed FROM students s JOIN users u ON u.id=s.user_id LEFT JOIN book_issues bi ON bi.student_id=s.id GROUP BY s.id ORDER BY total_borrowed DESC",
    'most_borrowed'      => "SELECT b.title, COUNT(bi.id) times_borrowed FROM books b LEFT JOIN book_issues bi ON bi.book_id=b.id GROUP BY b.id ORDER BY times_borrowed DESC LIMIT 20",
    'category_wise'      => "SELECT c.name category, COUNT(b.id) total_books FROM categories c LEFT JOIN books b ON b.category_id=c.id GROUP BY c.id ORDER BY total_books DESC",
    'monthly'            => "SELECT DATE_FORMAT(issue_date,'%Y-%m') month, COUNT(*) total_issued FROM book_issues GROUP BY month ORDER BY month DESC LIMIT 12",
    
    // Virtual Library Reports
    'total_digital'      => "SELECT db.title, COALESCE(a.name, db.author_name) author, c.name category, db.pages, db.language, db.access_type, db.view_count, db.read_count FROM digital_books db LEFT JOIN authors a ON a.id=db.author_id LEFT JOIN categories c ON c.id=db.category_id ORDER BY db.title",
    'digital_by_cat'     => "SELECT c.name category, COUNT(db.id) total_digital_books, COALESCE(SUM(db.view_count),0) total_views FROM categories c LEFT JOIN digital_books db ON db.category_id=c.id WHERE db.status='active' GROUP BY c.id ORDER BY total_digital_books DESC",
    'most_viewed_digital'=> "SELECT db.title, COALESCE(a.name, db.author_name) author, c.name category, db.view_count, db.read_count FROM digital_books db LEFT JOIN authors a ON a.id=db.author_id LEFT JOIN categories c ON c.id=db.category_id ORDER BY db.view_count DESC LIMIT 25",
    'reading_activity'   => "SELECT u.name student, s.roll_number, db.title, rh.last_page, rh.total_pages, CONCAT(ROUND(rh.progress_percent), '%') progress, rh.reading_status, rh.last_accessed_at FROM reading_history rh JOIN students s ON s.id=rh.student_id JOIN users u ON u.id=s.user_id JOIN digital_books db ON db.id=rh.digital_book_id ORDER BY rh.last_accessed_at DESC",
    'active_readers'     => "SELECT u.name student, s.roll_number, COUNT(rh.id) digital_books_read, MAX(rh.last_accessed_at) last_active FROM students s JOIN users u ON u.id=s.user_id JOIN reading_history rh ON rh.student_id=s.id GROUP BY s.id ORDER BY digital_books_read DESC",
    'recent_digital'     => "SELECT db.title, COALESCE(a.name, db.author_name) author, c.name category, db.access_type, db.created_at FROM digital_books db LEFT JOIN authors a ON a.id=db.author_id LEFT JOIN categories c ON c.id=db.category_id ORDER BY db.id DESC LIMIT 20",
    'attendance_report'  => "SELECT u.name student, s.roll_number, la.entry_time, la.exit_time, la.purpose FROM library_attendance la JOIN students s ON s.id=la.student_id JOIN users u ON u.id=s.user_id ORDER BY la.entry_time DESC"
];

$labels = [
    // Physical
    'issued'             => 'Physical: Issued Books Report',
    'total_books'        => 'Physical: Total Books Inventory',
    'available'          => 'Physical: Available Books on Shelf',
    'returned'           => 'Physical: Returned Books Report',
    'overdue'            => 'Physical: Overdue Books Report',
    'fines'              => 'Physical: Fines Report',
    'payments'           => 'Physical: Payments Report',
    'student_borrow'     => 'Physical: Student Borrowing Report',
    'most_borrowed'      => 'Physical: Most Borrowed Books',
    'category_wise'      => 'Physical: Category-wise Inventory',
    'monthly'            => 'Physical: Monthly Issue Report',
    'attendance_report'  => 'Physical: Library Attendance Records',
    
    // Virtual
    'total_digital'      => 'Virtual: Total Digital Resources',
    'digital_by_cat'     => 'Virtual: Digital Books by Category',
    'most_viewed_digital'=> 'Virtual: Most Viewed E-Books',
    'reading_activity'   => 'Virtual: Student Reading Activity Sessions',
    'active_readers'     => 'Virtual: Active Readers Report',
    'recent_digital'     => 'Virtual: Recently Added Digital Resources',
];

if (!isset($reportQueries[$reportType])) { $reportType = 'issued'; }
$rows = $pdo->query($reportQueries[$reportType])->fetchAll();
$columns = $rows ? array_keys($rows[0]) : [];

// ---------- CSV export ----------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $reportType . '_report_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_map(fn($c) => ucwords(str_replace('_', ' ', $c)), $columns));
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

$pageTitle = 'Reports & Analytics';
$currentPage = 'reports.php';
include __DIR__ . '/../includes/header.php';
?>
<div class="table-toolbar no-print">
    <select class="form-control" style="width:340px;" onchange="window.location='reports.php?type='+this.value">
        <optgroup label="Physical Library Reports">
            <option value="issued" <?= $reportType === 'issued' ? 'selected' : '' ?>>Physical: Issued Books Report</option>
            <option value="total_books" <?= $reportType === 'total_books' ? 'selected' : '' ?>>Physical: Total Books Inventory</option>
            <option value="available" <?= $reportType === 'available' ? 'selected' : '' ?>>Physical: Available Books on Shelf</option>
            <option value="returned" <?= $reportType === 'returned' ? 'selected' : '' ?>>Physical: Returned Books Report</option>
            <option value="overdue" <?= $reportType === 'overdue' ? 'selected' : '' ?>>Physical: Overdue Books Report</option>
            <option value="fines" <?= $reportType === 'fines' ? 'selected' : '' ?>>Physical: Fines Report</option>
            <option value="payments" <?= $reportType === 'payments' ? 'selected' : '' ?>>Physical: Payments Report</option>
            <option value="student_borrow" <?= $reportType === 'student_borrow' ? 'selected' : '' ?>>Physical: Student Borrowing Report</option>
            <option value="most_borrowed" <?= $reportType === 'most_borrowed' ? 'selected' : '' ?>>Physical: Most Borrowed Books</option>
            <option value="category_wise" <?= $reportType === 'category_wise' ? 'selected' : '' ?>>Physical: Category-wise Inventory</option>
            <option value="monthly" <?= $reportType === 'monthly' ? 'selected' : '' ?>>Physical: Monthly Issue Report</option>
            <option value="attendance_report" <?= $reportType === 'attendance_report' ? 'selected' : '' ?>>Physical: Library Attendance Records</option>
        </optgroup>
        <optgroup label="Virtual Library Reports">
            <option value="total_digital" <?= $reportType === 'total_digital' ? 'selected' : '' ?>>Virtual: Total Digital Resources</option>
            <option value="digital_by_cat" <?= $reportType === 'digital_by_cat' ? 'selected' : '' ?>>Virtual: Digital Books by Category</option>
            <option value="most_viewed_digital" <?= $reportType === 'most_viewed_digital' ? 'selected' : '' ?>>Virtual: Most Viewed E-Books</option>
            <option value="reading_activity" <?= $reportType === 'reading_activity' ? 'selected' : '' ?>>Virtual: Reading Activity Sessions</option>
            <option value="active_readers" <?= $reportType === 'active_readers' ? 'selected' : '' ?>>Virtual: Active Readers Report</option>
            <option value="recent_digital" <?= $reportType === 'recent_digital' ? 'selected' : '' ?>>Virtual: Recently Added Digital Resources</option>
        </optgroup>
    </select>
    <div class="flex gap-2">
        <a class="btn btn-outline" href="reports.php?type=<?= $reportType ?>&export=csv"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
        <button class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Report</button>
    </div>
</div>

<div class="card">
    <div class="flex justify-between items-center" style="margin-bottom:12px;">
        <h3 style="margin:0;"><?= e($labels[$reportType]) ?></h3>
        <span class="text-muted" style="font-weight:400; font-size:13px;">Generated on <?= date('d M Y, h:i A') ?> &middot; <?= count($rows) ?> records</span>
    </div>
    <?php if (!$rows): ?>
        <div class="empty-state"><i class="fa-solid fa-chart-column"></i>No data available for this report.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><?php foreach ($columns as $c): ?><th><?= e(ucwords(str_replace('_',' ',$c))) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><?php foreach ($r as $col => $val): ?>
                    <td><?= e(is_null($val) ? '—' : $val) ?></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
