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
<html lang="fr" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $pageTitle ?></title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        /* ── Reset & Variables ───────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg-deep:      #050a18;
            --bg-card:      #0d1526;
            --bg-input:     #111d35;
            --border:       rgba(59,130,246,.18);
            --border-focus: #3b82f6;
            --text-primary: #f0f4ff;
            --text-muted:   #8899b4;
            --blue-500:     #3b82f6;
            --blue-600:     #2563eb;
            --blue-glow:    rgba(59,130,246,.35);
            --red-400:      #f87171;
            --green-400:    #4ade80;
            --radius-lg:    16px;
            --radius-md:    10px;
            --transition:   .25s cubic-bezier(.4,0,.2,1);
        }

        body {
            min-height: 100vh;
            background: var(--bg-deep);
            font-family: 'Inter', sans-serif;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            overflow-x: hidden;
        }

        /* ── Background animated grid ────────────────── */
        body::before {
            content: '';
            position: fixed; inset: 0;
            background:
                radial-gradient(ellipse 80% 60% at 20% 10%, rgba(59,130,246,.12) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 80% 90%, rgba(99,102,241,.10) 0%, transparent 60%),
                linear-gradient(180deg, var(--bg-deep) 0%, #06101f 100%);
            pointer-events: none;
        }

        /* ── Layout ──────────────────────────────────── */
        .auth-wrapper {
            width: 100%;
            max-width: 980px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 600px;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 32px 80px rgba(0,0,0,.6), 0 0 0 1px var(--border);
            position: relative;
            z-index: 1;
        }

        /* ── Left panel (brand) ───────────────────────── */
        .auth-brand {
            background: linear-gradient(145deg, #0f1f3e 0%, #1a3a6b 50%, #0e2851 100%);
            padding: 56px 48px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .auth-brand::before {
            content: '';
            position: absolute; inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%233b82f6' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: .5;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-logo__icon {
            width: 52px; height: 52px;
            background: linear-gradient(135deg, var(--blue-500), #6366f1);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 8px 24px var(--blue-glow);
        }

        .brand-logo__text {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.1;
            letter-spacing: -.5px;
        }

        .brand-logo__text span {
            display: block;
            font-size: 11px;
            font-weight: 400;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .brand-features {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .brand-features li {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .brand-features__icon {
            width: 36px; height: 36px;
            background: rgba(59,130,246,.15);
            border: 1px solid rgba(59,130,246,.25);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            color: var(--blue-500);
            flex-shrink: 0;
        }

        .brand-features__text strong {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .brand-features__text span {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .brand-footer {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* ── Right panel (form) ───────────────────────── */
        .auth-form-panel {
            background: var(--bg-card);
            padding: 56px 48px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .auth-form-panel h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -.5px;
            margin-bottom: 6px;
        }

        .auth-form-panel p.subtitle {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 36px;
        }

        /* Alerts */
        .alert-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13.5px; }
        .alert--error   { background: rgba(248,113,113,.1); border: 1px solid rgba(248,113,113,.3); color: var(--red-400); }
        .alert--success { background: rgba(74,222,128,.1);  border: 1px solid rgba(74,222,128,.3);  color: var(--green-400); }

        /* Form elements */
        .form-group { margin-bottom: 20px; }
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 8px;
            letter-spacing: .2px;
        }

        .input-wrap { position: relative; }
        .input-wrap__icon {
            position: absolute;
            left: 14px; top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 15px;
            pointer-events: none;
            transition: color var(--transition);
        }

        .form-control {
            width: 100%;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 13px 44px 13px 42px;
            color: var(--text-primary);
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: border-color var(--transition), box-shadow var(--transition);
        }

        .form-control:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px var(--blue-glow);
        }

        .form-control::placeholder { color: var(--text-muted); opacity: .6; }

        .input-toggle {
            position: absolute;
            right: 14px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 15px;
            transition: color var(--transition);
            padding: 4px;
        }
        .input-toggle:hover { color: var(--text-primary); }

        .form-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }

        .checkbox-wrap { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: var(--text-muted); }
        .checkbox-wrap input[type="checkbox"] { accent-color: var(--blue-500); width: 15px; height: 15px; }

        .link { color: var(--blue-500); text-decoration: none; font-size: 13px; transition: color var(--transition); }
        .link:hover { color: #60a5fa; text-decoration: underline; }

        /* Submit button */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--blue-500) 0%, #6366f1 100%);
            border: none;
            border-radius: var(--radius-md);
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            letter-spacing: .3px;
            transition: opacity var(--transition), transform var(--transition), box-shadow var(--transition);
            box-shadow: 0 4px 20px var(--blue-glow);
            position: relative;
            overflow: hidden;
        }

        .btn-submit:hover { opacity: .92; transform: translateY(-1px); box-shadow: 0 8px 28px var(--blue-glow); }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit .btn-spinner { display: none; }
        .btn-submit.loading .btn-text { display: none; }
        .btn-submit.loading .btn-spinner { display: inline-block; }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 24px 0;
            color: var(--text-muted);
            font-size: 12px;
        }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

        .form-footer { text-align: center; font-size: 13px; color: var(--text-muted); margin-top: 20px; }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 768px) {
            .auth-wrapper { grid-template-columns: 1fr; max-width: 440px; }
            .auth-brand { display: none; }
            .auth-form-panel { padding: 40px 28px; }
        }
    </style>
</head>
<body>

<div class="auth-wrapper">

    <!-- LEFT — Brand panel -->
    <div class="auth-brand">
        <div class="brand-logo">
            <div class="brand-logo__icon"><i class="fa-solid fa-lungs"></i></div>
            <div class="brand-logo__text">
                PulmoCare IA
                <span>Plateforme médicale intelligente</span>
            </div>
        </div>

        <ul class="brand-features">
            <li>
                <div class="brand-features__icon"><i class="fa-solid fa-brain"></i></div>
                <div class="brand-features__text">
                    <strong>Détection IA avancée</strong>
                    <span>Réseau CNN entraîné sur +8 500 images CT Scan pulmonaires</span>
                </div>
            </li>
            <li>
                <div class="brand-features__icon"><i class="fa-solid fa-chart-pie"></i></div>
                <div class="brand-features__text">
                    <strong>Résultats en temps réel</strong>
                    <span>Analyse complète avec score de confiance et stade de gravité</span>
                </div>
            </li>
            <li>
                <div class="brand-features__icon"><i class="fa-solid fa-shield-halved"></i></div>
                <div class="brand-features__text">
                    <strong>Plateforme sécurisée</strong>
                    <span>Données chiffrées, accès réservé aux professionnels de santé</span>
                </div>
            </li>
            <li>
                <div class="brand-features__icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div class="brand-features__text">
                    <strong>Historique complet</strong>
                    <span>Suivi et archivage de toutes vos analyses CT Scan</span>
                </div>
            </li>
        </ul>

        <div class="brand-footer">
            &copy; <?= date('Y') ?> PulmoCare IA — Outil d'aide au diagnostic médical
        </div>
    </div>

    <!-- RIGHT — Form panel -->
    <div class="auth-form-panel">
        <h1>Connexion</h1>
        <p class="subtitle">Accès réservé aux médecins autorisés</p>

        <?php if (!empty($errors)): ?>
        <div class="alert-list">
            <?php foreach ($errors as $err): ?>
            <div class="alert alert--error" role="alert">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= htmlspecialchars($err) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?= html_flash() ?>

        <form method="POST" action="" id="loginForm" novalidate autocomplete="on">
            <?= html_csrf_input() ?>

            <div class="form-group">
                <label class="form-label" for="email">Adresse email professionnelle</label>
                <div class="input-wrap">
                    <i class="fa-regular fa-envelope input-wrap__icon"></i>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-control"
                        placeholder="dr.nom@hopital.fr"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        required
                        autocomplete="email"
                        spellcheck="false"
                    >
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Mot de passe</label>
                <div class="input-wrap">
                    <i class="fa-solid fa-lock input-wrap__icon"></i>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="••••••••"
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="input-toggle" id="togglePwd" aria-label="Afficher le mot de passe">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="form-row">
                <label class="checkbox-wrap">
                    <input type="checkbox" name="remember" id="remember">
                    Se souvenir de moi
                </label>
                <a href="/pulmocare/auth/forgot-password.php" class="link">Mot de passe oublié ?</a>
            </div>

            <button type="submit" class="btn-submit" id="loginBtn">
                <span class="btn-text"><i class="fa-solid fa-right-to-bracket"></i>&nbsp; Se connecter</span>
                <span class="btn-spinner"><i class="fa-solid fa-circle-notch fa-spin"></i>&nbsp; Connexion…</span>
            </button>
        </form>

        <div class="form-footer">
            Pas encore de compte ?&nbsp;
            <a href="/pulmocare/auth/register.php" class="link" style="font-weight:600">Demander un accès</a>
        </div>
    </div>

</div>

<script>
    // Toggle password visibility
    const toggleBtn = document.getElementById('togglePwd');
    const pwdInput  = document.getElementById('password');
    toggleBtn.addEventListener('click', () => {
        const show = pwdInput.type === 'password';
        pwdInput.type = show ? 'text' : 'password';
        toggleBtn.querySelector('i').className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
    });

    // Loading state on submit
    const form    = document.getElementById('loginForm');
    const btn     = document.getElementById('loginBtn');
    form.addEventListener('submit', (e) => {
        const email = document.getElementById('email').value.trim();
        const pwd   = pwdInput.value;
        if (!email || !pwd) return; // HTML5 validation handles it
        btn.classList.add('loading');
        btn.disabled = true;
    });

    // Input focus — highlight icon
    document.querySelectorAll('.form-control').forEach(input => {
        const icon = input.closest('.input-wrap')?.querySelector('.input-wrap__icon');
        input.addEventListener('focus',  () => icon && (icon.style.color = '#3b82f6'));
        input.addEventListener('blur',   () => icon && (icon.style.color = ''));
    });
</script>
</body>
</html>
