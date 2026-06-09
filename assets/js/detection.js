/**
 * detection.js — PulmoCare IA
 * Gestion upload CT Scan, preview, analyse IA AJAX, affichage résultats
 */

'use strict';

const Detection = (() => {

    // ── Éléments DOM ──────────────────────────────────────────
    const dom = {};

    function cacheDom() {
        dom.form        = document.getElementById('detectionForm');
        dom.dropZone    = document.getElementById('dropZone');
        dom.fileInput   = document.getElementById('scanFile');
        dom.preview     = document.getElementById('filePreview');
        dom.previewImg  = document.getElementById('previewImg');
        dom.previewName = document.getElementById('previewName');
        dom.previewSize = document.getElementById('previewSize');
        dom.removeFile  = document.getElementById('removeFile');
        dom.submitBtn   = document.getElementById('submitBtn');
        dom.btnText     = document.getElementById('btnText');
        dom.progressBar = document.getElementById('progressBar');
        dom.resultPanel = document.getElementById('resultPanel');
        dom.stepsList   = document.getElementById('stepsList');
    }

    // ── Drag & Drop ───────────────────────────────────────────
    function initDropzone() {
        if (!dom.dropZone) return;

        ['dragenter','dragover'].forEach(ev => {
            dom.dropZone.addEventListener(ev, e => {
                e.preventDefault();
                dom.dropZone.classList.add('dragover');
            });
        });

        ['dragleave','drop'].forEach(ev => {
            dom.dropZone.addEventListener(ev, e => {
                e.preventDefault();
                dom.dropZone.classList.remove('dragover');
            });
        });

        dom.dropZone.addEventListener('drop', e => {
            const file = e.dataTransfer?.files?.[0];
            if (file) injectFile(file);
        });

        dom.fileInput?.addEventListener('change', () => {
            if (dom.fileInput.files[0]) showPreview(dom.fileInput.files[0]);
        });

        dom.removeFile?.addEventListener('click', clearFile);
    }

    function injectFile(file) {
        const dt = new DataTransfer();
        dt.items.add(file);
        if (dom.fileInput) dom.fileInput.files = dt.files;
        showPreview(file);
    }

    function showPreview(file) {
        if (!dom.preview) return;

        dom.previewName.textContent = file.name;
        dom.previewSize.textContent = window.PulmoCare?.Utils?.formatBytes(file.size) || formatBytes(file.size);
        dom.dropZone?.classList.add('has-file');

        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = e => {
                if (dom.previewImg) {
                    dom.previewImg.src = e.target.result;
                    dom.previewImg.style.display = 'block';
                }
            };
            reader.readAsDataURL(file);
        } else {
            if (dom.previewImg) dom.previewImg.src = '/assets/images/scan-placeholder.svg';
        }

        dom.preview.classList.add('show');
    }

    function clearFile() {
        if (dom.fileInput) dom.fileInput.value = '';
        dom.preview?.classList.remove('show');
        dom.dropZone?.classList.remove('has-file');
        if (dom.previewImg) dom.previewImg.src = '';
    }

    // ── Form submit avec progress ─────────────────────────────
    function initForm() {
        if (!dom.form) return;

        dom.form.addEventListener('submit', async e => {
            // Laisser PHP gérer le submit classique (pas AJAX)
            // Juste gérer l'état de chargement
            const file = dom.fileInput?.files?.[0];
            if (!file) {
                e.preventDefault();
                window.PulmoCare?.Alerts.show('error', 'Veuillez sélectionner une image CT Scan.', dom.form.closest('.content') || document.body);
                return;
            }
            setLoadingState(true);
            startFakeProgress();
        });
    }

    function setLoadingState(loading) {
        if (!dom.submitBtn) return;
        if (loading) {
            dom.submitBtn.disabled = true;
            if (dom.btnText) dom.btnText.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Analyse IA en cours…';
        } else {
            dom.submitBtn.disabled = false;
            if (dom.btnText) dom.btnText.innerHTML = '<i class="fa-solid fa-brain"></i> Lancer l\'analyse IA';
        }
    }

    // Simulation de progression pendant le traitement
    function startFakeProgress() {
        if (!dom.progressBar) return;
        dom.progressBar.parentElement?.classList.remove('hidden');

        const steps = [
            { pct: 15, label: 'Téléchargement de l\'image…' },
            { pct: 35, label: 'Prétraitement CT Scan…' },
            { pct: 55, label: 'Extraction des features CNN…' },
            { pct: 75, label: 'Classification en cours…' },
            { pct: 90, label: 'Génération du résultat…' },
        ];

        let i = 0;
        const interval = setInterval(() => {
            if (i >= steps.length) { clearInterval(interval); return; }
            const step = steps[i++];
            dom.progressBar.style.width = step.pct + '%';
            if (dom.stepsList) updateStepUI(step.label, i - 1);
        }, 800);
    }

    function updateStepUI(label, idx) {
        if (!dom.stepsList) return;
        const items = dom.stepsList.querySelectorAll('.step-item');
        items.forEach((item, i) => {
            item.classList.toggle('active',    i === idx);
            item.classList.toggle('completed', i < idx);
        });
    }

    // ── Confidence bar animation ──────────────────────────────
    function animateConfidenceBars() {
        document.querySelectorAll('.confidence-bar__fill[data-width]').forEach(bar => {
            setTimeout(() => {
                bar.style.width = bar.dataset.width + '%';
            }, 300);
        });
    }

    // ── Result panel expand/collapse ──────────────────────────
    function initResultPanel() {
        const panel = document.getElementById('resultPanel');
        if (!panel) return;

        panel.classList.add('show');

        // Scroll vers le panel
        setTimeout(() => {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 200);

        animateConfidenceBars();
    }

    // ── Regions overlay (zones détectées sur image) ───────────
    function renderRegions(imageEl, regionsJson) {
        if (!imageEl || !regionsJson) return;
        let regions;
        try { regions = JSON.parse(regionsJson); } catch { return; }
        if (!Array.isArray(regions) || !regions.length) return;

        const wrap = imageEl.closest('.scan-wrap');
        if (!wrap) return;
        wrap.style.position = 'relative';

        regions.forEach(r => {
            const box = document.createElement('div');
            box.style.cssText = `
                position: absolute;
                border: 2px solid #ef4444;
                border-radius: 4px;
                left:   ${r.xmin_pct  || 0}%;
                top:    ${r.ymin_pct  || 0}%;
                width:  ${(r.xmax_pct - r.xmin_pct) || 10}%;
                height: ${(r.ymax_pct - r.ymin_pct) || 10}%;
                box-shadow: 0 0 0 2px rgba(239,68,68,.3);
                animation: pulse 2s infinite;
            `;
            box.title = `Zone suspecte — confiance ${r.confidence || '?'}%`;
            wrap.appendChild(box);
        });
    }

    // ── Utilitaires ───────────────────────────────────────────
    function formatBytes(bytes) {
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' Ko';
        return (bytes / 1048576).toFixed(1) + ' Mo';
    }

    // ── Init ──────────────────────────────────────────────────
    function init() {
        cacheDom();
        initDropzone();
        initForm();
        initResultPanel();
    }

    return { init, showPreview, clearFile, renderRegions };
})();

/* ── Animations CSS additionnelles ───────────────────────────── */
const style = document.createElement('style');
style.textContent = `
    @keyframes pulse {
        0%,100% { box-shadow: 0 0 0 2px rgba(239,68,68,.3); }
        50%      { box-shadow: 0 0 0 6px rgba(239,68,68,.1); }
    }
    #filePreview       { display: none; align-items: center; gap: 14px; padding: 14px; background: var(--bg-hover); border-radius: 9px; margin-top: 16px; border: 1px solid var(--border); }
    #filePreview.show  { display: flex; animation: alertIn .3s ease; }
    #filePreview img   { width: 60px; height: 60px; object-fit: cover; border-radius: 7px; border: 1px solid var(--border); }
    .result-panel      { display: none; }
    .result-panel.show { display: block; animation: alertIn .4s ease; }
    @keyframes alertIn { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: none; } }
    .step-item         { display: flex; align-items: center; gap: 10px; padding: 8px 0; color: var(--text-3); font-size: 13px; transition: color .2s; }
    .step-item.active  { color: var(--blue-500); font-weight: 600; }
    .step-item.completed { color: var(--green); }
    .step-item__dot    { width: 8px; height: 8px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
    .step-item.active .step-item__dot { animation: pulse 1s infinite; }
`;
document.head.appendChild(style);

document.addEventListener('DOMContentLoaded', Detection.init);
