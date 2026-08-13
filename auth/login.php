<?php
declare(strict_types=1);

use App\Config\SessionManager;

require_once __DIR__ . '/../backend/functions/functions.php';

// Rediriger si déjà connecté
if (auth_is_logged()) {
    response_redirect('/pulmocare/auth/dashboard.php');
}

$errors  = [];
$success = '';

// ── Traitement du formulaire ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!security_verify_csrf($_POST['_token'] ?? '')) {
        $errors[] = 'Requête invalide. Veuillez réessayer.';
    } else {
        $email    = security_sanitize($_POST['email']    ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        $result = auth_login($email, $password, $remember);

        if ($result['success']) {
            SessionManager::rotateCsrfToken();
            html_set_flash('success', 'Bienvenue, Dr. ' . $result['user']['prenom'] . ' !');
            response_redirect('/pulmocare/auth/dashboard.php');
        } else {
            $errors[] = $result['message'];
        }
    }
}

$pageTitle = html_page_title('Connexion');
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $pageTitle ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        /* ============================================================
           LOGIN — PulmoCare IA
           Deux volets : formulaire à gauche, portrait clinique à droite
           séparés par une diagonale. Palette maison (sauge / blanc).
           ============================================================ */

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --paper:      #f5f7f4;
            --card:       #ffffff;
            --teal-900:   #0e3d38;
            --teal-700:   #10665e;
            --teal-600:   #177e73;
            --teal-500:   #23948a;
            --teal-glow:  rgba(23, 126, 115, .22);
            --clay:       #c78622;
            --clay-soft:  #f4e1b8;
            --ink:        #1c2b2b;
            --ink-soft:   #5f6f6d;
            --ink-faint:  #93a29e;
            --line:       rgba(28, 43, 43, .12);
            --radius:     20px;
        }

        html { color-scheme: light; }

        body {
            min-height: 100vh;
            background: var(--paper);
            font-family: 'Inter', sans-serif;
            color: var(--ink);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
        }

        /* ── Shell ─────────────────────────────────────────── */
        .shell {
            width: 100%;
            max-width: 1160px;
            min-height: 640px;
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: 0 30px 70px rgba(20, 40, 38, .16);
            display: grid;
            grid-template-columns: minmax(0, 460px) 1fr;
            overflow: hidden;
            position: relative;
        }

        /* ── Colonne gauche : formulaire ──────────────────── */
        .panel-form {
            padding: 52px 56px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            z-index: 2;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 48px;
        }

        .brand__mark {
            width: 40px; height: 40px;
            border-radius: 11px;
            background: var(--teal-600);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .brand__name {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 19px;
            font-weight: 700;
            letter-spacing: -.3px;
            color: var(--ink);
        }

        .intro h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 27px;
            font-weight: 700;
            letter-spacing: -.4px;
            margin-bottom: 8px;
            color: var(--teal-900);
        }

        .intro p {
            font-size: 14px;
            color: var(--ink-soft);
            line-height: 1.6;
            margin-bottom: 30px;
            max-width: 340px;
        }

        /* Alerts */
        .alert-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 18px; }
        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 11px 14px; border-radius: 10px; font-size: 13px; line-height: 1.5;
        }
        .alert--error   { background: #fdf0ef; border: 1px solid #f3c9c4; color: #a3392f; }
        .alert--success { background: #eef7ef; border: 1px solid #bfe3c3; color: #1f7a3c; }

        /* Form */
        .field { margin-bottom: 18px; }
        .field label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: 7px;
        }

        .field-shell {
            position: relative;
            border: 1.5px solid var(--line);
            border-radius: 12px;
            background: #fbfcfb;
            transition: border-color .18s ease, box-shadow .18s ease;
        }
        .field-shell:focus-within {
            border-color: var(--teal-600);
            box-shadow: 0 0 0 4px var(--teal-glow);
            background: #fff;
        }

        .field-shell i.f-icon {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: var(--ink-faint); font-size: 14px; pointer-events: none;
        }

        .field-shell input {
            width: 100%;
            border: none; outline: none; background: transparent;
            padding: 13px 14px 13px 40px;
            font-size: 14px; font-family: inherit; color: var(--ink);
        }
        .field-shell input::placeholder { color: var(--ink-faint); }

        .f-toggle {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: var(--ink-faint);
            cursor: pointer; padding: 6px; font-size: 14px;
        }
        .f-toggle:hover { color: var(--ink); }

        .row-between {
            display: flex; align-items: center; justify-content: space-between;
            margin: 4px 0 26px; font-size: 12.5px;
        }

        .check {
            display: flex; align-items: center; gap: 7px; color: var(--ink-soft); cursor: pointer;
        }
        .check input { accent-color: var(--teal-600); width: 15px; height: 15px; }

        .row-between a { color: var(--teal-600); text-decoration: none; font-weight: 500; }
        .row-between a:hover { text-decoration: underline; }

        .btn-go {
            width: 100%;
            padding: 14px;
            background: var(--teal-600);
            border: none; border-radius: 12px;
            color: #fff; font-size: 14.5px; font-weight: 600;
            font-family: inherit; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 9px;
            box-shadow: 0 10px 24px var(--teal-glow);
            transition: background .18s ease, transform .18s ease;
        }
        .btn-go:hover { background: var(--teal-700); transform: translateY(-1px); }
        .btn-go:active { transform: none; }
        .btn-go .spinner { display: none; }
        .btn-go.loading .label { display: none; }
        .btn-go.loading .spinner { display: inline-flex; }

        .panel-form__footer {
            margin-top: 28px;
            font-size: 12.5px;
            color: var(--ink-faint);
        }
        .panel-form__footer a { color: var(--teal-600); text-decoration: none; font-weight: 600; }
        .panel-form__footer a:hover { text-decoration: underline; }

        /* ── Colonne droite : portrait clinique ───────────── */
        .panel-visual {
            position: relative;
            background: linear-gradient(160deg, var(--teal-500) 0%, var(--teal-700) 55%, var(--teal-900) 100%);
            clip-path: polygon(9% 0, 100% 0, 100% 100%, 0% 100%);
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            padding: 32px 40px;
        }

        .panel-visual::before {
            content: '';
            position: absolute; inset: 0;
            background-image: radial-gradient(circle at 22% 20%, rgba(255,255,255,.09), transparent 45%),
                               radial-gradient(circle at 85% 85%, rgba(199,134,34,.14), transparent 50%);
            pointer-events: none;
        }

        .lang-pill {
            background: var(--clay-soft);
            color: #7a5613;
            padding: 7px 16px;
            border-radius: 99px;
            font-size: 12.5px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 8px;
            border: 1px solid rgba(199,134,34,.35);
            position: relative; z-index: 3;
        }

        .visual-stage {
            position: relative;
            flex: 1;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Anneaux façon "cible de scanner", clin d'oeil au CT Scan */
        .scan-rings {
            position: absolute;
            width: 380px; height: 380px;
            border-radius: 50%;
            border: 1px dashed rgba(255,255,255,.18);
            display: flex; align-items: center; justify-content: center;
        }
        .scan-rings::before, .scan-rings::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            border: 1px dashed rgba(255,255,255,.14);
        }
        .scan-rings::before { width: 300px; height: 300px; }
        .scan-rings::after  { width: 220px; height: 220px; border-color: rgba(199,134,34,.35); }

        /* Portrait hexagonal */
        .hex-frame {
            position: relative;
            width: 258px; height: 320px;
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
            background: var(--clay-soft);
            box-shadow: 0 30px 60px rgba(8, 30, 27, .45);
            z-index: 2;
        }
        .hex-frame img {
            width: 100%; height: 100%; object-fit: cover; display: block;
            filter: saturate(1.02);
        }

        /* Cartes flottantes */
        .float-card {
            position: absolute;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 18px 40px rgba(10, 30, 28, .28);
            z-index: 4;
            padding: 12px 14px;
        }

        .float-card--stats {
            top: 14%; right: -6px;
            width: 178px;
        }
        .float-card--stats .fc-title { font-size: 11.5px; font-weight: 700; color: var(--ink); margin-bottom: 8px; }
        .avatars { display: flex; }
        .avatars span {
            width: 26px; height: 26px; border-radius: 50%;
            border: 2px solid #fff; margin-left: -8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 700; color: #fff;
        }
        .avatars span:first-child { margin-left: 0; }
        .avatars span:nth-child(1) { background: var(--teal-600); }
        .avatars span:nth-child(2) { background: var(--clay); }
        .avatars span:nth-child(3) { background: #4a607a; }
        .avatars span:nth-child(4) { background: var(--teal-900); font-size: 9px; }

        .float-card--contact {
            bottom: 8%; left: -18px;
            width: 208px;
            display: flex; align-items: center; gap: 11px;
        }
        .float-card--contact .fc-icon {
            width: 38px; height: 38px; border-radius: 50%;
            background: var(--teal-600); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; flex-shrink: 0;
        }
        .float-card--contact .fc-title { font-size: 12.5px; font-weight: 700; color: var(--ink); }
        .float-card--contact .fc-sub   { font-size: 10.5px; color: var(--ink-faint); margin-top: 2px; }

        .visual-caption {
            position: relative; z-index: 3;
            color: rgba(255,255,255,.82);
            font-size: 12.5px;
            line-height: 1.6;
            max-width: 300px;
            align-self: flex-start;
            margin-top: 8px;
        }
        .visual-caption strong { color: #fff; font-family: 'Space Grotesk', sans-serif; font-weight: 600; }

        /* ── Responsive ────────────────────────────────────── */
        @media (max-width: 900px) {
            .shell { grid-template-columns: 1fr; max-width: 460px; min-height: 0; }
            .panel-visual { display: none; }
            .panel-form { padding: 44px 32px; }
        }
    </style>
</head>
<body>

<div class="shell">

    <!-- ── FORMULAIRE ─────────────────────────────────────── -->
    <section class="panel-form">
        <div class="brand">
            <div class="brand__mark"><i class="fa-solid fa-lungs"></i></div>
            <div class="brand__name">PulmoCare IA</div>
        </div>

        <div class="intro">
            <h1>Content de vous revoir</h1>
            <p>Connectez-vous avec vos identifiants d'hôpital pour reprendre vos analyses là où vous les avez laissées.</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert-list">
            <?php foreach ($errors as $err): ?>
            <div class="alert alert--error" role="alert">
                <i class="fa-solid fa-circle-exclamation" style="margin-top:2px"></i>
                <span><?= htmlspecialchars($err) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?= html_flash() ?>

        <form method="POST" action="" id="loginForm" novalidate autocomplete="on">
            <?= html_csrf_input() ?>

            <div class="field">
                <label for="email">Adresse email professionnelle</label>
                <div class="field-shell">
                    <i class="fa-regular fa-envelope f-icon"></i>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="dr.nom@hopital.cd"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        required
                        autocomplete="email"
                        spellcheck="false"
                    >
                </div>
            </div>

            <div class="field">
                <label for="password">Mot de passe</label>
                <div class="field-shell">
                    <i class="fa-solid fa-lock f-icon"></i>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="••••••••"
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="f-toggle" id="togglePwd" aria-label="Afficher le mot de passe">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="row-between">
                <label class="check">
                    <input type="checkbox" name="remember" id="remember">
                    Se souvenir de moi
                </label>
                <a href="/pulmocare/auth/forgot-password.php">Mot de passe oublié ?</a>
            </div>

            <button type="submit" class="btn-go" id="loginBtn">
                <span class="label">Se connecter</span>
                <span class="spinner"><i class="fa-solid fa-circle-notch fa-spin"></i>Connexion…</span>
            </button>
        </form>

        <div class="panel-form__footer">
            Pas encore de compte ? <a href="/pulmocare/auth/register.php">Demander un accès</a>
        </div>
    </section>

    <!-- ── VISUEL ──────────────────────────────────────────── -->
    <section class="panel-visual">
        <span class="lang-pill">Français (FR) <i class="fa-solid fa-chevron-down" style="font-size:10px"></i></span>

        <div class="visual-stage">
            <div class="scan-rings"></div>

            <div class="hex-frame">
                <img src="/pulmocare/assets/images/doctorlung.jpg" alt="Médecin de la plateforme PulmoCare IA" onerror="this.style.display='none'">
            </div>

            <!-- <div class="float-card float-card--stats">
                <div class="fc-title">+500 analyses ce mois</div>
                <div class="avatars">
                    <span>DR</span><span>SK</span><span>ML</span><span>+8</span>
                </div>
            </div> -->

            <!-- <div class="float-card float-card--contact">
                <div class="fc-icon"><i class="fa-solid fa-comment-medical"></i></div>
                <div>
                    <div class="fc-title">Avis d'un confrère</div>
                    <div class="fc-sub">Second regard en un clic</div>
                </div>
            </div> -->
        </div>

        <p class="visual-caption"><strong>Une lecture assistée, jamais seule.</strong> Chaque scan reste sous votre œil avant toute décision.</p>
    </section>

</div>

<script>
    const toggleBtn = document.getElementById('togglePwd');
    const pwdInput  = document.getElementById('password');
    toggleBtn.addEventListener('click', () => {
        const show = pwdInput.type === 'password';
        pwdInput.type = show ? 'text' : 'password';
        toggleBtn.querySelector('i').className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
    });

    const form = document.getElementById('loginForm');
    const btn  = document.getElementById('loginBtn');
    form.addEventListener('submit', () => {
        const email = document.getElementById('email').value.trim();
        const pwd   = pwdInput.value;
        if (!email || !pwd) return;
        btn.classList.add('loading');
        btn.disabled = true;
    });
</script>
</body>
</html>