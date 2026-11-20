<?php
/**
 * Smart Library - Student Digital Book Rental Checkout & UPI QR Payment
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['student']);
$pageTitle = 'Rent Digital Book';
$currentPage = 'virtual-library.php';

$bookId = (int)($_GET['id'] ?? 0);
if ($bookId <= 0) {
    setFlash('error', 'Invalid digital book requested.');
    redirect('virtual-library.php');
}

// Fetch student
$st = $pdo->prepare("SELECT s.*, u.name, u.email, u.phone FROM students s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?");
$st->execute([$_SESSION['user_id']]);
$student = $st->fetch();
if (!$student) {
    setFlash('error', 'Student profile record not found.');
    redirect('virtual-library.php');
}
$studentId = (int)$student['id'];

// Fetch book
$stmt = $pdo->prepare("
    SELECT db.*, c.name AS category_name, COALESCE(a.name, db.author_name) AS author_display_name
    FROM digital_books db
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN authors a ON a.id = db.author_id
    WHERE db.id = ? AND db.status = 'active'
");
$stmt->execute([$bookId]);
$book = $stmt->fetch();

if (!$book) {
    setFlash('error', 'Digital book is not available for rental.');
    redirect('virtual-library.php');
}

// Fetch settings
$settings = getSettings($pdo);
$pricePerDay = !empty($settings['rental_price_per_day']) ? (float)$settings['rental_price_per_day'] : 5.00;
$upiId = !empty($settings['upi_id']) ? $settings['upi_id'] : '8766087273@ptyes';
$qrPath = !empty($settings['payment_qr_path']) ? $settings['payment_qr_path'] : 'assets/images/payment-qr.png';

// Check existing active or pending rental
$activeRental = checkStudentBookRental($pdo, $studentId, $bookId);
$pendingRental = getStudentPendingRental($pdo, $studentId, $bookId);
$isRenew = isset($_GET['renew']) && $_GET['renew'] === '1';

// Handle Form Submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $rentalDays = (int)($_POST['rental_days'] ?? 1);
    if ($rentalDays < 1 || $rentalDays > 365) {
        $errors[] = 'Please select a valid rental duration between 1 and 365 days.';
    }

    $utr = strtoupper(trim($_POST['transaction_reference'] ?? ''));
    if ($utr === '') {
        $errors[] = 'Please enter the UPI Transaction Reference / UTR Number from your payment receipt.';
    } elseif (strlen($utr) < 6 || strlen($utr) > 40 || !preg_match('/^[A-Z0-9_-]+$/', $utr)) {
        $errors[] = 'Invalid Transaction Reference format. Please enter the authentic reference/UTR number shown in your UPI app.';
    }

    // Duplicate check: Prevent submitting same UTR if already in PENDING or PAID
    if (empty($errors)) {
        $dupStmt = $pdo->prepare("
            SELECT id, payment_status, rental_code 
            FROM book_rentals 
            WHERE transaction_reference = ? AND payment_status IN ('PENDING', 'PAID')
        ");
        $dupStmt->execute([$utr]);
        $existing = $dupStmt->fetch();
        if ($existing) {
            $errors[] = "This Transaction Reference (UTR: $utr) has already been submitted under rental code {$existing['rental_code']} (Status: {$existing['payment_status']}). Each payment receipt can only be used once.";
        }
    }

    if (empty($errors)) {
        // Strict PHP backend calculation
        $totalAmount = round($rentalDays * $pricePerDay, 2);
        $rentalCode = 'RNT-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $ins = $pdo->prepare("
            INSERT INTO book_rentals (
                rental_code, student_id, digital_book_id, rental_days,
                price_per_day, total_amount, payment_method, upi_id,
                transaction_reference, payment_status, rental_status,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'UPI_QR', ?, ?, 'PENDING', 'pending', NOW())
        ");
        $ins->execute([
            $rentalCode, $studentId, $bookId, $rentalDays,
            $pricePerDay, $totalAmount, $upiId, $utr
        ]);

        $rentalId = $pdo->lastInsertId();

        // Notify Admins and log activity
        try {
            $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $adminUserId) {
                notify($pdo, $adminUserId, "New Book Rental Payment (₹$totalAmount)", "Student {$student['name']} ({$student['roll_number']}) submitted UPI UTR $utr for book \"{$book['title']}\" ($rentalDays days). Needs verification.");
            }
        } catch (Exception $e) {}

        logActivity($pdo, $_SESSION['user_id'], "Submitted rental payment of Rs. $totalAmount for digital book #$bookId (Code: $rentalCode, UTR: $utr)");

        setFlash('success', "Rental payment submitted successfully! Reference (UTR): $utr is recorded as PENDING. Once verified by the library administrator with the UPI statement, full reader access will be activated.");
        redirect('rentals.php');
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header flex justify-between items-center" style="margin-bottom: 24px;">
    <div>
        <h2 style="margin: 0 0 4px 0; font-size: 24px; font-weight: 700;">Rent Digital Book</h2>
        <div class="text-muted" style="font-size: 13.5px;">
            <a href="virtual-library.php" style="color: var(--primary); text-decoration: none;"><i class="fa-solid fa-arrow-left"></i> Virtual Library</a>
            &nbsp;/&nbsp; Checkout & Instant UPI QR Payment
        </div>
    </div>
    <div>
        <a href="rentals.php" class="btn btn-outline">
            <i class="fa-solid fa-receipt"></i> My Rented Books
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="card" style="border-left: 4px solid #ef4444; background: rgba(239, 68, 68, 0.08); padding: 16px 20px; margin-bottom: 24px;">
        <div style="font-weight: 600; color: #dc2626; margin-bottom: 6px;"><i class="fa-solid fa-triangle-exclamation"></i> Submission Problem</div>
        <ul style="margin: 0; padding-left: 20px; color: #dc2626; font-size: 13.5px;">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($activeRental && !$isRenew): 
    $expTime = strtotime($activeRental['expiry_date']);
    $daysLeft = max(0, ceil(($expTime - time()) / 86400));
?>
    <div class="card" style="border-left: 4px solid #10b981; background: rgba(16, 185, 129, 0.08); padding: 18px 22px; margin-bottom: 24px;">
        <div class="flex justify-between items-center" style="flex-wrap: wrap; gap: 12px;">
            <div>
                <h4 style="margin: 0 0 4px 0; color: #059669; font-size: 16px;">
                    <i class="fa-solid fa-circle-check"></i> You currently have an active rental for this book!
                </h4>
                <p class="text-muted" style="margin: 0; font-size: 13.5px;">
                    Rental valid until <strong><?= date('d M Y, h:i A', $expTime) ?></strong> (<?= $daysLeft ?> days remaining).
                </p>
            </div>
            <div class="flex gap-2">
                <a href="read-book.php?id=<?= $book['id'] ?>" class="btn btn-primary">
                    <i class="fa-solid fa-book-open"></i> Read Book Now
                </a>
                <a href="rent-book.php?id=<?= $book['id'] ?>&renew=1" class="btn btn-outline">
                    <i class="fa-solid fa-arrows-rotate"></i> Extend / Renew Rental
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($pendingRental): ?>
    <div class="card" style="border-left: 4px solid #f59e0b; background: rgba(245, 158, 11, 0.08); padding: 18px 22px; margin-bottom: 24px;">
        <div class="flex justify-between items-center" style="flex-wrap: wrap; gap: 12px;">
            <div>
                <h4 style="margin: 0 0 4px 0; color: #d97706; font-size: 16px;">
                    <i class="fa-solid fa-hourglass-half"></i> Payment Verification in Progress
                </h4>
                <p class="text-muted" style="margin: 0; font-size: 13.5px;">
                    You submitted UTR: <strong><?= e($pendingRental['transaction_reference']) ?></strong> for ₹<?= number_format($pendingRental['total_amount'], 2) ?>. The library administrator is verifying incoming funds in the merchant statement.
                </p>
            </div>
            <a href="rentals.php" class="btn btn-outline" style="border-color: #f59e0b; color: #d97706;">
                <i class="fa-solid fa-clock-rotate-left"></i> Check Verification Status
            </a>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-3" style="align-items: flex-start; gap: 24px;">
    
    <!-- LEFT COLUMN: Book Information & Duration Selection (Span 2) -->
    <div style="grid-column: span 2;">
        
        <!-- Book Details Card -->
        <div class="card" style="padding: 24px; margin-bottom: 24px;">
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div style="width: 100px; height: 135px; background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); border-radius: 10px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; box-shadow: 0 8px 16px rgba(234,88,12,0.25);">
                    <i class="fa-solid fa-book-bookmark" style="font-size: 38px; margin-bottom: 6px;"></i>
                    <span style="font-size: 11px; font-weight: 700; letter-spacing: 0.05em;">E-BOOK</span>
                </div>
                <div style="flex: 1; min-width: 260px;">
                    <div class="flex justify-between items-start">
                        <span class="badge badge-blue" style="font-size: 11.5px;"><?= e($book['category_name'] ?: 'Curriculum') ?></span>
                        <span class="badge" style="background: rgba(234, 88, 12, 0.15); color: #ea580c; font-weight: 700;">
                            ₹<?= number_format($pricePerDay, 2) ?> / DAY
                        </span>
                    </div>
                    <h3 style="margin: 8px 0 4px 0; font-size: 20px; font-weight: 700; color: var(--text);">
                        <?= e($book['title']) ?>
                    </h3>
                    <div class="text-muted" style="font-size: 14px; margin-bottom: 12px;">
                        By <strong><?= e($book['author_display_name']) ?></strong> &middot; <?= (int)$book['pages'] ?> pages &middot; <?= e($book['language']) ?>
                    </div>
                    <p class="text-muted" style="font-size: 13px; line-height: 1.5; margin: 0;">
                        <?= e($book['description'] ?: 'Complete full-text digital copy with interactive reader, multi-voice Text-to-Speech narration, bookmarking, and progress tracking.') ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Rental Duration Selector Form -->
        <form method="POST" id="rentalForm" class="card" style="padding: 24px;">
            <?= csrfField() ?>
            <input type="hidden" name="rental_days" id="selectedDaysInput" value="7">

            <h3 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 700;">
                <i class="fa-solid fa-calendar-days text-primary"></i> Step 1: Select Rental Duration
            </h3>
            <p class="text-muted" style="font-size: 13.5px; margin: 0 0 20px 0;">
                Choose how many days you need reading access for. The rental price is calculated strictly at <strong>₹<?= number_format($pricePerDay, 2) ?> per day</strong>.
            </p>

            <!-- Duration Chips -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 20px;">
                <?php
                $presets = [
                    1  => ['label' => '1 Day', 'popular' => false],
                    3  => ['label' => '3 Days', 'popular' => false],
                    5  => ['label' => '5 Days', 'popular' => false],
                    7  => ['label' => '7 Days', 'popular' => true],
                    15 => ['label' => '15 Days', 'popular' => false],
                    30 => ['label' => '30 Days', 'popular' => false],
                ];
                foreach ($presets as $days => $info):
                    $amount = $days * $pricePerDay;
                    $isSelected = ($days === 7);
                ?>
                    <button type="button" 
                            class="duration-chip <?= $isSelected ? 'active' : '' ?>" 
                            data-days="<?= $days ?>" 
                            onclick="selectDays(<?= $days ?>)"
                            style="border: 2px solid <?= $isSelected ? 'var(--primary)' : 'var(--border)' ?>; background: <?= $isSelected ? 'rgba(99, 102, 241, 0.08)' : 'var(--bg-card)' ?>; border-radius: 12px; padding: 14px 10px; text-align: center; cursor: pointer; transition: all 0.2s ease; position: relative;">
                        <?php if ($info['popular']): ?>
                            <span style="position: absolute; top: -8px; left: 50%; transform: translateX(-50%); background: #10b981; color: #fff; font-size: 9.5px; font-weight: 700; padding: 1px 7px; border-radius: 10px; letter-spacing: 0.04em;">POPULAR</span>
                        <?php endif; ?>
                        <div style="font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 4px;"><?= $info['label'] ?></div>
                        <div style="font-size: 17px; font-weight: 800; color: #ea580c;">₹<?= $amount ?></div>
                        <div class="text-muted" style="font-size: 11px;">₹<?= (int)$pricePerDay ?>/day</div>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Custom Days Option -->
            <div style="display: flex; align-items: center; gap: 12px; background: rgba(0,0,0,0.02); border: 1px dashed var(--border); border-radius: 10px; padding: 12px 16px; margin-bottom: 24px;">
                <label for="customDaysInput" style="font-size: 13.5px; font-weight: 600; color: var(--text); margin: 0;">
                    Or custom duration:
                </label>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <input type="number" id="customDaysInput" min="1" max="365" placeholder="e.g. 10" class="form-control" style="width: 100px; text-align: center; font-weight: 700;" oninput="onCustomDaysInput(this.value)">
                    <span class="text-muted" style="font-size: 13.5px;">days</span>
                </div>
                <div class="text-muted" style="font-size: 12px; margin-left: auto;">(1 to 365 days max)</div>
            </div>

            <div style="border-top: 1px solid var(--border); padding-top: 20px;">
                <h3 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 700;">
                    <i class="fa-solid fa-receipt text-primary"></i> Step 2: Submit Payment Reference
                </h3>
                <p class="text-muted" style="font-size: 13.5px; margin: 0 0 16px 0;">
                    After completing the UPI payment via your banking or UPI app using the QR code on the right, enter the <strong>12-digit UPI Reference No. / UTR</strong> below.
                </p>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; font-size: 14px; margin-bottom: 6px; display: block;">
                        UPI Transaction ID / UTR Number <span style="color:#ef4444;">*</span>
                    </label>
                    <div style="position: relative;">
                        <i class="fa-solid fa-barcode text-muted" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 16px;"></i>
                        <input type="text" 
                               name="transaction_reference" 
                               id="utrInput" 
                               required 
                               maxlength="35"
                               placeholder="e.g. 432567891234 or UPI Ref No" 
                               class="form-control" 
                               style="padding-left: 42px; font-size: 15px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase;">
                    </div>
                    <div class="text-muted" style="font-size: 12px; margin-top: 5px;">
                        <i class="fa-solid fa-circle-info"></i> Look for <strong>"UPI Ref No"</strong>, <strong>"UTR"</strong>, or <strong>"Transaction ID"</strong> in Google Pay, PhonePe, Paytm, or your banking app's payment receipt.
                    </div>
                </div>

                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary" style="padding: 12px 28px; font-size: 15px; font-weight: 700; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none; box-shadow: 0 4px 14px rgba(16,185,129,0.35);">
                        <i class="fa-solid fa-paper-plane"></i> Submit Payment for Verification
                    </button>
                    <a href="virtual-library.php" class="btn btn-outline" style="padding: 12px 20px;">
                        Cancel
                    </a>
                </div>
            </div>
        </form>

    </div>

    <!-- RIGHT COLUMN: Real UPI QR Code & Payment Information (Span 1) -->
    <div>
        <div class="card" style="padding: 24px; text-align: center; position: sticky; top: 20px;">
            <div style="display: inline-block; background: rgba(234, 88, 12, 0.12); color: #ea580c; font-size: 11px; font-weight: 800; letter-spacing: 0.06em; padding: 4px 12px; border-radius: 20px; margin-bottom: 12px;">
                <i class="fa-solid fa-shield-halved"></i> OFFICIAL UPI QR PAYMENT
            </div>

            <h4 style="margin: 0 0 6px 0; font-size: 17px; font-weight: 700; color: var(--text);">
                Scan to Pay with Any UPI App
            </h4>
            <p class="text-muted" style="margin: 0 0 16px 0; font-size: 12.5px;">
                GPay &middot; PhonePe &middot; Paytm &middot; BHIM &middot; CRED
            </p>

            <!-- QR Code Display Box -->
            <div style="background: #ffffff; padding: 16px; border-radius: 16px; display: inline-block; box-shadow: 0 8px 24px rgba(0,0,0,0.12); margin-bottom: 18px; border: 2px solid var(--border); max-width: 250px; width: 100%;">
                <img src="<?= basePath() ?>/<?= e($qrPath) ?>?v=<?= file_exists(__DIR__ . '/../' . $qrPath) ? filemtime(__DIR__ . '/../' . $qrPath) : time() ?>" 
                     alt="UPI Payment QR Code" 
                     id="officialQrImage"
                     style="width: 100%; height: auto; display: block; border-radius: 8px; image-rendering: crisp-edges;">
            </div>

            <!-- UPI ID Display & Copy -->
            <div style="background: rgba(0,0,0,0.03); border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; margin-bottom: 16px;">
                <div class="text-muted" style="font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 2px;">Merchant UPI ID</div>
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                    <span id="merchantUpiId" style="font-size: 14px; font-weight: 700; color: var(--text); font-family: monospace;">
                        <?= e($upiId) ?>
                    </span>
                    <button type="button" class="btn btn-sm btn-outline" onclick="copyUpiId()" title="Copy UPI ID to clipboard" style="padding: 4px 10px; font-size: 11.5px;">
                        <i class="fa-regular fa-copy" id="copyUpiIcon"></i> <span id="copyUpiText">Copy</span>
                    </button>
                </div>
            </div>

            <!-- Amount Calculation Summary Card -->
            <div style="background: linear-gradient(135deg, rgba(234,88,12,0.08) 0%, rgba(249,115,22,0.05) 100%); border: 1px solid rgba(234,88,12,0.25); border-radius: 12px; padding: 14px; margin-bottom: 16px; text-align: left;">
                <div class="flex justify-between items-center" style="margin-bottom: 6px;">
                    <span class="text-muted" style="font-size: 12.5px;">Selected Duration:</span>
                    <strong id="summaryDays" style="font-size: 13.5px; color: var(--text);">7 Days</strong>
                </div>
                <div class="flex justify-between items-center" style="margin-bottom: 8px;">
                    <span class="text-muted" style="font-size: 12.5px;">Rental Rate:</span>
                    <span style="font-size: 12.5px; color: var(--text);">₹<?= number_format($pricePerDay, 2) ?> / day</span>
                </div>
                <div class="flex justify-between items-center" style="border-top: 1px dashed rgba(234,88,12,0.3); padding-top: 8px;">
                    <span style="font-weight: 700; font-size: 14px; color: var(--text);">Total Amount to Pay:</span>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span id="summaryTotal" style="font-size: 20px; font-weight: 800; color: #ea580c;">₹35.00</span>
                        <button type="button" class="btn btn-sm btn-outline" onclick="copyAmount()" title="Copy Amount" style="padding: 2px 7px; font-size: 11px;">
                            <i class="fa-regular fa-copy" id="copyAmtIcon"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Crucial Static QR Instruction Notice -->
            <div style="background: rgba(245, 158, 11, 0.08); border-left: 3px solid #f59e0b; border-radius: 6px; padding: 10px 12px; text-align: left; margin-bottom: 16px;">
                <div style="font-size: 11.5px; font-weight: 700; color: #b45309; margin-bottom: 2px;">
                    <i class="fa-solid fa-circle-exclamation"></i> IMPORTANT PAYMENT NOTE:
                </div>
                <div style="font-size: 11.5px; color: #92400e; line-height: 1.4;">
                    This is an authentic static UPI QR. When scanning in your UPI app, please <strong>manually enter the exact amount (<span id="noteAmount">₹35.00</span>)</strong> shown above.
                </div>
            </div>

            <!-- Steps Reminder -->
            <div style="text-align: left; font-size: 12px; color: var(--text-muted); line-height: 1.5;">
                <div style="font-weight: 600; margin-bottom: 4px; color: var(--text);">How it works:</div>
                <ol style="margin: 0; padding-left: 18px;">
                    <li>Scan QR with any UPI application.</li>
                    <li>Pay the exact rental amount.</li>
                    <li>Copy 12-digit UTR from payment receipt.</li>
                    <li>Paste UTR in Step 2 and submit.</li>
                    <li>Admin verifies payment & unlocks reader.</li>
                </ol>
            </div>

        </div>
    </div>

</div>

<!-- Copy Toast Notification Popup -->
<div id="copyToast" style="display: none; position: fixed; bottom: 30px; right: 30px; background: #0f172a; color: #fff; padding: 12px 20px; border-radius: 8px; font-size: 13.5px; font-weight: 600; box-shadow: 0 10px 25px rgba(0,0,0,0.3); z-index: 9999; animation: fadeIn 0.2s;">
    <i class="fa-solid fa-circle-check" style="color: #10b981; margin-right: 6px;"></i> <span id="toastMsg">Copied to clipboard!</span>
</div>

<script>
const PRICE_PER_DAY = <?= (float)$pricePerDay ?>;
let currentDays = 7;

function selectDays(days) {
    currentDays = parseInt(days, 10);
    if (isNaN(currentDays) || currentDays < 1) currentDays = 1;

    document.getElementById('selectedDaysInput').value = currentDays;
    document.getElementById('customDaysInput').value = '';

    // Update chip styling
    document.querySelectorAll('.duration-chip').forEach(chip => {
        const chipDays = parseInt(chip.getAttribute('data-days'), 10);
        if (chipDays === currentDays) {
            chip.classList.add('active');
            chip.style.borderColor = 'var(--primary)';
            chip.style.background = 'rgba(99, 102, 241, 0.08)';
        } else {
            chip.classList.remove('active');
            chip.style.borderColor = 'var(--border)';
            chip.style.background = 'var(--bg-card)';
        }
    });

    updateSummary();
}

function onCustomDaysInput(val) {
    const parsed = parseInt(val, 10);
    if (!isNaN(parsed) && parsed >= 1 && parsed <= 365) {
        currentDays = parsed;
        document.getElementById('selectedDaysInput').value = currentDays;

        // Deselect presets
        document.querySelectorAll('.duration-chip').forEach(chip => {
            chip.classList.remove('active');
            chip.style.borderColor = 'var(--border)';
            chip.style.background = 'var(--bg-card)';
        });

        updateSummary();
    }
}

function updateSummary() {
    const total = (currentDays * PRICE_PER_DAY).toFixed(2);
    document.getElementById('summaryDays').textContent = currentDays + (currentDays === 1 ? ' Day' : ' Days');
    document.getElementById('summaryTotal').textContent = '₹' + total;
    document.getElementById('noteAmount').textContent = '₹' + total;
}

function copyUpiId() {
    const upiId = document.getElementById('merchantUpiId').textContent.trim();
    navigator.clipboard.writeText(upiId).then(() => {
        showToast('UPI ID copied: ' + upiId);
        const icon = document.getElementById('copyUpiIcon');
        const text = document.getElementById('copyUpiText');
        icon.className = 'fa-solid fa-check';
        text.textContent = 'Copied!';
        setTimeout(() => {
            icon.className = 'fa-regular fa-copy';
            text.textContent = 'Copy';
        }, 2000);
    });
}

function copyAmount() {
    const total = (currentDays * PRICE_PER_DAY).toFixed(2);
    navigator.clipboard.writeText(total).then(() => {
        showToast('Amount copied: ₹' + total);
        const icon = document.getElementById('copyAmtIcon');
        icon.className = 'fa-solid fa-check';
        setTimeout(() => {
            icon.className = 'fa-regular fa-copy';
        }, 2000);
    });
}

function showToast(msg) {
    const toast = document.getElementById('copyToast');
    document.getElementById('toastMsg').textContent = msg;
    toast.style.display = 'block';
    setTimeout(() => {
        toast.style.display = 'none';
    }, 2500);
}

// Initial calculation
updateSummary();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
