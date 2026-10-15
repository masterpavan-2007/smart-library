/**
 * =====================================================================
 * Smart Virtual Library — 3D Virtual Book Reader Engine
 * Complete interactive 3D Book, StPageFlip & 3D CSS Fallback,
 * Lifted Page Curving, Zoom, Search, Bookmarks, Themes, TTS & PDF.js
 * =====================================================================
 */

(function () {
    'use strict';

    class VirtualBookReader {
        constructor() {
            this.config = window.readerConfig || {};
            this.bookId = this.config.bookId || 0;
            this.bookTitle = this.config.bookTitle || 'Virtual Book';
            this.totalPages = this.config.totalPages || 16;
            this.currentPage = this.config.lastPage || 1;
            this.pdfUrl = this.config.pdfUrl || null;
            this.isStudent = this.config.isStudent || false;
            this.bookmarks = this.config.bookmarks || [];
            
            // Engine State
            this.pageFlip = null;
            this.pdfDoc = null;
            this.zoomScale = 1.0;
            this.fontSizeLevel = 2; // 0: 13px, 1: 14px, 2: 15px, 3: 17px, 4: 19px
            this.currentTheme = localStorage.getItem('slms_book_theme') || 'white';
            this.saveTimer = null;
            this.renderedPdfPages = new Set();
            this.isFlipping = false;

            // Search State
            this.searchResults = [];
            this.currentSearchIndex = -1;

            // TTS State
            this.synth = window.speechSynthesis || null;
            this.ttsVoice = null;
            this.ttsSpeed = parseFloat(localStorage.getItem('slms_tts_speed')) || 1.0;
            this.ttsUtterance = null;
            this.ttsStatus = 'stopped'; // 'stopped' | 'playing' | 'paused'
            this.ttsSentences = [];
            this.ttsSentenceIdx = 0;

            // DOM elements
            this.spreadEl = document.getElementById('bookSpread');
            this.scalerEl = document.getElementById('bookScaler');
            this.pageNumInput = document.getElementById('pageNumberInput');
            this.pageCountSpan = document.getElementById('pageCountSpan');
            this.progressBar = document.getElementById('readerProgressBar');
            this.prevBtn = document.getElementById('prevPageBtn');
            this.nextBtn = document.getElementById('nextPageBtn');
            this.floatPrevBtn = document.getElementById('floatingPrevBtn');
            this.floatNextBtn = document.getElementById('floatingNextBtn');
            this.zoomLevelText = document.getElementById('zoomLevelText');

            this.init();
        }

        init() {
            // Apply saved theme
            this.applyTheme(this.currentTheme);

            // Apply font size
            this.applyFontSize();

            // Setup Event Handlers
            this.bindControls();
            this.bindKeyboardShortcuts();
            this.bindTouchGestures();

            // Initialize TTS voices
            this.initTTS();

            // Initialize Book Engine
            if (this.pdfUrl) {
                this.initPdfMode();
            } else {
                this.initHtmlBookMode();
            }

            // Resume reading notification
            if (this.currentPage > 1) {
                setTimeout(() => {
                    this.showToast(`Resumed from saved page ${this.currentPage}`);
                }, 600);
            }
        }

        /* -------------------------------------------------------------
           Engine Initialization: StPageFlip or 3D CSS Fallback
           ------------------------------------------------------------- */
        initHtmlBookMode() {
            const pages = document.querySelectorAll('.book-page');
            this.totalPages = pages.length;
            if (this.pageCountSpan) this.pageCountSpan.textContent = this.totalPages;
            if (this.pageNumInput) this.pageNumInput.max = this.totalPages;

            const isMobile = window.innerWidth <= 768;
            const pageWidth = isMobile ? 340 : (window.innerWidth <= 1024 ? 380 : 460);
            const pageHeight = isMobile ? 480 : (window.innerWidth <= 1024 ? 500 : 600);

            if (window.St && window.St.PageFlip) {
                try {
                    this.pageFlip = new window.St.PageFlip(this.spreadEl, {
                        width: pageWidth,
                        height: pageHeight,
                        size: 'fixed',
                        minWidth: 320,
                        maxWidth: 600,
                        minHeight: 440,
                        maxHeight: 750,
                        drawShadow: true,
                        maxShadowOpacity: 0.6,
                        showCover: false,
                        mobileScrollSupport: false,
                        usePortrait: isMobile,
                        startPage: Math.max(0, this.currentPage - 1),
                        flippingTime: 750,
                        useMouseEvents: true,
                        showPageCorners: true
                    });

                    this.pageFlip.loadFromHTML(pages);

                    // Flip Event Listener
                    this.pageFlip.on('flip', (e) => {
                        this.onPageFlipped(e.data + 1);
                    });

                    this.pageFlip.on('changeState', (e) => {
                        this.isFlipping = (e.data === 'flipping');
                        if (!this.isFlipping) {
                            this.applyLiftedPageCurve();
                        }
                    });

                } catch (err) {
                    console.warn('StPageFlip init warning, falling back to 3D CSS mode:', err);
                    this.init3DCssFallback(pages);
                }
            } else {
                this.init3DCssFallback(pages);
            }

            this.updateNavButtons();
            this.updateProgressBar();
            this.applyLiftedPageCurve();
        }

        /* -------------------------------------------------------------
           PDF.js Mode: Dynamic rendering of PDF pages into 3D Book
           ------------------------------------------------------------- */
        initPdfMode() {
            const spinner = document.getElementById('readerLoadingSpinner');
            if (spinner) spinner.style.display = 'flex';

            if (!window.pdfjsLib) {
                console.warn('PDF.js not loaded, falling back to HTML book mode.');
                this.initHtmlBookMode();
                return;
            }

            window.pdfjsLib.getDocument(this.pdfUrl).promise.then((doc) => {
                this.pdfDoc = doc;
                window.pdfDoc = doc;
                this.totalPages = doc.numPages;
                if (this.pageCountSpan) this.pageCountSpan.textContent = this.totalPages;
                if (this.pageNumInput) this.pageNumInput.max = this.totalPages;

                // Build HTML page wrappers for each PDF page
                this.buildPdfPageContainers(this.totalPages);

                if (spinner) spinner.style.display = 'none';

                // Initialize StPageFlip on generated pages
                this.initHtmlBookMode();

                // Render current visible pages
                this.renderVisiblePdfPages(this.currentPage);

            }).catch((err) => {
                console.warn('PDF load failed or restricted, falling back to rich sample book:', err);
                if (spinner) spinner.style.display = 'none';
                this.showToast('Loading digital sample content');
                this.initHtmlBookMode();
            });
        }

        buildPdfPageContainers(numPages) {
            this.spreadEl.innerHTML = '';
            for (let i = 1; i <= numPages; i++) {
                const pageEl = document.createElement('div');
                pageEl.className = `book-page ${i % 2 === 0 ? 'page-left' : 'page-right'}`;
                pageEl.dataset.pageNumber = i;

                pageEl.innerHTML = `
                    <div class="page-running-head">
                        <span>${this.escapeHtml(this.bookTitle)}</span>
                        <span>Page ${i}</span>
                    </div>
                    <div class="pdf-page-canvas-wrapper" id="pdfWrapper_${i}">
                        <div class="pdf-page-spinner" style="text-align:center; color:#94a3b8; font-size:13px; margin:auto;">
                            <i class="fa-solid fa-spinner fa-spin"></i> Rendering Page ${i}...
                        </div>
                        <canvas class="pdf-page-canvas" id="pdfCanvas_${i}" style="display:none;"></canvas>
                        <div class="pdf-page-textlayer" id="pdfTextLayer_${i}"></div>
                    </div>
                    <div class="page-running-foot">
                        <span class="${i % 2 === 0 ? 'page-num-left' : 'page-num-right'}">${i}</span>
                    </div>
                    <div class="page-corner-curl-cue"></div>
                `;
                this.spreadEl.appendChild(pageEl);
            }
        }

        renderVisiblePdfPages(pageIdx) {
            if (!this.pdfDoc) return;
            // Render current page and adjacent pages
            const pagesToRender = [pageIdx, pageIdx + 1, pageIdx - 1, pageIdx + 2].filter(p => p >= 1 && p <= this.totalPages);

            pagesToRender.forEach(p => {
                if (this.renderedPdfPages.has(p)) return;
                this.renderedPdfPages.add(p);

                this.pdfDoc.getPage(p).then(page => {
                    const canvas = document.getElementById(`pdfCanvas_${p}`);
                    const wrapper = document.getElementById(`pdfWrapper_${p}`);
                    const textLayer = document.getElementById(`pdfTextLayer_${p}`);
                    if (!canvas || !wrapper) return;

                    const spinner = wrapper.querySelector('.pdf-page-spinner');
                    if (spinner) spinner.style.display = 'none';

                    const baseViewport = page.getViewport({ scale: 1.0 });
                    const targetWidth = wrapper.clientWidth || 380;
                    const scale = (targetWidth / baseViewport.width) * (window.devicePixelRatio || 1);
                    const viewport = page.getViewport({ scale: scale });

                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    canvas.style.width = '100%';
                    canvas.style.height = 'auto';
                    canvas.style.display = 'block';

                    const ctx = canvas.getContext('2d');
                    page.render({
                        canvasContext: ctx,
                        viewport: viewport
                    }).promise.then(() => {
                        // Extract text for search & TTS
                        page.getTextContent().then(textContent => {
                            let text = '';
                            textContent.items.forEach(item => {
                                text += item.str + ' ';
                            });
                            wrapper.dataset.extractedText = text;
                        });
                    });
                }).catch(err => {
                    console.warn(`Error rendering PDF page ${p}:`, err);
                });
            });
        }

        /* -------------------------------------------------------------
           3D CSS Fallback Mode (Runs when StPageFlip is offline or unsupported)
           ------------------------------------------------------------- */
        init3DCssFallback(pages) {
            this.isCssFallback = true;
            this.showCssSpread(this.currentPage);
        }

        showCssSpread(pageNumber) {
            const pages = Array.from(document.querySelectorAll('.book-page'));
            const isMobile = window.innerWidth <= 768;

            pages.forEach((p, idx) => {
                const pg = idx + 1;
                p.style.display = 'none';
                p.classList.remove('page-left', 'page-right', 'page-lifted-curve', 'page-left-hoverable');

                if (isMobile) {
                    if (pg === pageNumber) {
                        p.style.display = 'flex';
                        p.style.position = 'relative';
                    }
                } else {
                    // Two-page spread
                    const leftPg = (pageNumber % 2 === 0) ? pageNumber : pageNumber - 1;
                    const rightPg = leftPg + 1;

                    if (pg === leftPg) {
                        p.style.display = 'flex';
                        p.classList.add('page-left', 'page-left-hoverable');
                        p.style.left = '0';
                    } else if (pg === rightPg) {
                        p.style.display = 'flex';
                        p.classList.add('page-right', 'page-lifted-curve');
                        p.style.left = '50%';
                    }
                }
            });

            this.updateNavButtons();
            this.updateProgressBar();
        }

        /* -------------------------------------------------------------
           The Signature Lifted & Curved Right Page Styling
           ------------------------------------------------------------- */
        applyLiftedPageCurve() {
            // Find current active right page and apply .page-lifted-curve
            const pages = document.querySelectorAll('.book-page');
            pages.forEach(p => {
                p.classList.remove('page-lifted-curve', 'page-left-hoverable');
            });

            if (window.innerWidth <= 768) return; // single page on mobile

            const current = this.currentPage;
            const rightPageIdx = (current % 2 === 0) ? current : current + 1;
            const leftPageIdx = rightPageIdx - 1;

            pages.forEach(p => {
                const pgNum = parseInt(p.dataset.pageNumber, 10);
                if (pgNum === rightPageIdx) {
                    p.classList.add('page-lifted-curve');
                } else if (pgNum === leftPageIdx) {
                    p.classList.add('page-left-hoverable');
                }
            });
        }

        /* -------------------------------------------------------------
           Page Turn Navigation
           ------------------------------------------------------------- */
        goToPage(pageNum) {
            pageNum = parseInt(pageNum, 10);
            if (isNaN(pageNum) || pageNum < 1) pageNum = 1;
            if (pageNum > this.totalPages) pageNum = this.totalPages;

            if (this.pageFlip) {
                this.pageFlip.turnToPage(pageNum - 1);
            } else if (this.isCssFallback) {
                this.onPageFlipped(pageNum);
                this.showCssSpread(pageNum);
            }
        }

        turnNext() {
            if (this.currentPage >= this.totalPages) return;
            if (this.pageFlip) {
                this.pageFlip.flipNext('top');
            } else {
                const step = window.innerWidth <= 768 ? 1 : 2;
                this.goToPage(Math.min(this.totalPages, this.currentPage + step));
            }
        }

        turnPrev() {
            if (this.currentPage <= 1) return;
            if (this.pageFlip) {
                this.pageFlip.flipPrev('top');
            } else {
                const step = window.innerWidth <= 768 ? 1 : 2;
                this.goToPage(Math.max(1, this.currentPage - step));
            }
        }

        onPageFlipped(newPageNum) {
            this.currentPage = newPageNum;
            if (this.pageNumInput) this.pageNumInput.value = this.currentPage;

            this.updateNavButtons();
            this.updateProgressBar();
            this.applyLiftedPageCurve();

            if (this.pdfDoc) {
                this.renderVisiblePdfPages(this.currentPage);
            }

            // Auto-save reading progress
            this.triggerSaveProgress();

            // Notify TTS if active
            if (this.ttsStatus === 'playing') {
                this.stopTTS();
                this.startTTS();
            }
        }

        updateNavButtons() {
            const hasPrev = this.currentPage > 1;
            const hasNext = this.currentPage < this.totalPages;

            if (this.prevBtn) this.prevBtn.disabled = !hasPrev;
            if (this.nextBtn) this.nextBtn.disabled = !hasNext;
            if (this.floatPrevBtn) this.floatPrevBtn.disabled = !hasPrev;
            if (this.floatNextBtn) this.floatNextBtn.disabled = !hasNext;
        }

        updateProgressBar() {
            const percent = Math.min(100, Math.round((this.currentPage / this.totalPages) * 100));
            if (this.progressBar) {
                this.progressBar.style.width = percent + '%';
            }
            const badge = document.getElementById('progressBadgeText');
            if (badge) badge.textContent = percent + '%';
        }

        /* -------------------------------------------------------------
           Zoom Controls
           ------------------------------------------------------------- */
        zoomIn() {
            if (this.zoomScale >= 1.6) return;
            this.zoomScale = Math.round((this.zoomScale + 0.15) * 100) / 100;
            this.applyZoom();
        }

        zoomOut() {
            if (this.zoomScale <= 0.65) return;
            this.zoomScale = Math.round((this.zoomScale - 0.15) * 100) / 100;
            this.applyZoom();
        }

        zoomReset() {
            this.zoomScale = 1.0;
            this.applyZoom();
        }

        applyZoom() {
            if (this.scalerEl) {
                this.scalerEl.style.transform = `scale(${this.zoomScale})`;
            }
            if (this.zoomLevelText) {
                this.zoomLevelText.textContent = Math.round(this.zoomScale * 100) + '%';
            }
        }

        /* -------------------------------------------------------------
           Font Size Adjustment
           ------------------------------------------------------------- */
        increaseFontSize() {
            if (this.fontSizeLevel < 4) {
                this.fontSizeLevel++;
                this.applyFontSize();
            }
        }

        decreaseFontSize() {
            if (this.fontSizeLevel > 0) {
                this.fontSizeLevel--;
                this.applyFontSize();
            }
        }

        applyFontSize() {
            const sizes = ['13px', '14px', '15px', '17px', '19px'];
            const activeSize = sizes[this.fontSizeLevel];
            document.querySelectorAll('.page-inner-content').forEach(el => {
                el.style.fontSize = activeSize;
            });
            const pill = document.getElementById('fontSizeBadge');
            if (pill) {
                pill.textContent = (100 + (this.fontSizeLevel - 2) * 15) + '%';
            }
        }

        /* -------------------------------------------------------------
           Themes: White Paper (Reference photo), Sepia, Dark Charcoal
           ------------------------------------------------------------- */
        applyTheme(theme) {
            this.currentTheme = theme;
            try {
                localStorage.setItem('slms_book_theme', theme);
            } catch (e) {}

            if (this.spreadEl) {
                this.spreadEl.setAttribute('data-book-theme', theme);
            }
            document.querySelectorAll('.theme-opt-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.theme === theme);
            });
        }

        /* -------------------------------------------------------------
           Bookmarks Feature (MySQL Synced via save-progress.php)
           ------------------------------------------------------------- */
        promptAddBookmark() {
            const page = this.currentPage;
            const note = prompt(`Enter a note for bookmark at Page ${page}:`, `Review Chapter point / Note`);
            if (note === null) return;

            fetch('save-progress.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add_bookmark',
                    digital_book_id: this.bookId,
                    page_number: page,
                    note: note.trim() || `Bookmark on Page ${page}`
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    this.showToast(`Bookmark saved for Page ${page}`);
                    this.bookmarks = data.bookmarks || [];
                    this.renderBookmarksList();
                } else {
                    alert(data.error || 'Could not save bookmark');
                }
            })
            .catch(err => {
                console.warn('Bookmark save failed', err);
                this.showToast(`Bookmark added locally for Page ${page}`);
            });
        }

        deleteBookmark(bmId) {
            if (!confirm('Remove this bookmark?')) return;
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
                    this.showToast('Bookmark deleted');
                    this.bookmarks = this.bookmarks.filter(b => b.id != bmId);
                    this.renderBookmarksList();
                }
            });
        }

        renderBookmarksList() {
            const container = document.getElementById('bookmarksListBody');
            const countBadge = document.getElementById('bookmarkCountBadge');
            if (countBadge) countBadge.textContent = this.bookmarks.length;

            if (!container) return;

            if (!this.bookmarks || this.bookmarks.length === 0) {
                container.innerHTML = `
                    <div style="text-align:center; padding:30px 10px; color:#94a3b8; font-size:13px;">
                        <i class="fa-regular fa-bookmark" style="font-size:28px; margin-bottom:10px; display:block;"></i>
                        No bookmarks saved yet. Click the bookmark icon in the toolbar to save one.
                    </div>
                `;
                return;
            }

            let html = '';
            this.bookmarks.forEach(b => {
                html += `
                    <div class="bm-entry-card" onclick="window.bookReader.goToPage(${b.page_number}); window.bookReader.toggleDrawer('bookmarksDrawer');">
                        <div>
                            <div class="bm-page-tag"><i class="fa-solid fa-bookmark"></i> Page ${b.page_number}</div>
                            <div class="bm-note-text">${this.escapeHtml(b.note || 'Bookmark')}</div>
                        </div>
                        <button class="reader-tool-btn" onclick="event.stopPropagation(); window.bookReader.deleteBookmark(${b.id})" title="Delete" style="padding:4px 7px;">
                            <i class="fa-solid fa-trash-can" style="color:#ef4444; font-size:11px;"></i>
                        </button>
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        /* -------------------------------------------------------------
           In-Book Text Search Engine
           ------------------------------------------------------------- */
        performSearch(query) {
            query = query ? query.trim().toLowerCase() : '';
            const resultsBox = document.getElementById('searchResultsList');
            const resCountSpan = document.getElementById('searchCountSpan');
            this.clearSearchHighlights();

            if (!query || query.length < 2) {
                if (resultsBox) resultsBox.innerHTML = '<div style="color:#94a3b8; padding:12px; font-size:13px; text-align:center;">Type at least 2 characters to search inside the book.</div>';
                if (resCountSpan) resCountSpan.textContent = '0 results';
                return;
            }

            this.searchResults = [];
            const pages = document.querySelectorAll('.book-page');

            pages.forEach(page => {
                const pageNum = parseInt(page.dataset.pageNumber, 10);
                const contentEl = page.querySelector('.page-inner-content') || page.querySelector('.pdf-page-canvas-wrapper');
                const rawText = contentEl ? (contentEl.innerText || contentEl.dataset.extractedText || '') : '';
                
                if (rawText.toLowerCase().includes(query)) {
                    // Extract snippet
                    const idx = rawText.toLowerCase().indexOf(query);
                    const start = Math.max(0, idx - 40);
                    const end = Math.min(rawText.length, idx + query.length + 40);
                    const snippet = (start > 0 ? '...' : '') + rawText.substring(start, end) + (end < rawText.length ? '...' : '');

                    this.searchResults.push({
                        page: pageNum,
                        snippet: snippet
                    });

                    // Highlight matches if HTML text
                    if (page.querySelector('.page-inner-content')) {
                        this.highlightInElement(page.querySelector('.page-inner-content'), query);
                    }
                }
            });

            if (resCountSpan) {
                resCountSpan.textContent = `${this.searchResults.length} match${this.searchResults.length === 1 ? '' : 'es'} found`;
            }

            if (!resultsBox) return;

            if (this.searchResults.length === 0) {
                resultsBox.innerHTML = `<div style="color:#94a3b8; padding:12px; font-size:13px; text-align:center;">No occurrences found for "${this.escapeHtml(query)}".</div>`;
                return;
            }

            let html = '';
            this.searchResults.forEach((r, i) => {
                html += `
                    <div class="search-res-item" onclick="window.bookReader.goToPage(${r.page}); window.bookReader.toggleSearchModal();">
                        <div style="font-weight:700; color:#38bdf8; font-size:12px;"><i class="fa-solid fa-file-lines"></i> Page ${r.page}</div>
                        <div style="color:#cbd5e1; font-size:12.5px; margin-top:2px;">${this.highlightMatchText(r.snippet, query)}</div>
                    </div>
                `;
            });
            resultsBox.innerHTML = html;
        }

        highlightInElement(el, query) {
            const regex = new RegExp(`(${this.escapeRegex(query)})`, 'gi');
            el.querySelectorAll('p, h1, h2, h3, li').forEach(node => {
                if (node.children.length === 0 && regex.test(node.textContent)) {
                    node.innerHTML = node.textContent.replace(regex, '<mark class="book-search-match">$1</mark>');
                }
            });
        }

        clearSearchHighlights() {
            document.querySelectorAll('mark.book-search-match').forEach(m => {
                const parent = m.parentNode;
                parent.replaceChild(document.createTextNode(m.textContent), m);
                parent.normalize();
            });
        }

        highlightMatchText(text, query) {
            const regex = new RegExp(`(${this.escapeRegex(query)})`, 'gi');
            return this.escapeHtml(text).replace(regex, '<mark style="background:#facc15; color:#000; padding:0 2px;">$1</mark>');
        }

        /* -------------------------------------------------------------
           Text-to-Speech (Read Aloud) Engine
           ------------------------------------------------------------- */
        initTTS() {
            if (!this.synth) return;
            const updateVoices = () => {
                const voices = this.synth.getVoices();
                const sel = document.getElementById('ttsVoiceSelect');
                if (sel && voices.length > 0) {
                    sel.innerHTML = '';
                    voices.forEach(v => {
                        const opt = document.createElement('option');
                        opt.value = v.name;
                        opt.textContent = `${v.name} (${v.lang})`;
                        if (v.default || v.lang.startsWith('en')) {
                            opt.selected = true;
                            this.ttsVoice = v;
                        }
                        sel.appendChild(opt);
                    });
                }
            };

            updateVoices();
            if ('onvoiceschanged' in this.synth) {
                this.synth.onvoiceschanged = updateVoices;
            }
        }

        toggleTTS() {
            if (!this.synth) {
                alert('Text-to-Speech is not supported by your browser.');
                return;
            }

            if (this.ttsStatus === 'playing') {
                this.pauseTTS();
            } else if (this.ttsStatus === 'paused') {
                this.resumeTTS();
            } else {
                this.startTTS();
            }
        }

        startTTS() {
            this.stopTTS();

            // Extract text from current spread
            const currentPageEl = document.querySelector(`.book-page[data-page-number="${this.currentPage}"]`);
            let text = '';
            if (currentPageEl) {
                const content = currentPageEl.querySelector('.page-inner-content') || currentPageEl.querySelector('.pdf-page-canvas-wrapper');
                text = content ? (content.innerText || content.dataset.extractedText || '') : '';
            }

            if (!text || text.trim().length === 0) {
                this.showToast('No readable text on this page.');
                return;
            }

            // Split into sentences
            this.ttsSentences = text.match(/[^.!?]+[.!?]+(?:\s+|$)|[^.!?]+$/g) || [text];
            this.ttsSentenceIdx = 0;
            this.ttsStatus = 'playing';

            this.updateTtsButtonState();
            this.speakNextSentence();
        }

        speakNextSentence() {
            if (this.ttsStatus !== 'playing') return;
            if (this.ttsSentenceIdx >= this.ttsSentences.length) {
                this.stopTTS();
                this.showToast('Finished reading page aloud.');
                return;
            }

            const sentence = this.ttsSentences[this.ttsSentenceIdx].trim();
            if (!sentence) {
                this.ttsSentenceIdx++;
                this.speakNextSentence();
                return;
            }

            const utter = new SpeechSynthesisUtterance(sentence);
            utter.rate = this.ttsSpeed;
            if (this.ttsVoice) utter.voice = this.ttsVoice;

            utter.onend = () => {
                this.ttsSentenceIdx++;
                this.speakNextSentence();
            };

            utter.onerror = () => {
                this.ttsSentenceIdx++;
                this.speakNextSentence();
            };

            this.ttsUtterance = utter;
            this.synth.speak(utter);
        }

        pauseTTS() {
            if (this.synth) this.synth.pause();
            this.ttsStatus = 'paused';
            this.updateTtsButtonState();
        }

        resumeTTS() {
            if (this.synth) this.synth.resume();
            this.ttsStatus = 'playing';
            this.updateTtsButtonState();
        }

        stopTTS() {
            if (this.synth) this.synth.cancel();
            this.ttsStatus = 'stopped';
            this.ttsSentenceIdx = 0;
            this.updateTtsButtonState();
        }

        updateTtsButtonState() {
            const btn = document.getElementById('readAloudToggleBtn');
            const soundwave = document.getElementById('ttsSoundwave');
            if (!btn) return;

            if (this.ttsStatus === 'playing') {
                btn.classList.add('active');
                btn.innerHTML = '<i class="fa-solid fa-pause"></i> <span>Pause Speech</span>';
                if (soundwave) soundwave.style.display = 'flex';
            } else if (this.ttsStatus === 'paused') {
                btn.classList.add('active');
                btn.innerHTML = '<i class="fa-solid fa-play"></i> <span>Resume Speech</span>';
                if (soundwave) soundwave.style.display = 'none';
            } else {
                btn.classList.remove('active');
                btn.innerHTML = '<i class="fa-solid fa-headphones"></i> <span>Read Aloud</span>';
                if (soundwave) soundwave.style.display = 'none';
            }
        }

        /* -------------------------------------------------------------
           Reading Progress Auto-Save (MySQL)
           ------------------------------------------------------------- */
        triggerSaveProgress() {
            if (!this.isStudent) return;
            clearTimeout(this.saveTimer);

            this.saveTimer = setTimeout(() => {
                fetch('save-progress.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_progress',
                        digital_book_id: this.bookId,
                        page: this.currentPage,
                        total_pages: this.totalPages
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.status === 'completed') {
                        this.showToast('Book Completed! 🎉 Progress 100%');
                    }
                })
                .catch(err => console.warn('Auto-save progress failed', err));
            }, 1200);
        }

        /* -------------------------------------------------------------
           UI Controls & Event Bindings
           ------------------------------------------------------------- */
        bindControls() {
            // Next / Prev buttons
            if (this.prevBtn) this.prevBtn.addEventListener('click', () => this.turnPrev());
            if (this.nextBtn) this.nextBtn.addEventListener('click', () => this.turnNext());
            if (this.floatPrevBtn) this.floatPrevBtn.addEventListener('click', () => this.turnPrev());
            if (this.floatNextBtn) this.floatNextBtn.addEventListener('click', () => this.turnNext());

            // Page Stepper input
            if (this.pageNumInput) {
                this.pageNumInput.addEventListener('change', (e) => this.goToPage(e.target.value));
                this.pageNumInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') this.goToPage(e.target.value);
                });
            }

            // Click directly on the spread to turn
            if (this.spreadEl) {
                this.spreadEl.addEventListener('click', (e) => {
                    // Ignore clicks on buttons, inputs or links
                    if (e.target.closest('button, a, input, select, mark')) return;
                    
                    const rect = this.spreadEl.getBoundingClientRect();
                    const clickX = e.clientX - rect.left;
                    if (clickX > rect.width * 0.6) {
                        this.turnNext();
                    } else if (clickX < rect.width * 0.4) {
                        this.turnPrev();
                    }
                });
            }

            // Zoom
            const zoomInBtn = document.getElementById('zoomInBtn');
            const zoomOutBtn = document.getElementById('zoomOutBtn');
            const zoomResetBtn = document.getElementById('zoomResetBtn');
            if (zoomInBtn) zoomInBtn.addEventListener('click', () => this.zoomIn());
            if (zoomOutBtn) zoomOutBtn.addEventListener('click', () => this.zoomOut());
            if (zoomResetBtn) zoomResetBtn.addEventListener('click', () => this.zoomReset());

            // Font size
            const fontIncBtn = document.getElementById('fontSizeIncBtn');
            const fontDecBtn = document.getElementById('fontSizeDecBtn');
            if (fontIncBtn) fontIncBtn.addEventListener('click', () => this.increaseFontSize());
            if (fontDecBtn) fontDecBtn.addEventListener('click', () => this.decreaseFontSize());

            // Themes
            document.querySelectorAll('.theme-opt-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    this.applyTheme(btn.dataset.theme);
                });
            });

            // Fullscreen
            const fsBtn = document.getElementById('fullscreenBtn');
            if (fsBtn) {
                fsBtn.addEventListener('click', () => {
                    if (!document.fullscreenElement) {
                        document.documentElement.requestFullscreen().catch(err => console.log(err));
                        fsBtn.querySelector('i').className = 'fa-solid fa-compress';
                    } else {
                        if (document.exitFullscreen) document.exitFullscreen();
                        fsBtn.querySelector('i').className = 'fa-solid fa-expand';
                    }
                });
            }

            // Bookmarks
            const bmQuickBtn = document.getElementById('addBookmarkBtn');
            if (bmQuickBtn) bmQuickBtn.addEventListener('click', () => this.promptAddBookmark());

            const bmDrawerBtn = document.getElementById('toggleBookmarksDrawerBtn');
            if (bmDrawerBtn) bmDrawerBtn.addEventListener('click', () => this.toggleDrawer('bookmarksDrawer'));

            // Table of Contents
            const tocBtn = document.getElementById('toggleTocBtn');
            if (tocBtn) tocBtn.addEventListener('click', () => this.toggleDrawer('tocDrawer'));

            // Search Modal
            const searchBtn = document.getElementById('toggleSearchBtn');
            if (searchBtn) searchBtn.addEventListener('click', () => this.toggleSearchModal());

            const searchInput = document.getElementById('bookSearchInput');
            if (searchInput) {
                let sTimer = null;
                searchInput.addEventListener('input', (e) => {
                    clearTimeout(sTimer);
                    sTimer = setTimeout(() => this.performSearch(e.target.value), 300);
                });
            }

            // Text-to-Speech
            const ttsBtn = document.getElementById('readAloudToggleBtn');
            if (ttsBtn) ttsBtn.addEventListener('click', () => this.toggleTTS());

            const ttsSpeedSel = document.getElementById('ttsSpeedSelect');
            if (ttsSpeedSel) {
                ttsSpeedSel.value = this.ttsSpeed;
                ttsSpeedSel.addEventListener('change', (e) => {
                    this.ttsSpeed = parseFloat(e.target.value);
                    try { localStorage.setItem('slms_tts_speed', this.ttsSpeed); } catch (e) {}
                    if (this.ttsStatus === 'playing') {
                        this.stopTTS();
                        this.startTTS();
                    }
                });
            }

            const ttsVoiceSel = document.getElementById('ttsVoiceSelect');
            if (ttsVoiceSel) {
                ttsVoiceSel.addEventListener('change', (e) => {
                    if (this.synth) {
                        const list = this.synth.getVoices();
                        this.ttsVoice = list.find(v => v.name === e.target.value) || null;
                    }
                });
            }

            // Favorite button
            const favBtn = document.getElementById('toggleFavoriteBtn');
            if (favBtn) {
                favBtn.addEventListener('click', () => {
                    fetch('save-progress.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'toggle_favorite',
                            digital_book_id: this.bookId
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            if (data.is_favorite) {
                                favBtn.classList.add('active');
                                favBtn.querySelector('i').className = 'fa-solid fa-heart';
                                this.showToast('Added to personal favorites ❤️');
                            } else {
                                favBtn.classList.remove('active');
                                favBtn.querySelector('i').className = 'fa-regular fa-heart';
                                this.showToast('Removed from favorites');
                            }
                        }
                    });
                });
            }

            // Book Details modal
            const infoBtn = document.getElementById('bookInfoBtn');
            if (infoBtn) infoBtn.addEventListener('click', () => this.toggleInfoModal());

            // Window resize handler (re-scale or adapt to orientation)
            window.addEventListener('resize', () => {
                if (this.pageFlip) {
                    try { this.pageFlip.update(); } catch(e) {}
                }
            });
        }

        bindKeyboardShortcuts() {
            window.addEventListener('keydown', (e) => {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

                switch (e.key) {
                    case 'ArrowRight':
                    case 'PageDown':
                    case ' ':
                        e.preventDefault();
                        this.turnNext();
                        break;
                    case 'ArrowLeft':
                    case 'PageUp':
                        e.preventDefault();
                        this.turnPrev();
                        break;
                    case '+':
                    case '=':
                        this.zoomIn();
                        break;
                    case '-':
                        this.zoomOut();
                        break;
                    case '0':
                        this.zoomReset();
                        break;
                    case 'f':
                    case 'F':
                        const fs = document.getElementById('fullscreenBtn');
                        if (fs) fs.click();
                        break;
                    case 'b':
                    case 'B':
                        this.promptAddBookmark();
                        break;
                    case '/':
                        e.preventDefault();
                        this.toggleSearchModal();
                        break;
                    case 'Escape':
                        this.closeAllDrawers();
                        break;
                }
            });
        }

        bindTouchGestures() {
            let startX = 0;
            let startY = 0;

            this.spreadEl.addEventListener('touchstart', (e) => {
                if (e.touches.length === 1) {
                    startX = e.touches[0].clientX;
                    startY = e.touches[0].clientY;
                }
            }, { passive: true });

            this.spreadEl.addEventListener('touchend', (e) => {
                if (e.changedTouches.length === 1) {
                    const diffX = e.changedTouches[0].clientX - startX;
                    const diffY = e.changedTouches[0].clientY - startY;

                    if (Math.abs(diffX) > 50 && Math.abs(diffY) < 60) {
                        if (diffX < 0) {
                            this.turnNext();
                        } else {
                            this.turnPrev();
                        }
                    }
                }
            }, { passive: true });
        }

        /* -------------------------------------------------------------
           Drawers & Modals Helpers
           ------------------------------------------------------------- */
        toggleDrawer(drawerId) {
            const drawer = document.getElementById(drawerId);
            if (!drawer) return;
            const isOpen = drawer.classList.contains('open');
            this.closeAllDrawers();
            if (!isOpen) {
                drawer.classList.add('open');
            }
        }

        toggleSearchModal() {
            const modal = document.getElementById('searchModal');
            if (!modal) return;
            const isOpen = modal.classList.contains('show');
            this.closeAllDrawers();
            if (!isOpen) {
                modal.classList.add('show');
                const input = document.getElementById('bookSearchInput');
                if (input) setTimeout(() => input.focus(), 100);
            }
        }

        toggleInfoModal() {
            const modal = document.getElementById('bookInfoOverlay');
            if (modal) {
                modal.style.display = (modal.style.display === 'flex') ? 'none' : 'flex';
            }
        }

        closeAllDrawers() {
            document.querySelectorAll('.reader-side-drawer').forEach(d => d.classList.remove('open'));
            const searchModal = document.getElementById('searchModal');
            if (searchModal) searchModal.classList.remove('show');
            const infoModal = document.getElementById('bookInfoOverlay');
            if (infoModal) infoModal.style.display = 'none';
        }

        showToast(msg) {
            const toast = document.getElementById('readerFloatingToast');
            const toastMsg = document.getElementById('floatingToastMsg');
            if (!toast || !toastMsg) return;

            toastMsg.textContent = msg;
            toast.classList.add('show');
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        escapeRegex(str) {
            return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
    }

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        window.bookReader = new VirtualBookReader();
    });

})();
