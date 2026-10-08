<?php
/**
 * Smart Virtual Library - Interactive Digital Book & PDF Reader
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

// Allows students, librarians, and admin
requireRole(['student', 'librarian', 'admin']);

$bookId = (int)($_GET['id'] ?? 0);
if ($bookId <= 0) {
    setFlash('error', 'Invalid digital book requested.');
    redirect('virtual-library.php');
}

// Fetch digital book
$stmt = $pdo->prepare("
    SELECT db.*, c.name AS category_name, a.name AS author_name_rel, p.name AS publisher_name
    FROM digital_books db
    LEFT JOIN categories c ON c.id = db.category_id
    LEFT JOIN authors a ON a.id = db.author_id
    LEFT JOIN publishers p ON p.id = db.publisher_id
    WHERE db.id = ?
");
$stmt->execute([$bookId]);
$book = $stmt->fetch();

if (!$book) {
    setFlash('error', 'Digital book not found in the catalog.');
    redirect('virtual-library.php');
}

// Format author name fallback
$author = $book['author_name_rel'] ?: ($book['author_name'] ?: 'Unknown Author');

// Student specifics: progress, bookmarks, favorite status
$studentId = null;
$lastPage = 1;
$isFavorite = false;
$bookmarks = [];

if ($_SESSION['role'] === 'student') {
    $st = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $st->execute([$_SESSION['user_id']]);
    $studentId = $st->fetchColumn();

    if ($studentId) {
        // Fetch saved progress
        $rh = $pdo->prepare("SELECT last_page, progress_percent FROM reading_history WHERE student_id = ? AND digital_book_id = ?");
        $rh->execute([$studentId, $bookId]);
        $history = $rh->fetch();
        if ($history && $history['last_page'] > 0) {
            $lastPage = (int)$history['last_page'];
        }

        // Fetch bookmarks
        $bm = $pdo->prepare("SELECT id, page_number, note, created_at FROM bookmarks WHERE student_id = ? AND digital_book_id = ? ORDER BY page_number ASC");
        $bm->execute([$studentId, $bookId]);
        $bookmarks = $bm->fetchAll();

        // Fetch favorite status
        $fav = $pdo->prepare("SELECT id FROM favorites WHERE student_id = ? AND digital_book_id = ?");
        $fav->execute([$studentId, $bookId]);
        $isFavorite = (bool)$fav->fetchColumn();
    }
}

$pdfUrl = basePath() . '/student/serve-pdf.php?id=' . $bookId;
$backUrl = $_SESSION['role'] === 'student' ? basePath() . '/student/virtual-library.php' : basePath() . '/' . $_SESSION['role'] . '/digital-books.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($book['title']) ?> · Digital Reader</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= basePath() ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= basePath() ?>/assets/css/reader-tts.css">
<!-- PDF.js from CDN for browser rendering -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
</script>
<style>
/* Dedicated Reader Styles */
body.reader-body {
    margin: 0;
    padding: 0;
    background: #0f172a;
    color: #e2e8f0;
    overflow: hidden;
    height: 100vh;
    display: flex;
    flex-direction: column;
}

.reader-header {
    height: 58px;
    background: #1e293b;
    border-bottom: 1px solid #334155;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 16px;
    flex-shrink: 0;
    z-index: 100;
}

.reader-header .left-controls,
.reader-header .center-controls,
.reader-header .right-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.reader-header .book-meta {
    max-width: 320px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.reader-header .book-title {
    font-size: 14.5px;
    font-weight: 700;
    color: #f8fafc;
    overflow: hidden;
    text-overflow: ellipsis;
}

.reader-header .book-sub {
    font-size: 11.5px;
    color: #94a3b8;
}

.reader-btn {
    background: #334155;
    color: #f1f5f9;
    border: 1px solid #475569;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}

.reader-btn:hover:not(:disabled) {
    background: #475569;
    color: #fff;
}

.reader-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.reader-btn.primary {
    background: var(--primary);
    border-color: var(--primary-dark);
}
.reader-btn.primary:hover {
    background: var(--primary-dark);
}

.reader-btn.fav-btn.active {
    background: #dc2626;
    border-color: #b91c1c;
    color: #fff;
}

.page-stepper {
    display: flex;
    align-items: center;
    background: #0f172a;
    border: 1px solid #334155;
    border-radius: 6px;
    padding: 2px 8px;
    font-size: 13px;
    gap: 6px;
}

.page-stepper input {
    width: 44px;
    background: transparent;
    border: 1px solid #475569;
    border-radius: 4px;
    color: #fff;
    text-align: center;
    font-weight: 600;
    padding: 2px 4px;
}

/* Reader Viewport */
.reader-container {
    flex: 1;
    overflow: auto;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 24px;
    position: relative;
    background: #090d16;
}

.canvas-wrapper {
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.6), 0 8px 10px -6px rgba(0, 0, 0, 0.5);
    background: #fff;
    border-radius: 4px;
    overflow: hidden;
    position: relative;
    margin: 0 auto;
    transition: transform 0.15s ease;
}

#pdfCanvas {
    display: block;
    margin: 0 auto;
}

/* Fallback iframe container if PDF.js is loading or blocked */
#pdfFallbackFrame {
    width: 100%;
    height: 100%;
    border: none;
    display: none;
}

/* Toast alert */
.reader-toast {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(15, 23, 42, 0.94);
    border: 1px solid #3b82f6;
    color: #f8fafc;
    padding: 10px 20px;
    border-radius: 30px;
    font-size: 13.5px;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.4);
    z-index: 1000;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease, transform 0.3s ease;
}
.reader-toast.show {
    opacity: 1;
    transform: translateX(-50%) translateY(-5px);
}

/* Bookmark Drawer / Modal */
.reader-modal {
    position: fixed;
    top: 58px;
    right: -360px;
    width: 340px;
    bottom: 0;
    background: #1e293b;
    border-left: 1px solid #334155;
    z-index: 200;
    transition: right 0.25s ease;
    display: flex;
    flex-direction: column;
    box-shadow: -5px 0 25px rgba(0,0,0,0.5);
}
.reader-modal.open {
    right: 0;
}
.reader-modal-header {
    padding: 16px;
    border-bottom: 1px solid #334155;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.reader-modal-body {
    padding: 16px;
    overflow-y: auto;
    flex: 1;
}
.bookmark-card {
    background: #0f172a;
    border: 1px solid #334155;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    transition: border-color 0.15s;
}
.bookmark-card:hover {
    border-color: #3b82f6;
}

/* Info dialog */
.info-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.7);
    z-index: 500;
    display: none;
    align-items: center;
    justify-content: center;
}
.info-dialog {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 12px;
    width: 90%;
    max-width: 520px;
    padding: 24px;
    color: #f8fafc;
}

/* Loading spinner */
.reader-spinner {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    color: #94a3b8;
}
</style>
</head>
<body class="reader-body">

<!-- Reader Header Toolbar -->
<header class="reader-header">
    <div class="left-controls">
        <a href="<?= e($backUrl) ?>" class="reader-btn" title="Back to Library">
            <i class="fa-solid fa-arrow-left"></i>
            <span>Library</span>
        </a>
        <div class="book-meta">
            <div class="book-title" title="<?= e($book['title']) ?>"><?= e($book['title']) ?></div>
            <div class="book-sub"><?= e($author) ?> &middot; <?= e($book['category_name'] ?: 'General') ?></div>
        </div>
    </div>

    <!-- Center Navigation -->
    <div class="center-controls">
        <button id="prevPageBtn" class="reader-btn" title="Previous Page (Left Arrow)">
            <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div class="page-stepper">
            <span>Page</span>
            <input type="number" id="pageNumberInput" min="1" value="<?= $lastPage ?>">
            <span>of <span id="pageCountSpan">--</span></span>
        </div>
        <button id="nextPageBtn" class="reader-btn" title="Next Page (Right Arrow)">
            <i class="fa-solid fa-chevron-right"></i>
        </button>
    </div>

    <!-- Right Controls: Zoom, Bookmark, Favorites, Info, Fullscreen -->
    <div class="right-controls">
        <div class="flex gap-1">
            <button id="zoomOutBtn" class="reader-btn" title="Zoom Out (-)"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
            <button id="zoomResetBtn" class="reader-btn" title="Reset Zoom (100%)"><span id="zoomLevelText">100%</span></button>
            <button id="zoomInBtn" class="reader-btn" title="Zoom In (+)"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            <button id="fitWidthBtn" class="reader-btn" title="Fit to Width"><i class="fa-solid fa-arrows-left-right"></i></button>
        </div>

        <!-- Text-to-Speech (Listen / Read Aloud) -->
        <button id="readAloudToggleBtn" class="reader-btn tts-main-btn" title="Listen / Read Aloud (Text-to-Speech)">
            <i class="fa-solid fa-headphones"></i>
            <span>Listen / Read Aloud</span>
            <div class="tts-soundwave paused" id="headerSoundwave">
                <span></span><span></span><span></span><span></span>
            </div>
        </button>

        <?php if ($studentId): ?>
        <button id="toggleBookmarkDrawerBtn" class="reader-btn" title="Bookmarks">
            <i class="fa-solid fa-bookmark"></i>
            <span id="bookmarkBadgeCount" class="badge badge-blue" style="font-size:10px; padding:1px 6px;"><?= count($bookmarks) ?></span>
        </button>

        <button id="addBookmarkQuickBtn" class="reader-btn" title="Bookmark This Page">
            <i class="fa-regular fa-bookmark"></i>
        </button>

        <button id="toggleFavoriteBtn" class="reader-btn fav-btn <?= $isFavorite ? 'active' : '' ?>" title="Toggle Favorite">
            <i class="<?= $isFavorite ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i>
        </button>
        <?php endif; ?>

        <button id="bookInfoBtn" class="reader-btn" title="Book Details">
            <i class="fa-solid fa-circle-info"></i>
        </button>

        <button id="fullscreenBtn" class="reader-btn" title="Toggle Fullscreen">
            <i class="fa-solid fa-expand"></i>
        </button>
    </div>
</header>

<!-- Main Reader Canvas Viewport -->
<div class="reader-container" id="readerContainer">
    <div class="reader-spinner" id="readerSpinner">
        <i class="fa-solid fa-spinner fa-spin fa-2x" style="color:var(--primary);"></i>
        <div style="margin-top:10px; font-size:14px;">Loading digital document...</div>
    </div>
    
    <div class="canvas-wrapper" id="canvasWrapper" style="display:none;">
        <canvas id="pdfCanvas"></canvas>
        <div id="pdfTextLayer" class="textLayer"></div>
    </div>

    <!-- Fallback iframe for standalone PDF engines -->
    <iframe id="pdfFallbackFrame" src="<?= e($pdfUrl) ?>#page=<?= $lastPage ?>&zoom=100"></iframe>
</div>

<!-- Toast notification -->
<div class="reader-toast" id="readerToast">
    <i class="fa-solid fa-circle-check" style="color:#10b981;"></i>
    <span id="toastMsg">Progress saved</span>
</div>

<!-- Bookmarks Drawer Modal -->
<div class="reader-modal" id="bookmarksDrawer">
    <div class="reader-modal-header">
        <div style="font-weight:700; font-size:15px;"><i class="fa-solid fa-bookmark text-primary"></i> Saved Bookmarks</div>
        <button class="reader-btn" onclick="toggleBookmarksDrawer()" style="padding:3px 8px;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="reader-modal-body">
        <div style="margin-bottom:14px;">
            <button class="reader-btn primary" style="width:100%; justify-content:center;" onclick="promptAddBookmark()">
                <i class="fa-solid fa-plus"></i> Add Bookmark at Page <span id="currentPgSpan"><?= $lastPage ?></span>
            </button>
        </div>
        <div id="bookmarksListContainer">
            <?php if (empty($bookmarks)): ?>
                <div class="text-muted" style="text-align:center; padding:20px; font-size:13px;" id="noBookmarksText">
                    <i class="fa-regular fa-bookmark" style="font-size:24px; display:block; margin-bottom:8px;"></i>
                    No bookmarks saved for this book yet.
                </div>
            <?php else: ?>
                <?php foreach ($bookmarks as $bm): ?>
                    <div class="bookmark-card" onclick="goToPage(<?= (int)$bm['page_number'] ?>)">
                        <div>
                            <div style="font-weight:700; color:#38bdf8; font-size:13px;">Page <?= (int)$bm['page_number'] ?></div>
                            <div style="font-size:12px; color:#cbd5e1;"><?= e($bm['note'] ?: 'Bookmark') ?></div>
                        </div>
                        <button class="reader-btn" onclick="event.stopPropagation(); deleteBookmark(<?= (int)$bm['id'] ?>)" title="Remove" style="padding:3px 6px;">
                            <i class="fa-solid fa-trash-can" style="color:#ef4444; font-size:12px;"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Book Info Dialog Modal -->
<div class="info-overlay" id="infoOverlay" onclick="if(event.target===this) toggleInfoDialog()">
    <div class="info-dialog">
        <div class="flex justify-between items-center" style="border-bottom:1px solid #334155; padding-bottom:12px; margin-bottom:16px;">
            <h3 style="margin:0; font-size:17px; color:#fff;"><i class="fa-solid fa-book-open" style="color:var(--primary);"></i> Book Information</h3>
            <button class="reader-btn" onclick="toggleInfoDialog()" style="padding:2px 8px;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="font-size:14px; line-height:1.6;">
            <div style="margin-bottom:8px;"><strong>Title:</strong> <?= e($book['title']) ?></div>
            <div style="margin-bottom:8px;"><strong>Author:</strong> <?= e($author) ?></div>
            <div style="margin-bottom:8px;"><strong>Category:</strong> <?= e($book['category_name'] ?: 'General') ?></div>
            <div style="margin-bottom:8px;"><strong>Publication Year:</strong> <?= e($book['publication_year'] ?: 'N/A') ?></div>
            <div style="margin-bottom:8px;"><strong>Language:</strong> <?= e($book['language'] ?: 'English') ?></div>
            <div style="margin-bottom:8px;"><strong>Access Policy:</strong> <span class="badge badge-green"><?= e(strtoupper($book['access_type'])) ?></span></div>
            <div style="margin-bottom:8px;"><strong>License:</strong> <?= e($book['license_info']) ?></div>
            <div style="margin-bottom:8px;"><strong>Description:</strong><br><span style="color:#cbd5e1;"><?= nl2br(e($book['description'] ?: 'No synopsis provided.')) ?></span></div>
        </div>
    </div>
</div>

<script>
const bookId = <?= json_encode($bookId) ?>;
const pdfUrl = <?= json_encode($pdfUrl) ?>;
let currentPageNum = <?= (int)$lastPage ?>;
let totalPagesCount = 1;
let currentZoom = 1.25;
let pdfDoc = null;
let pageRendering = false;
let pageNumPending = null;
const scaleIncrement = 0.2;

const canvas = document.getElementById('pdfCanvas');
const ctx = canvas.getContext('2d');
const pageNumInput = document.getElementById('pageNumberInput');
const pageCountSpan = document.getElementById('pageCountSpan');
const currentPgSpan = document.getElementById('currentPgSpan');
const zoomLevelText = document.getElementById('zoomLevelText');
const spinner = document.getElementById('readerSpinner');
const canvasWrapper = document.getElementById('canvasWrapper');
const fallbackFrame = document.getElementById('pdfFallbackFrame');

// Initialize PDF.js loading
pdfjsLib.getDocument(pdfUrl).promise.then(function(doc) {
    window.pdfDoc = doc;
    pdfDoc = doc;
    totalPagesCount = doc.numPages;
    pageCountSpan.textContent = totalPagesCount;
    pageNumInput.max = totalPagesCount;

    spinner.style.display = 'none';
    canvasWrapper.style.display = 'block';

    if (currentPageNum > totalPagesCount) {
        currentPageNum = 1;
    }

    renderPage(currentPageNum);

    // Initialize TTS Engine
    if (typeof window.initEBookTTS === 'function') {
        window.initEBookTTS({
            bookId: bookId,
            bookTitle: <?= json_encode($book['title']) ?>,
            totalPages: totalPagesCount,
            currentPage: currentPageNum,
            onPageChange: function(newPage) {
                goToPage(newPage);
            }
        });
    }

    // Connect header Listen / Read Aloud button
    const ttsHeaderBtn = document.getElementById('readAloudToggleBtn');
    if (ttsHeaderBtn) {
        ttsHeaderBtn.addEventListener('click', () => {
            if (window.ttsReader) {
                window.ttsReader.toggleDock();
            }
        });
    }

    <?php if ($lastPage > 1): ?>
    showToast("Resumed from saved page " + currentPageNum);
    <?php endif; ?>
}).catch(function(err) {
    console.warn("PDF.js render failed or blocked, switching to native embed fallback:", err);
    spinner.style.display = 'none';
    canvasWrapper.style.display = 'none';
    fallbackFrame.style.display = 'block';
    showToast("Loaded through browser PDF viewer");
});

function renderPage(num) {
    pageRendering = true;
    pdfDoc.getPage(num).then(function(page) {
        const viewport = page.getViewport({ scale: currentZoom });
        canvas.height = viewport.height;
        canvas.width = viewport.width;

        const renderContext = {
            canvasContext: ctx,
            viewport: viewport
        };
        const renderTask = page.render(renderContext);

        renderTask.promise.then(function() {
            pageRendering = false;
            if (pageNumPending !== null) {
                renderPage(pageNumPending);
                pageNumPending = null;
            }
        });

        // Render PDF text layer for selection and text-to-speech syncing
        const textLayerDiv = document.getElementById('pdfTextLayer');
        if (textLayerDiv) {
            textLayerDiv.innerHTML = '';
            textLayerDiv.style.width = viewport.width + 'px';
            textLayerDiv.style.height = viewport.height + 'px';

            page.getTextContent().then(function(textContent) {
                try {
                    if (pdfjsLib.renderTextLayer) {
                        pdfjsLib.renderTextLayer({
                            textContentSource: textContent,
                            container: textLayerDiv,
                            viewport: viewport,
                            textDivs: []
                        });
                    }
                } catch (e) {
                    console.warn('TextLayer render error (graceful fallback):', e);
                }
            });
        }
    });

    pageNumInput.value = num;
    if (currentPgSpan) currentPgSpan.textContent = num;
    document.getElementById('prevPageBtn').disabled = (num <= 1);
    document.getElementById('nextPageBtn').disabled = (num >= totalPagesCount);

    // Save reading progress debounced
    triggerProgressSave(num);

    // Notify TTS reader of page change
    if (window.ttsReader) {
        window.ttsReader.onPageNavigated(num);
    }
}

function queueRenderPage(num) {
    if (pageRendering) {
        pageNumPending = num;
    } else {
        renderPage(num);
    }
}

function goToPage(num) {
    num = parseInt(num, 10);
    if (isNaN(num) || num < 1) num = 1;
    if (num > totalPagesCount) num = totalPagesCount;
    currentPageNum = num;
    queueRenderPage(currentPageNum);
}

document.getElementById('prevPageBtn').addEventListener('click', () => {
    if (currentPageNum <= 1) return;
    currentPageNum--;
    queueRenderPage(currentPageNum);
});

document.getElementById('nextPageBtn').addEventListener('click', () => {
    if (currentPageNum >= totalPagesCount) return;
    currentPageNum++;
    queueRenderPage(currentPageNum);
});

pageNumInput.addEventListener('change', (e) => {
    goToPage(e.target.value);
});

// Zoom Controls
document.getElementById('zoomInBtn').addEventListener('click', () => {
    if (currentZoom >= 3.0) return;
    currentZoom += scaleIncrement;
    updateZoomDisplay();
    queueRenderPage(currentPageNum);
});

document.getElementById('zoomOutBtn').addEventListener('click', () => {
    if (currentZoom <= 0.6) return;
    currentZoom -= scaleIncrement;
    updateZoomDisplay();
    queueRenderPage(currentPageNum);
});

document.getElementById('zoomResetBtn').addEventListener('click', () => {
    currentZoom = 1.0;
    updateZoomDisplay();
    queueRenderPage(currentPageNum);
});

document.getElementById('fitWidthBtn').addEventListener('click', () => {
    if (!pdfDoc) return;
    const containerWidth = document.getElementById('readerContainer').clientWidth - 60;
    pdfDoc.getPage(currentPageNum).then(page => {
        const vp = page.getViewport({ scale: 1.0 });
        currentZoom = Math.min(2.5, Math.max(0.6, containerWidth / vp.width));
        updateZoomDisplay();
        queueRenderPage(currentPageNum);
    });
});

function updateZoomDisplay() {
    zoomLevelText.textContent = Math.round(currentZoom * 100) + '%';
}

// Keyboard shortcuts (Arrow navigation)
window.addEventListener('keydown', (e) => {
    if (e.target.tagName === 'INPUT') return;
    if (e.key === 'ArrowRight' || e.key === 'PageDown') {
        if (currentPageNum < totalPagesCount) { currentPageNum++; queueRenderPage(currentPageNum); }
    } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
        if (currentPageNum > 1) { currentPageNum--; queueRenderPage(currentPageNum); }
    } else if (e.key === '+' || e.key === '=') {
        document.getElementById('zoomInBtn').click();
    } else if (e.key === '-') {
        document.getElementById('zoomOutBtn').click();
    }
});

// Fullscreen toggle
document.getElementById('fullscreenBtn').addEventListener('click', () => {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(err => console.log(err));
    } else {
        if (document.exitFullscreen) document.exitFullscreen();
    }
});

// Toast notification helper
let toastTimer = null;
function showToast(msg) {
    const toast = document.getElementById('readerToast');
    document.getElementById('toastMsg').textContent = msg;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.classList.remove('show'); }, 3000);
}

// Reading Progress Auto-Save (Throttled)
let saveTimer = null;
function triggerProgressSave(page) {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
        fetch('save-progress.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_progress',
                digital_book_id: bookId,
                page: page,
                total_pages: totalPagesCount
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.status === 'completed') {
                showToast("Finished reading! Progress: 100%");
            }
        })
        .catch(err => console.warn("Auto-save progress failed", err));
    }, 1200);
}

// Toggle Bookmarks Drawer
function toggleBookmarksDrawer() {
    const drawer = document.getElementById('bookmarksDrawer');
    drawer.classList.toggle('open');
}
const bmDrawerBtn = document.getElementById('toggleBookmarkDrawerBtn');
if (bmDrawerBtn) bmDrawerBtn.addEventListener('click', toggleBookmarksDrawer);

// Prompt Add Bookmark
function promptAddBookmark() {
    const note = prompt("Enter a note for this bookmark (Page " + currentPageNum + "):", "Chapter note / review point");
    if (note !== null) {
        addBookmark(currentPageNum, note);
    }
}
const quickBmBtn = document.getElementById('addBookmarkQuickBtn');
if (quickBmBtn) quickBmBtn.addEventListener('click', promptAddBookmark);

function addBookmark(pageNum, note) {
    fetch('save-progress.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'add_bookmark',
            digital_book_id: bookId,
            page_number: pageNum,
            note: note
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast("Bookmark added at page " + pageNum);
            renderBookmarksList(data.bookmarks);
        } else {
            alert(data.error || 'Could not save bookmark');
        }
    });
}

function deleteBookmark(bmId) {
    if (!confirm("Delete this bookmark?")) return;
    fetch('save-progress.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'delete_bookmark',
            bookmark_id: bmId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast("Bookmark removed");
            // Reload bookmarks
            location.reload();
        }
    });
}

function renderBookmarksList(list) {
    const c = document.getElementById('bookmarksListContainer');
    const badge = document.getElementById('bookmarkBadgeCount');
    if (badge) badge.textContent = list.length;

    if (!list || list.length === 0) {
        c.innerHTML = '<div class="text-muted" style="text-align:center; padding:20px; font-size:13px;"><i class="fa-regular fa-bookmark" style="font-size:24px; display:block; margin-bottom:8px;"></i>No bookmarks saved for this book yet.</div>';
        return;
    }
    let html = '';
    list.forEach(b => {
        html += `
            <div class="bookmark-card" onclick="goToPage(${b.page_number})">
                <div>
                    <div style="font-weight:700; color:#38bdf8; font-size:13px;">Page ${b.page_number}</div>
                    <div style="font-size:12px; color:#cbd5e1;">${b.note || 'Bookmark'}</div>
                </div>
                <button class="reader-btn" onclick="event.stopPropagation(); deleteBookmark(${b.id})" title="Remove" style="padding:3px 6px;">
                    <i class="fa-solid fa-trash-can" style="color:#ef4444; font-size:12px;"></i>
                </button>
            </div>
        `;
    });
    c.innerHTML = html;
}

// Toggle Favorite
const favBtn = document.getElementById('toggleFavoriteBtn');
if (favBtn) {
    favBtn.addEventListener('click', () => {
        fetch('save-progress.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'toggle_favorite',
                digital_book_id: bookId
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (data.is_favorite) {
                    favBtn.classList.add('active');
                    favBtn.querySelector('i').className = 'fa-solid fa-heart';
                    showToast("Added to Favorites ❤️");
                } else {
                    favBtn.classList.remove('active');
                    favBtn.querySelector('i').className = 'fa-regular fa-heart';
                    showToast("Removed from Favorites");
                }
            }
        });
    });
}

// Book Info Dialog
function toggleInfoDialog() {
    const dialog = document.getElementById('infoOverlay');
    dialog.style.display = (dialog.style.display === 'flex') ? 'none' : 'flex';
}
document.getElementById('bookInfoBtn').addEventListener('click', toggleInfoDialog);

// Save progress on page unload
window.addEventListener('beforeunload', () => {
    navigator.sendBeacon('save-progress.php', JSON.stringify({
        action: 'save_progress',
        digital_book_id: bookId,
        page: currentPageNum,
        total_pages: totalPagesCount
    }));
});
</script>
<script src="<?= basePath() ?>/assets/js/reader-tts.js"></script>
</body>
</html>
