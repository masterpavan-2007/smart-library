<?php
/**
 * Smart Virtual Library - Text-to-Speech (TTS) Diagnostic & Live Testing Console
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Text-to-Speech (TTS) Diagnostic & Testing Console · Smart Library</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= basePath() ?>/assets/css/style.css">
<style>
:root {
    --bg-dark: #090d16;
    --card-bg: rgba(30, 41, 59, 0.85);
    --border-color: rgba(51, 65, 85, 0.7);
    --primary: #3b82f6;
    --primary-light: #60a5fa;
    --success: #10b981;
    --danger: #ef4444;
}

body {
    background: radial-gradient(circle at 50% 0%, #1e293b 0%, #090d16 100%);
    color: #f1f5f9;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    min-height: 100vh;
    padding: 30px 16px;
    margin: 0;
}

.tts-test-container {
    max-width: 980px;
    margin: 0 auto;
}

.tts-header {
    text-align: center;
    margin-bottom: 28px;
}

.tts-header h1 {
    font-size: 28px;
    font-weight: 800;
    margin: 0 0 8px 0;
    background: linear-gradient(135deg, #60a5fa 0%, #38bdf8 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    display: inline-flex;
    align-items: center;
    gap: 12px;
}

.tts-header p {
    color: #94a3b8;
    font-size: 14.5px;
    margin: 0;
}

.grid-2 {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 20px;
}

@media (max-width: 860px) {
    .grid-2 {
        grid-template-columns: 1fr;
    }
}

.tts-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(12px);
    margin-bottom: 20px;
}

.tts-card h2 {
    font-size: 17px;
    font-weight: 700;
    margin: 0 0 16px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #f8fafc;
}

.status-badge-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    background: #0f172a;
    border: 1px solid #334155;
    color: #cbd5e1;
}

.status-pill.success {
    border-color: #059669;
    color: #34d399;
    background: rgba(6, 78, 59, 0.3);
}

.status-pill.warning {
    border-color: #d97706;
    color: #fbbf24;
    background: rgba(120, 53, 15, 0.3);
}

.status-pill.danger {
    border-color: #dc2626;
    color: #f87171;
    background: rgba(127, 29, 29, 0.3);
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #cbd5e1;
    margin-bottom: 6px;
}

.form-control, .form-select {
    width: 100%;
    box-sizing: border-box;
    background: #0f172a;
    border: 1px solid #334155;
    color: #f8fafc;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 14px;
    outline: none;
    transition: border-color 0.15s;
}

.form-control:focus, .form-select:focus {
    border-color: #3b82f6;
}

.slider-container {
    display: flex;
    align-items: center;
    gap: 12px;
}

.slider-container input[type="range"] {
    flex: 1;
    accent-color: #3b82f6;
    cursor: pointer;
}

.slider-val {
    font-size: 13px;
    font-weight: 600;
    color: #38bdf8;
    width: 45px;
    text-align: right;
}

.preset-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.preset-btn {
    background: #0f172a;
    border: 1px solid #334155;
    color: #94a3b8;
    padding: 5px 12px;
    border-radius: 16px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
}

.preset-btn:hover {
    background: #1e293b;
    border-color: #3b82f6;
    color: #f8fafc;
}

.btn-group-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 20px;
}

.tts-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.15s;
}

.tts-btn-primary {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #fff;
    border-color: #3b82f6;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
}

.tts-btn-primary:hover:not(:disabled) {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.6);
    transform: translateY(-1px);
}

.tts-btn-secondary {
    background: #1e293b;
    border-color: #334155;
    color: #f1f5f9;
}

.tts-btn-secondary:hover:not(:disabled) {
    background: #334155;
    color: #fff;
}

.tts-btn-danger {
    background: #991b1b;
    border-color: #dc2626;
    color: #fff;
}

.tts-btn-danger:hover:not(:disabled) {
    background: #dc2626;
}

.tts-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Sound Wave animation */
.soundwave-box {
    display: flex;
    align-items: center;
    gap: 3px;
    height: 20px;
}

.soundwave-box span {
    width: 4px;
    background: #38bdf8;
    border-radius: 2px;
    animation: ttsWave 0.8s ease-in-out infinite alternate;
}

.soundwave-box span:nth-child(1) { height: 6px; animation-delay: 0.1s; }
.soundwave-box span:nth-child(2) { height: 18px; animation-delay: 0.3s; }
.soundwave-box span:nth-child(3) { height: 10px; animation-delay: 0.5s; }
.soundwave-box span:nth-child(4) { height: 20px; animation-delay: 0.2s; }
.soundwave-box span:nth-child(5) { height: 8px; animation-delay: 0.4s; }

.soundwave-box.paused span {
    animation-play-state: paused;
    height: 4px !important;
}

@keyframes ttsWave {
    0% { height: 4px; }
    100% { height: 20px; }
}

.console-log {
    background: #020617;
    border: 1px solid #1e293b;
    border-radius: 8px;
    padding: 12px;
    font-family: Consolas, monospace;
    font-size: 12px;
    color: #94a3b8;
    height: 220px;
    overflow-y: auto;
    white-space: pre-wrap;
}

.log-entry {
    margin-bottom: 4px;
    line-height: 1.4;
}

.log-time {
    color: #64748b;
    margin-right: 6px;
}

.log-info { color: #38bdf8; }
.log-success { color: #34d399; }
.log-warn { color: #fbbf24; }
.log-error { color: #f87171; }

.nav-links {
    margin-top: 24px;
    display: flex;
    gap: 14px;
    justify-content: center;
}

.nav-links a {
    color: #94a3b8;
    text-decoration: none;
    font-size: 13.5px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 20px;
    transition: all 0.15s;
}

.nav-links a:hover {
    color: #38bdf8;
    border-color: #38bdf8;
}
</style>
</head>
<body>

<div class="tts-test-container">

    <div class="tts-header">
        <h1><i class="fa-solid fa-headphones"></i> Text-to-Speech (TTS) Voice Tester</h1>
        <p>Interactive speech synthesis verification for Smart Virtual Library E-Books</p>
    </div>

    <!-- Live Status Badges -->
    <div class="status-badge-row">
        <div class="status-pill" id="pillSupport">
            <i class="fa-solid fa-circle-question"></i> Checking API Support...
        </div>
        <div class="status-pill" id="pillVoices">
            <i class="fa-solid fa-microphone"></i> 0 Voices Loaded
        </div>
        <div class="status-pill" id="pillStatus">
            <i class="fa-solid fa-circle"></i> Status: Idle
        </div>
        <div class="status-pill" style="margin-left: auto;">
            <div class="soundwave-box paused" id="testWave">
                <span></span><span></span><span></span><span></span><span></span>
            </div>
        </div>
    </div>

    <div class="grid-2">
        <!-- Left: Speech Input & Controls -->
        <div>
            <div class="tts-card">
                <h2><i class="fa-solid fa-sliders text-primary"></i> Voice Settings & Pitch</h2>

                <div class="form-group">
                    <label for="voiceSelect">Select Speech Voice:</label>
                    <select class="form-select" id="voiceSelect">
                        <option value="">Detecting available voices...</option>
                    </select>
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between;">
                        <label for="speedRange">Playback Speed (Rate):</label>
                        <span class="slider-val" id="speedVal">1.0x</span>
                    </div>
                    <div class="slider-container">
                        <span style="font-size:12px; color:#64748b;">0.5x</span>
                        <input type="range" id="speedRange" min="0.5" max="2.0" step="0.05" value="1.0">
                        <span style="font-size:12px; color:#64748b;">2.0x</span>
                    </div>
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between;">
                        <label for="pitchRange">Voice Pitch:</label>
                        <span class="slider-val" id="pitchVal">1.0</span>
                    </div>
                    <div class="slider-container">
                        <span style="font-size:12px; color:#64748b;">0.5</span>
                        <input type="range" id="pitchRange" min="0.5" max="1.5" step="0.05" value="1.0">
                        <span style="font-size:12px; color:#64748b;">1.5</span>
                    </div>
                </div>
            </div>

            <div class="tts-card">
                <h2><i class="fa-solid fa-file-lines text-primary"></i> Text to Read Aloud</h2>

                <div class="preset-buttons">
                    <button class="preset-btn" onclick="setPreset('greeting')">👋 Welcome Greeting</button>
                    <button class="preset-btn" onclick="setPreset('clean_code')">📖 Clean Code (Book Quote)</button>
                    <button class="preset-btn" onclick="setPreset('multi_sentence')">📑 Multi-Sentence Passage</button>
                </div>

                <div class="form-group">
                    <textarea class="form-control" id="ttsInput" rows="5" placeholder="Type or paste any text to hear it spoken aloud...">Welcome to the Smart Virtual Library! Text-to-speech voice output is running and fully verified on your system.</textarea>
                </div>

                <div class="btn-group-actions">
                    <button class="tts-btn tts-btn-primary" id="btnSpeak" onclick="speakText()">
                        <i class="fa-solid fa-play"></i> <span>Play / Speak Voice</span>
                    </button>
                    <button class="tts-btn tts-btn-secondary" id="btnPause" onclick="togglePause()" disabled>
                        <i class="fa-solid fa-pause"></i> <span id="pauseBtnLabel">Pause</span>
                    </button>
                    <button class="tts-btn tts-btn-danger" id="btnStop" onclick="stopSpeech()" disabled>
                        <i class="fa-solid fa-stop"></i> <span>Stop</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Right: Diagnostic Console & Browser Info -->
        <div>
            <div class="tts-card">
                <h2><i class="fa-solid fa-terminal text-primary"></i> Real-Time Speech Log</h2>
                <div class="console-log" id="consoleLog"></div>
                <div style="text-align: right; margin-top: 8px;">
                    <button class="preset-btn" onclick="clearLog()"><i class="fa-solid fa-trash"></i> Clear Log</button>
                </div>
            </div>

            <div class="tts-card">
                <h2><i class="fa-solid fa-circle-info text-primary"></i> How Voice Output Works</h2>
                <ul style="padding-left: 20px; font-size: 13px; color: #94a3b8; line-height: 1.6; margin: 0;">
                    <li><strong>Web Speech API:</strong> Uses native OS/browser speech synthesizer with zero third-party audio latency.</li>
                    <li><strong>Offline Capability:</strong> Default Microsoft (Windows), Apple (macOS), or Android voices work without internet.</li>
                    <li><strong>Digital Reader Integration:</strong> Inside digital books, click <em>"Listen / Read Aloud"</em> or select any paragraph to trigger audio reading with glowing active sentence highlight!</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Navigation links -->
    <div class="nav-links">
        <a href="<?= basePath() ?>/student/read-book.php?id=1"><i class="fa-solid fa-book-open"></i> Open Clean Code in Digital Reader</a>
        <a href="<?= basePath() ?>/student/virtual-library.php"><i class="fa-solid fa-laptop-code"></i> Virtual Library</a>
        <a href="<?= basePath() ?>/auth/login.php"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
    </div>

</div>

<script>
const isSupported = ('speechSynthesis' in window) && ('SpeechSynthesisUtterance' in window);
let voices = [];
let isSpeaking = false;
let isPaused = false;

// DOM Elements
const pillSupport = document.getElementById('pillSupport');
const pillVoices  = document.getElementById('pillVoices');
const pillStatus  = document.getElementById('pillStatus');
const testWave    = document.getElementById('testWave');
const voiceSelect = document.getElementById('voiceSelect');
const speedRange  = document.getElementById('speedRange');
const speedVal    = document.getElementById('speedVal');
const pitchRange  = document.getElementById('pitchRange');
const pitchVal    = document.getElementById('pitchVal');
const ttsInput    = document.getElementById('ttsInput');
const btnSpeak    = document.getElementById('btnSpeak');
const btnPause    = document.getElementById('btnPause');
const btnStop     = document.getElementById('btnStop');
const pauseBtnLabel = document.getElementById('pauseBtnLabel');
const consoleLog  = document.getElementById('consoleLog');

function log(msg, type = 'info') {
    const time = new Date().toTimeString().split(' ')[0];
    const div = document.createElement('div');
    div.className = 'log-entry';
    div.innerHTML = `<span class="log-time">[${time}]</span><span class="log-${type}">${msg}</span>`;
    consoleLog.appendChild(div);
    consoleLog.scrollTop = consoleLog.scrollHeight;
}

function clearLog() {
    consoleLog.innerHTML = '';
    log('Console log cleared.', 'info');
}

// Sliders
speedRange.addEventListener('input', () => {
    speedVal.textContent = parseFloat(speedRange.value).toFixed(2) + 'x';
});
pitchRange.addEventListener('input', () => {
    pitchVal.textContent = parseFloat(pitchRange.value).toFixed(2);
});

// Check support
if (isSupported) {
    pillSupport.className = 'status-pill success';
    pillSupport.innerHTML = '<i class="fa-solid fa-circle-check"></i> Web Speech API: Supported';
    log('SpeechSynthesis API successfully detected in browser.', 'success');
} else {
    pillSupport.className = 'status-pill danger';
    pillSupport.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Web Speech API: Not Supported';
    log('SpeechSynthesis API is NOT supported in this browser. Please use Chrome, Edge, or Firefox.', 'error');
}

// Voice loader
function loadVoices() {
    if (!isSupported) return;
    voices = window.speechSynthesis.getVoices();
    
    if (voices && voices.length > 0) {
        pillVoices.className = 'status-pill success';
        pillVoices.innerHTML = `<i class="fa-solid fa-microphone"></i> ${voices.length} Voices Detected`;
        
        voiceSelect.innerHTML = '';

        // Sort: English first
        const sorted = [...voices].sort((a, b) => {
            const aEn = a.lang.toLowerCase().startsWith('en');
            const bEn = b.lang.toLowerCase().startsWith('en');
            if (aEn && !bEn) return -1;
            if (!aEn && bEn) return 1;
            return a.name.localeCompare(b.name);
        });

        sorted.forEach((v, idx) => {
            const opt = document.createElement('option');
            opt.value = v.voiceURI;
            opt.textContent = `${v.name} (${v.lang}) ${v.default ? '★ Default' : ''}`;
            if (v.default || v.lang === 'en-US') {
                opt.selected = true;
            }
            voiceSelect.appendChild(opt);
        });

        log(`Loaded ${voices.length} synthesized voices.`, 'success');
    }
}

if (isSupported) {
    loadVoices();
    if (typeof window.speechSynthesis !== 'undefined') {
        window.speechSynthesis.onvoiceschanged = loadVoices;
        window.speechSynthesis.addEventListener('voiceschanged', loadVoices);
    }
    setTimeout(loadVoices, 200);
    setTimeout(loadVoices, 800);
    setTimeout(loadVoices, 1800);
}

// Presets
function setPreset(key) {
    if (key === 'greeting') {
        ttsInput.value = 'Welcome to the Smart Virtual Library! Text-to-speech voice output is running and fully verified on your system.';
    } else if (key === 'clean_code') {
        ttsInput.value = 'Clean Code Chapter 1: Meaningful names save hours of code comprehension. Use pronounceable, searchable names and leave the code cleaner than you found it.';
    } else if (key === 'multi_sentence') {
        ttsInput.value = 'Digital reader speech synthesis test. First sentence is reading smoothly. Second sentence verifies sentence stepping and timing. Third sentence confirms speech audio output.';
    }
    log(`Applied preset: ${key}`, 'info');
}

// Speaking
function speakText() {
    if (!isSupported) {
        alert('Speech Synthesis is not supported in this browser.');
        return;
    }

    const text = ttsInput.value.trim();
    if (!text) {
        alert('Please enter or select text to speak.');
        return;
    }

    // Stop any existing utterance
    window.speechSynthesis.cancel();

    const utterance = new SpeechSynthesisUtterance(text);
    utterance.rate = parseFloat(speedRange.value) || 1.0;
    utterance.pitch = parseFloat(pitchRange.value) || 1.0;

    // Selected voice
    const selectedURI = voiceSelect.value;
    const voice = voices.find(v => v.voiceURI === selectedURI) || voices.find(v => v.lang.startsWith('en')) || voices[0];
    if (voice) {
        utterance.voice = voice;
        utterance.lang = voice.lang || 'en-US';
        log(`Using Voice: ${voice.name} (${voice.lang})`, 'info');
    } else {
        utterance.lang = 'en-US';
        log('Using default system voice (en-US).', 'warn');
    }

    utterance.onstart = () => {
        isSpeaking = true;
        isPaused = false;
        pillStatus.className = 'status-pill success';
        pillStatus.innerHTML = '<i class="fa-solid fa-volume-high"></i> Status: Speaking...';
        testWave.classList.remove('paused');
        btnPause.disabled = false;
        btnStop.disabled = false;
        pauseBtnLabel.textContent = 'Pause';
        log('Speech started: Audio playing now.', 'success');
    };

    utterance.onend = () => {
        isSpeaking = false;
        isPaused = false;
        pillStatus.className = 'status-pill';
        pillStatus.innerHTML = '<i class="fa-solid fa-circle"></i> Status: Finished';
        testWave.classList.add('paused');
        btnPause.disabled = true;
        btnStop.disabled = true;
        log('Speech finished successfully.', 'success');
    };

    utterance.onerror = (e) => {
        isSpeaking = false;
        isPaused = false;
        pillStatus.className = 'status-pill danger';
        pillStatus.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Status: Error';
        testWave.classList.add('paused');
        btnPause.disabled = true;
        btnStop.disabled = true;
        log(`Speech error: ${e.error || 'Unknown error'}`, 'error');
    };

    if (window.speechSynthesis.paused) {
        window.speechSynthesis.resume();
    }

    window.speechSynthesis.speak(utterance);
}

function togglePause() {
    if (!isSpeaking) return;

    if (isPaused) {
        window.speechSynthesis.resume();
        isPaused = false;
        pauseBtnLabel.textContent = 'Pause';
        pillStatus.className = 'status-pill success';
        pillStatus.innerHTML = '<i class="fa-solid fa-volume-high"></i> Status: Speaking...';
        testWave.classList.remove('paused');
        log('Speech resumed.', 'info');
    } else {
        window.speechSynthesis.pause();
        isPaused = true;
        pauseBtnLabel.textContent = 'Resume';
        pillStatus.className = 'status-pill warning';
        pillStatus.innerHTML = '<i class="fa-solid fa-circle-pause"></i> Status: Paused';
        testWave.classList.add('paused');
        log('Speech paused.', 'warn');
    }
}

function stopSpeech() {
    window.speechSynthesis.cancel();
    isSpeaking = false;
    isPaused = false;
    pillStatus.className = 'status-pill';
    pillStatus.innerHTML = '<i class="fa-solid fa-circle"></i> Status: Stopped';
    testWave.classList.add('paused');
    btnPause.disabled = true;
    btnStop.disabled = true;
    log('Speech cancelled/stopped by user.', 'warn');
}
</script>
</body>
</html>
