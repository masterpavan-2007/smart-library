<?php
/**
 * Admin: Import Digital Books from Open Library & Internet Archive
 * Search public domain and open access books, inspect PDF availability,
 * and perform one-click automatic PDF downloads with duplicate prevention.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

requireRole(['admin', 'librarian']);
$pageTitle = 'Import from Open Library';
$currentPage = 'openlibrary-import.php';

// Quick stats
$totalImported = (int)$pdo->query("SELECT COUNT(*) FROM digital_books WHERE ia_id IS NOT NULL AND ia_id != ''")->fetchColumn();
$totalDigital = (int)$pdo->query("SELECT COUNT(*) FROM digital_books")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<!-- Navigation Tabs -->
<div class="flex gap-2" style="margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 12px; flex-wrap: wrap;">
    <a href="digital-books.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-book-bookmark"></i> Digital Books Catalog</a>
    <a href="openlibrary-import.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-cloud-arrow-down"></i> Import from Open Library</a>
</div>

<!-- Header Hero Card -->
<div class="card" style="margin-bottom: 24px; background: linear-gradient(135deg, rgba(106, 90, 205, 0.08), rgba(46, 111, 115, 0.1)); border: 1px solid rgba(106, 90, 205, 0.2);">
    <div class="flex justify-between items-center" style="flex-wrap: wrap; gap: 16px;">
        <div>
            <div class="flex items-center gap-2" style="margin-bottom: 6px;">
                <span style="font-size: 24px;">🌐</span>
                <h2 style="margin: 0; font-size: 20px;">Open Library & Internet Archive PDF Importer</h2>
            </div>
            <p class="text-muted" style="margin: 0; font-size: 13px; max-width: 650px;">
                Discover millions of public domain, open-access, and classic books from the Open Library. One-click automatic PDF download, authentic PDF validation, and instant catalog integration for students.
            </p>
        </div>
        <div class="flex gap-3">
            <div class="stat-card" style="padding: 12px 18px; min-width: 130px; background: var(--card-bg, #fff); border-radius: 12px; border: 1px solid var(--border);">
                <div class="num" style="font-size: 20px; font-weight: 700; color: var(--primary);"><?= $totalImported ?></div>
                <div class="label" style="font-size: 11px; text-transform: uppercase;">Imported from IA</div>
            </div>
            <div class="stat-card" style="padding: 12px 18px; min-width: 130px; background: var(--card-bg, #fff); border-radius: 12px; border: 1px solid var(--border);">
                <div class="num" style="font-size: 20px; font-weight: 700; color: #2e6f73;"><?= $totalDigital ?></div>
                <div class="label" style="font-size: 11px; text-transform: uppercase;">Total Digital Catalog</div>
            </div>
        </div>
    </div>
</div>

<!-- Search Interface Card -->
<div class="card" style="margin-bottom: 24px;">
    <form id="olSearchForm" onsubmit="handleSearch(event)">
        <div class="flex gap-2" style="flex-wrap: wrap; align-items: center;">
            <div style="flex: 1; min-width: 260px;">
                <input type="text" id="searchInput" class="form-control" placeholder="Search by title, author name, or ISBN (e.g. Frankenstein, Dracula, Jane Eyre)..." required autofocus autocomplete="off">
            </div>
            <select id="searchType" class="form-control" style="width: 150px;">
                <option value="all">All Fields</option>
                <option value="title">Book Title</option>
                <option value="author">Author</option>
                <option value="isbn">ISBN</option>
            </select>
            <button type="submit" class="btn btn-primary" id="btnSearch">
                <i class="fa-solid fa-magnifying-glass"></i> Search Open Library
            </button>
            <button type="button" class="btn btn-outline" onclick="resetSearch()">Clear</button>
        </div>

        <div class="flex justify-between items-center" style="margin-top: 14px; flex-wrap: wrap; gap: 10px;">
            <!-- Filter Checkbox -->
            <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; user-select: none;">
                <input type="checkbox" id="openAccessOnly" checked style="width: 16px; height: 16px; accent-color: var(--primary);">
                <span>Show <strong>Full PDF Available (Open Access)</strong> only</span>
            </label>

            <!-- Quick Suggestions -->
            <div class="flex items-center gap-1" style="flex-wrap: wrap; font-size: 12px;">
                <span class="text-muted"><i class="fa-solid fa-wand-magic-sparkles"></i> Popular Classics:</span>
                <button type="button" class="badge" style="background: rgba(106, 90, 205, 0.12); color: var(--primary); border: none; cursor: pointer; padding: 4px 8px;" onclick="quickSearch('Frankenstein')">Frankenstein</button>
                <button type="button" class="badge" style="background: rgba(106, 90, 205, 0.12); color: var(--primary); border: none; cursor: pointer; padding: 4px 8px;" onclick="quickSearch('Dracula')">Dracula</button>
                <button type="button" class="badge" style="background: rgba(106, 90, 205, 0.12); color: var(--primary); border: none; cursor: pointer; padding: 4px 8px;" onclick="quickSearch('Pride and Prejudice')">Pride & Prejudice</button>
                <button type="button" class="badge" style="background: rgba(106, 90, 205, 0.12); color: var(--primary); border: none; cursor: pointer; padding: 4px 8px;" onclick="quickSearch('Sherlock Holmes')">Sherlock Holmes</button>
                <button type="button" class="badge" style="background: rgba(106, 90, 205, 0.12); color: var(--primary); border: none; cursor: pointer; padding: 4px 8px;" onclick="quickSearch('Alice in Wonderland')">Alice in Wonderland</button>
                <button type="button" class="badge" style="background: rgba(106, 90, 205, 0.12); color: var(--primary); border: none; cursor: pointer; padding: 4px 8px;" onclick="quickSearch('The Time Machine')">The Time Machine</button>
            </div>
        </div>
    </form>
</div>

<!-- Global Notification Toast Area -->
<div id="importAlertBox" style="display: none; margin-bottom: 20px;"></div>

<!-- Search Results Section -->
<div id="resultsContainer">
    <div id="initialState" class="card" style="text-align: center; padding: 48px 20px;">
        <div style="font-size: 42px; margin-bottom: 12px; color: var(--text-muted);"><i class="fa-solid fa-book-atlas"></i></div>
        <h3 style="margin: 0 0 8px 0; font-size: 18px;">Search Open Library</h3>
        <p class="text-muted" style="margin: 0; font-size: 14px;">Enter a book title, author, or ISBN above to discover books and import full-text PDFs automatically.</p>
    </div>

    <div id="loadingState" class="card" style="display: none; text-align: center; padding: 48px 20px;">
        <i class="fa-solid fa-spinner fa-spin" style="font-size: 36px; color: var(--primary); margin-bottom: 14px;"></i>
        <h4 style="margin: 0 0 6px 0;">Searching Open Library API...</h4>
        <p class="text-muted" style="margin: 0; font-size: 13px;">Retrieving book metadata, checking Internet Archive scans and local duplicates.</p>
    </div>

    <!-- Active Results Header & Grid -->
    <div id="resultsWrapper" style="display: none;">
        <div class="flex justify-between items-center" style="margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
            <div id="resultsCountText" style="font-weight: 600; font-size: 15px;"></div>
            <div class="flex gap-2 items-center">
                <span class="text-muted" style="font-size: 12px;"><i class="fa-solid fa-shield-halved"></i> Authenticated cURL Download &bull; 50MB Max</span>
            </div>
        </div>

        <div id="booksGrid" class="grid grid-3" style="gap: 20px;"></div>
    </div>
</div>

<style>
.ol-book-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 18px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
}
.ol-book-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
    border-color: rgba(106, 90, 205, 0.4);
}
.ol-cover-box {
    width: 80px;
    height: 115px;
    flex-shrink: 0;
    border-radius: 8px;
    overflow: hidden;
    background: linear-gradient(135deg, rgba(106, 90, 205, 0.1), rgba(46, 111, 115, 0.15));
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 8px rgba(0,0,0,0.12);
}
.ol-cover-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.ol-badge-available {
    background: rgba(34, 197, 94, 0.15);
    color: #16a34a;
    border: 1px solid rgba(34, 197, 94, 0.3);
}
[data-theme="dark"] .ol-badge-available {
    background: rgba(34, 197, 94, 0.2);
    color: #4ade80;
}
.ol-badge-borrowable {
    background: rgba(234, 179, 8, 0.15);
    color: #ca8a04;
    border: 1px solid rgba(234, 179, 8, 0.3);
}
[data-theme="dark"] .ol-badge-borrowable {
    background: rgba(234, 179, 8, 0.2);
    color: #facc15;
}
.ol-badge-unavailable {
    background: rgba(148, 163, 184, 0.15);
    color: #64748b;
    border: 1px solid rgba(148, 163, 184, 0.3);
}
.ol-badge-imported {
    background: rgba(46, 111, 115, 0.18);
    color: #2e6f73;
    border: 1px solid rgba(46, 111, 115, 0.35);
}
[data-theme="dark"] .ol-badge-imported {
    color: #7fb3b8;
}
.import-loading-pulse {
    animation: importPulse 1.4s infinite ease-in-out;
}
@keyframes importPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.55; }
}
</style>

<script>
let currentSearchResults = [];

function quickSearch(title) {
    document.getElementById('searchInput').value = title;
    document.getElementById('searchType').value = 'all';
    document.getElementById('olSearchForm').dispatchEvent(new Event('submit'));
}

function resetSearch() {
    document.getElementById('searchInput').value = '';
    document.getElementById('booksGrid').innerHTML = '';
    document.getElementById('resultsWrapper').style.display = 'none';
    document.getElementById('loadingState').style.display = 'none';
    document.getElementById('initialState').style.display = 'block';
    document.getElementById('importAlertBox').style.display = 'none';
}

async function handleSearch(e) {
    if (e) e.preventDefault();
    const query = document.getElementById('searchInput').value.trim();
    if (!query) return;

    const type = document.getElementById('searchType').value;
    const openAccessOnly = document.getElementById('openAccessOnly').checked ? 1 : 0;

    // Show loading UI
    document.getElementById('initialState').style.display = 'none';
    document.getElementById('resultsWrapper').style.display = 'none';
    document.getElementById('loadingState').style.display = 'block';
    document.getElementById('btnSearch').disabled = true;
    document.getElementById('btnSearch').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Searching...';

    try {
        const url = `<?= basePath() ?>/admin/ajax-openlibrary-search.php?q=${encodeURIComponent(query)}&type=${encodeURIComponent(type)}&open_access_only=${openAccessOnly}`;
        const res = await fetch(url);
        const data = await res.json();

        document.getElementById('loadingState').style.display = 'none';
        document.getElementById('btnSearch').disabled = false;
        document.getElementById('btnSearch').innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Search Open Library';

        if (!data.success) {
            showAlert('error', data.message || 'Error occurred while searching Open Library.');
            return;
        }

        currentSearchResults = data.docs || [];
        renderSearchResults(currentSearchResults, data.total || currentSearchResults.length);
    } catch (err) {
        document.getElementById('loadingState').style.display = 'none';
        document.getElementById('btnSearch').disabled = false;
        document.getElementById('btnSearch').innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Search Open Library';
        showAlert('error', 'Network error while contacting search endpoint. Please try again.');
    }
}

function renderSearchResults(docs, totalCount) {
    const grid = document.getElementById('booksGrid');
    grid.innerHTML = '';

    if (!docs || docs.length === 0) {
        document.getElementById('resultsCountText').textContent = 'No matching books found.';
        grid.innerHTML = `
            <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 40px;">
                <div style="font-size: 32px; color: var(--text-muted); margin-bottom: 8px;"><i class="fa-solid fa-circle-question"></i></div>
                <h4 style="margin: 0 0 6px 0;">No books found</h4>
                <p class="text-muted" style="margin: 0; font-size: 13px;">Try relaxing your search terms or uncheck "Full PDF Available Only".</p>
            </div>
        `;
        document.getElementById('resultsWrapper').style.display = 'block';
        return;
    }

    document.getElementById('resultsCountText').innerHTML = `Found <strong>${totalCount}</strong> results from Open Library &bull; Displaying top <strong>${docs.length}</strong>`;

    docs.forEach((b, idx) => {
        const card = document.createElement('div');
        card.className = 'ol-book-card';
        card.id = `bookCard-${idx}`;

        // Badge styling
        let badgeHtml = '';
        if (b.is_already_in_db) {
            badgeHtml = `<span class="badge ol-badge-imported"><i class="fa-solid fa-check-double"></i> Already in Library</span>`;
        } else if (b.pdf_status === 'available') {
            badgeHtml = `<span class="badge ol-badge-available"><i class="fa-solid fa-circle-check"></i> ${b.pdf_status_label}</span>`;
        } else if (b.pdf_status === 'borrowable') {
            badgeHtml = `<span class="badge ol-badge-borrowable" title="Protected book: Controlled digital lending required"><i class="fa-solid fa-lock"></i> ${b.pdf_status_label}</span>`;
        } else {
            badgeHtml = `<span class="badge ol-badge-unavailable"><i class="fa-solid fa-circle-xmark"></i> ${b.pdf_status_label}</span>`;
        }

        // Action button logic
        let actionBtnHtml = '';
        if (b.is_already_in_db) {
            actionBtnHtml = `
                <div class="flex gap-2 items-center" style="width: 100%;">
                    <span class="badge ol-badge-imported" style="padding: 7px 12px; font-size: 12px; flex: 1; text-align: center;">
                        <i class="fa-solid fa-check"></i> In Catalog
                    </span>
                    <a href="../student/read-book.php?id=${b.local_book_id}" target="_blank" class="btn btn-sm btn-outline" title="Open in Reader">
                        <i class="fa-solid fa-book-open"></i> Read
                    </a>
                </div>
            `;
        } else if (b.is_eligible && b.ia_id) {
            actionBtnHtml = `
                <button type="button" class="btn btn-primary btn-sm btn-import-pdf" id="importBtn-${idx}" onclick="importBook(${idx})" style="width: 100%;">
                    <i class="fa-solid fa-cloud-arrow-down"></i> Import PDF
                </button>
            `;
        } else if (b.pdf_status === 'borrowable') {
            actionBtnHtml = `
                <button type="button" class="btn btn-sm btn-outline" disabled title="This book is protected by lending copyright and cannot be downloaded as an open PDF." style="width: 100%; opacity: 0.65; cursor: not-allowed;">
                    <i class="fa-solid fa-lock"></i> Protected (Cannot Import)
                </button>
            `;
        } else {
            actionBtnHtml = `
                <button type="button" class="btn btn-sm btn-outline" disabled title="No PDF document is hosted on Internet Archive for this item." style="width: 100%; opacity: 0.5; cursor: not-allowed;">
                    <i class="fa-solid fa-ban"></i> No PDF Available
                </button>
            `;
        }

        // Book Cover
        const coverHtml = b.cover_url 
            ? `<img src="${escapeHtml(b.cover_url)}" alt="Cover" class="ol-cover-img" loading="lazy" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'fa-solid fa-book text-muted\' style=\'font-size:28px;\'></i>';">`
            : `<i class="fa-solid fa-book text-muted" style="font-size:28px;"></i>`;

        card.innerHTML = `
            <div>
                <div class="flex gap-3" style="margin-bottom: 12px; align-items: flex-start;">
                    <div class="ol-cover-box">
                        ${coverHtml}
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="margin-bottom: 4px;">
                            ${badgeHtml}
                        </div>
                        <h4 style="margin: 0 0 4px 0; font-size: 15px; line-height: 1.35; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;" title="${escapeHtml(b.title)}">
                            ${escapeHtml(b.title)}
                        </h4>
                        <div class="text-muted" style="font-size: 12px; margin-bottom: 2px;">
                            <i class="fa-solid fa-feather-pointed"></i> ${escapeHtml(b.author)}
                        </div>
                        <div class="text-muted" style="font-size: 11px;">
                            ${b.year ? `<span title="First Published Year"><i class="fa-regular fa-calendar"></i> ${b.year}</span> &middot; ` : ''}
                            ${b.isbn ? `<span title="ISBN"><i class="fa-solid fa-barcode"></i> ${escapeHtml(b.isbn)}</span>` : '<span class="text-muted">No ISBN</span>'}
                        </div>
                    </div>
                </div>

                ${b.ia_id ? `
                    <div style="font-size: 11px; margin-bottom: 12px; padding: 4px 8px; border-radius: 6px; background: rgba(106, 90, 205, 0.05); color: var(--text-muted); font-family: monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="Internet Archive Identifier: ${escapeHtml(b.ia_id)}">
                        <i class="fa-solid fa-box-archive"></i> IA: ${escapeHtml(b.ia_id)}
                    </div>
                ` : ''}
            </div>

            <div style="margin-top: 10px; border-top: 1px solid var(--border); padding-top: 12px;" id="actionContainer-${idx}">
                ${actionBtnHtml}
            </div>
        `;

        grid.appendChild(card);
    });

    document.getElementById('resultsWrapper').style.display = 'block';
}

async function importBook(idx) {
    const b = currentSearchResults[idx];
    if (!b || !b.ia_id) return;

    const btn = document.getElementById(`importBtn-${idx}`);
    const card = document.getElementById(`bookCard-${idx}`);
    const actionContainer = document.getElementById(`actionContainer-${idx}`);

    if (btn) {
        btn.disabled = true;
        btn.classList.add('import-loading-pulse');
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Downloading PDF...';
    }

    showAlert('info', `Downloading and verifying PDF for "<strong>${escapeHtml(b.title)}</strong>" from Internet Archive... Please wait.`);

    try {
        const payload = {
            title: b.title,
            author: b.author,
            year: b.year,
            isbn: b.isbn,
            ia_id: b.ia_id,
            cover_url: b.cover_url,
            openlibrary_key: b.openlibrary_key
        };

        const res = await fetch('<?= basePath() ?>/admin/ajax-openlibrary-import.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const data = await res.json();

        if (data.success) {
            // Update item state in search results
            b.is_already_in_db = true;
            b.local_book_id = data.book_id;

            if (card) {
                card.style.borderColor = '#16a34a';
            }

            if (actionContainer) {
                actionContainer.innerHTML = `
                    <div class="flex gap-2 items-center" style="width: 100%;">
                        <span class="badge ol-badge-available" style="padding: 7px 12px; font-size: 12px; flex: 1; text-align: center;">
                            <i class="fa-solid fa-circle-check"></i> Imported!
                        </span>
                        <a href="../student/read-book.php?id=${data.book_id}" target="_blank" class="btn btn-sm btn-primary" title="Open in Interactive Reader">
                            <i class="fa-solid fa-book-open"></i> Read
                        </a>
                    </div>
                `;
            }

            showAlert('success', `
                <div class="flex justify-between items-center" style="flex-wrap: wrap; gap: 10px;">
                    <div>
                        <i class="fa-solid fa-circle-check" style="color: #16a34a; font-size: 18px; vertical-align: middle; margin-right: 6px;"></i>
                        <strong>Import Complete!</strong> "<strong>${escapeHtml(data.title)}</strong>" was successfully imported (${data.pages} pages, ${Math.round(data.file_size_kb / 1024 * 10) / 10} MB).
                    </div>
                    <div class="flex gap-2">
                        <a href="../student/read-book.php?id=${data.book_id}" target="_blank" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-book-open"></i> Read Book Now
                        </a>
                        <a href="digital-books.php" class="btn btn-sm btn-outline">
                            <i class="fa-solid fa-table-list"></i> View in Catalog
                        </a>
                    </div>
                </div>
            `);
        } else if (data.duplicate) {
            b.is_already_in_db = true;
            b.local_book_id = data.book_id;

            if (actionContainer) {
                actionContainer.innerHTML = `
                    <div class="flex gap-2 items-center" style="width: 100%;">
                        <span class="badge ol-badge-imported" style="padding: 7px 12px; font-size: 12px; flex: 1; text-align: center;">
                            <i class="fa-solid fa-check"></i> In Catalog
                        </span>
                        ${data.book_id ? `
                            <a href="../student/read-book.php?id=${data.book_id}" target="_blank" class="btn btn-sm btn-outline">
                                <i class="fa-solid fa-book-open"></i> Read
                            </a>
                        ` : ''}
                    </div>
                `;
            }
            showAlert('warning', `<strong>Notice:</strong> ${escapeHtml(data.message)}`);
        } else {
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('import-loading-pulse');
                btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down"></i> Retry Import';
            }
            showAlert('error', `<strong>Import Failed:</strong> ${escapeHtml(data.message || 'An unexpected error occurred during download.')}`);
        }
    } catch (err) {
        if (btn) {
            btn.disabled = false;
            btn.classList.remove('import-loading-pulse');
            btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down"></i> Retry Import';
        }
        showAlert('error', `Network error during import: ${err.message}`);
    }
}

function showAlert(type, htmlContent) {
    const box = document.getElementById('importAlertBox');
    box.style.display = 'block';

    let bg = 'rgba(106, 90, 205, 0.1)';
    let border = 'var(--primary)';
    let color = 'var(--text)';

    if (type === 'success') {
        bg = 'rgba(34, 197, 94, 0.12)';
        border = '#16a34a';
    } else if (type === 'error') {
        bg = 'rgba(239, 68, 68, 0.12)';
        border = '#dc2626';
    } else if (type === 'warning') {
        bg = 'rgba(234, 179, 8, 0.12)';
        border = '#ca8a04';
    }

    box.innerHTML = `
        <div style="background: ${bg}; border-left: 4px solid ${border}; padding: 14px 18px; border-radius: 10px; color: ${color}; position: relative;">
            <button type="button" onclick="this.parentElement.parentElement.style.display='none'" style="position: absolute; right: 12px; top: 12px; background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 16px;">&times;</button>
            <div style="padding-right: 24px;">${htmlContent}</div>
        </div>
    `;
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
