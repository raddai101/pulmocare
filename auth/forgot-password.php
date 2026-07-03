<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/functions/functions.php';

if (auth_is_logged()) response_redirect('/auth/dashboard.php');

$step    = isset($_GET['token']) ? 'reset' : 'request';
$token   = security_sanitize($_GET['token'] ?? '');
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!security_verify_csrf($_POST['_token'] ?? '')) {
        $errors[] = 'Requête invalide.';
    } elseif ($step === 'request') {
        $r = auth_send_reset_link(security_sanitize($_POST['email'] ?? ''));
        $success = $r['message'];
    } elseif ($step === 'reset') {
        $r = auth_reset_password($token, $_POST['password'] ?? '', $_POST['password_confirmation'] ?? '');
        if ($r['success']) { html_set_flash('success', $r['message']); response_redirect('/auth/login.php'); }
        else $errors[] = $r['message'];
    }
}
$pageTitle = html_page_title($step === 'reset' ? 'Nouveau mot de passe' : 'Mot de passe oublié');
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $pageTitle ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg-deep:#050a18;--bg-card:#0d1526;--bg-input:#111d35;--border:rgba(59,130,246,.18);--border-focus:#3b82f6;--text-primary:#f0f4ff;--text-muted:#8899b4;--blue-500:#3b82f6;--blue-glow:rgba(59,130,246,.35);--red-400:#f87171;--green-400:#4ade80;--radius-lg:16px;--radius-md:10px;--tr:.25s cubic-bezier(.4,0,.2,1)}
body{min-height:100vh;background:var(--bg-deep);font-family:'Inter',sans-serif;color:var(--text-primary);display:flex;align-items:center;justify-content:center;padding:24px}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 20% 10%,rgba(59,130,246,.12) 0%,transparent 60%),radial-gradient(ellipse 60% 50% at 80% 90%,rgba(99,102,241,.10) 0%,transparent 60%);pointer-events:none}
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:48px 44px;width:100%;max-width:460px;position:relative;z-index:1;box-shadow:0 24px 60px rgba(0,0,0,.5)}
.logo{display:flex;align-items:center;gap:12px;margin-bottom:32px}
.logo-icon{width:46px;height:46px;background:linear-gradient(135deg,var(--blue-500),#6366f1);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 6px 20px var(--blue-glow)}
.logo-text{font-family:'Space Grotesk',sans-serif;font-size:20px;font-weight:700;letter-spacing:-.4px}
.logo-text span{display:block;font-size:10px;font-weight:400;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px}
h1{font-family:'Space Grotesk',sans-serif;font-size:22px;font-weight:700;letter-spacing:-.4px;margin-bottom:6px}
.subtitle{font-size:14px;color:var(--text-muted);margin-bottom:28px;line-height:1.6}
.alert{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:var(--radius-md);font-size:13.5px;margin-bottom:20px}
.alert--error  {background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:var(--red-400)}
.alert--success{background:rgba(74,222,128,.1); border:1px solid rgba(74,222,128,.3); color:var(--green-400)}
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:13px;font-weight:500;color:var(--text-muted);margin-bottom:7px}
.input-wrap{position:relative}
.input-wrap__icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:15px;pointer-events:none}
.form-control{width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-md);padding:13px 14px 13px 42px;color:var(--text-primary);font-size:14px;font-family:inherit;outline:none;transition:border-color var(--tr),box-shadow var(--tr)}
.form-control:focus{border-color:var(--border-focus);box-shadow:0 0 0 3px var(--blue-glow)}
.toggle-btn{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:15px;padding:4px}
.btn-submit{width:100%;padding:14px;background:linear-gradient(135deg,var(--blue-500),#6366f1);border:none;border-radius:var(--radius-md);color:#fff;font-size:15px;font-weight:600;font-family:inherit;cursor:pointer;box-shadow:0 4px 20px var(--blue-glow);transition:opacity var(--tr),transform var(--tr);margin-top:8px}
.btn-submit:hover{opacity:.92;transform:translateY(-1px)}
.back-link{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:20px;color:var(--text-muted);text-decoration:none;font-size:13.5px;transition:color var(--tr)}
.back-link:hover{color:var(--blue-500)}
/* Strength bar */
.pwd-bar{height:4px;background:rgba(255,255,255,.08);border-radius:99px;overflow:hidden;margin-top:6px}
.pwd-fill{height:100%;border-radius:99px;transition:width .3s,background .3s}
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon"><i class="fa-solid fa-lungs"></i></div>
    <div class="logo-text">PulmoCare IA<span>Plateforme médicale</span></div>
  </div>

  <?php if ($step === 'request'): ?>
  <h1>Mot de passe oublié ?</h1>
  <p class="subtitle">Saisissez votre email professionnel. Vous recevrez un lien de réinitialisation si votre compte existe.</p>

  <?php if ($success): ?><div class="alert alert--success"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php foreach($errors as $e): ?><div class="alert alert--error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

  <form method="POST">
    <?= html_csrf_input() ?>
    <div class="form-group">
      <label class="form-label" for="email">Adresse email professionnelle</label>
      <div class="input-wrap">
        <i class="fa-regular fa-envelope input-wrap__icon"></i>
        <input type="email" id="email" name="email" class="form-control" placeholder="dr.nom@hopital.fr" required>
      </div>
    </div>
    <button type="submit" class="btn-submit"><i class="fa-solid fa-paper-plane"></i>&nbsp; Envoyer le lien</button>
  </form>

  <?php else: ?>
  <h1>Nouveau mot de passe</h1>
  <p class="subtitle">Choisissez un mot de passe sécurisé pour votre compte médical.</p>

  <?php foreach($errors as $e): ?><div class="alert alert--error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

  <form method="POST">
    <?= html_csrf_input() ?>
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <div class="form-group">
      <label class="form-label">Nouveau mot de passe</label>
      <div class="input-wrap">
        <i class="fa-solid fa-lock input-wrap__icon"></i>
        <input type="password" name="password" id="pwd" class="form-control" required autocomplete="new-password">
        <button type="button" class="toggle-btn" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password'"><i class="fa-regular fa-eye"></i></button>
      </div>
      <div class="pwd-bar"><div class="pwd-fill" id="pf" style="width:0;background:var(--red-400)"></div></div>
    </div>
    <div class="form-group">
      <label class="form-label">Confirmer le mot de passe</label>
      <div class="input-wrap">
        <i class="fa-solid fa-lock input-wrap__icon"></i>
        <input type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
      </div>
    </div>
    <button type="submit" class="btn-submit"><i class="fa-solid fa-key"></i>&nbsp; Réinitialiser</button>
  </form>
  <script>
  const p=document.getElementById('pwd'),pf=document.getElementById('pf');
  p?.addEventListener('input',()=>{
    const v=p.value,s=[v.length>=8,/[A-Z]/.test(v),/[a-z]/.test(v),/[0-9]/.test(v),/[^A-Za-z0-9]/.test(v)].filter(Boolean).length;
    pf.style.width=s*20+'%';
    pf.style.background=['','#ef4444','#f59e0b','#f59e0b','#22c55e','#22c55e'][s];
  });
  </script>
  <?php endif; ?>

  <a href="/pulmocare/auth/login.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Retour à la connexion</a>
</div>
</body></html>
