/**
 * =====================================================================
 * Smart Virtual Library — Text-to-Speech (TTS) Reader Engine
 * Web Speech API SpeechSynthesis integration with:
 * - Play / Pause / Resume / Stop controls
 * - Speech speed control (0.5x - 2.0x)
 * - Dynamic Voice selection & language fallback
 * - Chapter/Page vs. Selected text reading
 * - Sentence-level active highlighting & auto-scrolling
 * - Auto-play next chapter support
 * - Reading position persistence (localStorage & backend sync)
 * - Chrome 15s keepalive & multi-instance race condition guards
 * =====================================================================
 */

class EBookTTSReader {
    constructor(options = {}) {
        this.bookId = options.bookId || 0;
        this.bookTitle = options.bookTitle || 'eBook';
        this.totalPages = options.totalPages || 1;
        this.currentPage = options.currentPage || 1;
        this.onPageChange = options.onPageChange || null; // Callback e.g. goToPage(n)

        // State
        this.isSupported = ('speechSynthesis' in window) && ('SpeechSynthesisUtterance' in window);
        this.status = 'stopped'; // 'stopped' | 'playing' | 'paused'
        this.mode = 'chapter';   // 'chapter' | 'selection'
        this.sentences = [];     // [{ text: string, index: number, page: number }]
        this.currentIndex = 0;
        this.voices = [];
        this.selectedVoiceURI = localStorage.getItem('slms_tts_voice_' + this.bookId) || localStorage.getItem('slms_tts_global_voice') || '';
        this.speed = parseFloat(localStorage.getItem('slms_tts_speed')) || 1.0;
        this.autoPlayNext = localStorage.getItem('slms_tts_autoplay') !== 'false'; // default true
        this.isDrawerExpanded = true;
        this.speechToken = 0;
        this.keepAliveTimer = null;
        this.selectedText = '';

        // DOM references
        this.dockEl = null;
        this.transcriptEl = null;
        this.playBtn = null;
        this.soundwaveEl = null;
        this.statusTextEl = null;
        this.sentenceInfoEl = null;
        this.voiceSelectEl = null;
        this.tooltipEl = null;

        this.init();
    }

    init() {
        if (!this.isSupported) {
            console.warn('SpeechSynthesis is not supported in this browser.');
            this.renderUnsupportedBanner();
            return;
        }

        // Initialize voices asynchronously
        this.initVoices();

        // Build UI elements
        this.createDockUI();
        this.createSelectionTooltip();
        this.setupTextSelectionListener();
        this.setupUnloadCleanup();

        // Check for saved reading position
        this.checkSavedPosition();
    }

    /* -------------------------------------------------------------
       Voice Loading & Setup
       ------------------------------------------------------------- */
    initVoices() {
        const update = () => {
            const list = window.speechSynthesis.getVoices();
            if (list && list.length > 0) {
                this.voices = list;
                this.populateVoiceSelect();
            }
        };

        update();
        if ('onvoiceschanged' in window.speechSynthesis) {
            window.speechSynthesis.onvoiceschanged = update;
        }
        // Fallback retry for Chromium browsers where onvoiceschanged may delay
        setTimeout(update, 600);
        setTimeout(update, 1800);
    }

    populateVoiceSelect() {
        if (!this.voiceSelectEl) return;
        this.voiceSelectEl.innerHTML = '';

        if (!this.voices || this.voices.length === 0) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'Default System Voice';
            this.voiceSelectEl.appendChild(opt);
            return;
        }

        // Prioritize English and local voices, then others
        const sorted = [...this.voices].sort((a, b) => {
            const aEn = a.lang.toLowerCase().startsWith('en');
            const bEn = b.lang.toLowerCase().startsWith('en');
            if (aEn && !bEn) return -1;
            if (!aEn && bEn) return 1;
            return a.name.localeCompare(b.name);
        });

        let defaultSelected = false;
        sorted.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v.voiceURI;
            opt.textContent = `${v.name} (${v.lang})`;
            if (this.selectedVoiceURI && v.voiceURI === this.selectedVoiceURI) {
                opt.selected = true;
                defaultSelected = true;
            } else if (!defaultSelected && (v.default || v.lang === 'en-US')) {
                opt.selected = true;
            }
            this.voiceSelectEl.appendChild(opt);
        });
    }

    getChosenVoice() {
        if (!this.voices || this.voices.length === 0) return null;
        if (this.selectedVoiceURI) {
            const found = this.voices.find(v => v.voiceURI === this.selectedVoiceURI);
            if (found) return found;
        }
        // Fallback to first English or first available
        return this.voices.find(v => v.lang.startsWith('en')) || this.voices[0] || null;
    }

    /* -------------------------------------------------------------
       Reading Position Persistence
       ------------------------------------------------------------- */
    savePosition() {
        const pos = {
            page: this.currentPage,
            sentenceIndex: this.currentIndex,
            speed: this.speed,
            voiceURI: this.selectedVoiceURI,
            autoPlay: this.autoPlayNext,
            timestamp: Date.now()
        };
        try {
            localStorage.setItem('slms_tts_pos_' + this.bookId, JSON.stringify(pos));
        } catch (e) {
            console.warn('LocalStorage save error:', e);
        }
    }

    checkSavedPosition() {
        try {
            const raw = localStorage.getItem('slms_tts_pos_' + this.bookId);
            if (!raw) return;
            const data = JSON.parse(raw);
            if (data && (data.sentenceIndex > 0 || data.page > 1)) {
                this.showResumeBanner(data);
            }
        } catch (e) {
            console.warn('Error reading saved position:', e);
        }
    }

    showResumeBanner(data) {
        const banner = document.getElementById('ttsResumeBanner') || this.createResumeBanner();
        const text = banner.querySelector('.banner-text');
        text.innerHTML = `Resume audio from <strong>Page ${data.page}</strong> (Sentence ${data.sentenceIndex + 1})?`;
        
        banner.querySelector('.btn-resume').onclick = () => {
            banner.style.display = 'none';
            if (this.currentPage !== data.page && typeof this.onPageChange === 'function') {
                this.onPageChange(data.page);
            }
            this.currentPage = data.page;
            this.openDock();
            this.loadCurrentPageText().then(() => {
                this.currentIndex = Math.min(data.sentenceIndex, Math.max(0, this.sentences.length - 1));
                this.play();
            });
        };

        banner.querySelector('.btn-dismiss').onclick = () => {
            banner.style.display = 'none';
        };

        banner.style.display = 'flex';
        setTimeout(() => {
            if (banner.style.display !== 'none') {
                banner.style.opacity = '0';
                setTimeout(() => { banner.style.display = 'none'; banner.style.opacity = '1'; }, 400);
            }
        }, 12000);
    }

    createResumeBanner() {
        const div = document.createElement('div');
        div.id = 'ttsResumeBanner';
        div.className = 'tts-resume-banner';
        div.innerHTML = `
            <i class="fa-solid fa-headphones text-primary" style="font-size:18px;"></i>
            <div class="banner-text">Resume audio listening?</div>
            <div class="flex gap-1">
                <button class="reader-btn primary btn-resume" style="padding:4px 10px; font-size:12px;">Resume</button>
                <button class="reader-btn btn-dismiss" style="padding:4px 8px; font-size:12px;"><i class="fa-solid fa-xmark"></i></button>
            </div>
        `;
        document.body.appendChild(div);
        return div;
    }

    /* -------------------------------------------------------------
       Text Extraction & Sentence Tokenization
       ------------------------------------------------------------- */
    splitIntoSentences(text) {
        if (!text || typeof text !== 'string') return [];
        
        // Clean excessive whitespace and carriage returns
        let clean = text.replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
        if (!clean) return [];

        // Avoid splitting on common abbreviations
        const abbrevReplacements = [
            [/Mr\./gi, 'Mr<DOT>'],
            [/Mrs\./gi, 'Mrs<DOT>'],
            [/Dr\./gi, 'Dr<DOT>'],
            [/Prof\./gi, 'Prof<DOT>'],
            [/e\.g\./gi, 'eg<DOT>'],
            [/i\.e\./gi, 'ie<DOT>'],
            [/vs\./gi, 'vs<DOT>'],
            [/etc\./gi, 'etc<DOT>'],
            [/No\./gi, 'No<DOT>'],
            [/Fig\./gi, 'Fig<DOT>'],
            [/(\d+)\.(\d+)/g, '$1<NUMDOT>$2'] // Decimal numbers e.g. 3.14
        ];

        abbrevReplacements.forEach(([pattern, rep]) => {
            clean = clean.replace(pattern, rep);
        });

        // Split on punctuation followed by space or quote or end-of-string
        const rawTokens = clean.match(/[^.!?]+[.!?]+(?:\s+|$)|[^.!?]+$/g);
        if (!rawTokens) return [clean];

        return rawTokens.map(tok => {
            return tok
                .replace(/<DOT>/g, '.')
                .replace(/<NUMDOT>/g, '.')
                .trim();
        }).filter(s => s.length > 1 && /[a-zA-Z0-9]/.test(s));
    }

    async loadCurrentPageText() {
        if (!window.pdfDoc) {
            this.sentences = [
                { text: 'Digital reader is ready. Please select text or wait for document to finish rendering.', index: 0, page: this.currentPage }
            ];
            this.renderTranscriptList();
            return;
        }

        try {
            const page = await window.pdfDoc.getPage(this.currentPage);
            const textContent = await page.getTextContent();
            
            let fullText = '';
            textContent.items.forEach(item => {
                if (item.str) {
                    fullText += item.str + (item.hasEOL ? '\n' : ' ');
                }
            });

            const rawSentences = this.splitIntoSentences(fullText);
            if (rawSentences.length === 0) {
                this.sentences = [
                    { text: `Page ${this.currentPage} has no readable digital text (scanned graphic or title image).`, index: 0, page: this.currentPage }
                ];
            } else {
                this.sentences = rawSentences.map((s, idx) => ({
                    text: s,
                    index: idx,
                    page: this.currentPage
                }));
            }

            this.renderTranscriptList();
            this.updateStatusInfo();
        } catch (err) {
            console.error('Failed to extract text from page:', err);
            this.sentences = [
                { text: `Could not load text for Page ${this.currentPage}.`, index: 0, page: this.currentPage }
            ];
            this.renderTranscriptList();
        }
    }

    loadSelectedText(text) {
        this.selectedText = text;
        this.mode = 'selection';
        const raw = this.splitIntoSentences(text);
        if (raw.length === 0) return;

        this.sentences = raw.map((s, idx) => ({
            text: s,
            index: idx,
            page: this.currentPage,
            isSelection: true
        }));
        this.currentIndex = 0;

        // Update UI pills
        if (this.dockEl) {
            this.dockEl.querySelectorAll('.tts-mode-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.mode === 'selection');
            });
        }

        this.openDock();
        this.renderTranscriptList();
        this.updateStatusInfo();
        this.play();
    }

    /* -------------------------------------------------------------
       Speech Synthesis Engine
       ------------------------------------------------------------- */
    play() {
        if (!this.isSupported) return;

        if (this.status === 'paused') {
            this.resume();
            return;
        }

        // If stopped and no sentences loaded yet, load them first
        if (this.sentences.length === 0) {
            this.loadCurrentPageText().then(() => {
                this.speakCurrentSentence();
            });
            return;
        }

        this.speakCurrentSentence();
    }

    speakCurrentSentence() {
        if (!this.isSupported) return;
        this.stopSpeechEngineOnly();

        const token = ++this.speechToken;
        if (this.currentIndex >= this.sentences.length) {
            this.handlePlaybackFinished();
            return;
        }

        const sentenceObj = this.sentences[this.currentIndex];
        const textToSpeak = sentenceObj.text;

        const utterance = new SpeechSynthesisUtterance(textToSpeak);
        utterance.rate = this.speed;
        utterance.pitch = 1.0;
        
        const voice = this.getChosenVoice();
        if (voice) {
            utterance.voice = voice;
        }

        utterance.onstart = () => {
            if (this.speechToken !== token) return;
            this.status = 'playing';
            this.updatePlayerUI();
            this.startKeepAlive();
            this.highlightActiveSentence(this.currentIndex);
            this.savePosition();
        };

        utterance.onend = () => {
            if (this.speechToken !== token) return;
            this.stopKeepAlive();
            
            // Mark sentence as played
            const prevCard = document.getElementById(`ttsSentenceCard_${this.currentIndex}`);
            if (prevCard) {
                prevCard.classList.remove('active');
                prevCard.classList.add('played');
            }

            this.currentIndex++;
            if (this.currentIndex < this.sentences.length) {
                this.speakCurrentSentence();
            } else {
                this.handlePlaybackFinished();
            }
        };

        utterance.onerror = (e) => {
            if (this.speechToken !== token) return;
            this.stopKeepAlive();
            // 'canceled' or 'interrupted' is expected during user skip/stop
            if (e.error !== 'canceled' && e.error !== 'interrupted') {
                console.warn('SpeechSynthesis error:', e.error);
                // Step forward to avoid deadlock
                this.currentIndex++;
                if (this.currentIndex < this.sentences.length) {
                    this.speakCurrentSentence();
                } else {
                    this.handlePlaybackFinished();
                }
            }
        };

        window.speechSynthesis.speak(utterance);
        this.status = 'playing';
        this.updatePlayerUI();
    }

    pause() {
        if (!this.isSupported || this.status !== 'playing') return;
        window.speechSynthesis.pause();
        this.stopKeepAlive();
        this.status = 'paused';
        this.updatePlayerUI();
    }

    resume() {
        if (!this.isSupported) return;
        if (window.speechSynthesis.paused) {
            window.speechSynthesis.resume();
            this.startKeepAlive();
            this.status = 'playing';
            this.updatePlayerUI();
        } else {
            this.speakCurrentSentence();
        }
    }

    stop() {
        if (!this.isSupported) return;
        this.speechToken++;
        this.stopSpeechEngineOnly();
        this.status = 'stopped';
        this.currentIndex = 0;
        this.updatePlayerUI();
        this.clearSentenceHighlights();
    }

    stopSpeechEngineOnly() {
        this.stopKeepAlive();
        if (window.speechSynthesis) {
            window.speechSynthesis.cancel();
        }
    }

    prevSentence() {
        this.speechToken++;
        this.stopSpeechEngineOnly();
        this.currentIndex = Math.max(0, this.currentIndex - 1);
        if (this.status === 'playing' || this.status === 'paused') {
            this.speakCurrentSentence();
        } else {
            this.highlightActiveSentence(this.currentIndex);
            this.updateStatusInfo();
        }
    }

    nextSentence() {
        this.speechToken++;
        this.stopSpeechEngineOnly();
        if (this.currentIndex < this.sentences.length - 1) {
            this.currentIndex++;
            if (this.status === 'playing' || this.status === 'paused') {
                this.speakCurrentSentence();
            } else {
                this.highlightActiveSentence(this.currentIndex);
                this.updateStatusInfo();
            }
        } else {
            this.handlePlaybackFinished();
        }
    }

    jumpToSentence(index) {
        if (index < 0 || index >= this.sentences.length) return;
        this.speechToken++;
        this.stopSpeechEngineOnly();
        this.currentIndex = index;
        this.speakCurrentSentence();
    }

    /* -------------------------------------------------------------
       Auto-Play Next Chapter / Page
       ------------------------------------------------------------- */
    handlePlaybackFinished() {
        this.stopKeepAlive();

        // If in selection mode, just stop
        if (this.mode === 'selection') {
            this.status = 'stopped';
            this.updatePlayerUI();
            if (typeof window.showToast === 'function') {
                window.showToast('Finished reading selected text.');
            }
            return;
        }

        // In Chapter/Page mode, check auto-play next chapter
        if (this.autoPlayNext && this.currentPage < this.totalPages) {
            const nextPage = this.currentPage + 1;
            if (typeof window.showToast === 'function') {
                window.showToast(`Auto-playing next chapter (Page ${nextPage})...`);
            }
            
            // Navigate to next page
            if (typeof this.onPageChange === 'function') {
                this.onPageChange(nextPage);
            }
            this.currentPage = nextPage;
            this.currentIndex = 0;

            // Load new page text and continue speaking
            setTimeout(() => {
                this.loadCurrentPageText().then(() => {
                    this.speakCurrentSentence();
                });
            }, 600);
        } else if (this.currentPage >= this.totalPages) {
            this.status = 'stopped';
            this.updatePlayerUI();
            if (typeof window.showToast === 'function') {
                window.showToast('Finished reading entire book! 📖');
            }
        } else {
            this.status = 'stopped';
            this.updatePlayerUI();
            if (typeof window.showToast === 'function') {
                window.showToast(`Completed Page ${this.currentPage}. Click Next or enable Auto-Play.`);
            }
        }
    }

    /* -------------------------------------------------------------
       Chrome 15s SpeechSynthesis Keepalive Fix
       ------------------------------------------------------------- */
    startKeepAlive() {
        this.stopKeepAlive();
        this.keepAliveTimer = setInterval(() => {
            if (window.speechSynthesis && window.speechSynthesis.speaking && !window.speechSynthesis.paused) {
                window.speechSynthesis.pause();
                window.speechSynthesis.resume();
            }
        }, 9500);
    }

    stopKeepAlive() {
        if (this.keepAliveTimer) {
            clearInterval(this.keepAliveTimer);
            this.keepAliveTimer = null;
        }
    }

    /* -------------------------------------------------------------
       Sentence Highlighting & Auto-Scroll
       ------------------------------------------------------------- */
    highlightActiveSentence(index) {
        if (!this.transcriptEl) return;

        // Clear previous active
        this.transcriptEl.querySelectorAll('.tts-sentence-card').forEach(el => {
            el.classList.remove('active');
        });

        const activeCard = document.getElementById(`ttsSentenceCard_${index}`);
        if (activeCard) {
            activeCard.classList.add('active');
            activeCard.classList.remove('played');
            // Smoothly auto-scroll to center
            activeCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        this.updateStatusInfo();
    }

    clearSentenceHighlights() {
        if (!this.transcriptEl) return;
        this.transcriptEl.querySelectorAll('.tts-sentence-card').forEach(el => {
            el.classList.remove('active', 'played');
        });
    }

    /* -------------------------------------------------------------
       Settings Handlers
       ------------------------------------------------------------- */
    setSpeed(speedVal) {
        this.speed = parseFloat(speedVal) || 1.0;
        localStorage.setItem('slms_tts_speed', this.speed);
        
        // Update pills
        if (this.dockEl) {
            this.dockEl.querySelectorAll('.tts-speed-btn').forEach(btn => {
                btn.classList.toggle('active', parseFloat(btn.dataset.speed) === this.speed);
            });
        }

        // If currently playing, restart sentence with new rate
        if (this.status === 'playing') {
            this.speakCurrentSentence();
        }
    }

    setVoice(voiceURI) {
        this.selectedVoiceURI = voiceURI;
        localStorage.setItem('slms_tts_voice_' + this.bookId, voiceURI);
        localStorage.setItem('slms_tts_global_voice', voiceURI);

        if (this.status === 'playing') {
            this.speakCurrentSentence();
        }
    }

    setAutoPlay(enabled) {
        this.autoPlayNext = enabled;
        localStorage.setItem('slms_tts_autoplay', enabled);
    }

    setMode(newMode) {
        if (newMode === this.mode) return;
        this.stop();
        this.mode = newMode;

        if (this.dockEl) {
            this.dockEl.querySelectorAll('.tts-mode-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.mode === newMode);
            });
        }

        if (newMode === 'chapter') {
            this.loadCurrentPageText();
        } else if (newMode === 'selection') {
            if (this.selectedText) {
                this.loadSelectedText(this.selectedText);
            } else {
                this.sentences = [{
                    text: 'Highlight any text in the book to listen to it.',
                    index: 0,
                    page: this.currentPage
                }];
                this.renderTranscriptList();
            }
        }
    }

    /* -------------------------------------------------------------
       UI Rendering & Updates
       ------------------------------------------------------------- */
    createDockUI() {
        const dock = document.createElement('div');
        dock.id = 'ttsDock';
        dock.className = 'tts-dock';

        dock.innerHTML = `
            <!-- Primary Player Row -->
            <div class="tts-player-bar">
                <!-- Left: Title & Soundwave -->
                <div class="tts-meta-info">
                    <div class="tts-avatar-badge">
                        <i class="fa-solid fa-volume-high"></i>
                    </div>
                    <div class="tts-meta-text">
                        <div class="tts-meta-title" id="ttsMetaTitle">${this.escapeHtml(this.bookTitle)}</div>
                        <div class="tts-meta-sub">
                            <span id="ttsStatusText">Page ${this.currentPage}</span>
                            <div class="tts-soundwave paused" id="ttsSoundwave">
                                <span></span><span></span><span></span><span></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Center: Playback Controls -->
                <div class="tts-center-actions">
                    <button class="tts-ctrl-btn" id="ttsPrevBtn" title="Previous Sentence">
                        <i class="fa-solid fa-backward-step"></i>
                    </button>
                    <button class="tts-ctrl-btn tts-play-btn" id="ttsPlayBtn" title="Play / Pause">
                        <i class="fa-solid fa-play"></i>
                    </button>
                    <button class="tts-ctrl-btn tts-stop-btn" id="ttsStopBtn" title="Stop">
                        <i class="fa-solid fa-stop"></i>
                    </button>
                    <button class="tts-ctrl-btn" id="ttsNextBtn" title="Next Sentence">
                        <i class="fa-solid fa-forward-step"></i>
                    </button>
                </div>

                <!-- Right: Speed, Voice & Drawer Toggles -->
                <div class="tts-right-actions">
                    <!-- Speed Pills -->
                    <div class="tts-speed-selector">
                        <button class="tts-speed-btn ${this.speed === 0.75 ? 'active' : ''}" data-speed="0.75">0.75x</button>
                        <button class="tts-speed-btn ${this.speed === 1.0 ? 'active' : ''}" data-speed="1.0">1x</button>
                        <button class="tts-speed-btn ${this.speed === 1.25 ? 'active' : ''}" data-speed="1.25">1.25x</button>
                        <button class="tts-speed-btn ${this.speed === 1.5 ? 'active' : ''}" data-speed="1.5">1.5x</button>
                        <button class="tts-speed-btn ${this.speed === 2.0 ? 'active' : ''}" data-speed="2.0">2x</button>
                    </div>

                    <!-- Voice Dropdown -->
                    <div class="tts-voice-select-wrap">
                        <select class="tts-select" id="ttsVoiceSelect" title="Select Voice">
                            <option value="">Loading Voices...</option>
                        </select>
                    </div>

                    <!-- Toggle Transcript Drawer -->
                    <button class="reader-btn" id="ttsToggleDrawerBtn" title="Toggle Live Transcript" style="padding:5px 9px;">
                        <i class="fa-solid fa-list-ol"></i>
                    </button>

                    <!-- Close / Minimize Dock -->
                    <button class="reader-btn" id="ttsCloseDockBtn" title="Close Read Aloud Bar" style="padding:5px 8px;">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <!-- Secondary Advanced Controls Bar -->
            <div class="tts-advanced-bar">
                <div class="tts-adv-left">
                    <!-- Mode Switcher -->
                    <div class="tts-mode-pills">
                        <button class="tts-mode-btn ${this.mode === 'chapter' ? 'active' : ''}" data-mode="chapter">
                            <i class="fa-solid fa-book-open"></i> Current Page / Chapter
                        </button>
                        <button class="tts-mode-btn ${this.mode === 'selection' ? 'active' : ''}" data-mode="selection">
                            <i class="fa-solid fa-i-cursor"></i> Selected Text
                        </button>
                    </div>

                    <!-- Sentence Progress Count -->
                    <span id="ttsSentenceInfo" style="color:#cbd5e1; font-weight:500;">Sentence 0 of 0</span>
                </div>

                <div class="tts-adv-right">
                    <!-- Auto-Play Next Chapter Switch -->
                    <label class="tts-toggle-wrap" title="Automatically advance and speak the next chapter/page">
                        <span>Auto-play Next Chapter</span>
                        <div class="tts-switch">
                            <input type="checkbox" id="ttsAutoPlayInput" ${this.autoPlayNext ? 'checked' : ''}>
                            <span class="tts-slider"></span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Expandable Sentence Teleprompter / Transcript -->
            <div class="tts-transcript-drawer" id="ttsTranscriptDrawer">
                <div class="text-muted" style="text-align:center; padding:15px; font-size:13px;">
                    <i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;"></i> Loading reading transcript...
                </div>
            </div>
        `;

        document.body.appendChild(dock);
        this.dockEl = dock;
        this.transcriptEl = dock.querySelector('#ttsTranscriptDrawer');
        this.playBtn = dock.querySelector('#ttsPlayBtn');
        this.soundwaveEl = dock.querySelector('#ttsSoundwave');
        this.statusTextEl = dock.querySelector('#ttsStatusText');
        this.sentenceInfoEl = dock.querySelector('#ttsSentenceInfo');
        this.voiceSelectEl = dock.querySelector('#ttsVoiceSelect');

        this.bindDockEvents();
    }

    bindDockEvents() {
        // Play / Pause
        this.playBtn.addEventListener('click', () => {
            if (this.status === 'playing') {
                this.pause();
            } else {
                this.play();
            }
        });

        // Stop
        this.dockEl.querySelector('#ttsStopBtn').addEventListener('click', () => this.stop());

        // Prev / Next Sentence
        this.dockEl.querySelector('#ttsPrevBtn').addEventListener('click', () => this.prevSentence());
        this.dockEl.querySelector('#ttsNextBtn').addEventListener('click', () => this.nextSentence());

        // Speed Pills
        this.dockEl.querySelectorAll('.tts-speed-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.setSpeed(btn.dataset.speed);
            });
        });

        // Voice Select
        this.voiceSelectEl.addEventListener('change', (e) => {
            this.setVoice(e.target.value);
        });

        // Mode Pills
        this.dockEl.querySelectorAll('.tts-mode-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.setMode(btn.dataset.mode);
            });
        });

        // AutoPlay Switch
        this.dockEl.querySelector('#ttsAutoPlayInput').addEventListener('change', (e) => {
            this.setAutoPlay(e.target.checked);
        });

        // Toggle Drawer (Transcript)
        this.dockEl.querySelector('#ttsToggleDrawerBtn').addEventListener('click', () => {
            this.isDrawerExpanded = !this.isDrawerExpanded;
            this.transcriptEl.style.display = this.isDrawerExpanded ? 'flex' : 'none';
        });

        // Close Dock
        this.dockEl.querySelector('#ttsCloseDockBtn').addEventListener('click', () => {
            this.closeDock();
        });
    }

    openDock() {
        if (!this.dockEl) return;
        this.dockEl.classList.add('open');
        const headerBtn = document.getElementById('readAloudToggleBtn');
        if (headerBtn) headerBtn.classList.add('active');
    }

    closeDock() {
        if (!this.dockEl) return;
        this.stop();
        this.dockEl.classList.remove('open');
        const headerBtn = document.getElementById('readAloudToggleBtn');
        if (headerBtn) headerBtn.classList.remove('active');
    }

    toggleDock() {
        if (!this.dockEl) return;
        if (this.dockEl.classList.contains('open')) {
            this.closeDock();
        } else {
            this.openDock();
            if (this.sentences.length === 0) {
                this.loadCurrentPageText();
            }
        }
    }

    renderTranscriptList() {
        if (!this.transcriptEl) return;
        this.transcriptEl.innerHTML = '';

        if (this.sentences.length === 0) {
            this.transcriptEl.innerHTML = `
                <div class="text-muted" style="text-align:center; padding:15px; font-size:13px;">
                    No sentences extracted. Click 'Play' or turn to a content page.
                </div>
            `;
            return;
        }

        this.sentences.forEach((s, idx) => {
            const card = document.createElement('div');
            card.id = `ttsSentenceCard_${idx}`;
            card.className = `tts-sentence-card ${idx === this.currentIndex && this.status !== 'stopped' ? 'active' : ''}`;
            card.innerHTML = `
                <span class="tts-sentence-num">${idx + 1}</span>
                <span class="tts-sentence-text">${this.escapeHtml(s.text)}</span>
            `;
            card.addEventListener('click', () => {
                this.jumpToSentence(idx);
            });
            this.transcriptEl.appendChild(card);
        });

        this.updateStatusInfo();
    }

    updatePlayerUI() {
        if (!this.playBtn) return;

        const isPlaying = this.status === 'playing';
        const isPaused = this.status === 'paused';

        // Play/Pause icon
        this.playBtn.innerHTML = isPlaying 
            ? '<i class="fa-solid fa-pause"></i>' 
            : '<i class="fa-solid fa-play"></i>';

        // Soundwave
        if (this.soundwaveEl) {
            this.soundwaveEl.classList.toggle('paused', !isPlaying);
        }

        // Header button pulse
        const headerBtn = document.getElementById('readAloudToggleBtn');
        if (headerBtn) {
            headerBtn.classList.toggle('active', isPlaying || isPaused);
        }

        this.updateStatusInfo();
    }

    updateStatusInfo() {
        if (this.statusTextEl) {
            if (this.mode === 'selection') {
                this.statusTextEl.textContent = 'Selected Text';
            } else {
                this.statusTextEl.textContent = `Page ${this.currentPage} of ${this.totalPages}`;
            }
        }

        if (this.sentenceInfoEl) {
            const currentDisplay = this.sentences.length > 0 ? (this.currentIndex + 1) : 0;
            const pct = this.sentences.length > 0 ? Math.round((currentDisplay / this.sentences.length) * 100) : 0;
            this.sentenceInfoEl.textContent = `Sentence ${currentDisplay} of ${this.sentences.length} (${pct}%)`;
        }
    }

    /* -------------------------------------------------------------
       Floating Selection Tooltip
       ------------------------------------------------------------- */
    createSelectionTooltip() {
        const tip = document.createElement('div');
        tip.id = 'ttsSelectionTooltip';
        tip.className = 'tts-selection-tooltip';
        tip.innerHTML = '<i class="fa-solid fa-headphones"></i> Listen to Selection';
        document.body.appendChild(tip);
        this.tooltipEl = tip;

        tip.addEventListener('click', (e) => {
            e.stopPropagation();
            if (this.selectedText) {
                this.loadSelectedText(this.selectedText);
                this.hideSelectionTooltip();
            }
        });
    }

    setupTextSelectionListener() {
        document.addEventListener('mouseup', (e) => {
            // Ignore mouseups inside the TTS dock itself
            if (this.dockEl && this.dockEl.contains(e.target)) return;

            setTimeout(() => {
                const sel = window.getSelection();
                const text = sel ? sel.toString().trim() : '';
                if (text && text.length >= 3) {
                    this.selectedText = text;
                    this.showSelectionTooltip(e.pageX, e.pageY);
                } else {
                    this.hideSelectionTooltip();
                }
            }, 10);
        });

        document.addEventListener('mousedown', (e) => {
            if (this.tooltipEl && !this.tooltipEl.contains(e.target)) {
                this.hideSelectionTooltip();
            }
        });
    }

    showSelectionTooltip(x, y) {
        if (!this.tooltipEl) return;
        this.tooltipEl.style.left = `${x}px`;
        this.tooltipEl.style.top = `${y - 12}px`;
        this.tooltipEl.style.display = 'block';
    }

    hideSelectionTooltip() {
        if (this.tooltipEl) {
            this.tooltipEl.style.display = 'none';
        }
    }

    /* -------------------------------------------------------------
       Browser Unsupported Banner
       ------------------------------------------------------------- */
    renderUnsupportedBanner() {
        const div = document.createElement('div');
        div.className = 'tts-unsupported-alert';
        div.style.display = 'block';
        div.innerHTML = `
            <i class="fa-solid fa-triangle-exclamation" style="margin-right:8px;"></i>
            Text-to-Speech is not supported by your browser. Please use Chrome, Edge, Safari, or Firefox to listen aloud.
        `;
        document.body.appendChild(div);
        setTimeout(() => { div.style.display = 'none'; }, 8000);
    }

    /* -------------------------------------------------------------
       Page Navigation Sync
       ------------------------------------------------------------- */
    onPageNavigated(newPage) {
        this.currentPage = newPage;
        if (this.mode === 'chapter') {
            if (this.status === 'playing') {
                this.stopSpeechEngineOnly();
                this.currentIndex = 0;
                this.loadCurrentPageText().then(() => {
                    this.speakCurrentSentence();
                });
            } else {
                this.currentIndex = 0;
                this.loadCurrentPageText();
            }
        }
    }

    /* -------------------------------------------------------------
       Unload & Cleanup (Prevents zombie speech instances)
       ------------------------------------------------------------- */
    setupUnloadCleanup() {
        window.addEventListener('beforeunload', () => {
            this.stopSpeechEngineOnly();
            this.savePosition();
        });
        window.addEventListener('pagehide', () => {
            this.stopSpeechEngineOnly();
        });
    }

    escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
}

// Global initialization helper
window.initEBookTTS = function(options) {
    if (window.ttsReader) {
        return window.ttsReader;
    }
    window.ttsReader = new EBookTTSReader(options);
    return window.ttsReader;
};
