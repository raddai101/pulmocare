<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/functions/functions.php';

auth_require('medecin');

$user       = auth_current_user();
$userId     = (int)$user['id'];
$userFull   = user_get_with_hospital($userId);
$stats      = user_get_stats($userId);
$recent     = detection_get_recent($userId, 6);
$globalStats= detection_get_global_stats();

$currentFile = basename(__FILE__);
$pageTitle   = html_page_title('Tableau de bord');
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
    <link rel="stylesheet" href="/pulmocare/assets/css/dashboard.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg-base:   #060d1a;
            --bg-card:   #0b1629;
            --bg-hover:  #0f1e38;
            --bg-input:  #0f1e38;
            --border:    rgba(59,130,246,.14);
            --blue-500:  #3b82f6;
            --blue-600:  #2563eb;
            --blue-glow: rgba(59,130,246,.3);
            --indigo:    #6366f1;
            --green:     #22c55e;
            --amber:     #f59e0b;
            --red:       #ef4444;
            --text-1:    #e8edf8;
            --text-2:    #7f93b4;
            --text-3:    #4a607a;
            --radius:    12px;
            --sidebar-w: 260px;
            --header-h:  70px;
            --tr: .22s ease;
        }
        body { background: var(--bg-base); color: var(--text-1); font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; overflow-x: hidden; }

        /* ═══ SIDEBAR ═════════════════════════════════════════════ */
        .sidebar {
            width: var(--sidebar-w);
            background: var(--bg-card);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 100;
            transition: transform var(--tr);
        }

        .sidebar__logo {
            padding: 24px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--border);
        }

        .sidebar__logo-icon {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, var(--blue-500), var(--indigo));
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            box-shadow: 0 4px 14px var(--blue-glow);
        }

        .sidebar__logo-text {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -.4px;
            line-height: 1.1;
        }
        .sidebar__logo-text span { display: block; font-size: 10px; font-weight: 400; color: var(--text-2); letter-spacing: 1px; text-transform: uppercase; }

        .sidebar__nav { flex: 1; padding: 20px 12px; display: flex; flex-direction: column; gap: 4px; overflow-y: auto; }

        .nav-section-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-3);
            padding: 14px 10px 6px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 9px;
            text-decoration: none;
            color: var(--text-2);
            font-size: 13.5px;
            font-weight: 500;
            transition: background var(--tr), color var(--tr);
            position: relative;
        }

        .nav-link i { width: 18px; text-align: center; font-size: 15px; }
        .nav-link:hover { background: var(--bg-hover); color: var(--text-1); }

        .nav-link.active {
            background: rgba(59,130,246,.14);
            color: var(--blue-500);
        }

        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0; top: 50%;
            transform: translateY(-50%);
            width: 3px; height: 60%;
            background: var(--blue-500);
            border-radius: 0 3px 3px 0;
        }

        .nav-badge {
            margin-left: auto;
            background: var(--blue-500);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 20px;
        }

        .sidebar__user {
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar__avatar {
            width: 38px; height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(59,130,246,.3);
        }

        .sidebar__user-info { flex: 1; min-width: 0; }
        .sidebar__user-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar__user-role { font-size: 11px; color: var(--text-2); }

        .sidebar__logout {
            color: var(--text-3);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            padding: 6px;
            border-radius: 7px;
            transition: color var(--tr), background var(--tr);
        }
        .sidebar__logout:hover { color: var(--red); background: rgba(239,68,68,.1); }

        /* ═══ MAIN ════════════════════════════════════════════════ */
        .main {
            margin-left: var(--sidebar-w);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ═══ TOPBAR ══════════════════════════════════════════════ */
        .topbar {
            height: var(--header-h);
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .topbar__title h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 19px;
            font-weight: 600;
            letter-spacing: -.3px;
        }

        .topbar__title p { font-size: 12.5px; color: var(--text-2); margin-top: 2px; }

        .topbar__actions { display: flex; align-items: center; gap: 10px; }

        .topbar-btn {
            width: 38px; height: 38px;
            border-radius: 9px;
            background: var(--bg-hover);
            border: 1px solid var(--border);
            color: var(--text-2);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 15px;
            text-decoration: none;
            transition: all var(--tr);
            position: relative;
        }
        .topbar-btn:hover { color: var(--text-1); border-color: var(--blue-500); }

        .btn-primary {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            background: linear-gradient(135deg, var(--blue-500), var(--indigo));
            border: none;
            border-radius: 9px;
            color: #fff;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: opacity var(--tr), transform var(--tr);
            box-shadow: 0 4px 14px var(--blue-glow);
        }
        .btn-primary:hover { opacity: .9; transform: translateY(-1px); }

        /* ═══ CONTENT ═════════════════════════════════════════════ */
        .content { padding: 32px; flex: 1; }

        /* ═══ KPI GRID ════════════════════════════════════════════ */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 28px;
        }

        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 22px 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: border-color var(--tr), transform var(--tr);
            position: relative;
            overflow: hidden;
        }

        .kpi-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
        }

        .kpi-card--blue::after   { background: var(--blue-500); }
        .kpi-card--green::after  { background: var(--green); }
        .kpi-card--amber::after  { background: var(--amber); }
        .kpi-card--red::after    { background: var(--red); }

        .kpi-card:hover { border-color: rgba(59,130,246,.3); transform: translateY(-2px); }

        .kpi-card__header { display: flex; align-items: center; justify-content: space-between; }
        .kpi-card__label  { font-size: 12px; font-weight: 500; color: var(--text-2); text-transform: uppercase; letter-spacing: .6px; }

        .kpi-card__icon {
            width: 36px; height: 36px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .kpi-card--blue .kpi-card__icon   { background: rgba(59,130,246,.12); color: var(--blue-500); }
        .kpi-card--green .kpi-card__icon  { background: rgba(34,197,94,.12);  color: var(--green); }
        .kpi-card--amber .kpi-card__icon  { background: rgba(245,158,11,.12); color: var(--amber); }
        .kpi-card--red .kpi-card__icon    { background: rgba(239,68,68,.12);  color: var(--red); }

        .kpi-card__value { font-family: 'Space Grotesk', sans-serif; font-size: 34px; font-weight: 700; letter-spacing: -1px; line-height: 1; }
        .kpi-card__sub   { font-size: 12px; color: var(--text-2); }

        /* ═══ TWO-COL GRID ════════════════════════════════════════ */
        .dash-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 20px;
            margin-bottom: 24px;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .card-header {
            padding: 18px 22px 14px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header h3 { font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 600; letter-spacing: -.2px; }
        .card-header a  { font-size: 12px; color: var(--blue-500); text-decoration: none; }
        .card-header a:hover { text-decoration: underline; }

        .card-body { padding: 20px 22px; }

        /* ─── Detection table ─── */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { padding: 0 14px 10px; text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: var(--text-2); white-space: nowrap; }
        tbody tr { border-top: 1px solid var(--border); transition: background var(--tr); }
        tbody tr:hover { background: var(--bg-hover); }
        tbody td { padding: 13px 14px; vertical-align: middle; white-space: nowrap; }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 600;
        }
        .badge--normal   { background: rgba(34,197,94,.12);  color: var(--green); }
        .badge--suspect  { background: rgba(245,158,11,.12); color: var(--amber); }
        .badge--cancereux{ background: rgba(239,68,68,.12);  color: var(--red); }
        .badge--inconnu  { background: rgba(107,114,128,.12);color: #9ca3af; }

        .action-btn { color: var(--text-2); text-decoration: none; padding: 4px 8px; border-radius: 6px; font-size: 13px; transition: all var(--tr); }
        .action-btn:hover { color: var(--blue-500); background: rgba(59,130,246,.08); }

        .empty-state { text-align: center; padding: 48px 20px; color: var(--text-2); }
        .empty-state i { font-size: 40px; margin-bottom: 12px; opacity: .4; }
        .empty-state p { font-size: 14px; }

        /* ─── Donut chart panel ─── */
        .donut-wrap { display: flex; align-items: center; gap: 20px; flex-direction: column; }
        .donut-canvas { max-width: 200px; }
        .donut-legend { width: 100%; display: flex; flex-direction: column; gap: 10px; }
        .legend-item { display: flex; align-items: center; justify-content: space-between; font-size: 13px; }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; margin-right: 8px; }
        .legend-label { display: flex; align-items: center; flex: 1; color: var(--text-2); }
        .legend-val { font-weight: 600; }

        /* ─── Progress bar ─── */
        .progress-row { margin-bottom: 14px; }
        .progress-header { display: flex; justify-content: space-between; font-size: 12.5px; margin-bottom: 5px; }
        .progress-header span:first-child { color: var(--text-2); }
        .progress-bar { height: 6px; background: var(--bg-hover); border-radius: 99px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 99px; transition: width .8s ease; }

        /* Flash alerts */
        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 9px; font-size: 13.5px; margin-bottom: 20px; }
        .alert--success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.25); color: #4ade80; }
        .alert--error   { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); color: #f87171; }
        .alert--warning { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.25); color: #fbbf24; }

        @media (max-width: 1200px) {
            .kpi-grid    { grid-template-columns: repeat(2, 1fr); }
            .dash-grid   { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main    { margin-left: 0; }
            .content { padding: 20px 16px; }
            .kpi-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ═══════════════ SIDEBAR ═══════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar__logo">
        <div class="sidebar__logo-icon"><i class="fa-solid fa-lungs"></i></div>
        <div class="sidebar__logo-text">
            PulmoCare IA
            <span>v1.0 — Médical</span>
        </div>
    </div>

    <nav class="sidebar__nav">
        <span class="nav-section-label">Principal</span>

        <a href="/pulmocare/auth/dashboard.php" class="nav-link <?= html_active_class('dashboard.php', $currentFile) ?>">
            <i class="fa-solid fa-gauge-high"></i> Tableau de bord
        </a>

        <a href="/pulmocare/pages/detection.php" class="nav-link <?= html_active_class('detection.php', $currentFile) ?>">
            <i class="fa-solid fa-magnifying-glass-plus"></i> Nouvelle analyse
        </a>

        <a href="/pulmocare/pages/resultats.php" class="nav-link <?= html_active_class('resultats.php', $currentFile) ?>">
            <i class="fa-solid fa-folder-open"></i> Mes analyses
            <?php if (($stats['total_analyses'] ?? 0) > 0): ?>
            <span class="nav-badge"><?= (int)$stats['total_analyses'] ?></span>
            <?php endif; ?>
        </a>

        <span class="nav-section-label">Compte</span>

        <a href="/pulmocare/pages/profil.php" class="nav-link <?= html_active_class('profil.php', $currentFile) ?>">
            <i class="fa-solid fa-user-doctor"></i> Mon profil
        </a>

        <a href="/pulmocare/auth/logout.php" class="nav-link" onclick="return confirm('Se déconnecter ?')">
            <i class="fa-solid fa-right-from-bracket"></i> Déconnexion
        </a>
    </nav>

    <div class="sidebar__user">
        <img
            src="<?= htmlspecialchars(html_avatar_url($user['avatar'] ?? null)) ?>"
            alt="Avatar"
            class="sidebar__avatar"
        >
        <div class="sidebar__user-info">
            <div class="sidebar__user-name">Dr. <?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></div>
            <div class="sidebar__user-role"><?= htmlspecialchars($user['specialite'] ?? 'Médecin') ?></div>
        </div>
        <a href="/pulmocare/auth/logout.php" class="sidebar__logout" title="Déconnexion" onclick="return confirm('Se déconnecter ?')">
            <i class="fa-solid fa-power-off"></i>
        </a>
    </div>
</aside>

<!-- ═══════════════ MAIN ══════════════════════════════════════ -->
<div class="main">

    <!-- TOPBAR -->
    <header class="topbar">
        <div class="topbar__title">
            <h2>Tableau de bord</h2>
            <p><?= date('l d F Y') ?> — Bienvenue, Dr. <?= htmlspecialchars($user['prenom']) ?></p>
        </div>
        <div class="topbar__actions">
            <a href="/pulmocare/pages/detection.php" class="btn-primary">
                <i class="fa-solid fa-plus"></i> Nouvelle analyse
            </a>
        </div>
    </header>

    <!-- CONTENT -->
    <main class="content">

        <?= html_flash() ?>

        <!-- ─── KPI Cards ─── -->
        <div class="kpi-grid">
            <div class="kpi-card kpi-card--blue">
                <div class="kpi-card__header">
                    <span class="kpi-card__label">Total analyses</span>
                    <div class="kpi-card__icon"><i class="fa-solid fa-microscope"></i></div>
                </div>
                <div class="kpi-card__value"><?= number_format((int)($stats['total_analyses'] ?? 0)) ?></div>
                <div class="kpi-card__sub">Depuis votre inscription</div>
            </div>

            <div class="kpi-card kpi-card--green">
                <div class="kpi-card__header">
                    <span class="kpi-card__label">Résultats normaux</span>
                    <div class="kpi-card__icon"><i class="fa-solid fa-circle-check"></i></div>
                </div>
                <div class="kpi-card__value" style="color:var(--green)"><?= (int)($stats['total_normaux'] ?? 0) ?></div>
                <div class="kpi-card__sub">CT Scan sans anomalie</div>
            </div>

            <div class="kpi-card kpi-card--amber">
                <div class="kpi-card__header">
                    <span class="kpi-card__label">Cas suspects</span>
                    <div class="kpi-card__icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                </div>
                <div class="kpi-card__value" style="color:var(--amber)"><?= (int)($stats['total_suspects'] ?? 0) ?></div>
                <div class="kpi-card__sub">Anomalie à surveiller</div>
            </div>

            <div class="kpi-card kpi-card--red">
                <div class="kpi-card__header">
                    <span class="kpi-card__label">Cas cancéreux</span>
                    <div class="kpi-card__icon"><i class="fa-solid fa-circle-xmark"></i></div>
                </div>
                <div class="kpi-card__value" style="color:var(--red)"><?= (int)($stats['total_cancereux'] ?? 0) ?></div>
                <div class="kpi-card__sub">Détection maligne</div>
            </div>
        </div>

        <!-- ─── Two-column section ─── -->
        <div class="dash-grid">

            <!-- Analyses récentes -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fa-solid fa-clock-rotate-left" style="color:var(--blue-500);margin-right:8px"></i>Analyses récentes</h3>
                    <a href="/pulmocare/pages/resultats.php">Voir tout <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="card-body" style="padding:0">
                    <?php if (empty($recent)): ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-folder-open"></i>
                        <p>Aucune analyse effectuée pour le moment.</p>
                        <a href="/pulmocare/pages/detection.php" class="btn-primary" style="margin-top:16px;display:inline-flex">
                            <i class="fa-solid fa-plus"></i> Lancer une analyse
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Date</th>
                                    <th>Résultat</th>
                                    <th>Confiance</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent as $det): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($det['patient_prenom'] . ' ' . $det['patient_nom']) ?></strong>
                                        <?php if ($det['patient_age']): ?>
                                        <br><small style="color:var(--text-2)"><?= (int)$det['patient_age'] ?> ans</small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:var(--text-2)"><?= html_format_date($det['created_at'], 'd/m/Y') ?></td>
                                    <td><?= html_result_badge($det['result_type']) ?></td>
                                    <td>
                                        <span style="font-weight:600;color:<?= ai_get_result_color($det['result_type']) ?>">
                                            <?= number_format((float)$det['confidence_score'], 1) ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <a href="/pulmocare/pages/resultats.php?id=<?= (int)$det['id'] ?>" class="action-btn" title="Voir détail">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Répartition des résultats -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fa-solid fa-chart-pie" style="color:var(--indigo);margin-right:8px"></i>Répartition</h3>
                </div>
                <div class="card-body">
                    <?php
                    $total    = max(1, (int)($stats['total_analyses'] ?? 1));
                    $normaux  = (int)($stats['total_normaux'] ?? 0);
                    $suspects = (int)($stats['total_suspects'] ?? 0);
                    $cancer   = (int)($stats['total_cancereux'] ?? 0);
                    ?>

                    <canvas id="donutChart" class="donut-canvas" style="margin:0 auto 20px;display:block"></canvas>

                    <div class="donut-legend">
                        <div class="legend-item">
                            <div class="legend-label"><span class="legend-dot" style="background:var(--green)"></span>Normaux</div>
                            <strong class="legend-val"><?= $normaux ?></strong>
                        </div>
                        <div class="legend-item">
                            <div class="legend-label"><span class="legend-dot" style="background:var(--amber)"></span>Suspects</div>
                            <strong class="legend-val"><?= $suspects ?></strong>
                        </div>
                        <div class="legend-item">
                            <div class="legend-label"><span class="legend-dot" style="background:var(--red)"></span>Cancéreux</div>
                            <strong class="legend-val"><?= $cancer ?></strong>
                        </div>
                    </div>

                    <div style="margin-top:24px">
                        <div class="progress-row">
                            <div class="progress-header"><span>Confiance IA moyenne</span><span style="font-weight:600"><?= number_format((float)($stats['confidence_moyenne'] ?? 0), 1) ?>%</span></div>
                            <div class="progress-bar"><div class="progress-fill" style="width:<?= min(100,(float)($stats['confidence_moyenne'] ?? 0)) ?>%;background:var(--blue-500)"></div></div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /dash-grid -->

    </main>
</div><!-- /main -->

<script>
// ── Donut chart ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('donutChart')?.getContext('2d');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Normaux', 'Suspects', 'Cancéreux'],
            datasets: [{
                data: [<?= $normaux ?>, <?= $suspects ?>, <?= $cancer ?>],
                backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
                borderWidth: 0,
                hoverOffset: 6,
            }]
        },
        options: {
            responsive: true,
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.label} : ${ctx.parsed} (${Math.round(ctx.parsed / <?= $total ?> * 100)}%)`
                    }
                }
            }
        }
    });
});
</script>

</body>
</html>
