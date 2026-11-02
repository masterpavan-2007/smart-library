<?php
/**
 * Admin Digital Book Management Module
 * Complete CRUD for E-Books, PDF Uploads, Access Policies, and Reading Statistics.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['admin']);
$pageTitle = 'Digital Books Management';
$currentPage = 'digital-books.php';

$uploadDir = __DIR__ . '/../uploads/digital-books/';
$coverDir = __DIR__ . '/../uploads/books/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
if (!is_dir($coverDir)) mkdir($coverDir, 0777, true);

// Handle POST actions: add, edit, delete, toggle_status
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ---------- ADD DIGITAL BOOK ----------
    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $authorId = !empty($_POST['author_id']) ? (int)$_POST['author_id'] : null;
        $authorName = trim($_POST['author_name'] ?? '');
        $publisherId = !empty($_POST['publisher_id']) ? (int)$_POST['publisher_id'] : null;
        $bookId = !empty($_POST['book_id']) ? (int)$_POST['book_id'] : null;
        $pubYear = !empty($_POST['publication_year']) ? (int)$_POST['publication_year'] : null;
        $edition = trim($_POST['edition'] ?? '1st');
        $language = trim($_POST['language'] ?? 'English');
        $pages = max(1, (int)($_POST['pages'] ?? 1));
        $description = trim($_POST['description'] ?? '');
        $accessType = in_array($_POST['access_type'] ?? '', ['free', 'restricted', 'open_access']) ? $_POST['access_type'] : 'free';
        $license = trim($_POST['license_info'] ?? 'Open Educational Resource');
        $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        if ($title === '') {
            setFlash('error', 'Book title is required.');
            redirect('digital-books.php');
        }

        // Validate PDF file upload
        if (empty($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            setFlash('error', 'A valid PDF document file must be uploaded.');
            redirect('digital-books.php');
        }

        $fileTmp = $_FILES['pdf_file']['tmp_name'];
        $fileNameOrig = $_FILES['pdf_file']['name'];
        $fileSize = $_FILES['pdf_file']['size'];

        // Server-side MIME & extension validation
        $ext = strtolower(pathinfo($fileNameOrig, PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $fileTmp);
        finfo_close($finfo);

        if ($ext !== 'pdf' || !str_contains($mime, 'pdf')) {
            setFlash('error', 'Security error: Only authentic PDF files are allowed.');
            redirect('digital-books.php');
        }

        if ($fileSize > 50 * 1024 * 1024) { // 50MB max
            setFlash('error', 'Uploaded PDF exceeds maximum allowed limit (50MB).');
            redirect('digital-books.php');
        }

        // Generate randomized secure filename
        $safeFileName = 'ebook_' . bin2hex(random_bytes(12)) . '.pdf';
        $destPath = $uploadDir . $safeFileName;

        if (!move_uploaded_file($fileTmp, $destPath)) {
            setFlash('error', 'Failed to store digital file on server.');
            redirect('digital-books.php');
        }

        $fileSizeKb = max(1, round($fileSize / 1024));

        $stmt = $pdo->prepare("
            INSERT INTO digital_books (
                book_id, title, author_id, author_name, category_id, publisher_id,
                publication_year, edition, language, pages, description,
                file_path, file_format, file_size_kb, access_type, license_info, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PDF', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $bookId, $title, $authorId, $authorName, $categoryId, $publisherId,
            $pubYear, $edition, $language, $pages, $description,
            $safeFileName, $fileSizeKb, $accessType, $license, $status
        ]);

        logActivity($pdo, $_SESSION['user_id'], "Added new digital book: \"$title\"");
        setFlash('success', "Digital book \"$title\" uploaded and published successfully.");
        redirect('digital-books.php');
    }

    // ---------- EDIT DIGITAL BOOK ----------
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $authorId = !empty($_POST['author_id']) ? (int)$_POST['author_id'] : null;
        $authorName = trim($_POST['author_name'] ?? '');
        $publisherId = !empty($_POST['publisher_id']) ? (int)$_POST['publisher_id'] : null;
        $bookId = !empty($_POST['book_id']) ? (int)$_POST['book_id'] : null;
        $pubYear = !empty($_POST['publication_year']) ? (int)$_POST['publication_year'] : null;
        $edition = trim($_POST['edition'] ?? '1st');
        $language = trim($_POST['language'] ?? 'English');
        $pages = max(1, (int)($_POST['pages'] ?? 1));
        $description = trim($_POST['description'] ?? '');
        $accessType = in_array($_POST['access_type'] ?? '', ['free', 'restricted', 'open_access']) ? $_POST['access_type'] : 'free';
        $license = trim($_POST['license_info'] ?? 'Open Educational Resource');
        $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        if ($id <= 0 || $title === '') {
            setFlash('error', 'Invalid digital book data submitted.');
            redirect('digital-books.php');
        }

        // Optional new file upload
        if (!empty($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmp = $_FILES['pdf_file']['tmp_name'];
            $fileNameOrig = $_FILES['pdf_file']['name'];
            $fileSize = $_FILES['pdf_file']['size'];

            $ext = strtolower(pathinfo($fileNameOrig, PATHINFO_EXTENSION));
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $fileTmp);
            finfo_close($finfo);

            if ($ext === 'pdf' && str_contains($mime, 'pdf')) {
                $safeFileName = 'ebook_' . bin2hex(random_bytes(12)) . '.pdf';
                if (move_uploaded_file($fileTmp, $uploadDir . $safeFileName)) {
                    $fileSizeKb = max(1, round($fileSize / 1024));
                    $pdo->prepare("UPDATE digital_books SET file_path = ?, file_size_kb = ? WHERE id = ?")->execute([$safeFileName, $fileSizeKb, $id]);
                }
            }
        }

        $stmt = $pdo->prepare("
            UPDATE digital_books SET
                book_id = ?, title = ?, author_id = ?, author_name = ?, category_id = ?, publisher_id = ?,
                publication_year = ?, edition = ?, language = ?, pages = ?, description = ?,
                access_type = ?, license_info = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $bookId, $title, $authorId, $authorName, $categoryId, $publisherId,
            $pubYear, $edition, $language, $pages, $description,
            $accessType, $license, $status, $id
        ]);

        logActivity($pdo, $_SESSION['user_id'], "Updated digital book: \"$title\"");
        setFlash('success', "Digital book \"$title\" updated successfully.");
        redirect('digital-books.php');
    }

    // ---------- DELETE DIGITAL BOOK ----------
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $bk = $pdo->prepare("SELECT title, file_path FROM digital_books WHERE id = ?");
        $bk->execute([$id]);
        $book = $bk->fetch();

        if ($book) {
            $pdo->prepare("DELETE FROM digital_books WHERE id = ?")->execute([$id]);
            // Remove file from disk if local
            $filePath = $uploadDir . basename($book['file_path']);
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            logActivity($pdo, $_SESSION['user_id'], "Deleted digital book: \"{$book['title']}\"");
            setFlash('success', "Digital book \"{$book['title']}\" deleted.");
        }
        redirect('digital-books.php');
    }
}

// Filters & Query
$search = trim($_GET['q'] ?? '');
$catFilter = trim($_GET['category'] ?? '');
$accessFilter = trim($_GET['access'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(db.title LIKE ? OR a.name LIKE ? OR db.author_name LIKE ? OR db.description LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term]);
}
if ($catFilter !== '') {
    $where[] = "db.category_id = ?";
    $params[] = $catFilter;
}
if ($accessFilter !== '') {
    $where[] = "db.access_type = ?";
    $params[] = $accessFilter;
}
if ($statusFilter !== '') {
    $where[] = "db.status = ?";
    $params[] = $statusFilter;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT db.*, c.name AS category_name, COALESCE(a.name, db.author_name) AS author_name,
           p.name AS publisher_name, b.title AS physical_title,
           (SELECT COUNT(*) FROM reading_history WHERE digital_book_id = db.id) AS active_readers
    FROM digital_books db
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN authors a ON a.id = db.author_id
    LEFT JOIN publishers p ON p.id = db.publisher_id
    LEFT JOIN books b ON b.id = db.book_id
    $whereSql
    ORDER BY db.id DESC
");
$stmt->execute($params);
$books = $stmt->fetchAll();

// Lookups for Modal dropdowns
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$authors = $pdo->query("SELECT * FROM authors ORDER BY name")->fetchAll();
$publishers = $pdo->query("SELECT * FROM publishers ORDER BY name")->fetchAll();
$physicalBooks = $pdo->query("SELECT id, title, isbn FROM books WHERE status='active' ORDER BY title")->fetchAll();

// Metrics
$totalDigital = (int)$pdo->query("SELECT COUNT(*) FROM digital_books")->fetchColumn();
$activeDigital = (int)$pdo->query("SELECT COUNT(*) FROM digital_books WHERE status='active'")->fetchColumn();
$totalViews = (int)$pdo->query("SELECT COALESCE(SUM(view_count),0) FROM digital_books")->fetchColumn();
$totalReadingSessions = (int)$pdo->query("SELECT COUNT(*) FROM reading_history")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<!-- Metrics Summary -->
<div class="grid grid-4" style="margin-bottom:20px;">
    <div class="card stat-card">
        <div class="icon blue"><i class="fa-solid fa-file-pdf"></i></div>
        <div><div class="num"><?= $totalDigital ?></div><div class="label">Total Digital Books</div></div>
    </div>
    <div class="card stat-card">
        <div class="icon green"><i class="fa-solid fa-circle-check"></i></div>
        <div><div class="num"><?= $activeDigital ?></div><div class="label">Active Resources</div></div>
    </div>
    <div class="card stat-card">
        <div class="icon yellow"><i class="fa-solid fa-eye"></i></div>
        <div><div class="num"><?= $totalViews ?></div><div class="label">Resource Views</div></div>
    </div>
    <div class="card stat-card">
        <div class="icon red"><i class="fa-solid fa-book-open-reader"></i></div>
        <div><div class="num"><?= $totalReadingSessions ?></div><div class="label">Reading Sessions</div></div>
    </div>
</div>

<!-- Toolbar -->
<div class="table-toolbar">
    <form method="GET" class="flex gap-2" style="flex-wrap:wrap; align-items:center;">
        <input type="text" name="q" class="form-control" style="width:240px;" placeholder="Search title, author..." value="<?= e($search) ?>">
        <select name="category" class="form-control" style="width:160px;">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $catFilter == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="access" class="form-control" style="width:140px;">
            <option value="">Access Type</option>
            <option value="free" <?= $accessFilter === 'free' ? 'selected' : '' ?>>Free</option>
            <option value="open_access" <?= $accessFilter === 'open_access' ? 'selected' : '' ?>>Open Access</option>
            <option value="restricted" <?= $accessFilter === 'restricted' ? 'selected' : '' ?>>Restricted</option>
        </select>
        <select name="status" class="form-control" style="width:130px;">
            <option value="">Status</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button class="btn btn-outline" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        <?php if ($search || $catFilter || $accessFilter || $statusFilter): ?>
            <a href="digital-books.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>

    <div>
        <button class="btn btn-primary" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Upload New Digital Book</button>
    </div>
</div>

<!-- Digital Books Table -->
<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title & Author</th>
                    <th>Category</th>
                    <th>Pages / Size</th>
                    <th>Access Policy</th>
                    <th>Linked Physical</th>
                    <th>Usage Stats</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($books)): ?>
                    <tr><td colspan="8" style="text-align:center; padding:30px;" class="text-muted">No digital books found. Click "Upload New Digital Book" to add one.</td></tr>
                <?php else: ?>
                    <?php foreach ($books as $b): ?>
                        <tr>
                            <td>
                                <strong><?= e($b['title']) ?></strong>
                                <div class="text-muted" style="font-size:12px;"><?= e($b['author_name'] ?: 'Unknown') ?> &middot; <?= e($b['language']) ?> (<?= e($b['publication_year'] ?: '—') ?>)</div>
                            </td>
                            <td><span class="badge badge-blue"><?= e($b['category_name'] ?: 'General') ?></span></td>
                            <td>
                                <div><?= (int)$b['pages'] ?> pages</div>
                                <div class="text-muted" style="font-size:11px;"><?= (int)$b['file_size_kb'] ?> KB (PDF)</div>
                            </td>
                            <td>
                                <span class="badge badge-green"><?= strtoupper(e($b['access_type'])) ?></span>
                            </td>
                            <td>
                                <?php if ($b['physical_title']): ?>
                                    <span class="badge badge-blue" title="Linked to physical inventory"><i class="fa-solid fa-link"></i> <?= e(substr($b['physical_title'], 0, 18)) ?>...</span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:12px;">Standalone</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size:12px;"><i class="fa-solid fa-eye text-muted"></i> <?= (int)$b['view_count'] ?> views</div>
                                <div style="font-size:12px;"><i class="fa-solid fa-book-reader text-muted"></i> <?= (int)$b['active_readers'] ?> readers</div>
                            </td>
                            <td><?= statusBadge($b['status']) ?></td>
                            <td>
                                <div class="flex gap-1">
                                    <a href="<?= basePath() ?>/student/read-book.php?id=<?= $b['id'] ?>" target="_blank" class="btn btn-sm btn-outline" title="Read / Preview Online">
                                        <i class="fa-solid fa-book-open"></i>
                                    </a>
                                    <button class="btn btn-sm btn-outline" onclick='openEditModal(<?= json_encode($b) ?>)' title="Edit metadata">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to permanently delete this digital resource?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                        <button class="btn btn-sm btn-outline" style="color:var(--danger);" type="submit" title="Delete">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add / Edit Digital Book -->
<div class="modal" id="bookModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div class="modal-box" style="max-width:720px; width:100%; max-height:92vh; overflow-y:auto; padding:26px; border-radius:18px;">
        <div class="flex justify-between items-center" style="border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:18px;">
            <div class="flex items-center gap-2">
                <div style="width:36px; height:36px; border-radius:10px; background:linear-gradient(135deg, var(--primary), #e67e22); display:flex; align-items:center; justify-content:center; color:#fff; font-size:16px;">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                </div>
                <div>
                    <h3 style="margin:0; font-size:18px;" id="modalTitle">Upload New Digital Book</h3>
                    <span class="text-muted" style="font-size:12px;" id="modalSubtitle">Select PDF first — AI will automatically extract & fill book details</span>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" enctype="multipart/form-data" id="bookForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="bookId" value="">

            <!-- AI Assistant & PDF Upload Box (Primary Step) -->
            <div class="ai-upload-card" id="aiUploadCard">
                <div class="flex justify-between items-center" style="margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                    <div class="flex items-center gap-2">
                        <span style="font-size: 22px;">✨</span>
                        <div>
                            <strong style="color: var(--text); font-size: 14px;">Smart AI Auto-Fill</strong>
                            <div class="text-muted" style="font-size: 11px;">Upload or select a PDF first — AI reads the book & searches Google to fill all details below automatically!</div>
                        </div>
                    </div>
                    <span id="aiBadge" class="badge" style="background: var(--primary); color: #fff; font-size: 11px; padding: 4px 10px; border-radius: 12px; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> <span id="aiBadgeText">AI Ready</span>
                    </span>
                </div>

                <div class="form-group" style="margin-bottom: 0;" id="fileGroup">
                    <label for="mFile" style="cursor: pointer; display: block; font-weight: 600; margin-bottom: 6px;">
                        <i class="fa-solid fa-file-pdf" style="color: #e63946; margin-right: 4px;"></i> PDF Document File *
                        <span class="text-muted" style="font-weight: 400; font-size: 12px;">(Max 50MB &middot; ✨ Triggers AI Auto-Fill)</span>
                    </label>
                    <div class="flex gap-2 items-center">
                        <input type="file" name="pdf_file" id="mFile" class="form-control" accept="application/pdf" style="flex: 1;">
                        <button type="button" class="btn btn-outline btn-sm" id="btnReScanPdf" onclick="reAnalyzeSelectedPdf()" style="display:none; white-space:nowrap;" title="Re-scan current PDF">
                            <i class="fa-solid fa-arrows-rotate"></i> Re-Scan
                        </button>
                    </div>
                    <div id="fileNotice" class="text-muted" style="font-size:12px; margin-top:4px; display:none;">Leave blank to preserve current PDF file.</div>
                </div>

                <!-- AI Live Status / Progress Bar -->
                <div id="aiProgressBar" class="ai-progress-bar">
                    <div class="flex items-center justify-between" style="gap: 10px; flex-wrap: wrap;">
                        <div class="flex items-center gap-2">
                            <i id="aiProgressIcon" class="fa-solid fa-spinner fa-spin" style="color: var(--primary);"></i>
                            <span id="aiProgressText" style="font-weight: 500;">Reading PDF & querying Google Books AI...</span>
                        </div>
                        <span id="aiFieldsCount" class="badge badge-green" style="display:none; font-size: 11px;">0 fields filled</span>
                    </div>
                </div>
            </div>

            <!-- Book Title with Manual AI Lookup Button -->
            <div class="form-group">
                <div class="flex justify-between items-center" style="margin-bottom: 4px;">
                    <label style="margin: 0; font-weight: 600;">Book Title *</label>
                    <button type="button" class="btn btn-sm btn-outline" id="btnTitleAi" onclick="manualAiSearchByTitle()" style="font-size: 11px; padding: 2px 8px; border-radius: 6px; color: var(--primary); border-color: var(--primary);" title="Lookup book info on Google Books by title">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> AI Fetch by Title
                    </button>
                </div>
                <input type="text" name="title" id="mTitle" class="form-control" required placeholder="e.g. Clean Code: A Handbook of Agile Software Craftsmanship">
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label style="font-weight: 600;">Author (Select existing or type below)</label>
                    <select name="author_id" id="mAuthorId" class="form-control">
                        <option value="">-- Select Author --</option>
                        <?php foreach ($authors as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= e($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-weight: 600;">Or Author Name (Custom / External)</label>
                    <input type="text" name="author_name" id="mAuthorName" class="form-control" placeholder="Author display name">
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label style="font-weight: 600;">Category *</label>
                    <select name="category_id" id="mCategoryId" class="form-control" required>
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-weight: 600;">Link to Physical Book (Optional)</label>
                    <select name="book_id" id="mBookId" class="form-control">
                        <option value="">-- No Physical Link (Standalone) --</option>
                        <?php foreach ($physicalBooks as $pb): ?>
                            <option value="<?= $pb['id'] ?>"><?= e($pb['title']) ?> (<?= e($pb['isbn']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-3">
                <div class="form-group">
                    <label style="font-weight: 600;">Publication Year</label>
                    <input type="number" name="publication_year" id="mYear" class="form-control" min="1800" max="<?= date('Y') + 1 ?>" value="<?= date('Y') ?>">
                </div>
                <div class="form-group">
                    <label style="font-weight: 600;">Total Pages *</label>
                    <input type="number" name="pages" id="mPages" class="form-control" min="1" value="10" required>
                </div>
                <div class="form-group">
                    <label style="font-weight: 600;">Language</label>
                    <input type="text" name="language" id="mLanguage" class="form-control" value="English">
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label style="font-weight: 600;">Access Policy</label>
                    <select name="access_type" id="mAccessType" class="form-control">
                        <option value="free">Free for Students</option>
                        <option value="open_access">Open Access (Public)</option>
                        <option value="restricted">Restricted / Staff Only</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-weight: 600;">Publication Status</label>
                    <select name="status" id="mStatus" class="form-control">
                        <option value="active">Active (Visible)</option>
                        <option value="inactive">Inactive (Hidden)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label style="font-weight: 600;">Copyright / License Information</label>
                <input type="text" name="license_info" id="mLicense" class="form-control" value="Open Educational Resource">
            </div>

            <div class="form-group">
                <label style="font-weight: 600;">Synopsis / Description</label>
                <textarea name="description" id="mDesc" class="form-control" rows="3" placeholder="Brief summary of the digital resource contents..."></textarea>
            </div>

            <div class="flex justify-end gap-2" style="border-top:1px solid var(--border); padding-top:16px; margin-top:16px;">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn"><i class="fa-solid fa-cloud-arrow-up"></i> Upload & Publish</button>
            </div>
        </form>
    </div>
</div>

<!-- Load PDF.js CDN Library for client-side book text & metadata extraction -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

<script>
// Configure PDF.js Worker
if (window.pdfjsLib) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

const modal = document.getElementById('bookModal');
const fileInput = document.getElementById('mFile');
const aiUploadCard = document.getElementById('aiUploadCard');
const aiProgressBar = document.getElementById('aiProgressBar');
const aiProgressIcon = document.getElementById('aiProgressIcon');
const aiProgressText = document.getElementById('aiProgressText');
const aiBadge = document.getElementById('aiBadge');
const aiBadgeText = document.getElementById('aiBadgeText');
const aiFieldsCount = document.getElementById('aiFieldsCount');
const btnReScanPdf = document.getElementById('btnReScanPdf');

// Drag & drop support on AI upload box
if (aiUploadCard && fileInput) {
    ['dragenter', 'dragover'].forEach(evt => {
        aiUploadCard.addEventListener(evt, e => {
            e.preventDefault();
            aiUploadCard.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(evt => {
        aiUploadCard.addEventListener(evt, e => {
            e.preventDefault();
            aiUploadCard.classList.remove('dragover');
        });
    });
    aiUploadCard.addEventListener('drop', e => {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            processPdfWithAi(fileInput.files[0]);
        }
    });
    fileInput.addEventListener('change', e => {
        if (e.target.files && e.target.files[0]) {
            processPdfWithAi(e.target.files[0]);
        }
    });
}

function reAnalyzeSelectedPdf() {
    if (fileInput && fileInput.files && fileInput.files[0]) {
        processPdfWithAi(fileInput.files[0]);
    }
}

// Visual pulsing highlight on auto-filled form fields
function highlightField(elem) {
    if (!elem) return;
    elem.classList.remove('ai-field-highlight');
    void elem.offsetWidth; // trigger reflow
    elem.classList.add('ai-field-highlight');
}

// Clean raw filenames into human readable search queries
function cleanFileName(filename) {
    let name = filename.replace(/\.pdf$/i, '');
    name = name.replace(/\[.*?\]|\(.*?\)/g, ' ');
    name = name.replace(/([a-z])([A-Z])/g, '$1 $2');
    name = name.replace(/[-_+]/g, ' ');
    name = name.replace(/\b(ebook|pdf|digital|handbook|edition|download|free|full|book|preview)\b/gi, ' ');
    return name.replace(/\s+/g, ' ').trim();
}

// Map subject/genre strings to existing categories in the dropdown
function matchCategoryInDropdown(subjectString) {
    const catSelect = document.getElementById('mCategoryId');
    if (!catSelect || !subjectString) return false;
    const str = subjectString.toLowerCase();

    // Map common genres to keywords
    const keywordsMap = [
        { key: 'computer', val: ['computer', 'code', 'programming', 'software', 'python', 'java', 'web', 'algorithm', 'data', 'developer', 'architecture', 'html', 'css', 'javascript'] },
        { key: 'technology', val: ['technology', 'tech', 'digital', 'artificial intelligence', 'machine learning', 'cyber', 'robotics'] },
        { key: 'fiction', val: ['fiction', 'novel', 'literature', 'stories', 'narrative', 'prose'] },
        { key: 'fantasy', val: ['fantasy', 'magic', 'wizard', 'mythology', 'dragon', 'potter'] },
        { key: 'science fiction', val: ['science fiction', 'sci-fi', 'space', 'galaxy', 'future', 'dystopia'] },
        { key: 'biography', val: ['biography', 'autobiography', 'memoir', 'life of', 'steve jobs'] },
        { key: 'mathematics', val: ['mathematics', 'math', 'algebra', 'calculus', 'geometry', 'statistics'] },
        { key: 'science', val: ['science', 'physics', 'astronomy', 'biology', 'chemistry', 'universe', 'quantum'] },
        { key: 'history', val: ['history', 'historical', 'war', 'revolution', 'ancient', 'civilization', 'empire'] },
        { key: 'self-help', val: ['self-help', 'habits', 'productivity', 'personal growth', 'motivation', 'success', 'mindset', 'psychology'] },
        { key: 'education', val: ['education', 'learning', 'teaching', 'textbook', 'handbook', 'academic'] },
        { key: 'thriller', val: ['thriller', 'suspense', 'crime', 'espionage'] },
        { key: 'horror', val: ['horror', 'ghost', 'supernatural', 'fear'] },
        { key: 'mystery', val: ['mystery', 'detective', 'sherlock', 'investigation'] },
        { key: 'romance', val: ['romance', 'love story', 'romantic'] },
        { key: 'comics', val: ['comic', 'graphic novel', 'comics'] },
        { key: 'manga', val: ['manga', 'anime'] },
        { key: 'general knowledge', val: ['general knowledge', 'encyclopedia', 'facts', 'trivia'] }
    ];

    // Check direct matching against options
    for (let i = 0; i < catSelect.options.length; i++) {
        const opt = catSelect.options[i];
        if (!opt.value) continue;
        const optText = opt.text.toLowerCase();
        if (str.includes(optText) || optText.includes(str)) {
            catSelect.value = opt.value;
            highlightField(catSelect);
            return true;
        }
    }

    // Check through keyword map
    for (const item of keywordsMap) {
        if (item.val.some(w => str.includes(w))) {
            for (let i = 0; i < catSelect.options.length; i++) {
                const opt = catSelect.options[i];
                if (opt.text.toLowerCase().includes(item.key) || item.key.includes(opt.text.toLowerCase())) {
                    catSelect.value = opt.value;
                    highlightField(catSelect);
                    return true;
                }
            }
        }
    }

    return false;
}

// Match author name in existing author dropdown or fill custom field
function matchAuthor(authorName) {
    if (!authorName) return false;
    const authorSelect = document.getElementById('mAuthorId');
    const authorCustom = document.getElementById('mAuthorName');
    const norm = authorName.toLowerCase().trim();

    let matched = false;
    if (authorSelect) {
        for (let i = 0; i < authorSelect.options.length; i++) {
            const opt = authorSelect.options[i];
            if (!opt.value) continue;
            const optNorm = opt.text.toLowerCase().trim();
            if (norm.includes(optNorm) || optNorm.includes(norm)) {
                authorSelect.value = opt.value;
                if (authorCustom) authorCustom.value = '';
                highlightField(authorSelect);
                matched = true;
                break;
            }
        }
    }

    if (!matched && authorCustom) {
        if (authorSelect) authorSelect.value = '';
        authorCustom.value = authorName;
        highlightField(authorCustom);
        return true;
    }
    return matched;
}

// Core AI extraction engine from PDF file & Web APIs
async function processPdfWithAi(file) {
    if (!file || file.type !== 'application/pdf') {
        if (file && !file.name.toLowerCase().endsWith('.pdf')) {
            alert('Please select a valid PDF file.');
            return;
        }
    }

    // Update UI status to processing
    aiProgressBar.style.display = 'block';
    aiProgressIcon.className = 'fa-solid fa-spinner fa-spin';
    aiProgressIcon.style.color = 'var(--primary)';
    aiProgressText.textContent = 'AI is reading PDF pages & extracting metadata...';
    aiBadge.style.background = '#e67e22';
    aiBadgeText.textContent = 'Scanning PDF...';
    aiFieldsCount.style.display = 'none';

    let filledFields = 0;
    let pdfPages = 0;
    let metaTitle = '';
    let metaAuthor = '';
    let metaYear = '';
    let firstPageText = '';
    let detectedIsbn = null;

    // 1. Client-Side PDF Parsing via PDF.js
    try {
        if (window.pdfjsLib) {
            const arrayBuffer = await file.arrayBuffer();
            const typedarray = new Uint8Array(arrayBuffer);
            const pdfDoc = await pdfjsLib.getDocument({ data: typedarray }).promise;
            
            pdfPages = pdfDoc.numPages || 1;
            
            // Immediately fill exact PDF Total Pages
            const pagesInput = document.getElementById('mPages');
            if (pagesInput && pdfPages > 0) {
                pagesInput.value = pdfPages;
                highlightField(pagesInput);
                filledFields++;
            }

            // Extract embedded PDF metadata
            try {
                const meta = await pdfDoc.getMetadata();
                if (meta && meta.info) {
                    if (meta.info.Title && meta.info.Title.trim() && !meta.info.Title.toLowerCase().includes('untitled')) {
                        metaTitle = meta.info.Title.trim();
                    }
                    if (meta.info.Author && meta.info.Author.trim()) {
                        metaAuthor = meta.info.Author.trim();
                    }
                    if (meta.info.CreationDate) {
                        const m = meta.info.CreationDate.match(/\b(19\d\d|20\d\d)\b/);
                        if (m) metaYear = m[0];
                    }
                }
            } catch (metaErr) {
                console.warn('PDF metadata warning:', metaErr);
            }

            // Extract text from first 2 pages
            const pagesToScan = Math.min(pdfPages, 2);
            for (let p = 1; p <= pagesToScan; p++) {
                try {
                    const page = await pdfDoc.getPage(p);
                    const textContent = await page.getTextContent();
                    const pageStr = textContent.items.map(it => it.str).join(' ');
                    firstPageText += ' ' + pageStr;
                } catch(e) {}
            }

            // Detect ISBN regex
            const isbnMatch = firstPageText.match(/(?:ISBN(?:-1[03])?:?\s*)([0-9X-]{10,17})/i);
            if (isbnMatch) {
                detectedIsbn = isbnMatch[1].replace(/[^0-9X]/gi, '');
            }
        }
    } catch (pdfErr) {
        console.warn('PDF.js reading error, continuing to Web AI lookup:', pdfErr);
    }

    // Determine best search query for Web / Google Books lookup
    const fallbackTitle = cleanFileName(file.name);
    let candidateQuery = detectedIsbn ? 'isbn:' + detectedIsbn : (metaTitle || fallbackTitle);

    // Update status to Google Books / Web search
    aiProgressText.textContent = `Searching Google Books & Web for "${metaTitle || fallbackTitle}"...`;
    aiBadgeText.textContent = 'Searching Web...';

    // 2. Query Google Books API or OpenLibrary or Server Endpoint
    let bookResult = null;

    // Try Google Books API directly from client browser
    try {
        const gbUrl = `https://www.googleapis.com/books/v1/volumes?q=${encodeURIComponent(candidateQuery)}&maxResults=1`;
        const gbRes = await fetch(gbUrl);
        if (gbRes.ok) {
            const gbJson = await gbRes.json();
            if (gbJson.items && gbJson.items.length > 0) {
                const vol = gbJson.items[0].volumeInfo;
                bookResult = {
                    title: vol.title ? (vol.subtitle ? `${vol.title}: ${vol.subtitle}` : vol.title) : '',
                    author: vol.authors ? vol.authors.join(', ') : '',
                    publishedYear: vol.publishedDate ? (vol.publishedDate.match(/\b\d{4}\b/) || [''])[0] : '',
                    language: vol.language === 'en' ? 'English' : (vol.language || 'English'),
                    categories: vol.categories ? vol.categories.join(' ') : '',
                    description: vol.description || '',
                    source: 'Google Books'
                };
            }
        }
    } catch (gbErr) {
        console.warn('Google Books client fetch error, trying fallback:', gbErr);
    }

    // Try OpenLibrary API fallback if Google Books did not find volume
    if (!bookResult || !bookResult.title) {
        try {
            const olUrl = `https://openlibrary.org/search.json?q=${encodeURIComponent(candidateQuery)}&limit=1`;
            const olRes = await fetch(olUrl);
            if (olRes.ok) {
                const olJson = await olRes.json();
                if (olJson.docs && olJson.docs.length > 0) {
                    const doc = olJson.docs[0];
                    bookResult = {
                        title: doc.title || '',
                        author: doc.author_name ? doc.author_name.join(', ') : '',
                        publishedYear: doc.first_publish_year || '',
                        language: 'English',
                        categories: doc.subject ? doc.subject.slice(0, 10).join(' ') : '',
                        description: '',
                        source: 'OpenLibrary'
                    };

                    // If work key exists, fetch description
                    if (doc.key) {
                        try {
                            const workRes = await fetch(`https://openlibrary.org${doc.key}.json`);
                            if (workRes.ok) {
                                const workJson = await workRes.json();
                                let d = workJson.description || '';
                                if (typeof d === 'object' && d.value) d = d.value;
                                if (typeof d === 'string' && d.trim()) {
                                    bookResult.description = d.replace(/\[(.*?)\]\(.*?\)/g, '$1').trim();
                                }
                            }
                        } catch(e) {}
                    }
                }
            }
        } catch (olErr) {
            console.warn('OpenLibrary client fetch error:', olErr);
        }
    }

    // Try Server-Side AI endpoint fallback
    if (!bookResult || !bookResult.title) {
        try {
            const srvUrl = `ai-book-lookup.php?q=${encodeURIComponent(candidateQuery)}&title=${encodeURIComponent(metaTitle || fallbackTitle)}&author=${encodeURIComponent(metaAuthor)}&isbn=${encodeURIComponent(detectedIsbn || '')}`;
            const srvRes = await fetch(srvUrl);
            if (srvRes.ok) {
                const srvJson = await srvRes.json();
                if (srvJson.success && srvJson.data && srvJson.data.title) {
                    bookResult = {
                        title: srvJson.data.title,
                        author: srvJson.data.author_name,
                        author_id: srvJson.data.author_id,
                        category_id: srvJson.data.category_id,
                        publishedYear: srvJson.data.year,
                        language: srvJson.data.language || 'English',
                        categories: srvJson.data.category_name,
                        description: srvJson.data.description,
                        book_id: srvJson.data.book_id,
                        source: srvJson.data.source
                    };
                }
            }
        } catch(srvErr) {}
    }

    // 3. Populate Form Fields with Auto-Filled Values
    const titleInput = document.getElementById('mTitle');
    const yearInput = document.getElementById('mYear');
    const langInput = document.getElementById('mLanguage');
    const descInput = document.getElementById('mDesc');
    const licenseInput = document.getElementById('mLicense');
    const bookIdSelect = document.getElementById('mBookId');

    // Title
    const finalTitle = (bookResult && bookResult.title) || metaTitle || fallbackTitle;
    if (finalTitle && titleInput) {
        titleInput.value = finalTitle;
        highlightField(titleInput);
        filledFields++;
    }

    // Author
    const finalAuthor = (bookResult && bookResult.author) || metaAuthor;
    if (finalAuthor) {
        if (bookResult && bookResult.author_id) {
            const authorSelect = document.getElementById('mAuthorId');
            if (authorSelect) {
                authorSelect.value = bookResult.author_id;
                highlightField(authorSelect);
                filledFields++;
            }
        } else {
            if (matchAuthor(finalAuthor)) {
                filledFields++;
            }
        }
    }

    // Category
    if (bookResult && bookResult.category_id) {
        const catSelect = document.getElementById('mCategoryId');
        if (catSelect) {
            catSelect.value = bookResult.category_id;
            highlightField(catSelect);
            filledFields++;
        }
    } else {
        const catSearchStr = (bookResult && bookResult.categories ? bookResult.categories + ' ' : '') + finalTitle + ' ' + firstPageText;
        if (matchCategoryInDropdown(catSearchStr)) {
            filledFields++;
        }
    }

    // Publication Year
    const finalYear = (bookResult && bookResult.publishedYear) || metaYear;
    if (finalYear && yearInput) {
        yearInput.value = finalYear;
        highlightField(yearInput);
        filledFields++;
    }

    // Language
    const finalLang = (bookResult && bookResult.language) || 'English';
    if (langInput) {
        langInput.value = finalLang;
        highlightField(langInput);
        filledFields++;
    }

    // Synopsis / Description
    let finalDesc = (bookResult && bookResult.description) || '';
    if (!finalDesc && firstPageText.length > 50) {
        // Fallback: extract meaningful introductory summary from PDF text
        const cleaned = firstPageText.replace(/\s+/g, ' ').trim();
        finalDesc = cleaned.substring(0, 320) + '...';
    }
    if (finalDesc && descInput) {
        descInput.value = finalDesc;
        highlightField(descInput);
        filledFields++;
    }

    // Physical Book Link
    if (bookResult && bookResult.book_id && bookIdSelect) {
        bookIdSelect.value = bookResult.book_id;
        highlightField(bookIdSelect);
        filledFields++;
    }

    // Copyright / License Information
    if (licenseInput && (!licenseInput.value || licenseInput.value === 'Open Educational Resource')) {
        licenseInput.value = 'Open Educational Resource';
        highlightField(licenseInput);
        filledFields++;
    }

    // 4. Update UI Status to Completed
    const sourceLabel = (bookResult && bookResult.source) ? ` & ${bookResult.source}` : '';
    aiProgressIcon.className = 'fa-solid fa-circle-check';
    aiProgressIcon.style.color = '#2e6f73';
    aiProgressText.innerHTML = `<strong>✨ AI Auto-Filled ${filledFields} fields!</strong> Sourced from PDF${sourceLabel}.`;
    aiBadge.style.background = '#2e6f73';
    aiBadgeText.textContent = `✨ ${filledFields} Fields Auto-Filled`;
    aiFieldsCount.textContent = `${filledFields} fields auto-filled`;
    aiFieldsCount.style.display = 'inline-block';
    btnReScanPdf.style.display = 'inline-block';
}

// Manual search trigger when user types in Title field and clicks "AI Fetch by Title"
async function manualAiSearchByTitle() {
    const titleVal = document.getElementById('mTitle').value.trim();
    if (!titleVal) {
        alert('Please enter a book title first to search Google Books.');
        document.getElementById('mTitle').focus();
        return;
    }

    aiProgressBar.style.display = 'block';
    aiProgressIcon.className = 'fa-solid fa-spinner fa-spin';
    aiProgressIcon.style.color = 'var(--primary)';
    aiProgressText.textContent = `Searching Google Books & Web for "${titleVal}"...`;
    aiBadge.style.background = '#e67e22';
    aiBadgeText.textContent = 'Searching...';

    // Call server AI endpoint or Google Books
    try {
        const srvUrl = `ai-book-lookup.php?q=${encodeURIComponent(titleVal)}&title=${encodeURIComponent(titleVal)}`;
        const res = await fetch(srvUrl);
        const data = await res.json();
        if (data.success && data.data) {
            const b = data.data;
            let count = 0;
            if (b.title) { document.getElementById('mTitle').value = b.title; highlightField(document.getElementById('mTitle')); count++; }
            if (b.author_id) { document.getElementById('mAuthorId').value = b.author_id; highlightField(document.getElementById('mAuthorId')); count++; }
            else if (b.author_name) { document.getElementById('mAuthorName').value = b.author_name; highlightField(document.getElementById('mAuthorName')); count++; }
            if (b.category_id) { document.getElementById('mCategoryId').value = b.category_id; highlightField(document.getElementById('mCategoryId')); count++; }
            if (b.year) { document.getElementById('mYear').value = b.year; highlightField(document.getElementById('mYear')); count++; }
            if (b.language) { document.getElementById('mLanguage').value = b.language; highlightField(document.getElementById('mLanguage')); count++; }
            if (b.description) { document.getElementById('mDesc').value = b.description; highlightField(document.getElementById('mDesc')); count++; }
            if (b.book_id) { document.getElementById('mBookId').value = b.book_id; highlightField(document.getElementById('mBookId')); count++; }

            aiProgressIcon.className = 'fa-solid fa-circle-check';
            aiProgressIcon.style.color = '#2e6f73';
            aiProgressText.innerHTML = `<strong>✨ AI Auto-Filled ${count} fields!</strong> Sourced from ${b.source || 'Google Books'}.`;
            aiBadge.style.background = '#2e6f73';
            aiBadgeText.textContent = `✨ ${count} Fields Filled`;
        } else {
            aiProgressIcon.className = 'fa-solid fa-circle-info';
            aiProgressIcon.style.color = '#e67e22';
            aiProgressText.textContent = 'No matching books found online for this title.';
            aiBadgeText.textContent = 'No Match';
        }
    } catch(err) {
        aiProgressIcon.className = 'fa-solid fa-circle-exclamation';
        aiProgressIcon.style.color = '#e63946';
        aiProgressText.textContent = 'Could not fetch book details. Please check your network connection.';
    }
}

function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Upload New Digital Book';
    document.getElementById('modalSubtitle').textContent = 'Select PDF first — AI will automatically extract & fill book details';
    document.getElementById('formAction').value = 'add';
    document.getElementById('bookId').value = '';
    document.getElementById('mTitle').value = '';
    document.getElementById('mAuthorId').value = '';
    document.getElementById('mAuthorName').value = '';
    document.getElementById('mCategoryId').value = '';
    document.getElementById('mBookId').value = '';
    document.getElementById('mYear').value = new Date().getFullYear();
    document.getElementById('mPages').value = '10';
    document.getElementById('mLanguage').value = 'English';
    document.getElementById('mAccessType').value = 'free';
    document.getElementById('mStatus').value = 'active';
    document.getElementById('mLicense').value = 'Open Educational Resource';
    document.getElementById('mDesc').value = '';
    document.getElementById('mFile').required = true;
    document.getElementById('mFile').value = '';
    document.getElementById('fileNotice').style.display = 'none';
    document.getElementById('modalSubmitBtn').innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Upload & Publish';
    
    // Reset AI status
    aiProgressBar.style.display = 'none';
    aiBadge.style.background = 'var(--primary)';
    aiBadgeText.textContent = 'AI Ready';
    aiFieldsCount.style.display = 'none';
    btnReScanPdf.style.display = 'none';
    
    modal.style.display = 'flex';
}

function openEditModal(b) {
    document.getElementById('modalTitle').textContent = 'Edit Digital Book: ' + b.title;
    document.getElementById('modalSubtitle').textContent = 'Modify book details or replace existing PDF document';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('bookId').value = b.id;
    document.getElementById('mTitle').value = b.title;
    document.getElementById('mAuthorId').value = b.author_id || '';
    document.getElementById('mAuthorName').value = b.author_name || '';
    document.getElementById('mCategoryId').value = b.category_id || '';
    document.getElementById('mBookId').value = b.book_id || '';
    document.getElementById('mYear').value = b.publication_year || '';
    document.getElementById('mPages').value = b.pages || 1;
    document.getElementById('mLanguage').value = b.language || 'English';
    document.getElementById('mAccessType').value = b.access_type || 'free';
    document.getElementById('mStatus').value = b.status || 'active';
    document.getElementById('mLicense').value = b.license_info || '';
    document.getElementById('mDesc').value = b.description || '';
    document.getElementById('mFile').required = false;
    document.getElementById('mFile').value = '';
    document.getElementById('fileNotice').style.display = 'block';
    document.getElementById('modalSubmitBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
    
    // Reset AI status
    aiProgressBar.style.display = 'none';
    aiBadge.style.background = 'var(--primary)';
    aiBadgeText.textContent = 'Edit Mode';
    aiFieldsCount.style.display = 'none';
    btnReScanPdf.style.display = 'none';

    modal.style.display = 'flex';
}

function closeModal() {
    modal.style.display = 'none';
}

// Close modal on click outside modal content
window.addEventListener('click', function(e) {
    if (e.target === modal) {
        closeModal();
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
