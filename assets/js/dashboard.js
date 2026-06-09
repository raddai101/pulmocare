/**
 * dashboard.js — PulmoCare IA
 * Charts Chart.js, widgets interactifs du tableau de bord
 */

'use strict';

const Dashboard = (() => {

    // ── Donut chart répartition résultats ─────────────────────
    function initDonutChart() {
        const canvas = document.getElementById('donutChart');
        if (!canvas || typeof Chart === 'undefined') return;

        const normaux  = parseInt(canvas.dataset.normaux  || '0');
        const suspects = parseInt(canvas.dataset.suspects || '0');
        const cancer   = parseInt(canvas.dataset.cancer   || '0');
        const total    = normaux + suspects + cancer || 1;

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: ['Normaux', 'Suspects', 'Cancéreux'],
                datasets: [{
                    data: [normaux, suspects, cancer],
                    backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
                    borderWidth: 0,
                    hoverOffset: 8,
                }],
            },
            options: {
                responsive: true,
                cutout: '74%',
                animation: { animateRotate: true, animateScale: false, duration: 900, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0b1629',
                        borderColor: 'rgba(59,130,246,.2)',
                        borderWidth: 1,
                        titleColor: '#e8edf8',
                        bodyColor: '#7f93b4',
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: ctx => {
                                const pct = Math.round(ctx.parsed / total * 100);
                                return `  ${ctx.label} : ${ctx.parsed} (${pct}%)`;
                            },
                        },
                    },
                },
            },
        });
    }

    // ── Line chart évolution mensuelle ───────────────────────
    function initLineChart() {
        const canvas = document.getElementById('lineChart');
        if (!canvas || typeof Chart === 'undefined') return;

        let monthly = [];
        try { monthly = JSON.parse(canvas.dataset.monthly || '[]'); } catch { return; }

        if (!monthly.length) return;

        const labels = monthly.map(m => {
            const [y, mo] = m.mois.split('-');
            return new Date(y, mo - 1).toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' });
        });

        new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Analyses',
                    data: monthly.map(m => parseInt(m.total)),
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,.08)',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#3b82f6',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.4,
                }],
            },
            options: {
                responsive: true,
                animation: { duration: 900, easing: 'easeOutQuart' },
                scales: {
                    x: {
                        grid: { color: 'rgba(59,130,246,.08)', drawBorder: false },
                        ticks: { color: '#7f93b4', font: { size: 11 } },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(59,130,246,.08)', drawBorder: false },
                        ticks: { color: '#7f93b4', font: { size: 11 }, stepSize: 1 },
                    },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0b1629',
                        borderColor: 'rgba(59,130,246,.2)',
                        borderWidth: 1,
                        titleColor: '#e8edf8',
                        bodyColor: '#7f93b4',
                        padding: 12,
                        cornerRadius: 8,
                    },
                },
            },
        });
    }

    // ── Bar chart résultats par type ─────────────────────────
    function initBarChart() {
        const canvas = document.getElementById('barChart');
        if (!canvas || typeof Chart === 'undefined') return;

        const labels = ['Normal', 'Suspect', 'Cancéreux'];
        const data   = [
            parseInt(canvas.dataset.normaux  || '0'),
            parseInt(canvas.dataset.suspects || '0'),
            parseInt(canvas.dataset.cancer   || '0'),
        ];

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: ['rgba(34,197,94,.7)', 'rgba(245,158,11,.7)', 'rgba(239,68,68,.7)'],
                    borderRadius: 6,
                    borderSkipped: false,
                }],
            },
            options: {
                responsive: true,
                animation: { duration: 800 },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#7f93b4' } },
                    y: { beginAtZero: true, grid: { color: 'rgba(59,130,246,.06)' }, ticks: { color: '#7f93b4', stepSize: 1 } },
                },
                plugins: { legend: { display: false } },
            },
        });
    }

    // ── KPI counter animation ─────────────────────────────────
    function animateCounters() {
        document.querySelectorAll('[data-count]').forEach(el => {
            const target = parseInt(el.dataset.count) || 0;
            const duration = 800;
            const step = target / (duration / 16);
            let current = 0;

            const timer = setInterval(() => {
                current = Math.min(current + step, target);
                el.textContent = Math.floor(current).toLocaleString('fr-FR');
                if (current >= target) clearInterval(timer);
            }, 16);
        });
    }

    // ── Quick action cards hover effect ──────────────────────
    function initQuickActions() {
        document.querySelectorAll('.quick-action').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px)';
                this.style.boxShadow = '0 12px 30px rgba(59,130,246,.15)';
            });
            card.addEventListener('mouseleave', function() {
                this.style.transform = '';
                this.style.boxShadow = '';
            });
        });
    }

    // ── Live clock in topbar ──────────────────────────────────
    function initClock() {
        const el = document.getElementById('liveClock');
        if (!el) return;

        function tick() {
            el.textContent = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        }
        tick();
        setInterval(tick, 1000);
    }

    // ── Refresh stats via AJAX (optionnel) ───────────────────
    async function refreshStats() {
        if (!window.PulmoCare?.Api) return;
        const res = await window.PulmoCare.Api.get('/backend/api/stats.php');
        if (!res.success) return;
        const s = res.data?.stats || {};
        updateKpi('kpiTotal',    s.total_analyses);
        updateKpi('kpiNormaux',  s.total_normaux);
        updateKpi('kpiSuspects', s.total_suspects);
        updateKpi('kpiCancer',   s.total_cancereux);
    }

    function updateKpi(id, value) {
        const el = document.getElementById(id);
        if (el && value !== undefined) el.textContent = parseInt(value).toLocaleString('fr-FR');
    }

    // ── Init ──────────────────────────────────────────────────
    function init() {
        initDonutChart();
        initLineChart();
        initBarChart();
        animateCounters();
        initQuickActions();
        initClock();
    }

    return { init, refreshStats };
})();

document.addEventListener('DOMContentLoaded', Dashboard.init);
