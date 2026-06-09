<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/functions/functions.php';

auth_require('medecin');

$user   = auth_current_user();
$userId = (int)$user['id'];

$detailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$detail   = null;

if ($detailId > 0) {
    $detail = detection_get($detailId);
    if (!$detail || (int)$detail['user_id'] !== $userId) {
        html_set_flash('error', 'Analyse introuvable ou accès refusé.');
        response_redirect('/pages/resultats.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!security_verify_csrf($_POST['_token'] ?? '')) {
        html_set_flash('error', 'Requête invalide.');
        response_redirect('/pages/resultats.php');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $r = detection_delete((int)($_POST['detection_id'] ?? 0), $userId);
        html_set_flash($r['success'] ? 'success' : 'error', $r['message']);
        response_redirect('/pages/resultats.php');
    }
    if ($action === 'review') {
        detection_mark_reviewed((int)($_POST['detection_id'] ?? 0), security_sanitize($_POST['notes'] ?? ''));
        html_set_flash('success', 'Annotation enregistrée.');
        response_redirect('/pages/resultats.php?id=' . (int)$_POST['detection_id']);
    }
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$filters = array_map('trim', [
    'result_type' => $_GET['result_type'] ?? '',
    'date_from'   => $_GET['date_from']   ?? '',
    'date_to'     => $_GET['date_to']     ?? '',
    'patient'     => $_GET['patient']     ?? '',
    'stage'       => $_GET['stage']       ?? '',
]);

$paginator   = detection_search(array_filter($filters, fn($v) => $v !== ''), $userId, $page);
$stats       = user_get_stats($userId);
$pageTitle   = $detail ? html_page_title('Analyse #'.$detailId) : html_page_title('Mes analyses');
$currentFile = basename(__FILE__);
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $pageTitle ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg-base:#060d1a;--bg-card:#0b1629;--bg-hover:#0f1e38;--bg-input:#0f1e38;
  --border:rgba(59,130,246,.14);--border-focus:#3b82f6;
  --blue-500:#3b82f6;--blue-glow:rgba(59,130,246,.3);--indigo:#6366f1;
  --green:#22c55e;--amber:#f59e0b;--red:#ef4444;
  --text-1:#e8edf8;--text-2:#7f93b4;--text-3:#4a607a;
  --radius:12px;--sidebar-w:260px;--header-h:70px;--tr:.22s ease;
}
body{background:var(--bg-base);color:var(--text-1);font-family:'Inter',sans-serif;display:flex;min-height:100vh}
.sidebar{width:var(--sidebar-w);background:var(--bg-card);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100}
.sidebar__logo{padding:24px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border)}
.sidebar__logo-icon{width:42px;height:42px;background:linear-gradient(135deg,var(--blue-500),var(--indigo));border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;box-shadow:0 4px 14px var(--blue-glow)}
.sidebar__logo-text{font-family:'Space Grotesk',sans-serif;font-size:17px;font-weight:700;letter-spacing:-.4px;line-height:1.1}
.sidebar__logo-text span{display:block;font-size:10px;font-weight:400;color:var(--text-2);letter-spacing:1px;text-transform:uppercase}
.sidebar__nav{flex:1;padding:20px 12px;display:flex;flex-direction:column;gap:4px;overflow-y:auto}
.nav-section-label{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:14px 10px 6px}
.nav-link{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:9px;text-decoration:none;color:var(--text-2);font-size:13.5px;font-weight:500;transition:background var(--tr),color var(--tr);position:relative}
.nav-link i{width:18px;text-align:center;font-size:15px}
.nav-link:hover{background:var(--bg-hover);color:var(--text-1)}
.nav-link.active{background:rgba(59,130,246,.14);color:var(--blue-500)}
.nav-link.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:60%;background:var(--blue-500);border-radius:0 3px 3px 0}
.sidebar__user{padding:16px 20px;border-top:1px solid var(--border);display:flex;align-items:center;gap:12px}
.sidebar__avatar{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(59,130,246,.3)}
.sidebar__user-info{flex:1;min-width:0}
.sidebar__user-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sidebar__user-role{font-size:11px;color:var(--text-2)}
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{height:var(--header-h);background:var(--bg-card);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 32px;position:sticky;top:0;z-index:50}
.topbar h2{font-family:'Space Grotesk',sans-serif;font-size:19px;font-weight:600;letter-spacing:-.3px;display:flex;align-items:center;gap:10px}
.topbar p{font-size:12.5px;color:var(--text-2);margin-top:2px}
.content{padding:32px;flex:1}
.btn-primary{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:linear-gradient(135deg,var(--blue-500),var(--indigo));border:none;border-radius:9px;color:#fff;font-size:13.5px;font-weight:600;text-decoration:none;cursor:pointer;transition:opacity var(--tr);box-shadow:0 4px 14px var(--blue-glow);font-family:inherit}
.btn-primary:hover{opacity:.9}
.btn-secondary{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;background:var(--bg-hover);border:1px solid var(--border);border-radius:9px;color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;cursor:pointer;transition:all var(--tr);font-family:inherit}
.btn-secondary:hover{color:var(--text-1);border-color:rgba(59,130,246,.4)}
.alert{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:9px;font-size:13.5px;margin-bottom:20px}
.alert--success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:#4ade80}
.alert--error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#f87171}
.alert--info{background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);color:#60a5fa}
.alert--warning{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);color:#fbbf24}
.alert__close{margin-left:auto;background:none;border:none;color:inherit;cursor:pointer;font-size:16px;opacity:.6}
/* Stats row */
.stats-row{display:flex;gap:14px;margin-bottom:22px;flex-wrap:wrap}
.stat-chip{background:var(--bg-card);border:1px solid var(--border);border-radius:9px;padding:12px 18px;display:flex;align-items:center;gap:10px;font-size:13px}
.stat-chip__dot{width:8px;height:8px;border-radius:50%}
.stat-chip__val{font-weight:700;font-size:18px;font-family:'Space Grotesk',sans-serif}
.stat-chip__label{color:var(--text-2);font-size:12px}
/* Filter bar */
.filter-bar{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:18px 22px;margin-bottom:22px}
.filter-bar form{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
.filter-group{display:flex;flex-direction:column;gap:5px;min-width:130px;flex:1}
.filter-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.7px;color:var(--text-2)}
.filter-control{background:var(--bg-input);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text-1);font-size:13px;font-family:inherit;outline:none;transition:border-color var(--tr)}
.filter-control:focus{border-color:var(--border-focus)}
/* Table card */
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.card-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.card-header h3{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:600;display:flex;align-items:center;gap:8px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px}
thead th{padding:0 16px 12px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-2);white-space:nowrap}
thead th:first-child{padding-left:22px}
tbody tr{border-top:1px solid var(--border);transition:background var(--tr)}
tbody tr:hover{background:var(--bg-hover)}
tbody td{padding:14px 16px;vertical-align:middle}
tbody td:first-child{padding-left:22px}
.patient-cell strong{display:block;font-size:14px}
.patient-cell span{font-size:12px;color:var(--text-2)}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600}
.badge--normal{background:rgba(34,197,94,.12);color:var(--green)}
.badge--suspect{background:rgba(245,158,11,.12);color:var(--amber)}
.badge--cancereux{background:rgba(239,68,68,.12);color:var(--red)}
.badge--inconnu{background:rgba(107,114,128,.1);color:#9ca3af}
.badge--reviewed{background:rgba(59,130,246,.1);color:var(--blue-500);font-size:11px}
.icon-btn{width:32px;height:32px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;color:var(--text-2);text-decoration:none;border:none;background:none;cursor:pointer;font-size:14px;transition:all var(--tr)}
.icon-btn:hover{background:var(--bg-hover);color:var(--text-1)}
.icon-btn.danger:hover{background:rgba(239,68,68,.1);color:var(--red)}
.empty-state{text-align:center;padding:60px 24px;color:var(--text-2)}
.empty-state i{font-size:48px;margin-bottom:16px;opacity:.3;display:block}
.empty-state h4{font-size:16px;font-weight:600;color:var(--text-1);margin-bottom:8px}
.empty-state p{font-size:14px;margin-bottom:20px}
/* Pagination */
.pagination{display:flex;justify-content:center;padding:20px;gap:6px}
.pagination__list{display:flex;list-style:none;gap:5px;align-items:center}
.pagination__btn{display:flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 10px;border-radius:8px;background:var(--bg-hover);border:1px solid var(--border);color:var(--text-2);text-decoration:none;font-size:13px;font-weight:500;transition:all var(--tr)}
.pagination__btn:hover{border-color:var(--blue-500);color:var(--blue-500)}
.pagination__btn--active{background:var(--blue-500);border-color:var(--blue-500);color:#fff}
.pagination__dots{color:var(--text-3);font-size:13px;padding:0 4px}
/* Detail view */
.detail-back{display:inline-flex;align-items:center;gap:8px;color:var(--text-2);text-decoration:none;font-size:14px;margin-bottom:24px;transition:color var(--tr)}
.detail-back:hover{color:var(--blue-500)}
.detail-grid{display:grid;grid-template-columns:320px 1fr;gap:22px}
.detail-scan-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.detail-scan-card img{width:100%;aspect-ratio:1;object-fit:cover;background:#000;display:block}
.detail-scan-info{padding:16px;border-top:1px solid var(--border)}
.detail-scan-info p{font-size:12px;color:var(--text-2);line-height:1.6}
.detail-main{display:flex;flex-direction:column;gap:18px}
.verdict-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:28px}
.verdict-header{display:flex;align-items:center;gap:18px;margin-bottom:24px}
.verdict-icon{width:64px;height:64px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0}
.verdict-title{font-family:'Space Grotesk',sans-serif;font-size:26px;font-weight:700}
.verdict-subtitle{font-size:14px;color:var(--text-2);margin-top:4px}
.metrics-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.metric-box{background:var(--bg-hover);border-radius:10px;padding:16px}
.metric-box__label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.7px;color:var(--text-2);margin-bottom:6px}
.metric-box__value{font-family:'Space Grotesk',sans-serif;font-size:20px;font-weight:700}
.metric-box__sub{font-size:12px;color:var(--text-2);margin-top:4px}
.confidence-bar{height:8px;background:rgba(255,255,255,.08);border-radius:99px;overflow:hidden;margin-top:8px}
.confidence-fill{height:100%;border-radius:99px;transition:width 1s ease}
.info-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px}
.info-card h4{font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.info-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);font-size:13.5px}
.info-row:last-child{border-bottom:none}
.info-row__label{color:var(--text-2)}
.info-row__value{font-weight:500;text-align:right}
.note-form textarea{width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:9px;padding:12px 14px;color:var(--text-1);font-size:13.5px;font-family:inherit;resize:vertical;min-height:100px;outline:none;transition:border-color var(--tr)}
.note-form textarea:focus{border-color:var(--border-focus)}
.disclaimer{background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.18);border-radius:10px;padding:14px 16px;font-size:12.5px;color:#fbbf24;line-height:1.6;display:flex;gap:10px;align-items:flex-start}
@media(max-width:1100px){.detail-grid{grid-template-columns:1fr}.metrics-grid{grid-template-columns:1fr 1fr}}
@media(max-width:768px){.sidebar{transform:translateX(-100%)}.main{margin-left:0}.content{padding:20px 16px}.stats-row{gap:8px}.filter-bar form{flex-direction:column}}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar__logo">
    <div class="sidebar__logo-icon"><i class="fa-solid fa-lungs"></i></div>
    <div class="sidebar__logo-text">PulmoCare IA<span>v1.0 — Médical</span></div>
  </div>
  <nav class="sidebar__nav">
    <span class="nav-section-label">Principal</span>
    <a href="/auth/dashboard.php"  class="nav-link"><i class="fa-solid fa-gauge-high"></i> Tableau de bord</a>
    <a href="/pages/detection.php" class="nav-link"><i class="fa-solid fa-magnifying-glass-plus"></i> Nouvelle analyse</a>
    <a href="/pages/resultats.php" class="nav-link active"><i class="fa-solid fa-folder-open"></i> Mes analyses</a>
    <span class="nav-section-label">Compte</span>
    <a href="/pages/profil.php"   class="nav-link"><i class="fa-solid fa-user-doctor"></i> Mon profil</a>
    <a href="/auth/logout.php"    class="nav-link" onclick="return confirm('Se déconnecter ?')"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</a>
  </nav>
  <div class="sidebar__user">
    <img src="<?= htmlspecialchars(html_avatar_url($user['avatar'] ?? null)) ?>" alt="Avatar" class="sidebar__avatar">
    <div class="sidebar__user-info">
      <div class="sidebar__user-name">Dr. <?= htmlspecialchars($user['prenom'].' '.$user['nom']) ?></div>
      <div class="sidebar__user-role"><?= htmlspecialchars($user['specialite'] ?? 'Médecin') ?></div>
    </div>
  </div>
</aside>

<div class="main">
  <header class="topbar">
    <div>
      <h2><i class="fa-solid fa-folder-open" style="color:var(--blue-500)"></i>
        <?= $detail ? 'Rapport #'.$detailId : 'Mes analyses' ?></h2>
      <p><?= $detail
          ? 'Patient : '.htmlspecialchars($detail['patient_prenom'].' '.$detail['patient_nom'])
          : $paginator['total'].' analyse(s) au total' ?></p>
    </div>
    <a href="/pages/detection.php" class="btn-primary"><i class="fa-solid fa-plus"></i> Nouvelle analyse</a>
  </header>

  <main class="content">

    <?= html_flash() ?>

    <?php if ($detail):
      $rt    = $detail['result_type'];
      $color = ai_get_result_color($rt);
      $icon  = ai_get_result_icon($rt);
      $label = ai_get_result_label($rt);
      $bgCol = $rt==='normal' ? 'rgba(34,197,94,.12)' : ($rt==='suspect' ? 'rgba(245,158,11,.12)' : 'rgba(239,68,68,.12)');
    ?>
    <!-- ══ DETAIL ══ -->
    <a href="/pages/resultats.php" class="detail-back"><i class="fa-solid fa-arrow-left"></i> Retour à la liste</a>

    <div class="detail-grid">
      <!-- Scan image -->
      <div>
        <div class="detail-scan-card">
          <img src="<?= htmlspecialchars(scan_get_url($detail['image_path'])) ?>"
               alt="CT Scan" onerror="this.src='/assets/images/scan-placeholder.svg'">
          <div class="detail-scan-info">
            <p><strong><?= htmlspecialchars($detail['image_original_name'] ?? 'scan.jpg') ?></strong><br>
              <?php if ($detail['image_size']): ?>Taille : <?= html_format_size((int)$detail['image_size']) ?> &nbsp;·&nbsp;<?php endif; ?>
              Analysé le <?= html_format_date($detail['created_at']) ?></p>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:14px">
          <a href="/backend/api/export-pdf.php?id=<?= $detailId ?>" class="btn-secondary" style="justify-content:center">
            <i class="fa-solid fa-file-pdf"></i> Exporter PDF
          </a>
          <form method="POST" onsubmit="return confirm('Supprimer définitivement cette analyse ?')">
            <?= html_csrf_input() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="detection_id" value="<?= $detailId ?>">
            <button type="submit" class="btn-secondary" style="width:100%;justify-content:center;color:var(--red);border-color:rgba(239,68,68,.2)">
              <i class="fa-solid fa-trash-can"></i> Supprimer
            </button>
          </form>
        </div>
      </div>

      <!-- Results -->
      <div class="detail-main">
        <div class="verdict-card">
          <div class="verdict-header">
            <div class="verdict-icon" style="background:<?= $bgCol ?>;color:<?= $color ?>">
              <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i>
            </div>
            <div>
              <div class="verdict-title" style="color:<?= $color ?>"><?= ucfirst(htmlspecialchars($rt)) ?></div>
              <div class="verdict-subtitle"><?= htmlspecialchars($label) ?></div>
            </div>
            <?php if ($detail['is_reviewed']): ?>
            <span class="badge badge--reviewed" style="margin-left:auto"><i class="fa-solid fa-check"></i> Vérifié</span>
            <?php endif; ?>
          </div>
          <div class="metrics-grid">
            <div class="metric-box">
              <div class="metric-box__label">Confiance IA</div>
              <div class="metric-box__value" style="color:<?= $color ?>"><?= number_format((float)$detail['confidence_score'],1) ?>%</div>
              <div class="confidence-bar"><div class="confidence-fill" style="width:<?= min(100,(float)$detail['confidence_score']) ?>%;background:<?= $color ?>"></div></div>
            </div>
            <div class="metric-box">
              <div class="metric-box__label">Stade</div>
              <div class="metric-box__value"><?= $detail['stage'] ? 'Stade '.$detail['stage'] : '—' ?></div>
              <div class="metric-box__sub"><?= htmlspecialchars(ai_get_stage_label($detail['stage'])) ?></div>
            </div>
            <div class="metric-box">
              <div class="metric-box__label">Temps analyse</div>
              <div class="metric-box__value"><?= $detail['processing_time_ms'] ? number_format((int)$detail['processing_time_ms']).' ms' : '—' ?></div>
              <div class="metric-box__sub">CNN v<?= htmlspecialchars($detail['model_version'] ?? '1.0') ?></div>
            </div>
          </div>
        </div>

        <div class="info-card">
          <h4><i class="fa-solid fa-user" style="color:var(--blue-500)"></i> Patient</h4>
          <div class="info-row"><span class="info-row__label">Nom complet</span><span class="info-row__value"><?= htmlspecialchars($detail['patient_prenom'].' '.$detail['patient_nom']) ?></span></div>
          <?php if ($detail['patient_age']): ?><div class="info-row"><span class="info-row__label">Âge</span><span class="info-row__value"><?= (int)$detail['patient_age'] ?> ans</span></div><?php endif; ?>
          <?php if ($detail['patient_sexe']): ?><div class="info-row"><span class="info-row__label">Sexe</span><span class="info-row__value"><?= $detail['patient_sexe']==='M'?'Masculin':($detail['patient_sexe']==='F'?'Féminin':'Autre') ?></span></div><?php endif; ?>
          <?php if ($detail['patient_code']): ?><div class="info-row"><span class="info-row__label">Code dossier</span><span class="info-row__value" style="font-family:monospace"><?= htmlspecialchars($detail['patient_code']) ?></span></div><?php endif; ?>
          <div class="info-row"><span class="info-row__label">Médecin</span><span class="info-row__value">Dr. <?= htmlspecialchars($detail['medecin_prenom'].' '.$detail['medecin_nom']) ?></span></div>
          <?php if (!empty($detail['hospital_nom'])): ?><div class="info-row"><span class="info-row__label">Établissement</span><span class="info-row__value"><?= htmlspecialchars($detail['hospital_nom']) ?></span></div><?php endif; ?>
        </div>

        <div class="info-card note-form">
          <h4><i class="fa-solid fa-pen-to-square" style="color:var(--indigo)"></i> Annotations cliniques</h4>
          <form method="POST">
            <?= html_csrf_input() ?>
            <input type="hidden" name="action" value="review">
            <input type="hidden" name="detection_id" value="<?= $detailId ?>">
            <textarea name="notes" placeholder="Observations, recommandations thérapeutiques…"><?= htmlspecialchars($detail['notes_medecin'] ?? '') ?></textarea>
            <div style="margin-top:10px"><button type="submit" class="btn-primary" style="padding:9px 18px;font-size:13.5px"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button></div>
          </form>
        </div>

        <div class="disclaimer">
          <i class="fa-solid fa-triangle-exclamation" style="flex-shrink:0;margin-top:2px"></i>
          <span>Ce résultat est généré par IA à titre d'aide au diagnostic uniquement. Toute décision thérapeutique doit être prise par un professionnel de santé qualifié.</span>
        </div>
      </div>
    </div>

    <?php else: ?>
    <!-- ══ LIST ══ -->
    <div class="stats-row">
      <div class="stat-chip"><div class="stat-chip__dot" style="background:var(--blue-500)"></div><div><div class="stat-chip__val"><?= (int)($stats['total_analyses']??0) ?></div><div class="stat-chip__label">Total</div></div></div>
      <div class="stat-chip"><div class="stat-chip__dot" style="background:var(--green)"></div><div><div class="stat-chip__val" style="color:var(--green)"><?= (int)($stats['total_normaux']??0) ?></div><div class="stat-chip__label">Normaux</div></div></div>
      <div class="stat-chip"><div class="stat-chip__dot" style="background:var(--amber)"></div><div><div class="stat-chip__val" style="color:var(--amber)"><?= (int)($stats['total_suspects']??0) ?></div><div class="stat-chip__label">Suspects</div></div></div>
      <div class="stat-chip"><div class="stat-chip__dot" style="background:var(--red)"></div><div><div class="stat-chip__val" style="color:var(--red)"><?= (int)($stats['total_cancereux']??0) ?></div><div class="stat-chip__label">Cancéreux</div></div></div>
      <?php if ($stats['confidence_moyenne']??0): ?><div class="stat-chip" style="margin-left:auto"><div><div class="stat-chip__val" style="color:var(--blue-500)"><?= number_format((float)$stats['confidence_moyenne'],1) ?>%</div><div class="stat-chip__label">Confiance moy.</div></div></div><?php endif; ?>
    </div>

    <div class="filter-bar">
      <form method="GET">
        <div class="filter-group"><label class="filter-label">Patient</label><input type="text" name="patient" class="filter-control" placeholder="Nom, prénom ou code…" value="<?= htmlspecialchars($filters['patient']) ?>"></div>
        <div class="filter-group"><label class="filter-label">Résultat</label>
          <select name="result_type" class="filter-control">
            <option value="">Tous</option>
            <option value="normal"    <?= $filters['result_type']==='normal'?'selected':'' ?>>Normal</option>
            <option value="suspect"   <?= $filters['result_type']==='suspect'?'selected':'' ?>>Suspect</option>
            <option value="cancereux" <?= $filters['result_type']==='cancereux'?'selected':'' ?>>Cancéreux</option>
          </select>
        </div>
        <div class="filter-group"><label class="filter-label">Stade</label>
          <select name="stage" class="filter-control">
            <option value="">Tous</option>
            <?php foreach(['I','II','III','IV'] as $s): ?><option value="<?= $s ?>" <?= $filters['stage']===$s?'selected':'' ?>>Stade <?= $s ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group"><label class="filter-label">Du</label><input type="date" name="date_from" class="filter-control" value="<?= htmlspecialchars($filters['date_from']) ?>"></div>
        <div class="filter-group"><label class="filter-label">Au</label><input type="date" name="date_to" class="filter-control" value="<?= htmlspecialchars($filters['date_to']) ?>"></div>
        <div style="display:flex;gap:8px;align-items:flex-end">
          <button type="submit" class="btn-primary" style="padding:9px 16px"><i class="fa-solid fa-magnifying-glass"></i> Filtrer</button>
          <a href="/pages/resultats.php" class="btn-secondary"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h3><i class="fa-solid fa-list" style="color:var(--blue-500)"></i> Résultats <span style="font-size:12px;font-weight:400;color:var(--text-2)">(<?= $paginator['total'] ?>)</span></h3>
      </div>
      <?php if (empty($paginator['data'])): ?>
      <div class="empty-state">
        <i class="fa-solid fa-folder-open"></i>
        <h4>Aucune analyse trouvée</h4>
        <p><?= array_filter($filters) ? 'Aucun résultat pour ces filtres.' : 'Vous n\'avez pas encore effectué d\'analyse.' ?></p>
        <a href="/pages/detection.php" class="btn-primary"><i class="fa-solid fa-plus"></i> Lancer une analyse</a>
      </div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th>Patient</th><th>Date</th><th>Résultat</th><th>Confiance</th><th>Stade</th><th>Statut</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($paginator['data'] as $det): ?>
          <tr>
            <td style="color:var(--text-3);font-size:12px;font-family:monospace">#<?= (int)$det['id'] ?></td>
            <td class="patient-cell">
              <strong><?= htmlspecialchars($det['patient_prenom'].' '.$det['patient_nom']) ?></strong>
              <?php if ($det['patient_age']||$det['patient_sexe']): ?><span><?= $det['patient_age']?(int)$det['patient_age'].' ans':'' ?><?= ($det['patient_age']&&$det['patient_sexe'])?' · ':'' ?><?= $det['patient_sexe']?htmlspecialchars($det['patient_sexe']):'' ?></span><?php endif; ?>
            </td>
            <td style="color:var(--text-2);white-space:nowrap"><?= html_format_date($det['created_at'],'d/m/Y') ?><br><span style="font-size:11px"><?= html_format_date($det['created_at'],'H:i') ?></span></td>
            <td><?= html_result_badge($det['result_type']) ?></td>
            <td><span style="font-weight:600;color:<?= ai_get_result_color($det['result_type']) ?>"><?= number_format((float)$det['confidence_score'],1) ?>%</span></td>
            <td style="color:var(--text-2)"><?= $det['stage']?'Stade '.htmlspecialchars($det['stage']):'—' ?></td>
            <td><?php if ($det['is_reviewed']): ?><span class="badge badge--reviewed"><i class="fa-solid fa-check"></i> Annoté</span><?php else: ?><span style="font-size:12px;color:var(--text-3)">En attente</span><?php endif; ?></td>
            <td>
              <div style="display:flex;gap:4px">
                <a href="/pages/resultats.php?id=<?= (int)$det['id'] ?>" class="icon-btn" title="Voir"><i class="fa-solid fa-eye"></i></a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ?')">
                  <?= html_csrf_input() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="detection_id" value="<?= (int)$det['id'] ?>">
                  <button type="submit" class="icon-btn danger"><i class="fa-solid fa-trash-can"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pagination_links($paginator) ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </main>
</div>
<script>
document.querySelectorAll('.alert__close').forEach(b=>b.addEventListener('click',()=>b.closest('.alert')?.remove()));
</script>
</body></html>
