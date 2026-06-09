<?php
declare(strict_types=1);
require_once __DIR__ . '/../../backend/functions/functions.php';

auth_require('medecin');

$userId = (int)(auth_current_user()['id'] ?? 0);
$id     = (int)($_GET['id'] ?? 0);

if (!$id) {
    html_set_flash('error', 'ID de détection manquant.');
    response_redirect('/pages/resultats.php');
}

$detection = detection_get($id);
if (!$detection || (int)$detection['user_id'] !== $userId) {
    html_set_flash('error', 'Analyse introuvable.');
    response_redirect('/pages/resultats.php');
}

// PDF generation — intégrer mPDF ou DOMPDF selon requirements.txt
// Exemple avec DOMPDF :
// require_once __DIR__ . '/../../vendor/autoload.php';
// use Dompdf\Dompdf;
// $dompdf = new Dompdf();
// $html = generatePdfHtml($detection);
// $dompdf->loadHtml($html);
// $dompdf->setPaper('A4', 'portrait');
// $dompdf->render();
// $dompdf->stream('rapport_'.$id.'.pdf', ['Attachment' => true]);

// Pour l'instant : aperçu HTML imprimable
$rt    = $detection['result_type'];
$color = ai_get_result_color($rt);
$date  = html_format_date($detection['created_at']);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport d'analyse #<?= $id ?></title>
<style>
  body{font-family:'Arial',sans-serif;color:#1a1a2e;padding:40px;max-width:800px;margin:auto}
  .header{display:flex;justify-content:space-between;border-bottom:3px solid #1e40af;padding-bottom:16px;margin-bottom:28px}
  .logo{font-size:22px;font-weight:700;color:#1e40af}
  .logo span{display:block;font-size:12px;font-weight:400;color:#6b7280}
  h2{font-size:18px;color:#1e40af;margin:24px 0 12px}
  table{width:100%;border-collapse:collapse;margin-bottom:20px}
  td{padding:10px 12px;border:1px solid #e5e7eb;font-size:14px}
  td:first-child{background:#f9fafb;font-weight:600;width:35%}
  .result-badge{display:inline-block;padding:4px 14px;border-radius:20px;font-weight:700;font-size:14px;background:<?= $rt==='normal'?'#dcfce7':($rt==='suspect'?'#fef9c3':'#fee2e2') ?>;color:<?= $color ?>}
  .disclaimer{margin-top:32px;padding:14px;background:#fefce8;border:1px solid #fde047;border-radius:8px;font-size:12px;color:#854d0e;line-height:1.6}
  .footer{margin-top:40px;border-top:1px solid #e5e7eb;padding-top:12px;font-size:11px;color:#9ca3af;display:flex;justify-content:space-between}
  @media print{body{padding:0}.no-print{display:none}}
</style>
</head>
<body>
<div class="header">
  <div class="logo">PulmoCare IA <span>Plateforme de détection du cancer du poumon</span></div>
  <div style="text-align:right;font-size:12px;color:#6b7280">Rapport #<?= $id ?><br><?= $date ?></div>
</div>
<button class="no-print" onclick="window.print()" style="margin-bottom:20px;padding:8px 18px;background:#1e40af;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px">
  🖨️ Imprimer / Enregistrer en PDF
</button>
<h2>Résultat de l'analyse IA</h2>
<table>
  <tr><td>Résultat</td><td><span class="result-badge"><?= ucfirst(htmlspecialchars($rt)) ?></span></td></tr>
  <tr><td>Score de confiance</td><td><strong><?= number_format((float)$detection['confidence_score'],1) ?>%</strong></td></tr>
  <tr><td>Stade détecté</td><td><?= $detection['stage']?'Stade '.htmlspecialchars($detection['stage']):'Non applicable' ?></td></tr>
  <tr><td>Version modèle IA</td><td>CNN v<?= htmlspecialchars($detection['model_version']??'1.0') ?></td></tr>
  <tr><td>Temps de traitement</td><td><?= number_format((int)$detection['processing_time_ms']) ?> ms</td></tr>
  <tr><td>Date d'analyse</td><td><?= $date ?></td></tr>
</table>
<h2>Informations patient</h2>
<table>
  <tr><td>Nom complet</td><td><?= htmlspecialchars($detection['patient_prenom'].' '.$detection['patient_nom']) ?></td></tr>
  <?php if ($detection['patient_age']): ?><tr><td>Âge</td><td><?= (int)$detection['patient_age'] ?> ans</td></tr><?php endif; ?>
  <?php if ($detection['patient_sexe']): ?><tr><td>Sexe</td><td><?= $detection['patient_sexe']==='M'?'Masculin':($detection['patient_sexe']==='F'?'Féminin':'Autre') ?></td></tr><?php endif; ?>
  <?php if ($detection['patient_code']): ?><tr><td>Code dossier</td><td><?= htmlspecialchars($detection['patient_code']) ?></td></tr><?php endif; ?>
</table>
<h2>Médecin traitant</h2>
<table>
  <tr><td>Nom</td><td>Dr. <?= htmlspecialchars($detection['medecin_prenom'].' '.$detection['medecin_nom']) ?></td></tr>
  <tr><td>Spécialité</td><td><?= htmlspecialchars($detection['specialite']??'—') ?></td></tr>
  <?php if ($detection['hospital_nom']): ?><tr><td>Établissement</td><td><?= htmlspecialchars($detection['hospital_nom']) ?></td></tr><?php endif; ?>
</table>
<?php if ($detection['notes_medecin']): ?>
<h2>Notes cliniques</h2>
<div style="padding:14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:14px;line-height:1.7">
  <?= nl2br(htmlspecialchars($detection['notes_medecin'])) ?>
</div>
<?php endif; ?>
<div class="disclaimer">
  ⚠️ Ce rapport est généré automatiquement par un système d'intelligence artificielle à titre d'aide au diagnostic uniquement.
  Il ne remplace pas l'expertise d'un médecin spécialiste. Toute décision thérapeutique doit être prise par un professionnel de santé qualifié.
</div>
<div class="footer">
  <span>PulmoCare IA — Plateforme médicale intelligente</span>
  <span>Généré le <?= date('d/m/Y à H:i') ?></span>
</div>
</body></html>
