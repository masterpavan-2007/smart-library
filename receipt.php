<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (!isLoggedIn()) {
    redirect(basePath() . '/auth/login.php');
}

$paymentRef = trim($_GET['payment_id'] ?? ($_GET['id'] ?? ''));
if ($paymentRef === '') {
    die('Missing payment reference.');
}

// Fetch payment with fine, student, and book details
$stmt = $pdo->prepare("
    SELECT p.*, f.reason, f.late_days, f.fine_rate, f.fine_amount, f.due_date, f.return_date, f.issue_id,
           b.title as book_title, b.isbn, b.id as book_ref_id,
           bi.issue_date, COALESCE(f.due_date, bi.due_date) as final_due_date,
           COALESCE(f.return_date, bi.return_date) as final_return_date,
           s.roll_number, s.department, s.user_id as student_user_id,
           u.name as student_name, u.phone, u.email
    FROM payments p
    JOIN fines f ON f.id = p.fine_id
    JOIN students s ON s.id = p.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN books b ON b.id = f.book_id
    LEFT JOIN book_issues bi ON bi.id = f.issue_id
    WHERE p.payment_id = ? OR p.id = ? OR p.receipt_no = ?
    LIMIT 1
");
$stmt->execute([$paymentRef, is_numeric($paymentRef) ? (int)$paymentRef : 0, $paymentRef]);
$payment = $stmt->fetch();

if (!$payment) {
    die('<div style="font-family:sans-serif;text-align:center;padding:50px;"><h2>Receipt Not Found</h2><p>No valid payment receipt matches the provided identifier.</p><a href="javascript:history.back()">Go Back</a></div>');
}

// Security: Verify student can only see their own receipt
if ($_SESSION['role'] === 'student') {
    $currentStu = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $currentStu->execute([$_SESSION['user_id']]);
    $currentStudentId = (int)$currentStu->fetchColumn();
    if ((int)$payment['student_id'] !== $currentStudentId) {
        http_response_code(403);
        die('Unauthorized receipt access.');
    }
}

$settings = getSettings($pdo);
$libraryName = !empty($settings['library_name']) ? $settings['library_name'] : 'Smart Library';
$libraryAddress = !empty($settings['library_address']) ? $settings['library_address'] : 'Knowledge Hub, Central Campus, Pune - 411001';
$libraryPhone = !empty($settings['library_phone']) ? $settings['library_phone'] : '+91 98765 43210';

$receiptNo = !empty($payment['receipt_no']) ? $payment['receipt_no'] : ('RCPT-' . date('Ymd', strtotime($payment['payment_date'])) . '-' . $payment['id']);
$paymentId = !empty($payment['payment_id']) ? $payment['payment_id'] : ('LIBPAY' . $payment['id']);

$lateDays = (int)$payment['late_days'];
$fineRate = (float)($payment['fine_rate'] > 0 ? $payment['fine_rate'] : ($settings['fine_per_day'] ?? 5.00));
$fineAmount = (float)($payment['fine_amount'] > 0 ? $payment['fine_amount'] : $payment['amount']);
$paidAmount = (float)$payment['amount'];
$outstandingAmount = 0.00;

// Back button URL
$backUrl = match($_SESSION['role']) {
    'admin'     => basePath() . '/admin/payments.php',
    'librarian' => basePath() . '/librarian/fines.php',
    default     => basePath() . '/student/fines.php',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?= e($receiptNo) ?> &middot; <?= e($libraryName) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --text: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f1f5f9;
            color: var(--text);
            padding: 30px 16px;
            font-size: 14px;
        }
        .receipt-wrapper {
            max-width: 720px;
            margin: 0 auto;
        }
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: #4338ca; }
        .btn-outline { background: #fff; border-color: #cbd5e1; color: #334155; }
        .btn-outline:hover { background: #f8fafc; }
        .btn-success { background: #16a34a; color: #fff; }
        .btn-success:hover { background: #15803d; }

        /* Receipt Card */
        .receipt-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid var(--border);
            padding: 36px 40px;
            position: relative;
            overflow: hidden;
        }
        .watermark {
            position: absolute;
            top: 45%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-25deg);
            font-size: 84px;
            font-weight: 900;
            color: rgba(22, 163, 74, 0.07);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            pointer-events: none;
            user-select: none;
        }
        .receipt-header {
            text-align: center;
            padding-bottom: 22px;
            border-bottom: 2px solid var(--border);
            margin-bottom: 22px;
        }
        .receipt-title {
            font-size: 20px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #1e293b;
            margin-bottom: 4px;
        }
        .inst-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 4px;
        }
        .inst-meta {
            font-size: 12.5px;
            color: var(--text-muted);
            line-height: 1.5;
        }
        .meta-strip {
            display: flex;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 13px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .meta-item strong { display: block; font-size: 13.5px; color: #1e293b; margin-top: 2px; }
        .meta-item span { font-size: 11.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }

        .section-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px 20px;
            background: #fff;
            margin-bottom: 22px;
            font-size: 13.5px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px dashed #f1f5f9;
            padding-bottom: 6px;
        }
        .info-label { color: var(--text-muted); }
        .info-value { font-weight: 600; color: #0f172a; text-align: right; }

        .divider {
            border-top: 2px solid var(--border);
            margin: 22px 0;
        }
        .divider-dashed {
            border-top: 1px dashed var(--border);
            margin: 16px 0;
        }

        /* Financials Box */
        .financial-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .financial-table th {
            text-align: left;
            padding: 10px 12px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
        }
        .financial-table td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
        }
        .total-row td {
            font-weight: 700;
            font-size: 15px;
            border-top: 2px solid var(--border);
            border-bottom: 2px solid var(--border);
            background: #fcfdfd;
        }

        .status-stamp {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 2px solid #16a34a;
            color: #16a34a;
            padding: 6px 18px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            background: #f0fdf4;
        }

        .receipt-footer {
            text-align: center;
            margin-top: 28px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Print styles */
        @media print {
            body { background: #fff; padding: 0; }
            .action-bar { display: none !important; }
            .receipt-card {
                box-shadow: none !important;
                border: 1px solid #000 !important;
                padding: 24px 28px !important;
            }
            .watermark { color: rgba(0,0,0,0.06) !important; }
            @page {
                size: A4 portrait;
                margin: 12mm;
            }
        }
    </style>
</head>
<body>

<div class="receipt-wrapper">
    <!-- Action Header (Hidden in Print) -->
    <div class="action-bar">
        <a href="<?= e($backUrl) ?>" class="btn btn-outline">
            <i class="fa-solid fa-arrow-left"></i> Return
        </a>
        <div style="display:flex; gap:10px;">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fa-solid fa-print"></i> Print Receipt
            </button>
            <button onclick="downloadAsPdf()" class="btn btn-success">
                <i class="fa-solid fa-file-arrow-down"></i> Download PDF
            </button>
        </div>
    </div>

    <!-- Official Printable Bill / Receipt Card -->
    <div class="receipt-card" id="printableReceipt">
        <div class="watermark">PAID</div>

        <!-- Header -->
        <div class="receipt-header">
            <div style="display:flex; justify-content:center; align-items:center; gap:8px; margin-bottom:8px; color:var(--primary); font-size:24px;">
                <i class="fa-solid fa-book-open"></i>
            </div>
            <div class="receipt-title">LIBRARY FINE PAYMENT RECEIPT</div>
            <div class="inst-name"><?= e($libraryName) ?></div>
            <div class="inst-meta">
                <?= e($libraryAddress) ?><br>
                Contact: <?= e($libraryPhone) ?> &bull; Email: support@library.edu
            </div>
        </div>

        <!-- Receipt Meta Information Strip -->
        <div class="meta-strip">
            <div class="meta-item">
                <span>Receipt Number</span>
                <strong style="color:#0f766e;"><?= e($receiptNo) ?></strong>
            </div>
            <div class="meta-item">
                <span>Payment Reference</span>
                <strong style="font-family:monospace;"><?= e($paymentId) ?></strong>
            </div>
            <div class="meta-item">
                <span>Receipt Date</span>
                <strong><?= date('d/m/Y', strtotime($payment['payment_date'])) ?></strong>
            </div>
            <div class="meta-item">
                <span>Time</span>
                <strong><?= date('h:i A', strtotime($payment['payment_date'])) ?></strong>
            </div>
        </div>

        <!-- Student Details (Section 8) -->
        <div class="section-title"><i class="fa-solid fa-user-graduate"></i> Student Details</div>
        <div class="info-grid">
            <div class="info-row">
                <span class="info-label">Student Name:</span>
                <span class="info-value"><?= e($payment['student_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Student ID (Roll No):</span>
                <span class="info-value"><?= e($payment['roll_number']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Department:</span>
                <span class="info-value"><?= e($payment['department'] ?: 'B.Tech') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Phone:</span>
                <span class="info-value"><?= e($payment['phone'] ?: '—') ?></span>
            </div>
        </div>

        <div class="divider-dashed"></div>

        <!-- Fine Details (Section 8) -->
        <div class="section-title"><i class="fa-solid fa-book"></i> Fine & Circulation Details</div>
        <div class="info-grid">
            <div class="info-row">
                <span class="info-label">Book Name:</span>
                <span class="info-value"><?= e($payment['book_title'] ?? 'Overdue Library Item') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Book ID / ISBN:</span>
                <span class="info-value"><?= e($payment['isbn'] ?: ('#BOOK-' . ($payment['book_ref_id'] ?: '—'))) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Issue Date:</span>
                <span class="info-value"><?= fmtDate($payment['issue_date']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Due Date:</span>
                <span class="info-value"><?= fmtDate($payment['final_due_date']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Return Date:</span>
                <span class="info-value"><?= fmtDate($payment['final_return_date']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Overdue Days:</span>
                <span class="info-value"><?= $lateDays ?> Day(s)</span>
            </div>
            <div class="info-row">
                <span class="info-label">Fine Rate:</span>
                <span class="info-value">&#8377;<?= number_format($fineRate, 2) ?>/day</span>
            </div>
            <div class="info-row">
                <span class="info-label">Reason:</span>
                <span class="info-value"><?= e($payment['reason']) ?></span>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Financial Summary Breakdown -->
        <table class="financial-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align:right;">Amount (INR)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>Late Return Library Fine (<?= $lateDays ?> day(s) &times; &#8377;<?= number_format($fineRate, 2) ?>)</strong>
                        <div style="font-size:12px; color:var(--text-muted); margin-top:2px;"><?= e($payment['book_title'] ?? 'Circulation overdue fee') ?></div>
                    </td>
                    <td style="text-align:right; font-weight:600;">&#8377;<?= number_format($fineAmount, 2) ?></td>
                </tr>
                <tr class="total-row">
                    <td>Total Fine Charged</td>
                    <td style="text-align:right;">&#8377;<?= number_format($fineAmount, 2) ?></td>
                </tr>
                <tr class="total-row" style="color:#15803d; background:#f0fdf4;">
                    <td>Amount Paid</td>
                    <td style="text-align:right;">&#8377;<?= number_format($paidAmount, 2) ?></td>
                </tr>
                <tr style="font-weight:600;">
                    <td>Remaining Outstanding Balance</td>
                    <td style="text-align:right; color:#16a34a;">&#8377;0.00</td>
                </tr>
            </tbody>
        </table>

        <!-- Transaction Details -->
        <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:14px 18px; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
            <div>
                <div style="font-size:13px; color:var(--text-muted); margin-bottom:2px;">
                    Payment Method: <strong style="color:#0f172a;"><?= strtoupper(e($payment['payment_method'])) ?></strong>
                </div>
                <div style="font-size:13px; color:var(--text-muted);">
                    Transaction ID / Ref: <code style="color:#0f172a; font-weight:700;"><?= e($payment['transaction_reference'] ?: 'TXN-CONFIRMED') ?></code>
                </div>
            </div>
            <div>
                <div class="status-stamp">
                    <i class="fa-solid fa-circle-check"></i> PAYMENT STATUS: PAID &check;
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="receipt-footer">
            <p style="margin-bottom:4px; font-weight:600; color:#334155;">Thank you for your payment.</p>
            <p>This is a computer-generated receipt issued by <?= e($libraryName) ?> and requires no physical signature.</p>
        </div>
    </div>
</div>

<script>
function downloadAsPdf() {
    window.print();
}
</script>

</body>
</html>
