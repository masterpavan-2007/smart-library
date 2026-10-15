<?php
/**
 * Smart Virtual Library - 3D Virtual Book Reader
 * Realistic interactive 3D open book inspired by physical books with lifted curved right page.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

// Allows students, librarians, admin, or guest preview for sample books
$isLogged = isLoggedIn();
$userRole = $_SESSION['role'] ?? 'guest';

$bookId = (int)($_GET['id'] ?? 0);
$book = null;

if ($bookId > 0) {
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
}

// Fallback to rich sample book if no digital book is selected or found
$isSampleBook = false;
if (!$book) {
    $isSampleBook = true;
    $book = [
        'id' => 0,
        'title' => 'Clean Code & The Architecture of Modern Systems',
        'author_name' => 'Robert C. Martin & Donald Knuth',
        'author_name_rel' => 'Robert C. Martin & Donald Knuth',
        'category_name' => 'Computer Science & Software Architecture',
        'publisher_name' => 'Academic Computer Press',
        'publication_year' => '2024',
        'language' => 'English',
        'edition' => 'Interactive 3D Collector Edition',
        'pages' => 16,
        'access_type' => 'open_access',
        'license_info' => 'Creative Commons Attribution (CC-BY 4.0)',
        'description' => 'A masterclass in software craftsmanship, clean system design, algorithmic elegance, and resilient computing architectures. Explores principles of maintainable code, decoupling, asynchronous patterns, and virtual libraries.',
        'file_path' => ''
    ];
}

$author = $book['author_name_rel'] ?: ($book['author_name'] ?: 'Unknown Author');

// Student specifics: progress, bookmarks, favorite status
$studentId = null;
$lastPage = 1;
$isFavorite = false;
$bookmarks = [];

if ($isLogged && $userRole === 'student') {
    $st = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $st->execute([$_SESSION['user_id']]);
    $studentId = $st->fetchColumn();

    if ($studentId && $book['id'] > 0) {
        // Fetch saved progress
        $rh = $pdo->prepare("SELECT last_page, progress_percent FROM reading_history WHERE student_id = ? AND digital_book_id = ?");
        $rh->execute([$studentId, $book['id']]);
        $history = $rh->fetch();
        if ($history && $history['last_page'] > 0) {
            $lastPage = (int)$history['last_page'];
        }

        // Fetch bookmarks
        $bm = $pdo->prepare("SELECT id, page_number, note, created_at FROM bookmarks WHERE student_id = ? AND digital_book_id = ? ORDER BY page_number ASC");
        $bm->execute([$studentId, $book['id']]);
        $bookmarks = $bm->fetchAll();

        // Fetch favorite status
        $fav = $pdo->prepare("SELECT id FROM favorites WHERE student_id = ? AND digital_book_id = ?");
        $fav->execute([$studentId, $book['id']]);
        $isFavorite = (bool)$fav->fetchColumn();
    }
}

// Resolve PDF URL if uploaded file exists
$pdfUrl = null;
if (!empty($book['file_path']) && $book['id'] > 0) {
    $pdfBaseDir = realpath(__DIR__ . '/../uploads/digital-books');
    $pdfTarget = realpath($pdfBaseDir . DIRECTORY_SEPARATOR . basename($book['file_path']));
    if ($pdfTarget && file_exists($pdfTarget) && str_starts_with($pdfTarget, $pdfBaseDir)) {
        $pdfUrl = basePath() . '/student/serve-pdf.php?id=' . (int)$book['id'];
    }
}

$backUrl = ($userRole === 'student') ? basePath() . '/student/virtual-library.php' : (
    in_array($userRole, ['admin', 'librarian']) ? basePath() . '/' . $userRole . '/digital-books.php' : basePath() . '/auth/login.php'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= e($book['title']) ?> &middot; 3D Virtual Book Reader</title>
<!-- Google Fonts for rich book typography -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Georgia&family=Inter:wght@400;500;600;700;800&family=Merriweather:ital,wght@0,300;0,400;0,700;1,300;1,400&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
<!-- Font Awesome 6 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<!-- Main Styles & 3D Reader Styles -->
<link rel="stylesheet" href="<?= basePath() ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= basePath() ?>/assets/css/reader-3d.css">
<!-- StPageFlip Local Library -->
<script src="<?= basePath() ?>/assets/js/page-flip.browser.js"></script>
<!-- PDF.js CDN with Worker -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
    if (window.pdfjsLib) {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }
</script>
</head>
<body class="virtual-reader-body">

<!-- Reader Header Toolbar -->
<header class="reader-top-bar">
    <div class="reader-bar-group">
        <a href="<?= e($backUrl) ?>" class="reader-tool-btn" title="Back to Library">
            <i class="fa-solid fa-arrow-left"></i>
            <span class="hide-mobile">Library</span>
        </a>
        <div class="reader-book-heading">
            <div class="reader-book-title" title="<?= e($book['title']) ?>"><?= e($book['title']) ?></div>
            <div class="reader-book-meta"><?= e($author) ?> &middot; <?= e($book['category_name'] ?: 'General') ?></div>
        </div>
    </div>

    <!-- Center Navigation Stepper -->
    <div class="reader-bar-group">
        <button id="prevPageBtn" class="reader-tool-btn" title="Previous Page (Left Arrow)">
            <i class="fa-solid fa-chevron-left"></i>
        </button>
        <div class="reader-stepper-box">
            <span>Page</span>
            <input type="number" id="pageNumberInput" min="1" max="<?= (int)$book['pages'] ?>" value="<?= (int)$lastPage ?>" class="reader-stepper-input">
            <span>of <span id="pageCountSpan"><?= (int)$book['pages'] ?></span></span>
        </div>
        <button id="nextPageBtn" class="reader-tool-btn" title="Next Page (Right Arrow)">
            <i class="fa-solid fa-chevron-right"></i>
        </button>
    </div>

    <!-- Right Controls: TOC, Search, Font, Themes, TTS, Bookmarks, Fullscreen -->
    <div class="reader-bar-group">
        <!-- Table of Contents -->
        <button id="toggleTocBtn" class="reader-tool-btn" title="Table of Contents">
            <i class="fa-solid fa-list-ol"></i>
            <span class="hide-mobile">TOC</span>
        </button>

        <!-- Search in Book -->
        <button id="toggleSearchBtn" class="reader-tool-btn" title="Search inside book">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>

        <!-- Font Size Adjustment -->
        <div class="reader-bar-group hide-mobile">
            <button id="fontSizeDecBtn" class="reader-tool-btn" title="Decrease Font Size"><i class="fa-solid fa-font" style="font-size:10px;"></i>-</button>
            <span id="fontSizeBadge" style="font-size:11px; color:#94a3b8; min-width:32px; text-align:center;">100%</span>
            <button id="fontSizeIncBtn" class="reader-tool-btn" title="Increase Font Size"><i class="fa-solid fa-font" style="font-size:13px;"></i>+</button>
        </div>

        <!-- Theme Selection (White / Sepia / Dark) -->
        <div class="reader-bar-group hide-mobile">
            <button class="reader-tool-btn theme-opt-btn active" data-theme="white" title="White Paper (Reference Look)" style="background:#f8fafc; color:#0f172a; border-color:#cbd5e1;">A</button>
            <button class="reader-tool-btn theme-opt-btn" data-theme="sepia" title="Warm Sepia Vintage" style="background:#f6eedb; color:#78350f; border-color:#d97706;">A</button>
            <button class="reader-tool-btn theme-opt-btn" data-theme="dark" title="Dark / OLED Night" style="background:#0f172a; color:#f8fafc; border-color:#475569;">A</button>
        </div>

        <!-- Zoom Controls -->
        <div class="reader-bar-group hide-mobile">
            <button id="zoomOutBtn" class="reader-tool-btn" title="Zoom Out (-)"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
            <button id="zoomResetBtn" class="reader-tool-btn" title="Reset Zoom (100%)"><span id="zoomLevelText">100%</span></button>
            <button id="zoomInBtn" class="reader-tool-btn" title="Zoom In (+)"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
        </div>

        <!-- Text-to-Speech (Read Aloud) -->
        <button id="readAloudToggleBtn" class="reader-tool-btn" title="Listen / Read Aloud (Text-to-Speech)">
            <i class="fa-solid fa-headphones"></i>
            <span class="hide-mobile">Read Aloud</span>
        </button>

        <?php if ($studentId): ?>
        <!-- Bookmark Current Page -->
        <button id="addBookmarkBtn" class="reader-tool-btn" title="Bookmark This Page">
            <i class="fa-regular fa-bookmark"></i>
        </button>

        <!-- Bookmarks Drawer Toggle -->
        <button id="toggleBookmarksDrawerBtn" class="reader-tool-btn" title="Saved Bookmarks">
            <i class="fa-solid fa-bookmark"></i>
            <span id="bookmarkCountBadge" style="background:#2563eb; color:#fff; font-size:10px; padding:1px 5px; border-radius:10px;"><?= count($bookmarks) ?></span>
        </button>

        <!-- Favorite Toggle -->
        <button id="toggleFavoriteBtn" class="reader-tool-btn <?= $isFavorite ? 'active' : '' ?>" title="Toggle Favorite">
            <i class="<?= $isFavorite ? 'fa-solid' : 'fa-regular' ?> fa-heart" style="<?= $isFavorite ? 'color:#ef4444;' : '' ?>"></i>
        </button>
        <?php endif; ?>

        <!-- Book Info Modal -->
        <button id="bookInfoBtn" class="reader-tool-btn" title="Book Details & Synopsis">
            <i class="fa-solid fa-circle-info"></i>
        </button>

        <!-- Fullscreen Mode -->
        <button id="fullscreenBtn" class="reader-tool-btn" title="Toggle Fullscreen">
            <i class="fa-solid fa-expand"></i>
        </button>
    </div>
</header>

<!-- Reading Progress Bar (Subtle track under toolbar) -->
<div class="reader-progress-track">
    <div class="reader-progress-bar" id="readerProgressBar"></div>
</div>

<!-- Main 3D Viewport Stage -->
<main class="reader-stage-container" id="readerStage">
    <!-- Floating Side Navigation Arrows -->
    <button class="reader-floating-nav prev" id="floatingPrevBtn" title="Previous Page (Left Arrow)">
        <i class="fa-solid fa-chevron-left"></i>
    </button>
    <button class="reader-floating-nav next" id="floatingNextBtn" title="Next Page (Right Arrow)">
        <i class="fa-solid fa-chevron-right"></i>
    </button>

    <!-- Scalable 3D Book Container -->
    <div class="book-3d-scaler" id="bookScaler">
        <!-- Realistic Hardcover Outer Base -->
        <div class="book-hardcover-shell" id="bookHardcover">
            <!-- Left Stack of Pages (Thickness) -->
            <div class="book-edge-stack-left"></div>
            <!-- Right Stack of Pages (Thickness) -->
            <div class="book-edge-stack-right"></div>
            <!-- Center Spine Gutter Crease & Shadow -->
            <div class="book-spine-gutter"></div>

            <!-- StPageFlip Spread Container -->
            <div id="bookSpread" data-book-theme="white">
                <?php if ($pdfUrl): ?>
                    <!-- PDF pages will be dynamically injected here by PDF.js and StPageFlip -->
                    <div class="book-page cover-page" data-page-number="1" data-density="hard">
                        <div class="cover-gold-badge"><i class="fa-solid fa-shield-halved"></i> Verified Digital Resource</div>
                        <h1 class="cover-title"><?= e($book['title']) ?></h1>
                        <div class="cover-subtitle"><?= e($book['category_name'] ?: 'Digital Library Collection') ?></div>
                        <div class="cover-author">By <?= e($author) ?></div>
                        <div class="cover-stamp"><i class="fa-solid fa-spinner fa-spin"></i> Loading High-Res PDF Pages...</div>
                        <div class="page-corner-curl-cue"></div>
                    </div>
                <?php else: ?>
                    <!-- Rich Interactive 16-Page Sample Book -->
                    
                    <!-- PAGE 1: Hardcover Jacket / Title -->
                    <div class="book-page cover-page" data-page-number="1" data-density="hard">
                        <div class="cover-gold-badge"><i class="fa-solid fa-award"></i> Premier Academic Edition</div>
                        <h1 class="cover-title"><?= e($book['title']) ?></h1>
                        <div class="cover-subtitle"><?= e($book['category_name'] ?: 'Advanced Software Engineering') ?></div>
                        <div class="cover-author">Written by <?= e($author) ?></div>
                        <div class="cover-stamp">
                            <span>Smart Library Digital Archives &bull; Publication <?= e($book['publication_year'] ?: '2024') ?></span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 2: Front Matter & Metadata (Left Page) -->
                    <div class="book-page page-left" data-page-number="2">
                        <div class="page-running-head">
                            <span>Cataloging in Publication</span>
                            <span>Front Matter</span>
                        </div>
                        <div class="page-inner-content">
                            <div style="font-size:13px; line-height:1.6; color:#64748b; margin-top:20px;">
                                <p><strong>Smart Library Management System Digital Edition</strong></p>
                                <p>Copyright &copy; <?= date('Y') ?> Academic Computer Press.<br>All rights reserved under International and Pan-American Conventions.</p>
                                <hr style="border:0; border-top:1px solid rgba(0,0,0,0.1); margin:20px 0;">
                                <p><strong>Library Catalog Data:</strong></p>
                                <p>Title: <?= e($book['title']) ?><br>
                                Author(s): <?= e($author) ?><br>
                                Subject: Software Design Patterns, Clean Architecture, Algorithms<br>
                                Access Policy: <?= strtoupper(e($book['access_type'])) ?><br>
                                License: <?= e($book['license_info']) ?></p>
                                <div style="margin-top:35px; padding:15px; background:rgba(0,0,0,0.03); border-radius:6px; font-style:italic;">
                                    "Dedicated to the students and researchers who believe that writing clean, elegant code is an art form that endures."
                                </div>
                            </div>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-left">2</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 3: Table of Contents (Right Page) -->
                    <div class="book-page page-right page-lifted-curve" data-page-number="3">
                        <div class="page-running-head">
                            <span>Contents</span>
                            <span>Table of Contents</span>
                        </div>
                        <div class="page-inner-content">
                            <h2 class="chapter-title" style="font-size:20px; border-bottom:2px solid #2563eb; padding-bottom:6px;">Table of Contents</h2>
                            <ul class="toc-list">
                                <li class="toc-item" onclick="window.bookReader.goToPage(4)">
                                    <span class="toc-title">Chapter 1: The Essence of Clean Code & Craftsmanship</span>
                                    <span class="toc-page">Page 4</span>
                                </li>
                                <li class="toc-item" onclick="window.bookReader.goToPage(6)">
                                    <span class="toc-title">Chapter 2: Computational Complexity & Elegance</span>
                                    <span class="toc-page">Page 6</span>
                                </li>
                                <li class="toc-item" onclick="window.bookReader.goToPage(8)">
                                    <span class="toc-title">Chapter 3: Designing for Scale & Resilience</span>
                                    <span class="toc-page">Page 8</span>
                                </li>
                                <li class="toc-item" onclick="window.bookReader.goToPage(10)">
                                    <span class="toc-title">Chapter 4: The Virtual Library of Alexandria</span>
                                    <span class="toc-page">Page 10</span>
                                </li>
                                <li class="toc-item" onclick="window.bookReader.goToPage(12)">
                                    <span class="toc-title">Chapter 5: Architectural Patterns & Decoupling</span>
                                    <span class="toc-page">Page 12</span>
                                </li>
                                <li class="toc-item" onclick="window.bookReader.goToPage(14)">
                                    <span class="toc-title">Epilogue & Further Reading</span>
                                    <span class="toc-page">Page 14</span>
                                </li>
                                <li class="toc-item" onclick="window.bookReader.goToPage(15)">
                                    <span class="toc-title">Index & Glossary of Terms</span>
                                    <span class="toc-page">Page 15</span>
                                </li>
                            </ul>
                            <div class="book-quote-box" style="margin-top:25px;">
                                "Any fool can write code that a computer can understand. Good programmers write code that humans can understand."<br>
                                <strong>&mdash; Martin Fowler</strong>
                            </div>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-right">3</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 4: Chapter 1 (Left Page) -->
                    <div class="book-page page-left" data-page-number="4">
                        <div class="page-running-head">
                            <span>Chapter 1</span>
                            <span>The Essence of Clean Code</span>
                        </div>
                        <div class="page-inner-content">
                            <div class="chapter-num">Chapter 1</div>
                            <h2 class="chapter-title">The Essence of Clean Code & Craftsmanship</h2>
                            <p><span class="drop-cap">T</span>he ratio of time spent reading versus writing code is well over ten to one. We are constantly reading old code as part of the effort to write new code. Therefore, making code readable makes it fundamentally easier and faster to write.</p>
                            <p>Clean code always looks like it was written by someone who cared. There is nothing obvious that you can do to make it better. Everything is right where you expect it to be, organized with deliberate precision and intent.</p>
                            <div class="book-quote-box">
                                "Clean code is not written by following a rigid checklist; it is sculpted through disciplined care for the developer who reads it next."
                            </div>
                            <p>When you enter a well-crafted system, the architecture speaks to you. The modules have distinct responsibilities, and unexpected side effects are minimized through pure functions and immutable boundaries.</p>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-left">4</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 5: Chapter 1 Continued (Right Page) -->
                    <div class="book-page page-right" data-page-number="5">
                        <div class="page-running-head">
                            <span>Chapter 1</span>
                            <span>Function Principles</span>
                        </div>
                        <div class="page-inner-content">
                            <h3 style="margin-top:0; font-size:16px;">Small Functions & Single Responsibility</h3>
                            <p>Functions should do one thing. They should do it well. They should do it only. If a function contains sections that can be extracted into separate units, it is trying to solve too many problems at once.</p>
                            <div class="book-code-block">
// Elegant, Self-Documenting Function
function calculateLateFee(daysOverdue, dailyRate, gracePeriod) {
    if (daysOverdue <= gracePeriod) return 0.00;
    const chargeableDays = daysOverdue - gracePeriod;
    return Math.min(chargeableDays * dailyRate, MAX_FINE_CAP);
}
                            </div>
                            <p>Notice how clear the domain logic becomes when parameters are intentional and side-effects are absent. The name explains exactly what the computation accomplishes without demanding supplementary inline comments.</p>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-right">5</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 6: Chapter 2 (Left Page) -->
                    <div class="book-page page-left" data-page-number="6">
                        <div class="page-running-head">
                            <span>Chapter 2</span>
                            <span>Computational Complexity</span>
                        </div>
                        <div class="page-inner-content">
                            <div class="chapter-num">Chapter 2</div>
                            <h2 class="chapter-title">Computational Complexity & Elegance</h2>
                            <p><span class="drop-cap">A</span>n algorithm is more than a mechanical recipe; it is a mathematical assertion that a problem can be solved within defined boundaries of time and memory.</p>
                            <p>As dataset volumes scale from hundreds of physical books to millions of digitized records, the asymptotic complexity of our search algorithms becomes the decisive factor in system responsiveness.</p>
                            <div class="book-diagram-box">
                                <div style="font-family:monospace; font-weight:700; font-size:13px; color:#2563eb;">
                                    O(1) &lt; O(log n) &lt; O(n) &lt; O(n log n) &lt; O(n&sup2;)
                                </div>
                                <div class="book-diagram-caption">Asymptotic time complexity hierarchy</div>
                            </div>
                            <p>A binary search over an indexed ISBN catalog delivers answers in <code>O(log n)</code> steps, reducing millions of comparisons to fewer than twenty operations.</p>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-left">6</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 7: Chapter 2 Continued (Right Page) -->
                    <div class="book-page page-right" data-page-number="7">
                        <div class="page-running-head">
                            <span>Chapter 2</span>
                            <span>Asynchronous Flow & Memory</span>
                        </div>
                        <div class="page-inner-content">
                            <h3 style="margin-top:0; font-size:16px;">The Asynchronous Pipeline</h3>
                            <p>In modern web architectures, blocking the execution thread while fetching digital assets is unacceptable. The non-blocking event loop offloads input/output operations, keeping the interactive user interface fluid and responsive.</p>
                            <div class="book-quote-box">
                                "Simplicity is prerequisite for reliability."<br>
                                <strong>&mdash; Edsger W. Dijkstra</strong>
                            </div>
                            <p>By streaming digital pages incrementally and caching rendered canvases in memory, the Virtual Library delivers instantaneous 3D page flips without stutter or network latency.</p>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-right">7</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 8: Chapter 3 (Left Page) -->
                    <div class="book-page page-left" data-page-number="8">
                        <div class="page-running-head">
                            <span>Chapter 3</span>
                            <span>Designing for Scale</span>
                        </div>
                        <div class="page-inner-content">
                            <div class="chapter-num">Chapter 3</div>
                            <h2 class="chapter-title">Designing for Scale & Resilience</h2>
                            <p><span class="drop-cap">S</span>ystems are like living organisms; they must adapt to changing loads, unexpected network partitions, and hardware failures while continuing to serve users without interruption.</p>
                            <p>When engineering database schemas for college library networks, foreign key constraints, cascade rules, and normalized relational designs prevent corrupted circulation states and orphaned fine records.</p>
                            <div class="book-code-block">
-- Scalable Circulation Schema Indexing
CREATE INDEX idx_student_circulation 
ON book_issues (student_id, status);

CREATE INDEX idx_digital_category 
ON digital_books (category_id, status);
                            </div>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-left">8</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 9: Chapter 3 Continued (Right Page) -->
                    <div class="book-page page-right" data-page-number="9">
                        <div class="page-running-head">
                            <span>Chapter 3</span>
                            <span>Security & Authentication</span>
                        </div>
                        <div class="page-inner-content">
                            <h3 style="margin-top:0; font-size:16px;">The Defense-in-Depth Model</h3>
                            <p>Security is never an afterthought tacked on at the end of development. Every endpoint streaming digital assets must verify student authentication, role permissions, and resource ownership before serving a single byte.</p>
                            <div class="book-diagram-box">
                                <i class="fa-solid fa-lock" style="font-size:24px; color:#10b981; margin-bottom:6px;"></i>
                                <div style="font-size:12.5px; font-weight:600;">Secure Stream Controller Validation</div>
                                <div class="book-diagram-caption">Session Verification &bull; MIME Integrity &bull; Traversal Prevention</div>
                            </div>
                            <p>By utilizing PHP PDO prepared statements with parameter binding, SQL injection vectors are rendered obsolete at the database driver layer.</p>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-right">9</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 10: Chapter 4 (Left Page) -->
                    <div class="book-page page-left" data-page-number="10">
                        <div class="page-running-head">
                            <span>Chapter 4</span>
                            <span>The Virtual Alexandria</span>
                        </div>
                        <div class="page-inner-content">
                            <div class="chapter-num">Chapter 4</div>
                            <h2 class="chapter-title">The Virtual Library of Alexandria</h2>
                            <p><span class="drop-cap">F</span>or thousands of years, physical libraries have stood as beacons of human curiosity, preserving parchment, papyrus, and printed ink against the ravages of time.</p>
                            <p>Today, the Virtual Library synthesizes physical circulation records with digital readers, granting students access to rare manuscripts, scientific textbooks, and research journals from any device on campus.</p>
                            <div class="book-quote-box">
                                "The library is not merely a warehouse for printed sheets; it is an active laboratory of human thought."
                            </div>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-left">10</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 11: Chapter 4 Continued (Right Page) -->
                    <div class="book-page page-right" data-page-number="11">
                        <div class="page-running-head">
                            <span>Chapter 4</span>
                            <span>3D Spatial Reading</span>
                        </div>
                        <div class="page-inner-content">
                            <h3 style="margin-top:0; font-size:16px;">Spatial Perception in Digital Reading</h3>
                            <p>Studies in cognitive psychology reveal that human memory relies heavily on spatial landmarks while absorbing long-form literature. Traditional vertical endless scrolling disrupts this mental map.</p>
                            <p>By rendering an authentic 3D open book with a center spine, page thickness, and realistic turning physics, the brain retains a physical sense of progress and bookmark location.</p>
                            <div class="book-diagram-box">
                                <i class="fa-solid fa-book-open" style="font-size:22px; color:#3b82f6; margin-bottom:6px;"></i>
                                <div style="font-size:12.5px; font-weight:600;">Spine Curvature & Two-Page Field of View</div>
                                <div class="book-diagram-caption">Mimics physical book ergonomics for enhanced reading endurance</div>
                            </div>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-right">11</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 12: Chapter 5 (Left Page) -->
                    <div class="book-page page-left" data-page-number="12">
                        <div class="page-running-head">
                            <span>Chapter 5</span>
                            <span>Architectural Patterns</span>
                        </div>
                        <div class="page-inner-content">
                            <div class="chapter-num">Chapter 5</div>
                            <h2 class="chapter-title">Architectural Patterns & Decoupling</h2>
                            <p><span class="drop-cap">G</span>reat architectures defer decisions. They decouple the business rules of the library &mdash; borrowing limits, grace periods, fine calculators &mdash; from UI delivery channels.</p>
                            <p>Whether a student interacts via an Android tablet, a laptop browser, or a desktop workstation, the underlying domain entities remain invariant, testable, and robust.</p>
                            <div class="book-quote-box">
                                "A good architecture allows major decisions to be deferred. A good architect maximizes the number of decisions not made."<br>
                                <strong>&mdash; Robert C. Martin</strong>
                            </div>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-left">12</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 13: Chapter 5 Continued (Right Page) -->
                    <div class="book-page page-right" data-page-number="13">
                        <div class="page-running-head">
                            <span>Chapter 5</span>
                            <span>Quality & Testing</span>
                        </div>
                        <div class="page-inner-content">
                            <h3 style="margin-top:0; font-size:16px;">Automated Verification</h3>
                            <p>Software systems without automated regression suites are legacy code from the moment they are written. Every circulation workflow &mdash; issuing a book, validating student limits, calculating overdue penalties &mdash; deserves comprehensive test coverage.</p>
                            <div class="book-code-block">
// Automated Flow Verification Sample
$testUser = createStudentUser($pdo);
$issue = issueBook($pdo, $testUser['id'], $bookId);
assert($issue['status'] === 'issued');
                            </div>
                            <p>When automated tests give developers confidence, refactoring ceases to be a frightening gamble and becomes a joyful discipline.</p>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-right">13</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 14: Epilogue (Left Page) -->
                    <div class="book-page page-left" data-page-number="14">
                        <div class="page-running-head">
                            <span>Epilogue</span>
                            <span>Summary & Looking Ahead</span>
                        </div>
                        <div class="page-inner-content">
                            <h2 class="chapter-title" style="font-size:20px;">Epilogue & Looking Forward</h2>
                            <p>The journey of building software is endless. As technology stacks evolve from physical card catalogs to relational databases and immersive 3D interfaces, the underlying human mission remains constant: connecting curious minds with knowledge.</p>
                            <p>We encourage you to experiment with this reader: adjust font scales, flip between vintage Sepia and modern OLED dark modes, bookmark memorable paragraphs, and listen via text-to-speech voice synthesis.</p>
                            <div style="margin-top:30px; text-align:center;">
                                <i class="fa-solid fa-graduation-cap" style="font-size:32px; color:#3b82f6; margin-bottom:10px;"></i>
                                <div style="font-weight:700; font-size:14px;">Smart Library Management System</div>
                                <div style="font-size:12px; color:#64748b;">Full-Stack Academic Mini-Project &bull; 2024</div>
                            </div>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-left">14</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 15: Index (Right Page) -->
                    <div class="book-page page-right" data-page-number="15">
                        <div class="page-running-head">
                            <span>Index</span>
                            <span>Alphabetical Glossary</span>
                        </div>
                        <div class="page-inner-content">
                            <h2 class="chapter-title" style="font-size:18px;">Index & Key Terms</h2>
                            <div style="font-size:12.5px; line-height:1.7;">
                                <p><strong>Algorithm Complexity:</strong> Asymptotic notation, Big-O analysis &mdash; <em>Page 6</em></p>
                                <p><strong>Asynchronous I/O:</strong> Non-blocking event loops, streams &mdash; <em>Page 7</em></p>
                                <p><strong>Clean Code:</strong> Naming conventions, function sizing &mdash; <em>Page 4, 5</em></p>
                                <p><strong>Defense-in-Depth:</strong> Prepared PDO statements, session tokens &mdash; <em>Page 9</em></p>
                                <p><strong>Digital Preservation:</strong> Electronic document archiving &mdash; <em>Page 10</em></p>
                                <p><strong>Ergonomics of Reading:</strong> 3D perspective, page curvature &mdash; <em>Page 11</em></p>
                                <p><strong>Single Responsibility:</strong> SOLID principles, cohesion &mdash; <em>Page 5, 12</em></p>
                                <p><strong>Test Automation:</strong> Unit testing, continuous validation &mdash; <em>Page 13</em></p>
                            </div>
                        </div>
                        <div class="page-running-foot">
                            <span class="page-num-right">15</span>
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>

                    <!-- PAGE 16: Back Cover (Hardcover Back) -->
                    <div class="book-page cover-page" data-page-number="16" data-density="hard" style="justify-content:center;">
                        <i class="fa-solid fa-bookmark" style="font-size:36px; color:#38bdf8; margin-bottom:16px;"></i>
                        <h2 style="font-size:20px; font-weight:800; color:#fff; margin:0 0 10px 0;">Smart Library Digital Resource</h2>
                        <p style="font-size:13px; color:#94a3b8; max-width:280px; line-height:1.5; margin:0 auto 20px auto;">
                            Verified Academic Circulation Asset &bull; College Mini-Project Integration
                        </p>
                        <div style="background:#fff; padding:10px; border-radius:6px; display:inline-block; margin-bottom:15px;">
                            <i class="fa-solid fa-barcode" style="font-size:48px; color:#000;"></i>
                            <div style="font-size:9px; color:#000; font-family:monospace; font-weight:700;">*SLMS-DIGITAL-2024*</div>
                        </div>
                        <div style="font-size:11px; color:#64748b;">
                            Smart Library Management System with Virtual 3D Book Reader
                        </div>
                        <div class="page-corner-curl-cue"></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Floating Bottom Controls Dock -->
    <div class="reader-bottom-dock">
        <button class="reader-tool-btn" onclick="window.bookReader.turnPrev()" title="Previous"><i class="fa-solid fa-chevron-left"></i></button>
        <span style="font-size:12px; color:#cbd5e1; font-weight:600;"><span id="progressBadgeText">0%</span> Read</span>
        <button class="reader-tool-btn" onclick="window.bookReader.turnNext()" title="Next"><i class="fa-solid fa-chevron-right"></i></button>
        <div class="reader-dock-divider"></div>
        <button class="reader-tool-btn" onclick="window.bookReader.zoomIn()" title="Zoom In"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
        <button class="reader-tool-btn" onclick="window.bookReader.zoomOut()" title="Zoom Out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
        <div class="reader-dock-divider"></div>
        <button class="reader-tool-btn" onclick="window.bookReader.toggleDrawer('tocDrawer')" title="Contents"><i class="fa-solid fa-list-ol"></i></button>
    </div>
</main>

<!-- Loading Spinner Overlay -->
<div id="readerLoadingSpinner" style="position:fixed; inset:0; background:rgba(12,15,20,0.88); z-index:999; display:none; align-items:center; justify-content:center; flex-direction:column; gap:14px;">
    <i class="fa-solid fa-spinner fa-spin fa-3x" style="color:#38bdf8;"></i>
    <div style="font-size:15px; font-weight:600; color:#f8fafc;">Rendering 3D Book Pages...</div>
</div>

<!-- Floating Toast Notification -->
<div class="reader-floating-toast" id="readerFloatingToast">
    <i class="fa-solid fa-circle-check" style="color:#10b981;"></i>
    <span id="floatingToastMsg">Progress saved</span>
</div>

<!-- Table of Contents Drawer -->
<aside class="reader-side-drawer" id="tocDrawer">
    <div class="drawer-head">
        <div class="drawer-title"><i class="fa-solid fa-list-ol text-primary"></i> Table of Contents</div>
        <button class="reader-tool-btn" onclick="window.bookReader.toggleDrawer('tocDrawer')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="drawer-body">
        <ul class="toc-list" style="margin:0;">
            <li class="toc-item" onclick="window.bookReader.goToPage(1); window.bookReader.toggleDrawer('tocDrawer');">
                <span class="toc-title">Book Cover / Title Page</span>
                <span class="toc-page">Page 1</span>
            </li>
            <li class="toc-item" onclick="window.bookReader.goToPage(3); window.bookReader.toggleDrawer('tocDrawer');">
                <span class="toc-title">Table of Contents</span>
                <span class="toc-page">Page 3</span>
            </li>
            <li class="toc-item" onclick="window.bookReader.goToPage(4); window.bookReader.toggleDrawer('tocDrawer');">
                <span class="toc-title">Chapter 1: Clean Code & Craftsmanship</span>
                <span class="toc-page">Page 4</span>
            </li>
            <li class="toc-item" onclick="window.bookReader.goToPage(6); window.bookReader.toggleDrawer('tocDrawer');">
                <span class="toc-title">Chapter 2: Computational Complexity</span>
                <span class="toc-page">Page 6</span>
            </li>
            <li class="toc-item" onclick="window.bookReader.goToPage(8); window.bookReader.toggleDrawer('tocDrawer');">
                <span class="toc-title">Chapter 3: Designing for Scale</span>
                <span class="toc-page">Page 8</span>
            </li>
            <li class="toc-item" onclick="window.bookReader.goToPage(10); window.bookReader.toggleDrawer('tocDrawer');">
                <span class="toc-title">Chapter 4: The Virtual Alexandria</span>
                <span class="toc-page">Page 10</span>
            </li>
            <li class="toc-item" onclick="window.bookReader.goToPage(12); window.bookReader.toggleDrawer('tocDrawer');">
                <span class="toc-title">Chapter 5: Architectural Patterns</span>
                <span class="toc-page">Page 12</span>
            </li>
            <li class="toc-item" onclick="window.bookReader.goToPage(14); window.bookReader.toggleDrawer('tocDrawer');">
                <span class="toc-title">Epilogue & Looking Forward</span>
                <span class="toc-page">Page 14</span>
            </li>
            <li class="toc-item" onclick="window.bookReader.goToPage(15); window.bookReader.toggleDrawer('tocDrawer');">
                <span class="toc-title">Index & Glossary</span>
                <span class="toc-page">Page 15</span>
            </li>
        </ul>
    </div>
</aside>

<!-- Bookmarks Drawer -->
<aside class="reader-side-drawer" id="bookmarksDrawer">
    <div class="drawer-head">
        <div class="drawer-title"><i class="fa-solid fa-bookmark text-primary"></i> Saved Bookmarks</div>
        <button class="reader-tool-btn" onclick="window.bookReader.toggleDrawer('bookmarksDrawer')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="drawer-body">
        <button class="reader-tool-btn primary" style="width:100%; justify-content:center; margin-bottom:16px;" onclick="window.bookReader.promptAddBookmark()">
            <i class="fa-solid fa-plus"></i> Bookmark Current Page
        </button>
        <div id="bookmarksListBody">
            <?php if (empty($bookmarks)): ?>
                <div style="text-align:center; padding:30px 10px; color:#94a3b8; font-size:13px;">
                    <i class="fa-regular fa-bookmark" style="font-size:28px; margin-bottom:10px; display:block;"></i>
                    No bookmarks saved yet. Click above to bookmark your current page.
                </div>
            <?php else: ?>
                <?php foreach ($bookmarks as $bm): ?>
                    <div class="bm-entry-card" onclick="window.bookReader.goToPage(<?= (int)$bm['page_number'] ?>); window.bookReader.toggleDrawer('bookmarksDrawer');">
                        <div>
                            <div class="bm-page-tag"><i class="fa-solid fa-bookmark"></i> Page <?= (int)$bm['page_number'] ?></div>
                            <div class="bm-note-text"><?= e($bm['note'] ?: 'Bookmark') ?></div>
                        </div>
                        <button class="reader-tool-btn" onclick="event.stopPropagation(); window.bookReader.deleteBookmark(<?= (int)$bm['id'] ?>)" title="Delete" style="padding:4px 7px;">
                            <i class="fa-solid fa-trash-can" style="color:#ef4444; font-size:11px;"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</aside>

<!-- In-Book Search Modal -->
<div class="reader-search-modal" id="searchModal">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <span style="font-weight:700; font-size:15px; color:#f8fafc;"><i class="fa-solid fa-magnifying-glass text-primary"></i> Search in Book</span>
        <button class="reader-tool-btn" onclick="window.bookReader.toggleSearchModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="search-input-wrapper">
        <i class="fa-solid fa-magnifying-glass" style="color:#94a3b8;"></i>
        <input type="text" id="bookSearchInput" class="search-input-field" placeholder="Search keywords, concepts, code..." autofocus>
        <span id="searchCountSpan" style="font-size:12px; color:#94a3b8;">0 results</span>
    </div>
    <div class="search-results-list" id="searchResultsList">
        <div style="color:#94a3b8; padding:12px; font-size:13px; text-align:center;">Type at least 2 characters to search across book pages.</div>
    </div>
</div>

<!-- Book Information Modal -->
<div class="info-overlay" id="bookInfoOverlay" onclick="if(event.target===this) window.bookReader.toggleInfoModal()">
    <div class="info-dialog" style="background:#172033; border:1px solid #334155; border-radius:12px; max-width:540px; padding:24px; color:#f8fafc; box-shadow:0 20px 50px rgba(0,0,0,0.8);">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #334155; padding-bottom:12px; margin-bottom:16px;">
            <h3 style="margin:0; font-size:17px; color:#f8fafc;"><i class="fa-solid fa-book-open" style="color:#38bdf8;"></i> Digital Book Details</h3>
            <button class="reader-tool-btn" onclick="window.bookReader.toggleInfoModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="font-size:13.5px; line-height:1.65;">
            <div style="margin-bottom:8px;"><strong>Title:</strong> <?= e($book['title']) ?></div>
            <div style="margin-bottom:8px;"><strong>Author:</strong> <?= e($author) ?></div>
            <div style="margin-bottom:8px;"><strong>Category:</strong> <?= e($book['category_name'] ?: 'General') ?></div>
            <div style="margin-bottom:8px;"><strong>Publication Year:</strong> <?= e($book['publication_year'] ?: 'N/A') ?></div>
            <div style="margin-bottom:8px;"><strong>Access Policy:</strong> <span class="badge badge-green"><?= e(strtoupper($book['access_type'])) ?></span></div>
            <div style="margin-bottom:8px;"><strong>License:</strong> <?= e($book['license_info']) ?></div>
            <div style="margin-bottom:10px;"><strong>Synopsis:</strong><br><span style="color:#cbd5e1;"><?= nl2br(e($book['description'] ?: 'No synopsis provided.')) ?></span></div>
        </div>
    </div>
</div>

<!-- Reader Configuration Object for JavaScript -->
<script>
window.readerConfig = {
    bookId: <?= json_encode((int)$book['id']) ?>,
    bookTitle: <?= json_encode($book['title']) ?>,
    author: <?= json_encode($author) ?>,
    totalPages: <?= json_encode((int)$book['pages']) ?>,
    lastPage: <?= json_encode((int)$lastPage) ?>,
    pdfUrl: <?= json_encode($pdfUrl) ?>,
    isStudent: <?= json_encode($userRole === 'student') ?>,
    bookmarks: <?= json_encode($bookmarks) ?>
};
</script>
<script src="<?= basePath() ?>/assets/js/reader-3d.js"></script>
</body>
</html>
