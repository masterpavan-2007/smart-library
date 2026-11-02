<?php
/**
 * Color Palette Showcase Component
 * Faithfully recreates the theme banner from the reference designs
 * with interactive click-to-copy color swatches.
 */
?>
<div class="theme-palette-card card">
    <div class="theme-palette-grid">
        <!-- Palette Swatches Side -->
        <div class="theme-swatches-col">
            <!-- Light Mode Swatches (visible in light mode) -->
            <div class="swatches-group swatches-light">
                <div class="swatch-pill" style="background:#6A5ACD; color:#fff;" onclick="copyColor('#6A5ACD', this)" title="Click to copy #6A5ACD">
                    <span>#6A5ACD</span>
                </div>
                <div class="swatch-pill" style="background:#5A3B8C; color:#fff;" onclick="copyColor('#5A3B8C', this)" title="Click to copy #5A3B8C">
                    <span>#5A3B8C</span>
                </div>
                <div class="swatch-pill" style="background:#8B7CC7; color:#fff;" onclick="copyColor('#8B7CC7', this)" title="Click to copy #8B7CC7">
                    <span>#8B7CC7</span>
                </div>
                <div class="swatch-pill" style="background:#C9C2E3; color:#3A3A52;" onclick="copyColor('#C9C2E3', this)" title="Click to copy #C9C2E3">
                    <span>#C9C2E3</span>
                </div>
                <div class="swatch-pill" style="background:#3A3A52; color:#fff;" onclick="copyColor('#3A3A52', this)" title="Click to copy #3A3A52">
                    <span>#3A3A52</span>
                </div>
                <div class="swatches-label">+ New colours:</div>
                <div class="swatch-pill" style="background:#2E6F73; color:#fff;" onclick="copyColor('#2E6F73', this)" title="Click to copy #2E6F73">
                    <span>#2E6F73</span>
                </div>
                <div class="swatch-pill" style="background:#EADCC8; color:#3A3A52;" onclick="copyColor('#EADCC8', this)" title="Click to copy #EADCC8">
                    <span>#EADCC8</span>
                </div>
                <div class="swatch-pill" style="background:#C6601F; color:#fff;" onclick="copyColor('#C6601F', this)" title="Click to copy #C6601F">
                    <span>#C6601F</span>
                </div>
            </div>

            <!-- Dark Mode Swatches (visible in dark mode) -->
            <div class="swatches-group swatches-dark">
                <div class="swatch-pill" style="background:#0A0F14; color:#7FB3B8; border:1px solid rgba(127,179,184,0.3);" onclick="copyColor('#0A0F14', this)" title="Click to copy #0A0F14">
                    <span>#0A0F14</span>
                </div>
                <div class="swatch-pill" style="background:#0F3D3E; color:#EDF4F5;" onclick="copyColor('#0F3D3E', this)" title="Click to copy #0F3D3E">
                    <span>#0F3D3E</span>
                </div>
                <div class="swatch-pill" style="background:#2E6F73; color:#fff;" onclick="copyColor('#2E6F73', this)" title="Click to copy #2E6F73">
                    <span>#2E6F73</span>
                </div>
                <div class="swatch-pill" style="background:#7FB3B8; color:#0A0F14;" onclick="copyColor('#7FB3B8', this)" title="Click to copy #7FB3B8">
                    <span>#7FB3B8</span>
                </div>
                <div class="swatch-pill" style="background:#EADCC8; color:#0A0F14;" onclick="copyColor('#EADCC8', this)" title="Click to copy #EADCC8">
                    <span>#EADCC8</span>
                </div>
                <div class="swatch-pill" style="background:#C6601F; color:#fff;" onclick="copyColor('#C6601F', this)" title="Click to copy #C6601F">
                    <span>#C6601F</span>
                </div>
            </div>
        </div>

        <!-- Information Side -->
        <div class="theme-info-col">
            <div class="theme-info-accent-bar"></div>
            <div class="theme-info-content">
                <h3 class="theme-title">Updated Theme</h3>
                <h4 class="theme-subtitle">Modern. Elegant. Readable.</h4>
                <p class="theme-desc">
                    Your project now uses the colours from the provided palettes for a fresh and professional look.
                </p>
                <div class="theme-bottom-accent-line"></div>
            </div>
        </div>
    </div>
</div>

<script>
function copyColor(hex, el) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(hex).then(() => {
            const originalText = el.innerText;
            el.innerText = 'Copied!';
            setTimeout(() => { el.innerText = hex; }, 1200);
        });
    }
}
</script>

