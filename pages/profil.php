<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/functions/functions.php';

auth_require('medecin');

$user     = auth_current_user();
$userId   = (int)$user['id'];
$userFull = user_get_with_hospital($userId);
$stats    = user_get_stats($userId);
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!security_verify_csrf($_POST['_token'] ?? '')) {
        $errors[] = 'Requête invalide.';
    } else {
        $tab = $_POST['tab'] ?? 'profile';

        // Repli sans JavaScript (progressive enhancement) : la mini-form de
        // l'avatar poste tab=avatar et ne doit JAMAIS déclencher la
        // validation du formulaire "Informations professionnelles" — c'était
        // exactement le bug précédent (avatar rattaché au tab "profile").
        if ($tab === 'avatar') {
            if (empty($_FILES['avatar']['tmp_name'])) {
                $errors[] = 'Aucun fichier reçu.';
            } else {
                $r = user_update_avatar($userId, $_FILES['avatar']);
                if (!$r['success']) {
                    $errors[] = $r['message'];
                } else {
                    html_set_flash('success', 'Avatar mis à jour.');
                    response_redirect('/pages/profil.php');
                }
            }
        }

        if ($tab === 'profile') {
            $r = user_update_profile($userId, $_POST);
            if (!$r['success']) { foreach ($r['errors'] as $msgs) $errors = array_merge($errors, $msgs); }
            else { html_set_flash('success', 'Profil mis à jour avec succès.'); response_redirect('/pages/profil.php'); }
        }

        if ($tab === 'password') {
            $r = user_change_password($userId, $_POST['current_password'] ?? '', $_POST['new_password'] ?? '', $_POST['new_password_confirmation'] ?? '');
            if (!$r['success']) $errors[] = $r['message'];
            else { html_set_flash('success', 'Mot de passe modifié.'); response_redirect('/pages/profil.php'); }
        }
    }
}

$pageTitle = html_page_title('Mon profil');
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $pageTitle ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="/pulmocare/assets/css/style.css">
<link rel="stylesheet" href="/pulmocare/assets/css/human-clinic.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg-base:#060d1a;--bg-card:#0b1629;--bg-hover:#0f1e38;--bg-input:#0f1e38;--border:rgba(59,130,246,.14);--border-focus:#3b82f6;--blue-500:#3b82f6;--blue-glow:rgba(59,130,246,.3);--indigo:#6366f1;--green:#22c55e;--amber:#f59e0b;--red:#ef4444;--text-1:#e8edf8;--text-2:#7f93b4;--text-3:#4a607a;--radius:12px;--sidebar-w:260px;--header-h:70px;--tr:.22s ease}
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
.content{padding:32px;flex:1;max-width:900px}
.btn-primary{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:linear-gradient(135deg,var(--blue-500),var(--indigo));border:none;border-radius:9px;color:#fff;font-size:13.5px;font-weight:600;cursor:pointer;transition:opacity var(--tr);box-shadow:0 4px 14px var(--blue-glow);font-family:inherit}
.btn-primary:hover{opacity:.9}
.alert{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:9px;font-size:13.5px;margin-bottom:20px;animation:alertIn .3s ease}
@keyframes alertIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.alert--success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:#4ade80}
.alert--error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#f87171}
/* Profile hero */
.profile-hero{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:28px;display:flex;align-items:center;gap:24px;margin-bottom:24px;position:relative;overflow:hidden}
.profile-hero::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--blue-500),var(--indigo))}
.profile-hero__avatar-wrap{position:relative;cursor:pointer}
.profile-hero__avatar{width:88px;height:88px;border-radius:50%;object-fit:cover;border:3px solid rgba(59,130,246,.3);display:block;transition:opacity .2s ease}
.profile-hero__avatar-wrap.is-uploading .profile-hero__avatar{opacity:.45}
.profile-hero__avatar-overlay{position:absolute;inset:0;background:rgba(0,0,0,.6);border-radius:50%;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity var(--tr);color:#fff;font-size:18px}
.profile-hero__avatar-wrap:hover .profile-hero__avatar-overlay{opacity:1}
.profile-hero__avatar-wrap.is-uploading .profile-hero__avatar-overlay{opacity:1}
.profile-hero__avatar-spinner{position:absolute;inset:0;display:none;align-items:center;justify-content:center;color:#fff;font-size:20px}
.profile-hero__avatar-wrap.is-uploading .profile-hero__avatar-spinner{display:flex}
.profile-hero__avatar-wrap.is-uploading .profile-hero__avatar-overlay i{display:none}
.profile-hero__info{flex:1}
.profile-hero__name{font-family:'Space Grotesk',sans-serif;font-size:22px;font-weight:700;letter-spacing:-.3px}
.profile-hero__meta{display:flex;flex-wrap:wrap;gap:12px;margin-top:8px}
.profile-hero__tag{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text-2)}
.profile-hero__tag i{color:var(--blue-500)}
.profile-hero__stats{display:flex;gap:20px}
.hero-stat{text-align:center}
.hero-stat__val{font-family:'Space Grotesk',sans-serif;font-size:22px;font-weight:700}
.hero-stat__label{font-size:11px;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px}
/* Tabs */
.tabs{display:flex;gap:4px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:6px;margin-bottom:22px}
.tab-btn{flex:1;padding:10px 16px;border:none;border-radius:9px;background:none;color:var(--text-2);font-size:13.5px;font-weight:500;cursor:pointer;transition:all var(--tr);font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px}
.tab-btn:hover{color:var(--text-1);background:var(--bg-hover)}
.tab-btn.active{background:rgba(59,130,246,.14);color:var(--blue-500)}
/* Form card */
.form-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.form-card-header{padding:18px 24px;border-bottom:1px solid var(--border)}
.form-card-header h3{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:600;display:flex;align-items:center;gap:10px}
.form-card-header p{font-size:13px;color:var(--text-2);margin-top:3px}
.form-card-body{padding:24px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-label{font-size:12.5px;font-weight:500;color:var(--text-2)}
.form-label .req{color:var(--red);margin-left:2px}
.form-control{background:var(--bg-input);border:1px solid var(--border);border-radius:9px;padding:11px 14px;color:var(--text-1);font-size:13.5px;font-family:inherit;outline:none;transition:border-color var(--tr),box-shadow var(--tr);width:100%}
.form-control:focus{border-color:var(--border-focus);box-shadow:0 0 0 3px var(--blue-glow)}
.form-control:disabled{opacity:.5;cursor:not-allowed}
.form-hint{font-size:11.5px;color:var(--text-3);margin-top:3px}
/* Password strength */
.pwd-strength{margin-top:6px}
.pwd-bar{height:4px;background:var(--bg-hover);border-radius:99px;overflow:hidden}
.pwd-fill{height:100%;border-radius:99px;transition:width .3s,background .3s}
.pwd-label{font-size:11px;color:var(--text-2);margin-top:4px}
/* Tab panels */
.tab-panel{display:none}
.tab-panel.active{display:block}
/* Info readonly */
.info-readonly{background:var(--bg-hover);border-radius:9px;padding:11px 14px;font-size:13.5px;color:var(--text-2);border:1px solid var(--border)}
@media(max-width:768px){.sidebar{transform:translateX(-100%)}.main{margin-left:0}.content{padding:20px 16px}.form-grid{grid-template-columns:1fr}.profile-hero{flex-direction:column;text-align:center}.profile-hero__meta{justify-content:center}.profile-hero__stats{justify-content:center}}
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
    <a href="/pulmocare/auth/dashboard.php"  class="nav-link"><i class="fa-solid fa-gauge-high"></i> Tableau de bord</a>
    <a href="/pulmocare/pages/detection.php" class="nav-link"><i class="fa-solid fa-magnifying-glass-plus"></i> Nouvelle analyse</a>
    <a href="/pulmocare/pages/resultats.php" class="nav-link"><i class="fa-solid fa-folder-open"></i> Mes analyses</a>
    <span class="nav-section-label">Compte</span>
    <a href="/pulmocare/pages/profil.php"  class="nav-link active"><i class="fa-solid fa-user-doctor"></i> Mon profil</a>
    <a href="/pulmocare/auth/logout.php"   class="nav-link" onclick="return confirm('Se déconnecter ?')"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</a>
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
      <h2><i class="fa-solid fa-user-doctor" style="color:var(--blue-500)"></i> Mon profil</h2>
      <p>Gérez vos informations professionnelles et paramètres de sécurité</p>
    </div>
  </header>

  <main class="content">

    <?= html_flash() ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert--error">
      <i class="fa-solid fa-circle-exclamation"></i>
      <div><?php foreach($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div>
    </div>
    <?php endif; ?>

    <!-- Hero card -->
    <div class="profile-hero">
      <!-- Mini-form dédiée à l'avatar : tab=avatar, aucun champ profil,
           n'est plus jamais soumise "en entier" côté JS (upload en AJAX) ;
           conservée comme repli fonctionnel si JavaScript est désactivé. -->
      <form method="POST" enctype="multipart/form-data" id="avatarForm" style="display:contents">
        <?= html_csrf_input() ?>
        <input type="hidden" name="tab" value="avatar">
        <label class="profile-hero__avatar-wrap" title="Changer la photo" id="avatarWrap">
          <img src="<?= htmlspecialchars(html_avatar_url($userFull['avatar'] ?? null)) ?>" alt="Avatar" class="profile-hero__avatar" id="heroAvatar">
          <div class="profile-hero__avatar-overlay"><i class="fa-solid fa-camera"></i></div>
          <div class="profile-hero__avatar-spinner"><i class="fa-solid fa-circle-notch fa-spin"></i></div>
          <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" style="display:none" id="avatarInput">
        </label>
      </form>
      <div class="profile-hero__info">
        <div class="profile-hero__name">Dr. <?= htmlspecialchars($userFull['prenom'].' '.$userFull['nom']) ?></div>
        <div class="profile-hero__meta">
          <span class="profile-hero__tag"><i class="fa-solid fa-stethoscope"></i><?= htmlspecialchars($userFull['specialite'] ?? '—') ?></span>
          <span class="profile-hero__tag"><i class="fa-solid fa-hospital"></i><?= htmlspecialchars($userFull['hospital_nom'] ?? 'Non renseigné') ?><?php if ($userFull['hospital_ville']??null): ?>, <?= htmlspecialchars($userFull['hospital_ville']) ?><?php endif; ?></span>
          <span class="profile-hero__tag"><i class="fa-solid fa-envelope"></i><?= htmlspecialchars($userFull['email']) ?></span>
          <?php if ($userFull['last_login_at']): ?><span class="profile-hero__tag"><i class="fa-solid fa-clock"></i>Dernière connexion : <?= html_format_date($userFull['last_login_at'],'d/m/Y à H:i') ?></span><?php endif; ?>
        </div>
      </div>
      <div class="profile-hero__stats">
        <div class="hero-stat"><div class="hero-stat__val"><?= (int)($stats['total_analyses']??0) ?></div><div class="hero-stat__label">Analyses</div></div>
        <div class="hero-stat"><div class="hero-stat__val" style="color:var(--green)"><?= (int)($stats['total_normaux']??0) ?></div><div class="hero-stat__label">Normaux</div></div>
        <div class="hero-stat"><div class="hero-stat__val" style="color:var(--red)"><?= (int)($stats['total_cancereux']??0) ?></div><div class="hero-stat__label">Cancéreux</div></div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <button class="tab-btn active" data-tab="profile"><i class="fa-solid fa-user"></i> Informations</button>
      <button class="tab-btn" data-tab="password"><i class="fa-solid fa-lock"></i> Mot de passe</button>
      <button class="tab-btn" data-tab="security"><i class="fa-solid fa-shield-halved"></i> Sécurité</button>
    </div>

    <!-- Tab: Profile -->
    <div class="tab-panel active" id="tab-profile">
      <div class="form-card">
        <div class="form-card-header">
          <h3><i class="fa-solid fa-id-card" style="color:var(--blue-500)"></i> Informations professionnelles</h3>
          <p>Ces informations sont visibles sur vos rapports d'analyse</p>
        </div>
        <div class="form-card-body">
          <form method="POST">
            <?= html_csrf_input() ?>
            <input type="hidden" name="tab" value="profile">
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">Nom <span class="req">*</span></label>
                <input type="text" name="nom" class="form-control" value="<?= htmlspecialchars($userFull['nom']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">Prénom <span class="req">*</span></label>
                <input type="text" name="prenom" class="form-control" value="<?= htmlspecialchars($userFull['prenom']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">Adresse email</label>
                <div class="info-readonly"><?= htmlspecialchars($userFull['email']) ?></div>
                <span class="form-hint">L'email ne peut pas être modifié ici.</span>
              </div>
              <div class="form-group">
                <label class="form-label">Téléphone <span class="req">*</span></label>
                <input type="tel" name="telephone" class="form-control" value="<?= htmlspecialchars($userFull['telephone'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">Spécialité <span class="req">*</span></label>
                <input type="text" name="specialite" class="form-control" value="<?= htmlspecialchars($userFull['specialite'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">N° Ordre professionnel</label>
                <div class="info-readonly"><?= htmlspecialchars($userFull['numero_ordre'] ?? '—') ?></div>
              </div>
              <div class="form-group form-full">
                <label class="form-label">Établissement hospitalier</label>
                <div class="info-readonly"><?= htmlspecialchars(($userFull['hospital_nom'] ?? '').' — '.($userFull['hospital_ville'] ?? '')) ?></div>
                <span class="form-hint">Contactez l'administrateur pour modifier l'établissement.</span>
              </div>
            </div>
            <div style="margin-top:24px;display:flex;justify-content:flex-end">
              <button type="submit" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> Enregistrer les modifications</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Tab: Password -->
    <div class="tab-panel" id="tab-password">
      <div class="form-card">
        <div class="form-card-header">
          <h3><i class="fa-solid fa-lock" style="color:var(--indigo)"></i> Modifier le mot de passe</h3>
          <p>Utilisez un mot de passe fort d'au moins 8 caractères</p>
        </div>
        <div class="form-card-body">
          <form method="POST" id="pwdForm">
            <?= html_csrf_input() ?>
            <input type="hidden" name="tab" value="password">
            <div class="form-grid">
              <div class="form-group form-full">
                <label class="form-label">Mot de passe actuel <span class="req">*</span></label>
                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
              </div>
              <div class="form-group">
                <label class="form-label">Nouveau mot de passe <span class="req">*</span></label>
                <input type="password" name="new_password" class="form-control" id="newPwd" required autocomplete="new-password">
                <div class="pwd-strength">
                  <div class="pwd-bar"><div class="pwd-fill" id="pwdFill" style="width:0%;background:var(--red)"></div></div>
                  <div class="pwd-label" id="pwdLabel">Saisissez votre nouveau mot de passe</div>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Confirmer <span class="req">*</span></label>
                <input type="password" name="new_password_confirmation" class="form-control" id="confirmPwd" required autocomplete="new-password">
              </div>
            </div>
            <div style="margin-top:8px;padding:12px 14px;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.15);border-radius:9px;font-size:12.5px;color:var(--text-2);line-height:1.7">
              <strong style="color:var(--text-1)">Critères :</strong>
              <span id="c-len"  style="margin-left:8px">8+ caractères</span> ·
              <span id="c-upper">Majuscule</span> ·
              <span id="c-lower">Minuscule</span> ·
              <span id="c-num">  Chiffre</span> ·
              <span id="c-spec"> Caractère spécial</span>
            </div>
            <div style="margin-top:20px;display:flex;justify-content:flex-end">
              <button type="submit" class="btn-primary"><i class="fa-solid fa-key"></i> Changer le mot de passe</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Tab: Security -->
    <div class="tab-panel" id="tab-security">
      <div class="form-card">
        <div class="form-card-header">
          <h3><i class="fa-solid fa-shield-halved" style="color:var(--green)"></i> Informations de sécurité</h3>
          <p>Historique des connexions et état du compte</p>
        </div>
        <div class="form-card-body">
          <div style="display:flex;flex-direction:column;gap:14px">
            <?php
            $secItems = [
                ['label'=>'Statut du compte',       'value'=> $userFull['is_active'] ? '<span style="color:var(--green)"><i class="fa-solid fa-circle-check"></i> Actif</span>' : '<span style="color:var(--red)">Inactif</span>'],
                ['label'=>'Dernière connexion',     'value'=> $userFull['last_login_at'] ? html_format_date($userFull['last_login_at']) : 'Jamais'],
                ['label'=>'IP dernière connexion',  'value'=> htmlspecialchars($userFull['last_login_ip'] ?? '—')],
                ['label'=>'Email vérifié le',       'value'=> $userFull['email_verified_at'] ? html_format_date($userFull['email_verified_at']) : 'Non vérifié'],
                ['label'=>'Compte créé le',         'value'=> html_format_date($userFull['created_at'])],
                ['label'=>'Rôle',                   'value'=> ucfirst(htmlspecialchars($userFull['role']))],
            ];
            foreach ($secItems as $item): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:var(--bg-hover);border-radius:9px;font-size:13.5px">
              <span style="color:var(--text-2)"><?= $item['label'] ?></span>
              <span style="font-weight:500"><?= $item['value'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="margin-top:20px;padding:14px 16px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.18);border-radius:9px;font-size:13px;color:#f87171;line-height:1.6">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Si vous suspectez une activité suspecte sur votre compte, changez immédiatement votre mot de passe et contactez l'administrateur.
          </div>
        </div>
      </div>
    </div>

  </main>
</div>

<script>
// Tabs
const tabBtns   = document.querySelectorAll('.tab-btn');
const tabPanels = document.querySelectorAll('.tab-panel');
tabBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    const target = btn.dataset.tab;
    tabBtns.forEach(b => b.classList.remove('active'));
    tabPanels.forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + target)?.classList.add('active');
  });
});

// Password strength
const newPwd   = document.getElementById('newPwd');
const fill     = document.getElementById('pwdFill');
const lbl      = document.getElementById('pwdLabel');
const criteria = {len:'c-len',upper:'c-upper',lower:'c-lower',num:'c-num',spec:'c-spec'};

newPwd?.addEventListener('input', () => {
  const v = newPwd.value;
  const checks = {
    len:   v.length >= 8,
    upper: /[A-Z]/.test(v),
    lower: /[a-z]/.test(v),
    num:   /[0-9]/.test(v),
    spec:  /[^A-Za-z0-9]/.test(v),
  };
  const score = Object.values(checks).filter(Boolean).length;
  const pct   = score * 20;
  const colors = ['','#ef4444','#f59e0b','#f59e0b','#22c55e','#22c55e'];
  const labels = ['','Très faible','Faible','Moyen','Fort','Très fort'];
  fill.style.width   = pct + '%';
  fill.style.background = colors[score] || '#ef4444';
  lbl.textContent    = labels[score] || '';
  for (const [k,id] of Object.entries(criteria)) {
    const el = document.getElementById(id);
    if (el) el.style.color = checks[k] ? '#22c55e' : '';
  }
});
</script>

<!-- ── Avatar : aperçu instantané + envoi asynchrone (façon "senior") ── -->
<script>
(function () {
    const form       = document.getElementById('avatarForm');
    const wrap       = document.getElementById('avatarWrap');
    const input      = document.getElementById('avatarInput');
    const heroAvatar = document.getElementById('heroAvatar');
    const sideAvatar = document.querySelector('.sidebar__avatar');
    if (!form || !input || !heroAvatar) return;

    const csrfToken = form.querySelector('input[name="_token"]')?.value || '';
    const uploadUrl = '/pulmocare/backend/api/avatarApi.php';

    let objectUrl   = null;
    let fallbackSrc = heroAvatar.src;

    function setAvatar(src) {
        heroAvatar.src = src;
        if (sideAvatar) sideAvatar.src = src;
    }

    function toast(type, message) {
        const container = document.querySelector('main.content');
        if (!container) return;
        const el = document.createElement('div');
        el.className = 'alert alert--' + type;
        el.innerHTML = '<i class="fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation') + '"></i><span>' + message + '</span>';
        container.prepend(el);
        setTimeout(() => el.remove(), 4000);
    }

    input.addEventListener('change', async () => {
        const file = input.files && input.files[0];
        if (!file) return;

        // 1) Aperçu instantané côté client — ObjectURL est immédiat et
        //    beaucoup plus léger que FileReader/base64 pour de l'affichage.
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = URL.createObjectURL(file);
        setAvatar(objectUrl);
        wrap?.classList.add('is-uploading');

        // 2) Envoi asynchrone en tâche de fond — n'affecte ni ne recharge
        //    le reste du formulaire "Informations professionnelles".
        const formData = new FormData();
        formData.append('avatar', file);
        formData.append('_token', csrfToken);

        try {
            const res  = await fetch(uploadUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            });
            const json = await res.json();

            if (!res.ok || !json.success) {
                throw new Error(json.message || "Échec de l'envoi de l'avatar.");
            }

            // 3) On remplace l'aperçu local (ObjectURL) par l'URL réelle,
            //    définitivement enregistrée côté serveur.
            const finalUrl = json.data.avatar_url;
            setAvatar(finalUrl);
            fallbackSrc = finalUrl;
            toast('success', json.message || 'Avatar mis à jour.');
        } catch (err) {
            console.error('[Avatar] Upload error:', err);
            setAvatar(fallbackSrc);
            toast('error', err.message || "Impossible d'envoyer l'avatar. Réessayez.");
        } finally {
            wrap?.classList.remove('is-uploading');
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            input.value = '';
        }
    });
})();
</script>
</body></html>