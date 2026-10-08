<?php
/**
 * Admin Dashboard - Physical & Virtual Library Analytics
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
requireRole(['admin']);

$pageTitle = 'Admin Dashboard';
$currentPage = 'dashboard.php';

// ---- Physical Library Stat cards ----
$totalPhysicalTitles = (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
$totalPhysicalCopies = (int)$pdo->query("SELECT COALESCE(SUM(total_copies),0) FROM books")->fetchColumn();
$availableCopies     = (int)$pdo->query("SELECT COALESCE(SUM(available_copies),0) FROM books")->fetchColumn();
$issuedBooks         = (int)$pdo->query("SELECT COUNT(*) FROM book_issues WHERE status IN ('issued','overdue')")->fetchColumn();
$overdueBooks        = (int)$pdo->query("SELECT COUNT(*) FROM book_issues WHERE status='issued' AND due_date < CURDATE()")->fetchColumn();
$totalStudents       = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$totalReservations   = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status IN ('pending','approved','ready')")->fetchColumn();

// ---- Fine & Revenue Statistics (Section 11) ----
syncStudentFines($pdo);
$totalFineOutstanding = (float)$pdo->query("
    SELECT COALESCE(SUM(CASE WHEN outstanding_amount > 0 THEN outstanding_amount ELSE (fine_amount - paid_amount) END), 0)
    FROM fines WHERE status IN ('unpaid', 'pending', 'partially_paid')
")->fetchColumn();
$totalFineCollected   = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments")->fetchColumn();
$pendingPaymentsCount = (int)$pdo->query("SELECT COUNT(*) FROM fines WHERE status IN ('unpaid', 'pending', 'partially_paid')")->fetchColumn();
$paidFinesCount       = (int)$pdo->query("SELECT COUNT(*) FROM fines WHERE status = 'paid'")->fetchColumn();
$studentsWithFineCount= (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM fines WHERE status IN ('unpaid', 'pending', 'partially_paid')")->fetchColumn();

// ---- Virtual Library Stat cards ----
$totalDigitalBooks    = (int)$pdo->query("SELECT COUNT(*) FROM digital_books")->fetchColumn();
$availableDigital     = (int)$pdo->query("SELECT COUNT(*) FROM digital_books WHERE status='active'")->fetchColumn();
$totalReadingSessions = (int)$pdo->query("SELECT COUNT(*) FROM reading_history")->fetchColumn();
$activeReaders        = (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM reading_history")->fetchColumn();

// ---- Chart 1: monthly issues vs returns (last 6 months) ----
$issueStmt = $pdo->query("
    SELECT DATE_FORMAT(issue_date, '%Y-%m') ym, COUNT(*) c
    FROM book_issues
    WHERE issue_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym");
$issuesByMonth = $issueStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$returnStmt = $pdo->query("
    SELECT DATE_FORMAT(return_date, '%Y-%m') ym, COUNT(*) c
    FROM book_returns
    WHERE return_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym");
$returnsByMonth = $returnStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[] = date('Y-m', strtotime("-$i months"));
}
$issueSeries = array_map(fn($m) => (int)($issuesByMonth[$m] ?? 0), $months);
$returnSeries = array_map(fn($m) => (int)($returnsByMonth[$m] ?? 0), $months);
$monthLabels = array_map(fn($m) => date('M Y', strtotime($m . '-01')), $months);

// ---- Chart 2: Physical Category distribution ----
$catDist = $pdo->query("
    SELECT c.name, COUNT(b.id) c
    FROM categories c LEFT JOIN books b ON b.category_id = c.id
    GROUP BY c.id HAVING c > 0 ORDER BY c DESC LIMIT 8")->fetchAll();

// ---- Chart 3: Virtual Category distribution ----
$virtCatDist = $pdo->query("
    SELECT c.name, COUNT(db.id) c
    FROM categories c LEFT JOIN digital_books db ON db.category_id = c.id
    WHERE db.status = 'active'
    GROUP BY c.id HAVING c > 0 ORDER BY c DESC LIMIT 8")->fetchAll();

// ---- Chart 4: Most borrowed physical books (top 5) ----
$topBooks = $pdo->query("
    SELECT b.title, COUNT(*) c
    FROM book_issues bi JOIN books b ON b.id = bi.book_id
    GROUP BY b.id ORDER BY c DESC LIMIT 5")->fetchAll();

// ---- Chart 5: Most accessed digital books (top 5) ----
$topDigitalBooks = $pdo->query("
    SELECT title, view_count, read_count
    FROM digital_books WHERE status='active'
    ORDER BY (view_count + read_count * 2) DESC LIMIT 5")->fetchAll();

// ---- Recent activity ----
$recentIssues = $pdo->query("
    SELECT bi.issue_date, b.title, u.name student_name
    FROM book_issues bi
    JOIN books b ON b.id = bi.book_id
    JOIN students s ON s.id = bi.student_id
    JOIN users u ON u.id = s.user_id
    ORDER BY bi.id DESC LIMIT 4")->fetchAll();

$recentReturns = $pdo->query("
    SELECT br.return_date, b.title, u.name student_name
    FROM book_returns br
    JOIN book_issues bi ON bi.id = br.issue_id
    JOIN books b ON b.id = bi.book_id
    JOIN students s ON s.id = bi.student_id
    JOIN users u ON u.id = s.user_id
    ORDER BY br.id DESC LIMIT 4")->fetchAll();

$recentDigitalReads = $pdo->query("
    SELECT rh.last_accessed_at, rh.last_page, rh.progress_percent, db.title, u.name student_name
    FROM reading_history rh
    JOIN digital_books db ON db.id = rh.digital_book_id
    JOIN students s ON s.id = rh.student_id
    JOIN users u ON u.id = s.user_id
    ORDER BY rh.last_accessed_at DESC LIMIT 4")->fetchAll();

$recentUploadedBooks = $pdo->query("
    SELECT title, created_at, access_type
    FROM digital_books ORDER BY id DESC LIMIT 4")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Section: Physical Library Stat Cards -->
<div style="margin-bottom:8px;">
    <h3 style="font-size:16px; margin:0 0 10px 0; color:var(--text); display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-building-columns text-primary"></i> Physical Library Overview
    </h3>
    <div class="grid grid-4">
        <div class="card stat-card">
            <div class="icon blue"><i class="fa-solid fa-book"></i></div>
            <div><div class="num"><?= $totalPhysicalCopies ?></div><div class="label">Physical Copies (<?= $totalPhysicalTitles ?> Titles)</div></div>
        </div>
        <div class="card stat-card">
            <div class="icon green"><i class="fa-solid fa-circle-check"></i></div>
            <div><div class="num"><?= $availableCopies ?></div><div class="label">Available on Shelf</div></div>
        </div>
        <div class="card stat-card">
            <div class="icon yellow"><i class="fa-solid fa-arrow-right-from-bracket"></i></div>
            <div><div class="num"><?= $issuedBooks ?></div><div class="label">Currently Issued</div></div>
        </div>
        <div class="card stat-card">
            <div class="icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div><div class="num"><?= $overdueBooks ?></div><div class="label">Overdue Returns</div></div>
        </div>
    </div>
</div>

<!-- Section: Virtual Library Stat Cards -->
<div style="margin-top:18px; margin-bottom:8px;">
    <h3 style="font-size:16px; margin:0 0 10px 0; color:var(--text); display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-cloud-bolt text-primary"></i> Virtual Library Overview
    </h3>
    <div class="grid grid-4">
        <div class="card stat-card">
            <div class="icon blue"><i class="fa-solid fa-file-pdf"></i></div>
            <div><div class="num"><?= $totalDigitalBooks ?></div><div class="label">Digital E-Books & PDFs</div></div>
        </div>
        <div class="card stat-card">
            <div class="icon green"><i class="fa-solid fa-lock-open"></i></div>
            <div><div class="num"><?= $availableDigital ?></div><div class="label">Open Digital Resources</div></div>
        </div>
        <div class="card stat-card">
            <div class="icon yellow"><i class="fa-solid fa-book-open-reader"></i></div>
            <div><div class="num"><?= $totalReadingSessions ?></div><div class="label">Total Reading Sessions</div></div>
        </div>
        <div class="card stat-card">
            <div class="icon blue"><i class="fa-solid fa-users"></i></div>
            <div><div class="num"><?= $activeReaders ?></div><div class="label">Active Online Readers</div></div>
        </div>
    </div>
</div>

<!-- Section: Fine & Financial Revenue Dashboard (Section 11) -->
<div style="margin-top:18px; margin-bottom:8px;">
    <div class="flex justify-between items-center" style="margin-bottom:10px;">
        <h3 style="font-size:16px; margin:0; color:var(--text); display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-coins text-warning"></i> Fine Management & Financial Overview
        </h3>
        <a href="fines.php" style="font-size:13px; font-weight:600;">Manage Fines &rarr;</a>
    </div>
    <div class="grid grid-5">
        <div class="card stat-card">
            <div class="icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="num" style="color:#dc2626;">&#8377;<?= number_format($totalFineOutstanding, 2) ?></div>
                <div class="label">Total Outstanding</div>
            </div>
        </div>
        <div class="card stat-card">
            <div class="icon green"><i class="fa-solid fa-sack-dollar"></i></div>
            <div>
                <div class="num">&#8377;<?= number_format($totalFineCollected, 2) ?></div>
                <div class="label">Total Collected</div>
            </div>
        </div>
        <div class="card stat-card">
            <div class="icon yellow"><i class="fa-solid fa-clock"></i></div>
            <div>
                <div class="num"><?= $pendingPaymentsCount ?></div>
                <div class="label">Pending Payments</div>
            </div>
        </div>
        <div class="card stat-card">
            <div class="icon blue"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="num"><?= $paidFinesCount ?></div>
                <div class="label">Paid Fines</div>
            </div>
        </div>
        <div class="card stat-card">
            <div class="icon red"><i class="fa-solid fa-user-xmark"></i></div>
            <div>
                <div class="num"><?= $studentsWithFineCount ?></div>
                <div class="label">Students with Fine</div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Action Shortcuts -->
<div class="grid grid-4" style="margin-top:16px;">
    <a href="books.php?action=add" class="card stat-card" style="text-decoration:none;">
        <div class="icon blue"><i class="fa-solid fa-plus"></i></div>
        <div><div class="num" style="font-size:14px;">Add Physical Book</div><div class="label">New inventory</div></div>
    </a>
    <a href="digital-books.php" class="card stat-card" style="text-decoration:none;">
        <div class="icon green"><i class="fa-solid fa-cloud-arrow-up"></i></div>
        <div><div class="num" style="font-size:14px;">Upload E-Book</div><div class="label">Virtual library</div></div>
    </a>
    <a href="students.php?action=add" class="card stat-card" style="text-decoration:none;">
        <div class="icon yellow"><i class="fa-solid fa-user-plus"></i></div>
        <div><div class="num" style="font-size:14px;">Register Student</div><div class="label">Student records</div></div>
    </a>
    <a href="reports.php" class="card stat-card" style="text-decoration:none;">
        <div class="icon red"><i class="fa-solid fa-chart-column"></i></div>
        <div><div class="num" style="font-size:14px;">Analytics Reports</div><div class="label">Export CSV / Print</div></div>
    </a>
</div>

<!-- Primary Charts: Issues vs Returns + Physical Categories -->
<div class="grid grid-2" style="margin-top:18px;">
    <div class="card">
        <h3>Physical Issues vs Returns (Last 6 Months)</h3>
        <canvas id="issueReturnChart" height="200"></canvas>
    </div>
    <div class="card">
        <h3>Physical Book Category Distribution</h3>
        <canvas id="categoryChart" height="200"></canvas>
    </div>
</div>

<!-- Secondary Charts: Virtual Category Distribution + Most Accessed E-Books -->
<div class="grid grid-2" style="margin-top:18px;">
    <div class="card">
        <h3>Virtual E-Book Category Distribution</h3>
        <canvas id="virtCatChart" height="200"></canvas>
    </div>
    <div class="card">
        <h3>Top Digital Resources by Views</h3>
        <canvas id="topDigitalChart" height="200"></canvas>
    </div>
</div>

<!-- Recent Activity Feeds -->
<div class="grid grid-2" style="margin-top:18px;">
    <!-- Physical Activity -->
    <div class="card">
        <h3>Recent Physical Circulation</h3>
        <?php if (!$recentIssues && !$recentReturns): ?>
            <div class="empty-state"><i class="fa-solid fa-inbox"></i>No recent circulation activity.</div>
        <?php else: ?>
            <div style="max-height:260px; overflow-y:auto;">
                <?php foreach ($recentIssues as $r): ?>
                    <div style="padding:8px 0; border-bottom:1px solid var(--border); font-size:13.5px;">
                        <i class="fa-solid fa-arrow-right-from-bracket text-muted"></i>
                        <strong><?= e($r['student_name']) ?></strong> issued <em><?= e($r['title']) ?></em>
                        <span class="text-muted"> &middot; <?= fmtDate($r['issue_date']) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($recentReturns as $r): ?>
                    <div style="padding:8px 0; border-bottom:1px solid var(--border); font-size:13.5px;">
                        <i class="fa-solid fa-arrow-right-to-bracket text-muted"></i>
                        <strong><?= e($r['student_name']) ?></strong> returned <em><?= e($r['title']) ?></em>
                        <span class="text-muted"> &middot; <?= fmtDate($r['return_date']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Virtual Reading Activity -->
    <div class="card">
        <h3>Recent Virtual Reading Activity</h3>
        <?php if (!$recentDigitalReads): ?>
            <div class="empty-state"><i class="fa-solid fa-laptop-code"></i>No recent digital reading sessions.</div>
        <?php else: ?>
            <div style="max-height:260px; overflow-y:auto;">
                <?php foreach ($recentDigitalReads as $dr): ?>
                    <div style="padding:8px 0; border-bottom:1px solid var(--border); font-size:13.5px;">
                        <i class="fa-solid fa-book-open-reader text-primary"></i>
                        <strong><?= e($dr['student_name']) ?></strong> read <em><?= e($dr['title']) ?></em>
                        <span class="badge badge-blue" style="font-size:10px; margin-left:4px;">Pg <?= (int)$dr['last_page'] ?> (<?= round((float)$dr['progress_percent']) ?>%)</span>
                        <div class="text-muted" style="font-size:11.5px;"><?= date('d M Y, h:i A', strtotime($dr['last_accessed_at'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const chartColors = ['#4f46e5','#16a34a','#ca8a04','#dc2626','#0891b2','#9333ea','#db2777','#65a30d'];

// Monthly issues vs returns
new Chart(document.getElementById('issueReturnChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($monthLabels) ?>,
        datasets: [
            { label: 'Issued', data: <?= json_encode($issueSeries) ?>, borderColor: '#4f46e5', backgroundColor: '#4f46e5', tension: 0.3 },
            { label: 'Returned', data: <?= json_encode($returnSeries) ?>, borderColor: '#16a34a', backgroundColor: '#16a34a', tension: 0.3 }
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});

// Physical category chart
new Chart(document.getElementById('categoryChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($catDist, 'name')) ?>,
        datasets: [{ data: <?= json_encode(array_column($catDist, 'c')) ?>, backgroundColor: chartColors }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});

// Virtual category chart
new Chart(document.getElementById('virtCatChart'), {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_column($virtCatDist, 'name')) ?>,
        datasets: [{ data: <?= json_encode(array_column($virtCatDist, 'c')) ?>, backgroundColor: chartColors.slice().reverse() }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});

// Top Digital Books chart
new Chart(document.getElementById('topDigitalChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($topDigitalBooks, 'title')) ?>,
        datasets: [{ label: 'Views', data: <?= json_encode(array_column($topDigitalBooks, 'view_count')) ?>, backgroundColor: '#0891b2', borderRadius: 6 }]
    },
    options: { responsive: true, indexAxis: 'y', plugins: { legend: { display: false } } }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
