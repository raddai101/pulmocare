/**
 * main.js — PulmoCare IA
 * Interactions globales : sidebar, alertes, AJAX, utilitaires
 */

'use strict';

/* ════════════════════════════════════════════════════════════
   MODULE : Sidebar mobile (toggle)
   ════════════════════════════════════════════════════════════ */
const Sidebar = (() => {
    let sidebar, overlay;

    function init() {
        sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        // Créer overlay mobile
        overlay = document.createElement('div');
        overlay.id = 'sidebarOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199;display:none;backdrop-filter:blur(2px)';
        document.body.appendChild(overlay);

        // Bouton hamburger dans topbar
        const toggleBtn = document.getElementById('sidebarToggle');
        toggleBtn?.addEventListener('click', open);
        overlay.addEventListener('click', close);

        // Fermer sur navigation
        sidebar.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) close();
            });
        });
    }

    function open()  { sidebar?.classList.add('open'); overlay.style.display = 'block'; }
    function close() { sidebar?.classList.remove('open'); overlay.style.display = 'none'; }

    return { init, open, close };
})();

/* ════════════════════════════════════════════════════════════
   MODULE : Alerts (auto-dismiss, close button)
   ════════════════════════════════════════════════════════════ */
const Alerts = (() => {
    function init() {
        document.querySelectorAll('.alert').forEach(setupAlert);
        // Observer pour alertes ajoutées dynamiquement
        const observer = new MutationObserver(mutations => {
            mutations.forEach(m => m.addedNodes.forEach(node => {
                if (node.nodeType === 1 && node.classList?.contains('alert')) setupAlert(node);
            }));
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    function setupAlert(el) {
        // Close button
        el.querySelector('.alert__close')?.addEventListener('click', () => dismiss(el));

        // Auto-dismiss
        const delay = parseInt(el.dataset.autoDismiss) || 0;
        if (delay > 0) setTimeout(() => dismiss(el), delay);
    }

    function dismiss(el) {
        el.style.transition = 'opacity .35s ease, max-height .35s ease, margin .35s ease';
        el.style.opacity    = '0';
        el.style.maxHeight  = el.offsetHeight + 'px';
        setTimeout(() => {
            el.style.maxHeight = '0';
            el.style.margin    = '0';
            el.style.padding   = '0';
            el.style.border    = '0';
        }, 10);
        setTimeout(() => el.remove(), 360);
    }

    function show(type, message, container = document.body, autoDismiss = 5000) {
        const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
        const el = document.createElement('div');
        el.className = `alert alert--${type}`;
        el.dataset.autoDismiss = autoDismiss;
        el.innerHTML = `<i class="fa-solid ${icons[type] || 'fa-bell'}"></i><span>${message}</span><button class="alert__close" aria-label="Fermer">&times;</button>`;
        container.prepend(el);
        setupAlert(el);
        return el;
    }

    return { init, show, dismiss };
})();

/* ════════════════════════════════════════════════════════════
   MODULE : AJAX — helper fetch avec CSRF + JSON
   ════════════════════════════════════════════════════════════ */
const Api = (() => {
    function getCsrfToken() {
        return document.querySelector('input[name="_token"]')?.value
            || document.querySelector('meta[name="csrf-token"]')?.content
            || '';
    }

    async function post(url, data = {}, options = {}) {
        const isFormData = data instanceof FormData;

        if (!isFormData) {
            data['_token'] = getCsrfToken();
        } else {
            data.set('_token', getCsrfToken());
        }

        const headers = { 'X-Requested-With': 'XMLHttpRequest' };
        if (!isFormData) headers['Content-Type'] = 'application/json';

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers,
                body: isFormData ? data : JSON.stringify(data),
                ...options,
            });

            const json = await res.json();
            return { ok: res.ok, status: res.status, ...json };
        } catch (err) {
            console.error('[API] Error:', err);
            return { ok: false, success: false, message: 'Erreur réseau. Veuillez réessayer.' };
        }
    }

    async function get(url, params = {}) {
        const qs = new URLSearchParams(params).toString();
        const fullUrl = qs ? `${url}?${qs}` : url;
        try {
            const res  = await fetch(fullUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const json = await res.json();
            return { ok: res.ok, status: res.status, ...json };
        } catch (err) {
            console.error('[API] Error:', err);
            return { ok: false, success: false, message: 'Erreur réseau.' };
        }
    }

    return { post, get, getCsrfToken };
})();

/* ════════════════════════════════════════════════════════════
   MODULE : Forms — Loading state, validation UI
   ════════════════════════════════════════════════════════════ */
const Forms = (() => {
    function init() {
        // Auto focus premier champ vide
        const firstInput = document.querySelector('.form-control:not([type=hidden]):not([disabled])');
        if (firstInput && window.location.hash === '') firstInput.focus();

        // Input icon focus highlight
        document.querySelectorAll('.input-group .form-control').forEach(input => {
            const icon = input.closest('.input-group')?.querySelector('.input-group__icon');
            if (!icon) return;
            input.addEventListener('focus', () => icon.style.color = 'var(--blue-500)');
            input.addEventListener('blur',  () => icon.style.color = '');
        });
    }

    function setLoading(btn, loading, text = '') {
        if (!btn) return;
        if (loading) {
            btn._originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner" style="width:16px;height:16px;border-width:2px"></span>${text ? ' ' + text : ''}`;
        } else {
            btn.disabled = false;
            btn.innerHTML = btn._originalHTML || btn.innerHTML;
        }
    }

    function clearErrors(form) {
        form.querySelectorAll('.form-error').forEach(el => el.remove());
        form.querySelectorAll('.form-control--error').forEach(el => el.classList.remove('form-control--error'));
    }

    function showFieldError(form, fieldName, message) {
        const input = form.querySelector(`[name="${fieldName}"]`);
        if (!input) return;
        input.classList.add('form-control--error');
        const err = document.createElement('span');
        err.className = 'form-error';
        err.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i>${message}`;
        input.closest('.form-group')?.appendChild(err);
    }

    function handleApiErrors(form, errors = {}) {
        clearErrors(form);
        Object.entries(errors).forEach(([field, msgs]) => {
            const msg = Array.isArray(msgs) ? msgs[0] : msgs;
            showFieldError(form, field, msg);
        });
    }

    return { init, setLoading, clearErrors, showFieldError, handleApiErrors };
})();

/* ════════════════════════════════════════════════════════════
   MODULE : Modals
   ════════════════════════════════════════════════════════════ */
const Modal = (() => {
    function open(id)  { document.getElementById(id)?.classList.add('open'); document.body.style.overflow = 'hidden'; }
    function close(id) { document.getElementById(id)?.classList.remove('open'); document.body.style.overflow = ''; }

    function confirm(message, onConfirm, onCancel) {
        return window.confirm(message) ? (onConfirm?.(), true) : (onCancel?.(), false);
    }

    function init() {
        // [data-modal-open] triggers
        document.querySelectorAll('[data-modal-open]').forEach(btn => {
            btn.addEventListener('click', () => open(btn.dataset.modalOpen));
        });
        // Close on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', e => {
                if (e.target === overlay) close(overlay.id);
            });
        });
        // Close on Escape
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.open').forEach(m => close(m.id));
            }
        });
    }

    return { init, open, close, confirm };
})();

/* ════════════════════════════════════════════════════════════
   MODULE : Utility functions
   ════════════════════════════════════════════════════════════ */
const Utils = (() => {
    function formatBytes(bytes) {
        if (bytes < 1024)       return bytes + ' o';
        if (bytes < 1048576)    return (bytes / 1024).toFixed(1) + ' Ko';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' Mo';
        return (bytes / 1073741824).toFixed(2) + ' Go';
    }

    function formatDate(dateStr, locale = 'fr-FR') {
        return new Date(dateStr).toLocaleDateString(locale, { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
    }

    function debounce(fn, delay = 300) {
        let timer;
        return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
    }

    function throttle(fn, limit = 100) {
        let lastCall = 0;
        return (...args) => { const now = Date.now(); if (now - lastCall >= limit) { lastCall = now; fn(...args); } };
    }

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            Alerts.show('success', 'Copié dans le presse-papiers.', document.querySelector('.page-content') || document.body, 2000);
        });
    }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    function getUrlParam(name) {
        return new URLSearchParams(window.location.search).get(name);
    }

    return { formatBytes, formatDate, debounce, throttle, copyToClipboard, escapeHtml, getUrlParam };
})();

/* ════════════════════════════════════════════════════════════
   MODULE : Confirm Delete (remplace window.confirm natif)
   ════════════════════════════════════════════════════════════ */
const ConfirmDelete = (() => {
    function init() {
        document.querySelectorAll('[data-confirm]').forEach(el => {
            el.addEventListener('click', e => {
                if (!window.confirm(el.dataset.confirm || 'Confirmer cette action ?')) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        });
    }
    return { init };
})();

/* ════════════════════════════════════════════════════════════
   MODULE : Tables — sort côté client
   ════════════════════════════════════════════════════════════ */
const TableSort = (() => {
    function init() {
        document.querySelectorAll('[data-sortable]').forEach(table => {
            table.querySelectorAll('thead th[data-col]').forEach((th, idx) => {
                th.style.cursor = 'pointer';
                th.title = 'Cliquer pour trier';
                th.innerHTML += ' <i class="fa-solid fa-sort" style="opacity:.3;font-size:10px"></i>';
                th.addEventListener('click', () => sortTable(table, idx, th));
            });
        });
    }

    function sortTable(table, colIdx, th) {
        const tbody = table.querySelector('tbody');
        const rows  = [...tbody.querySelectorAll('tr')];
        const asc   = th.dataset.sortDir !== 'asc';
        th.dataset.sortDir = asc ? 'asc' : 'desc';

        // Reset other headers
        table.querySelectorAll('thead th i').forEach(i => i.style.opacity = '.3');
        th.querySelector('i').style.opacity = '1';
        th.querySelector('i').className = `fa-solid fa-sort-${asc ? 'up' : 'down'}`;

        rows.sort((a, b) => {
            const va = a.cells[colIdx]?.textContent.trim() || '';
            const vb = b.cells[colIdx]?.textContent.trim() || '';
            const na = parseFloat(va.replace(/[^0-9.-]/g,''));
            const nb = parseFloat(vb.replace(/[^0-9.-]/g,''));
            if (!isNaN(na) && !isNaN(nb)) return asc ? na - nb : nb - na;
            return asc ? va.localeCompare(vb, 'fr') : vb.localeCompare(va, 'fr');
        });

        rows.forEach(r => tbody.appendChild(r));
    }

    return { init };
})();

/* ════════════════════════════════════════════════════════════
   MODULE : Notification bubble (topbar)
   ════════════════════════════════════════════════════════════ */
const Notifications = (() => {
    function init() {
        const btn = document.getElementById('notifBtn');
        if (!btn) return;
        btn.addEventListener('click', () => {
            Alerts.show('info', 'Pas de nouvelle notification.', document.querySelector('.page-content') || document.body, 3000);
        });
    }
    return { init };
})();

/* ════════════════════════════════════════════════════════════
   MODULE : Password toggle (eye icon)
   ════════════════════════════════════════════════════════════ */
const PasswordToggle = (() => {
    function init() {
        document.querySelectorAll('[data-toggle-pwd]').forEach(btn => {
            const target = document.getElementById(btn.dataset.togglePwd);
            if (!target) return;
            btn.addEventListener('click', () => {
                const show = target.type === 'password';
                target.type = show ? 'text' : 'password';
                btn.querySelector('i').className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
            });
        });
    }
    return { init };
})();

/* ════════════════════════════════════════════════════════════
   INIT
   ════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    Sidebar.init();
    Alerts.init();
    Forms.init();
    Modal.init();
    ConfirmDelete.init();
    TableSort.init();
    Notifications.init();
    PasswordToggle.init();
});

// Exposer globalement pour les pages spécifiques
window.PulmoCare = { Api, Alerts, Forms, Modal, Utils, Sidebar };
