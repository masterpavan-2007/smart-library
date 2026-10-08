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
<div class="modal" id="bookModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:100; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; max-width:650px; width:92%; max-height:90vh; overflow-y:auto; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3);">
        <div class="flex justify-between items-center" style="border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:16px;">
            <h3 style="margin:0;" id="modalTitle">Upload New Digital Book</h3>
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" enctype="multipart/form-data" id="bookForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="bookId" value="">

            <div class="form-group">
                <label>Book Title *</label>
                <input type="text" name="title" id="mTitle" class="form-control" required placeholder="e.g. Modern Web Architecture">
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label>Author (Select existing or type below)</label>
                    <select name="author_id" id="mAuthorId" class="form-control">
                        <option value="">-- Select Author --</option>
                        <?php foreach ($authors as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= e($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Or Author Name (Custom / External)</label>
                    <input type="text" name="author_name" id="mAuthorName" class="form-control" placeholder="Author display name">
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label>Category *</label>
                    <select name="category_id" id="mCategoryId" class="form-control" required>
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Link to Physical Book (Optional)</label>
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
                    <label>Publication Year</label>
                    <input type="number" name="publication_year" id="mYear" class="form-control" min="1800" max="<?= date('Y') + 1 ?>" value="<?= date('Y') ?>">
                </div>
                <div class="form-group">
                    <label>Total Pages</label>
                    <input type="number" name="pages" id="mPages" class="form-control" min="1" value="10" required>
                </div>
                <div class="form-group">
                    <label>Language</label>
                    <input type="text" name="language" id="mLanguage" class="form-control" value="English">
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label>Access Policy</label>
                    <select name="access_type" id="mAccessType" class="form-control">
                        <option value="free">Free for Students</option>
                        <option value="open_access">Open Access (Public)</option>
                        <option value="restricted">Restricted / Staff Only</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Publication Status</label>
                    <select name="status" id="mStatus" class="form-control">
                        <option value="active">Active (Visible)</option>
                        <option value="inactive">Inactive (Hidden)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Copyright / License Information</label>
                <input type="text" name="license_info" id="mLicense" class="form-control" value="Open Educational Resource">
            </div>

            <div class="form-group">
                <label>Synopsis / Description</label>
                <textarea name="description" id="mDesc" class="form-control" rows="3" placeholder="Brief summary of the digital resource contents..."></textarea>
            </div>

            <div class="form-group" id="fileGroup">
                <label>PDF Document File * <span class="text-muted" style="font-weight:400;">(Max 50MB)</span></label>
                <input type="file" name="pdf_file" id="mFile" class="form-control" accept="application/pdf">
                <div id="fileNotice" class="text-muted" style="font-size:12px; margin-top:4px; display:none;">Leave blank to preserve current PDF file.</div>
            </div>

            <div class="flex justify-end gap-2" style="border-top:1px solid var(--border); padding-top:16px; margin-top:16px;">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn"><i class="fa-solid fa-cloud-arrow-up"></i> Upload & Publish</button>
            </div>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('bookModal');

function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Upload New Digital Book';
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
    document.getElementById('fileNotice').style.display = 'none';
    document.getElementById('modalSubmitBtn').innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Upload & Publish';
    modal.style.display = 'flex';
}

function openEditModal(b) {
    document.getElementById('modalTitle').textContent = 'Edit Digital Book: ' + b.title;
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
    document.getElementById('fileNotice').style.display = 'block';
    document.getElementById('modalSubmitBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
    modal.style.display = 'flex';
}

function closeModal() {
    modal.style.display = 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
