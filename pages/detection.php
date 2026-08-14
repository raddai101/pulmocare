<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/functions/functions.php';

auth_require('medecin');

$user   = auth_current_user();
$userId = (int)$user['id'];

$errors          = [];
$result          = null;
$detectionId     = null;
$uploadedImageUrl = null;
$uploadedGradcamUrl = null;

// ── Traitement upload + prédiction IA ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!security_verify_csrf($_POST['_token'] ?? '')) {
        $errors[] = 'Jeton de sécurité invalide. Veuillez réessayer.';
    } else {

        // 1. Validation des données patient
        $patientData = [
            'nom'    => $_POST['patient_nom']    ?? '',
            'prenom' => $_POST['patient_prenom'] ?? '',
            'age'    => $_POST['patient_age']    ?? '',
            'sexe'   => $_POST['patient_sexe']   ?? '',
            'code'   => $_POST['patient_code']   ?? '',
        ];

        $v = validate_fields($patientData, [
            'nom'    => 'required|min:2|max:80',
            'prenom' => 'required|min:2|max:80',
            'age'    => 'required|age:0,120',
            'sexe'   => 'required|in:M,F,Autre',
        ]);

        if (!$v['valid']) {
            foreach ($v['errors'] as $msgs) {
                $errors = array_merge($errors, $msgs);
            }
        }

        // 2. Validation et upload du scan
        if (empty($errors)) {
            if (empty($_FILES['scan_file']['tmp_name'])) {
                $errors[] = 'Veuillez sélectionner une image CT Scan.';
            } else {
                $scanResult = scan_upload($_FILES['scan_file'], $userId);

                if (!$scanResult['success']) {
                    $errors[] = $scanResult['message'];
                } else {
                    // 3. Prédiction IA
                    $aiResponse = ai_predict($scanResult['path']);

                    if (!$aiResponse['success']) {
                        $errors[] = $aiResponse['message'];
                        scan_delete_file($scanResult['url']);
                    } else {
                        // 4. Sauvegarde en BDD
                        $createResult = detection_create(
                            $userId,
                            $scanResult,
                            $aiResponse['result'],
                            $patientData
                        );

                        if ($createResult['success']) {
                            $detectionId = $createResult['detection_id'];
                            $result      = ai_format_result($aiResponse['result']);

                            // déterminer l'URL publique de l'image analysée (nouvelle ou doublon)
                            if (!empty($scanResult['url'])) {
                                $uploadedImageUrl = $scanResult['url'];
                            }

                            // récupérer l'eventuel gradcam sauvegardé
                            $existing = detection_get((int)$detectionId);
                            if ($existing && !empty($existing['gradcam_path'])) {
                                $uploadedGradcamUrl = $existing['gradcam_path'];
                            }

                            if ($createResult['is_duplicate']) {
                                // si doublon, récupérer l'enregistrement existant pour obtenir le chemin image
                                $existing = detection_get((int)$createResult['detection_id']);
                                if ($existing && !empty($existing['image_path'])) {
                                    $uploadedImageUrl = $existing['image_path'];
                                }
                                html_set_flash('info', 'Ce scan a déjà été analysé. Résultats précédents affichés.');
                            } else {
                                log_activity('detection_completed', ['user_id' => $userId, 'detection_id' => $detectionId]);
                            }
                        } else {
                            $errors[] = 'Erreur lors de la sauvegarde des résultats.';
                        }
                    }
                }
            }
        }
    }
}

$pageTitle   = html_page_title('Nouvelle analyse CT Scan');
$currentFile = basename(__FILE__);
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
    <link rel="stylesheet" href="/pulmocare/assets/css/style.css">
    <link rel="stylesheet" href="/pulmocare/assets/css/human-clinic.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg-base: #060d1a; --bg-card: #0b1629; --bg-hover: #0f1e38; --bg-input: #0f1e38;
            --border: rgba(59,130,246,.14); --border-focus: #3b82f6;
            --blue-500: #3b82f6; --blue-glow: rgba(59,130,246,.3);
            --indigo: #6366f1; --green: #22c55e; --amber: #f59e0b; --red: #ef4444;
            --text-1: #e8edf8; --text-2: #7f93b4; --text-3: #4a607a;
            --radius: 12px; --sidebar-w: 260px; --header-h: 70px; --tr: .22s ease;
        }
        body { background: var(--bg-base); color: var(--text-1); font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; }

        .sidebar { width: var(--sidebar-w); background: var(--bg-card); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 100; }
        .sidebar__logo { padding: 24px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--border); }
        .sidebar__logo-icon { width: 42px; height: 42px; background: linear-gradient(135deg,var(--blue-500),var(--indigo)); border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 19px; box-shadow: 0 4px 14px var(--blue-glow); }
        .sidebar__logo-text { font-family: 'Space Grotesk',sans-serif; font-size: 17px; font-weight: 700; letter-spacing: -.4px; line-height: 1.1; }
        .sidebar__logo-text span { display: block; font-size: 10px; font-weight: 400; color: var(--text-2); letter-spacing: 1px; text-transform: uppercase; }
        .sidebar__nav { flex: 1; padding: 20px 12px; display: flex; flex-direction: column; gap: 4px; overflow-y: auto; }
        .nav-section-label { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-3); padding: 14px 10px 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 9px; text-decoration: none; color: var(--text-2); font-size: 13.5px; font-weight: 500; transition: background var(--tr),color var(--tr); position: relative; }
        .nav-link i { width: 18px; text-align: center; font-size: 15px; }
        .nav-link:hover { background: var(--bg-hover); color: var(--text-1); }
        .nav-link.active { background: rgba(59,130,246,.14); color: var(--blue-500); }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 3px; height: 60%; background: var(--blue-500); border-radius: 0 3px 3px 0; }
        .sidebar__user { padding: 16px 20px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
        .sidebar__avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(59,130,246,.3); }
        .sidebar__user-info { flex: 1; min-width: 0; }
        .sidebar__user-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar__user-role { font-size: 11px; color: var(--text-2); }

        .main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }
        .topbar { height: var(--header-h); background: var(--bg-card); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; position: sticky; top: 0; z-index: 50; }
        .topbar h2 { font-family: 'Space Grotesk',sans-serif; font-size: 19px; font-weight: 600; letter-spacing: -.3px; }
        .topbar p { font-size: 12.5px; color: var(--text-2); margin-top: 2px; }
        .content { padding: 32px; flex: 1; max-width: 1000px; }

        .alert { display: flex; align-items: flex-start; gap: 10px; padding: 14px 18px; border-radius: 10px; font-size: 13.5px; margin-bottom: 20px; }
        .alert--error   { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.25); color: #f87171; }
        .alert--success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.25); color: #4ade80; }
        .alert--info    { background: rgba(59,130,246,.1); border: 1px solid rgba(59,130,246,.25); color: #60a5fa; }

        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 24px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid var(--border); }
        .card-header h3 { font-family: 'Space Grotesk',sans-serif; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .card-header p  { font-size: 13px; color: var(--text-2); margin-top: 4px; }
        .card-body { padding: 24px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-grid.three { grid-template-columns: 1fr 1fr 1fr; }
        .form-full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-label { font-size: 12.5px; font-weight: 500; color: var(--text-2); letter-spacing: .2px; }
        .form-label span.req { color: var(--red); margin-left: 2px; }
        .form-control { background: var(--bg-input); border: 1px solid var(--border); border-radius: 9px; padding: 11px 14px; color: var(--text-1); font-size: 13.5px; font-family: inherit; outline: none; transition: border-color var(--tr), box-shadow var(--tr); width: 100%; }
        .form-control:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px var(--blue-glow); }
        select.form-control { cursor: pointer; }

        .drop-zone {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 48px 24px;
            text-align: center;
            cursor: pointer;
            transition: border-color var(--tr), background var(--tr);
            position: relative;
        }
        .drop-zone:hover, .drop-zone.dragover {
            border-color: var(--blue-500);
            background: rgba(59,130,246,.05);
        }
        .drop-zone__icon { font-size: 48px; color: var(--text-3); margin-bottom: 16px; }
        .drop-zone__title { font-family: 'Space Grotesk',sans-serif; font-size: 16px; font-weight: 600; margin-bottom: 6px; }
        .drop-zone__subtitle { font-size: 13px; color: var(--text-2); margin-bottom: 20px; }
        .drop-zone__info { font-size: 12px; color: var(--text-3); }
        .drop-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

        .drop-zone.has-file { border-style: solid; border-color: var(--green); background: rgba(34,197,94,.04); }
        .drop-zone.has-file .drop-zone__icon { color: var(--green); }

        .file-preview { display: none; align-items: center; gap: 14px; padding: 14px; background: var(--bg-hover); border-radius: 9px; margin-top: 16px; }
        .file-preview.show { display: flex; }
        .file-preview img { width: 60px; height: 60px; object-fit: cover; border-radius: 7px; border: 1px solid var(--border); }
        .file-preview__info { flex: 1; min-width: 0; }
        .file-preview__name { font-size: 13.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .file-preview__size { font-size: 12px; color: var(--text-2); margin-top: 2px; }
        .file-preview__remove { color: var(--red); cursor: pointer; background: none; border: none; font-size: 18px; padding: 4px; }

        .btn-submit { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; padding: 14px; background: linear-gradient(135deg, var(--blue-500), var(--indigo)); border: none; border-radius: 10px; color: #fff; font-size: 15px; font-weight: 600; font-family: inherit; cursor: pointer; transition: opacity var(--tr), transform var(--tr); box-shadow: 0 4px 20px var(--blue-glow); margin-top: 8px; }
        .btn-submit:hover { opacity: .9; transform: translateY(-1px); }
        .btn-submit:disabled { opacity: .5; cursor: not-allowed; transform: none; }

        .result-panel { display: none; }
        .result-panel.show { display: block; animation: slideUp .4s ease; }

        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .result-main {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .result-verdict {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 32px 28px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 12px;
        }

        .result-verdict__icon {
            width: 72px; height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
        }

        .result-verdict__type  { font-family: 'Space Grotesk',sans-serif; font-size: 24px; font-weight: 700; }
        .result-verdict__label { font-size: 14px; color: var(--text-2); max-width: 220px; line-height: 1.5; }

        .result-meta { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; display: flex; flex-direction: column; gap: 18px; }
        .meta-item { display: flex; flex-direction: column; gap: 4px; }
        .meta-item__label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .8px; color: var(--text-2); }
        .meta-item__value { font-size: 16px; font-weight: 600; }

        .confidence-bar { height: 8px; background: var(--bg-hover); border-radius: 99px; overflow: hidden; margin-top: 6px; }
        .confidence-fill { height: 100%; border-radius: 99px; transition: width 1s ease; }

        .result-actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .btn-action { display: flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 9px; font-size: 13.5px; font-weight: 600; text-decoration: none; cursor: pointer; border: 1px solid var(--border); background: var(--bg-hover); color: var(--text-1); transition: all var(--tr); }
        .btn-action:hover { border-color: var(--blue-500); color: var(--blue-500); }
        .btn-action--primary { background: linear-gradient(135deg, var(--blue-500), var(--indigo)); border: none; box-shadow: 0 4px 14px var(--blue-glow); color: #fff; }
        .btn-action--primary:hover { color: #fff; opacity: .9; }

        #scanPreviewImg.is-fallback,
        #previewImg.is-fallback {
            opacity: .5;
            filter: grayscale(.4);
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main { margin-left: 0; }
            .content { padding: 20px 16px; }
            .form-grid, .form-grid.three, .result-main { grid-template-columns: 1fr; }
        }

        .wf-slideshow {
            position: relative;
            overflow: hidden;
            border-radius: 16px;
            margin-bottom: 24px;
            box-shadow: 0 14px 34px rgba(4, 10, 22, .35), 0 0 0 1px rgba(59,130,246,.10);
        }
        .wf-slideshow__stage {
            position: relative;
            width: 100%;
            height: clamp(220px, 32vw, 340px);
        }
        .wf-slide {
            position: absolute; inset: 0;
            opacity: 0;
            transition: opacity .35s ease;
        }
        .wf-slide.is-active { opacity: 1; }
        .wf-slide img {
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
        }
        .wf-slide__label {
            position: absolute;
            left: 16px; bottom: 14px;
            padding: 7px 14px;
            border-radius: 99px;
            background: rgba(6, 13, 26, .62);
            backdrop-filter: blur(4px);
            color: #fff;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: .2px;
        }

        @media (max-width: 560px) {
            .wf-slideshow__stage { height: 220px; }
            .wf-slide__label { font-size: 12px; padding: 6px 12px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .wf-slide { transition: opacity .15s ease; }
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar__logo">
        <div class="sidebar__logo-icon"><i class="fa-solid fa-lungs"></i></div>
        <div class="sidebar__logo-text">PulmoCare IA<span>v1.0  Médical</span></div>
    </div>
    <nav class="sidebar__nav">
        <span class="nav-section-label">Principal</span>
        <a href="/pulmocare/auth/dashboard.php" class="nav-link"><i class="fa-solid fa-gauge-high"></i> Tableau de bord</a>
        <a href="/pulmocare/pages/detection.php" class="nav-link active"><i class="fa-solid fa-magnifying-glass-plus"></i> Nouvelle analyse</a>
        <a href="/pulmocare/pages/resultats.php" class="nav-link"><i class="fa-solid fa-folder-open"></i> Mes analyses</a>
        <span class="nav-section-label">Compte</span>
        <a href="/pulmocare/pages/profil.php" class="nav-link"><i class="fa-solid fa-user-doctor"></i> Mon profil</a>
        <a href="/pulmocare/auth/logout.php" class="nav-link" onclick="return confirm('Se déconnecter ?')"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</a>
    </nav>
    <div class="sidebar__user">
        <img src="<?= htmlspecialchars(html_avatar_url($user['avatar'] ?? null)) ?>" alt="Avatar" class="sidebar__avatar">
        <div class="sidebar__user-info">
            <div class="sidebar__user-name">Dr. <?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></div>
            <div class="sidebar__user-role"><?= htmlspecialchars($user['specialite'] ?? 'Médecin') ?></div>
        </div>
    </div>
</aside>

<!-- MAIN -->
<div class="main">
    <header class="topbar">
        <div>
            <h2><i class="fa-solid fa-magnifying-glass-plus" style="color:var(--blue-500);margin-right:10px"></i>Nouvelle analyse CT Scan</h2>
            <p>Importez une image CT Scan pulmonaire pour obtenir une analyse IA</p>
        </div>
    </header>

    <main class="content">

        <!-- ============================================================
             WORKFLOW — slideshow simple, une image plein cadre à la fois,
             changement rapide en fondu, légende courte en bas.
             ============================================================ -->
        <section class="wf-slideshow" id="wfSlideshow" aria-label="Comment se déroule une analyse PulmoCare IA" data-interval="1600">
            <?php
            $wfSlides = [
                ['src' => '/assets/images/workflow/dossier-patient.jpg',    'alt' => 'Constitution du dossier patient',   'label' => 'Dossier patient'],
                ['src' => '/assets/images/workflow/acquisition-scan.jpg',   'alt' => 'Acquisition de l\'image CT Scan',   'label' => 'Acquisition du scan'],
                ['src' => '/assets/images/workflow/analyse-cnn.jpg',        'alt' => 'Analyse par le réseau CNN',         'label' => 'Analyse CNN'],
                ['src' => '/assets/images/workflow/lecture-medicale.jpg',   'alt' => 'Lecture et interprétation médicale','label' => 'Lecture médicale'],
                ['src' => '/assets/images/workflow/rapport-validation.jpg', 'alt' => 'Rapport et validation clinique',    'label' => 'Rapport & validation'],
            ];
            ?>
            <div class="wf-slideshow__stage">
                <?php foreach ($wfSlides as $i => $slide): ?>
                <figure class="wf-slide<?= $i === 0 ? ' is-active' : '' ?>" data-index="<?= (int)$i ?>">
                    <img src="<?= htmlspecialchars(scan_get_url($slide['src'])) ?>" alt="<?= htmlspecialchars($slide['alt']) ?>" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
                    <figcaption class="wf-slide__label"><?= htmlspecialchars((string)($i + 1)) ?>. <?= htmlspecialchars($slide['label']) ?></figcaption>
                </figure>
                <?php endforeach; ?>
            </div>
        </section>


        <?= html_flash() ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert--error">
            <i class="fa-solid fa-circle-exclamation" style="font-size:18px;flex-shrink:0"></i>
            <div>
                <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- RESULT PANEL -->
        <?php if ($result && $detectionId): ?>
        <div class="result-panel show" id="resultPanel">
            <div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;font-size:14px;color:var(--green)">
                <i class="fa-solid fa-circle-check"></i>
                <strong>Analyse terminée avec succès</strong>
                <span style="color:var(--text-2);margin-left:auto;font-size:12px">ID #<?= $detectionId ?></span>
            </div>

            <?php if (!empty($uploadedImageUrl)): ?>
            <div style="margin-bottom:14px;text-align:center">
                <img src="<?= htmlspecialchars(scan_get_url($uploadedImageUrl)) ?>" alt="Scan uploadé" style="max-width:100%;height:auto;border-radius:10px;border:1px solid var(--border);box-shadow:0 8px 24px rgba(0,0,0,.35)">
            </div>
            <?php endif; ?>

            <?php if (!empty($uploadedGradcamUrl)): ?>
            <div style="margin-bottom:14px;text-align:center">
                <img src="<?= htmlspecialchars(scan_get_url($uploadedGradcamUrl)) ?>" alt="Grad-CAM" style="max-width:100%;height:auto;border-radius:10px;border:1px solid var(--border);box-shadow:0 8px 24px rgba(0,0,0,.35)">
                <div style="font-size:13px;color:var(--text-2);margin-top:6px">Carte d'activation Grad-CAM — zones ayant influencé la décision du CNN.</div>
            </div>
            <?php endif; ?>

            <div class="result-main">
                <!-- Verdict -->
                <div class="result-verdict">
                    <?php
                    $bgColor = $result['result_type'] === 'normal' ? 'rgba(34,197,94,.15)' : ($result['result_type'] === 'suspect' ? 'rgba(245,158,11,.15)' : 'rgba(239,68,68,.15)');
                    ?>
                    <div class="result-verdict__icon" style="background:<?= $bgColor ?>;color:<?= $result['color'] ?>">
                        <i class="fa-solid <?= htmlspecialchars($result['icon']) ?>"></i>
                    </div>
                    <div class="result-verdict__type" style="color:<?= $result['color'] ?>">
                        <?= ucfirst(htmlspecialchars($result['result_type'])) ?>
                    </div>
                    <div class="result-verdict__label"><?= htmlspecialchars($result['label']) ?></div>

                    <div style="width:100%;margin-top:8px">
                        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-2);margin-bottom:6px">
                            <span>Score de confiance IA</span>
                            <strong style="color:<?= $result['color'] ?>"><?= number_format((float)$result['confidence_score'], 1) ?>%</strong>
                        </div>
                        <div class="confidence-bar">
                            <div class="confidence-fill" style="width:<?= min(100, $result['confidence_score']) ?>%;background:<?= $result['color'] ?>"></div>
                        </div>
                    </div>
                </div>

                <!-- Meta -->
                <div class="result-meta">
                    <div class="meta-item">
                        <span class="meta-item__label">Stade détecté</span>
                        <span class="meta-item__value"><?= htmlspecialchars($result['stage_label']) ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-item__label">Version modele</span>
                        <span class="meta-item__value" style="font-size:13px;color:var(--text-2)">CNN v<?= htmlspecialchars($result['model_version']) ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-item__label">Temps de traitement</span>
                        <span class="meta-item__value" style="font-size:13px;color:var(--text-2)"><?= number_format((int)$result['processing_time_ms']) ?> ms</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-item__label">Patient analysé</span>
                        <span class="meta-item__value" style="font-size:14px">
                            <?= htmlspecialchars(($_POST['patient_prenom'] ?? '') . ' ' . ($_POST['patient_nom'] ?? '')) ?>
                            <?php if (!empty($_POST['patient_age'])): ?>
                            <span style="color:var(--text-2);font-weight:400;font-size:13px">— <?= (int)$_POST['patient_age'] ?> ans</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div style="padding:12px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:8px;font-size:12.5px;color:#fbbf24;line-height:1.5">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        Ce résultat est un outil d'aide au diagnostic. Toute décision médicale doit être prise par un médecin qualifié.
                    </div>
                </div>
            </div>

            <div class="result-actions">
                <a href="/pulmocare/pages/resultats.php?id=<?= $detectionId ?>" class="btn-action btn-action--primary">
                    <i class="fa-solid fa-eye"></i> Voir le rapport complet
                </a>
                <a href="/pulmocare/pages/detection.php" class="btn-action">
                    <i class="fa-solid fa-plus"></i> Nouvelle analyse
                </a>
                <a href="/pulmocare/pages/resultats.php" class="btn-action">
                    <i class="fa-solid fa-folder-open"></i> Historique
                </a>
            </div>
        </div>

        <?php else: ?>

        <!-- FORM -->
        <form method="POST" enctype="multipart/form-data" id="detectionForm" novalidate>
            <?= html_csrf_input() ?>

            <!-- Patient info -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fa-solid fa-user" style="color:var(--blue-500)"></i> Informations patient</h3>
                    <p>Renseignez les informations du patient avant d'importer le scan</p>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="patient_nom">Nom <span class="req">*</span></label>
                            <input type="text" id="patient_nom" name="patient_nom" class="form-control"
                                placeholder="Nom de famille"
                                value="<?= htmlspecialchars($_POST['patient_nom'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="patient_prenom">Prénom <span class="req">*</span></label>
                            <input type="text" id="patient_prenom" name="patient_prenom" class="form-control"
                                placeholder="Prénom"
                                value="<?= htmlspecialchars($_POST['patient_prenom'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="patient_age">Âge <span class="req">*</span></label>
                            <input type="number" id="patient_age" name="patient_age" class="form-control"
                                placeholder="Âge en années" min="0" max="120"
                                value="<?= htmlspecialchars($_POST['patient_age'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="patient_sexe">Sexe <span class="req">*</span></label>
                            <select id="patient_sexe" name="patient_sexe" class="form-control" required>
                                <option value=""> Sélectionner </option>
                                <option value="M"   <?= (($_POST['patient_sexe'] ?? '') === 'M')     ? 'selected' : '' ?>>Masculin</option>
                                <option value="F"   <?= (($_POST['patient_sexe'] ?? '') === 'F')     ? 'selected' : '' ?>>Féminin</option>
                                <option value="Autre" <?= (($_POST['patient_sexe'] ?? '') === 'Autre') ? 'selected' : '' ?>>Autre</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="patient_code">Code patient</label>
                            <input type="text" id="patient_code" name="patient_code" class="form-control"
                                placeholder="Code ou numéro dossier (optionnel)"
                                value="<?= htmlspecialchars($_POST['patient_code'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upload CT Scan -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fa-solid fa-x-ray" style="color:var(--indigo)"></i> Image CT Scan</h3>
                    <p>Formats acceptés : JPG, PNG, DICOM, TIFF  Taille max : 20 Mo</p>
                </div>
                <div class="card-body">
                    <div class="drop-zone" id="dropZone">
                        <input type="file" name="scan_file" id="scanFile" accept=".jpg,.jpeg,.png,.dcm,.tiff,.tif,.svg" required>
                        <div id="dropDefault">
                            <div class="drop-zone__icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                            <div class="drop-zone__title">Glissez l'image ici ou cliquez pour sélectionner</div>
                            <div class="drop-zone__subtitle">CT Scan thoracique  Vue axiale recommandée</div>
                            <div class="drop-zone__info">JPG · PNG · DICOM · TIFF &nbsp;|&nbsp; Max 20 Mo</div>
                        </div>
                        <div class="drop-zone__scan-preview" id="scanPreviewInZone" aria-live="polite">
                            <img id="scanPreviewImg" src="<?= htmlspecialchars(scan_get_url('/assets/images/CTScan.png')) ?>" data-placeholder="<?= htmlspecialchars(scan_get_url('/assets/images/CTScan.png')) ?>" alt="Aperçu du scan sélectionné">
                            <div class="drop-zone__scan-meta">
                                <i class="fa-solid fa-file-medical" style="color:var(--blue-500)"></i>
                                <div style="min-width:0;flex:1">
                                    <strong id="scanPreviewName"></strong>
                                    <span id="scanPreviewSize"></span>
                                </div>
                                <button type="button" class="file-preview__remove" id="removeFileInZone" title="Supprimer">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="file-preview" id="filePreview">
                        <img id="previewImg" src="<?= htmlspecialchars(scan_get_url('/assets/images/scan-placeholder.svg')) ?>" data-placeholder="<?= htmlspecialchars(scan_get_url('/assets/images/scan-placeholder.svg')) ?>" alt="Aperçu scan">
                        <div class="file-preview__info">
                            <div class="file-preview__name" id="previewName"></div>
                            <div class="file-preview__size" id="previewSize"></div>
                        </div>
                        <button type="button" class="file-preview__remove" id="removeFile" title="Supprimer">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn-submit" id="submitBtn">
                <i class="fa-solid fa-brain"></i>
                <span id="btnText">Lancer l'analyse IA</span>
            </button>
        </form>

        <?php endif; ?>

    </main>
</div>

<script>
(function() {
    const dropZone          = document.getElementById('dropZone');
    const fileInput         = document.getElementById('scanFile');
    const preview           = document.getElementById('filePreview');
    const previewImg        = document.getElementById('previewImg');
    const previewName       = document.getElementById('previewName');
    const previewSize       = document.getElementById('previewSize');
    const scanPreviewInZone = document.getElementById('scanPreviewInZone');
    const scanPreviewImg    = document.getElementById('scanPreviewImg');
    const scanPreviewName   = document.getElementById('scanPreviewName');
    const scanPreviewSize   = document.getElementById('scanPreviewSize');
    const removeBtn         = document.getElementById('removeFile');
    const removeBtnInZone   = document.getElementById('removeFileInZone');
    const submitBtn         = document.getElementById('submitBtn');
    const form               = document.getElementById('detectionForm');

    if (!dropZone) return;

    const RENDERABLE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp'];

    let currentObjectUrl = null;

    function formatSize(bytes) {
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' Ko';
        return (bytes / 1048576).toFixed(1) + ' Mo';
    }

    function getExt(filename) {
        return (filename.split('.').pop() || '').toLowerCase();
    }

    function isRenderable(file) {
        return RENDERABLE_EXT.includes(getExt(file.name));
    }

    function releaseObjectUrl() {
        if (currentObjectUrl) {
            URL.revokeObjectURL(currentObjectUrl);
            currentObjectUrl = null;
        }
    }

    function showPreview(file) {
        previewName.textContent = file.name;
        previewSize.textContent = formatSize(file.size);
        if (scanPreviewName) scanPreviewName.textContent = file.name;
        if (scanPreviewSize) scanPreviewSize.textContent = formatSize(file.size);
        dropZone.classList.add('has-file');

        releaseObjectUrl();

        if (isRenderable(file)) {
            currentObjectUrl = URL.createObjectURL(file);

            if (previewImg) {
                previewImg.src = currentObjectUrl;
                previewImg.style.display = 'block';
                previewImg.classList.remove('is-fallback');
            }
            if (scanPreviewImg) {
                scanPreviewImg.src = currentObjectUrl;
                scanPreviewImg.style.display = 'block';
                scanPreviewImg.style.maxWidth = '100%';
                scanPreviewImg.style.height = 'auto';
                scanPreviewImg.classList.remove('is-fallback');
            }
        } else {
            const placeholder = previewImg ? previewImg.dataset.placeholder : '';
            if (previewImg) {
                previewImg.src = placeholder || previewImg.src;
                previewImg.style.display = 'block';
                previewImg.classList.add('is-fallback');
            }
            if (scanPreviewImg) {
                scanPreviewImg.src = scanPreviewImg.dataset.placeholder || scanPreviewImg.src;
                scanPreviewImg.style.display = 'block';
                scanPreviewImg.classList.add('is-fallback');
            }
            if (scanPreviewName) {
                scanPreviewName.textContent = file.name + ' — aperçu indisponible pour ce format';
            }
        }

        preview.classList.add('show');
        if (scanPreviewInZone) scanPreviewInZone.style.display = 'block';
    }

    function clearFile() {
        if (fileInput) fileInput.value = '';
        preview.classList.remove('show');
        dropZone.classList.remove('has-file');
        releaseObjectUrl();
        if (previewImg) { previewImg.style.display = 'none'; previewImg.classList.remove('is-fallback'); }
        if (scanPreviewImg) { scanPreviewImg.src = ''; scanPreviewImg.classList.remove('is-fallback'); }
        if (scanPreviewName) scanPreviewName.textContent = '';
        if (scanPreviewSize) scanPreviewSize.textContent = '';
        if (scanPreviewInZone) scanPreviewInZone.style.display = 'none';
    }

    fileInput.addEventListener('change', () => {
        if (fileInput.files[0]) showPreview(fileInput.files[0]);
    });

    removeBtn?.addEventListener('click', clearFile);
    removeBtnInZone?.addEventListener('click', clearFile);

    ['dragenter', 'dragover'].forEach(e => {
        dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(e => {
        dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('dragover'); });
    });
    dropZone.addEventListener('drop', ev => {
        const file = ev.dataTransfer?.files?.[0];
        if (file) {
            const dt = new DataTransfer();
            dt.items.add(file);
            if (fileInput) fileInput.files = dt.files;
            showPreview(file);
        }
    });

    window.addEventListener('pagehide', releaseObjectUrl);

    form?.addEventListener('submit', () => {
        if (submitBtn) submitBtn.disabled = true;
        const btnText = document.getElementById('btnText');
        if (btnText) btnText.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Analyse en cours…';
    });
})();
</script>

<!-- ── Slideshow simple du bandeau workflow (.wf-slideshow) ── -->
<script>
(function () {
    const root = document.getElementById('wfSlideshow');
    if (!root) return;

    const slides = Array.from(root.querySelectorAll('.wf-slide'));
    if (slides.length < 2) return;

    const interval = parseInt(root.dataset.interval, 10) || 1600;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    let index = 0;

    function show(i) {
        slides[index].classList.remove('is-active');
        index = (i + slides.length) % slides.length;
        slides[index].classList.add('is-active');
    }

    if (!reduceMotion) {
        setInterval(() => show(index + 1), interval);
    }
})();
</script>
</body>
</html>