/**
 * auth.js — PulmoCare IA
 * Interactions formulaires d'authentification
 * Login, inscription, mot de passe, indicateur de force
 */

'use strict';

const Auth = (() => {

    // ── Password visibility toggle ────────────────────────────
    function initPasswordToggles() {
        document.querySelectorAll('[data-toggle-pwd]').forEach(btn => {
            const targetId = btn.dataset.togglePwd;
            const input    = document.getElementById(targetId);
            if (!input) return;

            btn.addEventListener('click', () => {
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                const icon = btn.querySelector('i');
                if (icon) icon.className = isPassword ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
                btn.setAttribute('aria-label', isPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
            });
        });
    }

    // ── Password strength indicator ───────────────────────────
    function initPasswordStrength() {
        const inputs = document.querySelectorAll('[data-pwd-strength]');
        inputs.forEach(input => {
            const targetId = input.dataset.pwdStrength;
            const bar      = document.getElementById(targetId + '-bar');
            const label    = document.getElementById(targetId + '-label');
            const criteria = {
                len:   document.getElementById('c-len'),
                upper: document.getElementById('c-upper'),
                lower: document.getElementById('c-lower'),
                num:   document.getElementById('c-num'),
                spec:  document.getElementById('c-spec'),
            };

            input.addEventListener('input', () => {
                const v = input.value;
                const checks = {
                    len:   v.length >= 8,
                    upper: /[A-Z]/.test(v),
                    lower: /[a-z]/.test(v),
                    num:   /[0-9]/.test(v),
                    spec:  /[^A-Za-z0-9]/.test(v),
                };

                const score    = Object.values(checks).filter(Boolean).length;
                const pct      = score * 20;
                const colors   = ['', '#ef4444', '#f59e0b', '#f59e0b', '#22c55e', '#22c55e'];
                const labels   = ['', 'Très faible', 'Faible', 'Moyen', 'Fort', 'Très fort'];

                if (bar)   { bar.style.width = pct + '%'; bar.style.background = colors[score] || '#ef4444'; bar.style.transition = 'width .35s, background .35s'; }
                if (label) { label.textContent = v.length ? (labels[score] || '') : ''; label.style.color = colors[score] || '#ef4444'; }

                // Critères individuels
                Object.entries(criteria).forEach(([key, el]) => {
                    if (!el) return;
                    el.style.color = checks[key] ? '#22c55e' : '';
                    el.style.textDecoration = checks[key] ? 'line-through' : '';
                });
            });
        });
    }

    // ── Password confirmation match ───────────────────────────
    function initPasswordConfirm() {
        const pwd     = document.getElementById('newPwd') || document.getElementById('password');
        const confirm = document.getElementById('confirmPwd') || document.getElementById('password_confirmation');
        if (!pwd || !confirm) return;

        confirm.addEventListener('input', () => {
            if (!confirm.value) { confirm.style.borderColor = ''; return; }
            const match = pwd.value === confirm.value;
            confirm.style.borderColor = match ? 'var(--green)' : 'var(--red)';
        });
    }

    // ── Login form — loading state ────────────────────────────
    function initLoginForm() {
        const form = document.getElementById('loginForm');
        const btn  = document.getElementById('loginBtn');
        if (!form || !btn) return;

        form.addEventListener('submit', () => {
            const email = form.querySelector('[name="email"]')?.value.trim();
            const pwd   = form.querySelector('[name="password"]')?.value;
            if (email && pwd) {
                btn.disabled = true;
                const txt = btn.querySelector('.btn-text') || btn;
                if (btn.querySelector('.btn-text')) {
                    btn.querySelector('.btn-text').style.display = 'none';
                    const spin = btn.querySelector('.btn-spinner');
                    if (spin) spin.style.display = 'inline-flex';
                } else {
                    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Connexion…';
                }
            }
        });

        // Highlight icône au focus
        form.querySelectorAll('.input-group .form-control').forEach(input => {
            const icon = input.closest('.input-group')?.querySelector('.input-group__icon');
            if (!icon) return;
            input.addEventListener('focus', () => icon.style.color = 'var(--blue-500)');
            input.addEventListener('blur',  () => icon.style.color = '');
        });
    }

    // ── Register form — hôpitaux dynamiques ──────────────────
    async function loadHospitals() {
        const select = document.getElementById('hospitalSelect');
        if (!select || !window.PulmoCare?.Api) return;

        const res = await window.PulmoCare.Api.get('/backend/api/hospitals.php');
        if (!res.success || !res.data?.hospitals?.length) return;

        res.data.hospitals.forEach(h => {
            const opt = document.createElement('option');
            opt.value       = h.id;
            opt.textContent = `${h.nom} — ${h.ville}`;
            select.appendChild(opt);
        });
    }

    // ── Capslock warning ──────────────────────────────────────
    function initCapslockWarning() {
        document.querySelectorAll('input[type="password"]').forEach(input => {
            input.addEventListener('keyup', e => {
                const warn = input.parentElement?.querySelector('.capslock-warn');
                if (e.getModifierState && e.getModifierState('CapsLock')) {
                    if (!warn) {
                        const el = document.createElement('span');
                        el.className = 'capslock-warn';
                        el.style.cssText = 'font-size:11px;color:#f59e0b;margin-top:4px;display:flex;align-items:center;gap:4px';
                        el.innerHTML = '<i class="fa-solid fa-lock-open"></i> Majuscules activées';
                        input.parentElement.appendChild(el);
                    }
                } else {
                    warn?.remove();
                }
            });
        });
    }

    // ── Init ──────────────────────────────────────────────────
    function init() {
        initPasswordToggles();
        initPasswordStrength();
        initPasswordConfirm();
        initLoginForm();
        initCapslockWarning();
        loadHospitals();
    }

    return { init };
})();

document.addEventListener('DOMContentLoaded', Auth.init);
