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

// Étapes du workflow réel de la plateforme — affichées dans le guide défilant
$workflowSteps = [
    [
        'icon'  => 'fa-regular fa-id-card',
        'title' => 'Dossier patient',
        'text'  => 'Renseignez l\'identité et les informations essentielles du patient.',
    ],
    [
        'icon'  => 'fa-regular fa-image',
        'title' => 'Import du scan',
        'text'  => 'Déposez l\'image CT Scan — JPG, PNG, DICOM ou TIFF.',
    ],
    [
        'icon'  => 'fa-regular fa-lightbulb',
        'title' => 'Analyse CNN',
        'text'  => 'Le réseau de neurones classe l\'image et localise les zones suspectes.',
    ],
    [
        'icon'  => 'fa-regular fa-file-lines',
        'title' => 'Rapport médical',
        'text'  => 'Consultez le score de confiance et exportez le compte-rendu.',
    ],
];
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $pageTitle ?></title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        /* ══════════════════════════════════════════════════════════
           TOKENS — Identité PulmoCare IA (teal & blanc)
           ══════════════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --teal:         #177e73;
            --teal-deep:    #0f5751;
            --teal-soft:    #e6f2ef;
            --teal-glow:    rgba(23, 126, 115, .18);
            --ink:          #142322;
            --ink-muted:    #5f6f6d;
            --ink-faint:    #94a29f;
            --paper:        #ffffff;
            --canvas:       #f4f8f7;
            --line:         rgba(20, 35, 34, .10);
            --amber:        #c78622;
            --red:          #c0483f;
            --radius-lg:    22px;
            --radius-md:    13px;
            --ease:         cubic-bezier(.22, 1, .36, 1);
        }

        html { scroll-behavior: smooth; }

        body {
            min-height: 100vh;
            background: var(--canvas);
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--ink);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 24px;
        }

        body::before {
            content: '';
            position: fixed; inset: 0;
            background:
                radial-gradient(1100px 620px at 12% -8%, rgba(23,126,115,.09), transparent 55%),
                radial-gradient(900px 560px at 104% 108%, rgba(23,126,115,.06), transparent 55%);
            pointer-events: none;
        }

        /* ══════════════════════════════════════════════════════════
           SHELL
           ══════════════════════════════════════════════════════════ */
        .shell {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1128px;
            min-height: 660px;
            display: grid;
            grid-template-columns: minmax(0, 460px) 1fr;
            background: var(--paper);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow:
                0 1px 0 rgba(20,35,34,.03),
                0 30px 70px -24px rgba(15, 60, 55, .28);
        }

        /* ══════════════════════════════════════════════════════════
           FORM PANEL
           ══════════════════════════════════════════════════════════ */
        .form-panel {
            padding: 52px 52px 44px;
            display: flex;
            flex-direction: column;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 44px;
        }

        .brand__mark {
            width: 40px; height: 40px;
            border-radius: 11px;
            background: var(--teal);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 17px;
            flex-shrink: 0;
        }

        .brand__text {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 16.5px; font-weight: 700;
            letter-spacing: -.2px;
            line-height: 1.15;
        }
        .brand__text span {
            display: block;
            font-size: 10.5px; font-weight: 500;
            color: var(--ink-muted);
            letter-spacing: 1.2px; text-transform: uppercase;
            margin-top: 1px;
        }

        .form-panel h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 28px; font-weight: 700;
            letter-spacing: -.7px;
            line-height: 1.15;
            margin-bottom: 8px;
        }

        .form-panel .lede {
            font-size: 14px; color: var(--ink-muted);
            line-height: 1.55;
            margin-bottom: 30px;
            max-width: 34ch;
        }

        /* Alerts */
        .alert-stack { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
        .alert {
            display: flex; align-items: flex-start; gap: 9px;
            padding: 11px 14px; border-radius: 10px;
            font-size: 13px; line-height: 1.5;
        }
        .alert i { margin-top: 1.5px; flex-shrink: 0; }
        .alert--error   { background: #fbecea; color: #9c332b; border: 1px solid #f3d3ce; }
        .alert--success { background: var(--teal-soft); color: var(--teal-deep); border: 1px solid #cfe6e2; }

        /* Form */
        .field { margin-bottom: 18px; }
        .field label {
            display: block;
            font-size: 12.5px; font-weight: 600;
            color: var(--ink);
            margin-bottom: 7px;
            letter-spacing: .1px;
        }

        .field-shell { position: relative; }
        .field-shell i.icon-lead {
            position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
            color: var(--ink-faint); font-size: 14.5px; pointer-events: none;
            transition: color .2s var(--ease);
        }

        .field-shell input {
            width: 100%;
            background: #fbfdfc;
            border: 1.5px solid var(--line);
            border-radius: var(--radius-md);
            padding: 13px 16px 13px 42px;
            font-size: 14px; font-family: inherit; color: var(--ink);
            outline: none;
            transition: border-color .2s var(--ease), box-shadow .2s var(--ease), background .2s var(--ease);
        }
        .field-shell input::placeholder { color: var(--ink-faint); }
        .field-shell input:focus {
            border-color: var(--teal);
            background: #fff;
            box-shadow: 0 0 0 4px var(--teal-glow);
        }
        .field-shell input:focus ~ i.icon-lead,
        .field-shell:focus-within i.icon-lead { color: var(--teal); }

        .field-shell .icon-toggle {
            position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: var(--ink-faint); font-size: 14.5px; padding: 4px;
            transition: color .2s var(--ease);
        }
        .field-shell .icon-toggle:hover { color: var(--teal); }

        .row-between {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 26px; gap: 12px; flex-wrap: wrap;
        }

        .check {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: var(--ink-muted);
            cursor: pointer; user-select: none;
        }
        .check input { accent-color: var(--teal); width: 15px; height: 15px; cursor: pointer; }

        .link { color: var(--teal); font-size: 13px; font-weight: 600; text-decoration: none; }
        .link:hover { text-decoration: underline; }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--teal);
            border: none; border-radius: var(--radius-md);
            color: #fff; font-size: 14.5px; font-weight: 700;
            font-family: inherit; cursor: pointer;
            letter-spacing: .2px;
            display: flex; align-items: center; justify-content: center; gap: 9px;
            box-shadow: 0 10px 24px -8px var(--teal-glow);
            transition: background .2s var(--ease), transform .15s var(--ease), box-shadow .2s var(--ease);
        }
        .btn-submit:hover  { background: var(--teal-deep); transform: translateY(-1px); box-shadow: 0 14px 28px -8px var(--teal-glow); }
        .btn-submit:active { transform: none; }
        .btn-submit .spinner { display: none; }
        .btn-submit.loading .label   { display: none; }
        .btn-submit.loading .spinner { display: inline-flex; }

        .foot-note {
            margin-top: auto;
            padding-top: 30px;
            text-align: center;
            font-size: 13px; color: var(--ink-muted);
        }
        .foot-note a { color: var(--teal); font-weight: 700; text-decoration: none; }
        .foot-note a:hover { text-decoration: underline; }

        /* ══════════════════════════════════════════════════════════
           HERO PANEL — photo réelle + guide de workflow
           ══════════════════════════════════════════════════════════ */
        .hero-panel {
            position: relative;
            background:
                linear-gradient(180deg, rgba(10,40,37,.16) 0%, rgba(8,32,30,.72) 78%, rgba(6,26,24,.90) 100%),
                var(--teal-deep) url('/pulmocare/assets/images/doctor-login.jpg') center 18% / cover no-repeat;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 28px 28px 26px;
            color: #fff;
            overflow: hidden;
        }

        /* Filet de sécurité si l'image n'est pas encore présente sur le serveur */
        .hero-panel.hero-panel--fallback {
            background:
                linear-gradient(180deg, rgba(10,40,37,.10) 0%, rgba(8,32,30,.7) 100%),
                radial-gradient(900px 600px at 20% 0%, #1c948a, var(--teal-deep) 65%);
        }

        .hero-top {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px;
        }

        .pill {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 7px 13px 7px 11px;
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.22);
            backdrop-filter: blur(6px);
            border-radius: 99px;
            font-size: 12px; font-weight: 600; letter-spacing: .2px;
            color: #eafaf7;
        }
        .pill i { font-size: 11px; color: #7fe8d8; }

        .lang-pill {
            width: 34px; height: 34px; border-radius: 50%;
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.22);
            backdrop-filter: blur(6px);
            display: flex; align-items: center; justify-content: center;
            font-size: 11.5px; font-weight: 700; color: #eafaf7;
        }

        .hero-stat {
            align-self: flex-start;
            margin-top: auto;
            margin-bottom: 18px;
        }
        .hero-stat__num {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 44px; font-weight: 700; letter-spacing: -1.5px;
            line-height: 1;
            text-shadow: 0 2px 18px rgba(0,0,0,.25);
        }
        .hero-stat__label {
            font-size: 13px; color: rgba(255,255,255,.78);
            margin-top: 6px; max-width: 30ch; line-height: 1.5;
        }

        /* ─── Guide de workflow (carrousel auto, droite → gauche) ─── */
        .guide {
            background: rgba(9, 32, 30, .56);
            border: 1px solid rgba(255,255,255,.14);
            backdrop-filter: blur(14px);
            border-radius: 17px;
            padding: 18px 18px 16px;
        }

        .guide__head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 12px;
        }
        .guide__head span {
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.1px;
            color: rgba(255,255,255,.62);
        }
        .guide__dots { display: flex; gap: 6px; }
        .guide__dot {
            width: 16px; height: 4px; border-radius: 99px;
            background: rgba(255,255,255,.28);
            border: none; cursor: pointer; padding: 0;
            transition: background .25s var(--ease), width .25s var(--ease);
        }
        .guide__dot.is-active { background: #fff; width: 26px; }

        .guide__viewport { overflow: hidden; position: relative; height: 64px; }
        .guide__track {
            display: flex;
            width: 100%; height: 100%;
            transition: transform .55s var(--ease);
        }
        .guide__slide {
            flex: 0 0 100%;
            display: flex; align-items: center; gap: 13px;
        }
        .guide__icon {
            width: 42px; height: 42px; border-radius: 11px;
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.18);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; color: #fff;
            flex-shrink: 0;
        }
        .guide__body strong {
            display: block; font-size: 13.5px; font-weight: 700;
            letter-spacing: -.1px; margin-bottom: 2px;
        }
        .guide__body p {
            font-size: 12px; line-height: 1.4;
            color: rgba(255,255,255,.72);
        }

        @media (prefers-reduced-motion: reduce) {
            .guide__track { transition: none; }
        }

        /* ══════════════════════════════════════════════════════════
           RESPONSIVE
           ══════════════════════════════════════════════════════════ */
        @media (max-width: 900px) {
            .shell { grid-template-columns: 1fr; max-width: 460px; min-height: 0; }
            .hero-panel { display: none; }
            .form-panel { padding: 40px 30px 34px; }
        }
        @media (max-width: 420px) {
            .form-panel { padding: 34px 22px 28px; }
            .form-panel h1 { font-size: 24px; }
        }
    </style>
</head>
<body>

<div class="shell">

    <!-- ═══════════ FORM PANEL ═══════════ -->
    <div class="form-panel">

        <div class="brand">
            <div class="brand__mark"><i class="fa-solid fa-lungs"></i></div>
            <div class="brand__text">
                PulmoCare IA
                <span>Espace praticien</span>
            </div>
        </div>

        <h1>Connexion</h1>
        <p class="lede">Accédez à votre espace d'analyse CT Scan. Réservé aux médecins autorisés de la plateforme.</p>

        <?php if (!empty($errors)): ?>
        <div class="alert-stack">
            <?php foreach ($errors as $err): ?>
            <div class="alert alert--error" role="alert">
                <i class="fa-solid fa-circle-exclamation"></i>
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
                    <i class="fa-regular fa-envelope icon-lead"></i>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="dr.nom@hopital.fr"
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
                    <i class="fa-solid fa-lock icon-lead"></i>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="••••••••"
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="icon-toggle" id="togglePwd" aria-label="Afficher le mot de passe">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="row-between">
                <label class="check">
                    <input type="checkbox" name="remember" id="remember">
                    Se souvenir de moi
                </label>
                <a href="/pulmocare/auth/forgot-password.php" class="link">Mot de passe oublié ?</a>
            </div>

            <button type="submit" class="btn-submit" id="loginBtn">
                <span class="label"><i class="fa-solid fa-arrow-right-to-bracket"></i>&nbsp; Se connecter</span>
                <span class="spinner"><i class="fa-solid fa-circle-notch fa-spin"></i>&nbsp; Connexion…</span>
            </button>
        </form>

        <p class="foot-note">
            Pas encore de compte ? <a href="/pulmocare/auth/register.php">Demander un accès</a>
        </p>
    </div>

    <!-- ═══════════ HERO PANEL ═══════════ -->
    <div class="hero-panel" id="heroPanel">

        <div class="hero-top">
            <span class="pill"><i class="fa-solid fa-circle"></i> Plateforme médicale sécurisée</span>
            <span class="lang-pill">FR</span>
        </div>

        <div class="hero-stat">
            <div class="hero-stat__num">95,83%</div>
            <div class="hero-stat__label">Précision du modèle CNN sur la classification normal / bénin / malin.</div>
        </div>

        <div class="guide">
            <div class="guide__head">
                <span>Comment ça marche</span>
                <div class="guide__dots" id="guideDots"></div>
            </div>
            <div class="guide__viewport">
                <div class="guide__track" id="guideTrack">
                    <?php foreach ($workflowSteps as $step): ?>
                    <div class="guide__slide">
                        <div class="guide__icon"><i class="<?= htmlspecialchars($step['icon']) ?>"></i></div>
                        <div class="guide__body">
                            <strong><?= htmlspecialchars($step['title']) ?></strong>
                            <p><?= htmlspecialchars($step['text']) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
    // ── Vérifie que la photo du médecin est bien présente ──────
    // (si le fichier n'a pas encore été déposé côté serveur, on
    //  bascule sur un dégradé teal pour ne jamais casser la mise en page)
    (function checkHeroImage() {
        const hero = document.getElementById('heroPanel');
        const img  = new Image();
        img.onerror = () => hero.classList.add('hero-panel--fallback');
        img.src = '/pulmocare/assets/images/doctor-login.jpg';
    })();

    // ── Toggle mot de passe ──────────────────────────────────────
    const toggleBtn = document.getElementById('togglePwd');
    const pwdInput  = document.getElementById('password');
    toggleBtn.addEventListener('click', () => {
        const show = pwdInput.type === 'password';
        pwdInput.type = show ? 'text' : 'password';
        toggleBtn.querySelector('i').className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
    });

    // ── État de chargement au submit ─────────────────────────────
    const form = document.getElementById('loginForm');
    const btn  = document.getElementById('loginBtn');
    form.addEventListener('submit', () => {
        const email = document.getElementById('email').value.trim();
        const pwd   = pwdInput.value;
        if (!email || !pwd) return;
        btn.classList.add('loading');
        btn.disabled = true;
    });

    // ── Guide de workflow : carrousel auto, glisse de droite à gauche ──
    (function initGuide() {
        const track = document.getElementById('guideTrack');
        const dotsWrap = document.getElementById('guideDots');
        if (!track) return;

        const slides = Array.from(track.children);
        const dots = slides.map((_, i) => {
            const b = document.createElement('button');
            b.className = 'guide__dot' + (i === 0 ? ' is-active' : '');
            b.setAttribute('aria-label', 'Étape ' + (i + 1));
            b.addEventListener('click', () => goTo(i, true));
            dotsWrap.appendChild(b);
            return b;
        });

        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let index = 0;
        let timer = null;

        function render() {
            track.style.transform = `translateX(-${index * 100}%)`;
            dots.forEach((d, i) => d.classList.toggle('is-active', i === index));
        }

        function goTo(i, manual) {
            index = (i + slides.length) % slides.length;
            render();
            if (manual) restart();
        }

        function next() { goTo(index + 1); }

        function start() {
            if (reduceMotion || slides.length < 2) return;
            timer = setInterval(next, 3400);
        }
        function stop() { if (timer) clearInterval(timer); }
        function restart() { stop(); start(); }

        const guideEl = track.closest('.guide');
        guideEl.addEventListener('mouseenter', stop);
        guideEl.addEventListener('mouseleave', start);

        render();
        start();
    })();
</script>
</body>
</html>