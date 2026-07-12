<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/functions/functions.php';

// Rediriger si déjà connecté
if (auth_is_logged()) {
    response_redirect('/auth/dashboard.php');
}

$errors = [];
$success = '';

// Récupère la liste des hôpitaux actifs pour le select
$hospitalModel = new \App\Models\Hospital();
$hospitals = $hospitalModel->getActiveList();

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!security_verify_csrf($_POST['_token'] ?? '')) {
        $errors[] = 'Requête invalide. Veuillez réessayer.';
    } else {
        $data = [
            'nom' => $_POST['nom'] ?? '',
            'prenom' => $_POST['prenom'] ?? '',
            'email' => $_POST['email'] ?? '',
            'password' => $_POST['password'] ?? '',
            'password_confirmation' => $_POST['password_confirmation'] ?? '',
            'telephone' => $_POST['telephone'] ?? '',
            'specialite' => $_POST['specialite'] ?? '',
            'numero_ordre' => $_POST['numero_ordre'] ?? '',
            'hospital_id' => $_POST['hospital_id'] ?? '',
        ];

        $result = user_register($data);

        if ($result['success']) {
            html_set_flash('success', $result['message']);
            response_redirect('/auth/login.php');
        } else {
            // user_register retourne 'errors' ou 'message'
            if (isset($result['errors']) && is_array($result['errors'])) {
                // Flatten validation errors
                foreach ($result['errors'] as $fieldErrors) {
                    if (is_array($fieldErrors)) {
                        foreach ($fieldErrors as $e) $errors[] = $e;
                    } else {
                        $errors[] = (string)$fieldErrors;
                    }
                }
            } elseif (isset($result['message'])) {
                $errors[] = $result['message'];
            } else {
                $errors[] = 'Erreur inconnue lors de l\'inscription.';
            }
        }
    }
}

$pageTitle = html_page_title('Inscription');
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $pageTitle ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background:#050a18; color:#f0f4ff; padding:24px; }
        .card { max-width:900px; margin:0 auto; background:#0d1526; padding:28px; border-radius:12px; box-shadow:0 20px 40px rgba(0,0,0,.6); }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        label { display:block; font-size:13px; color:#a8bdde; margin-bottom:6px; }
        input, select { width:80%; padding:10px 12px; background:#0f2136; border:1px solid rgba(59,130,246,.08); color:#eaf2ff; border-radius:8px; }
        .btn { background:#3b82f6; color:white; padding:10px 14px; border-radius:8px; border:0; cursor:pointer; }
        .errors { background:#2b1111; color:#ffdada; padding:10px; border-radius:8px; margin-bottom:12px; }
        .success { background:#0b2b16; color:#baf5c6; padding:10px; border-radius:8px; margin-bottom:12px; }
        .actions { display:flex; gap:12px; align-items:center; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Inscription - Accès médecins</h1>
        <p>Créer un compte médecin pour accéder à la plateforme.</p>

        <?= html_flash() ?>

        <?php if (!empty($errors)): ?>
            <div class="errors">
                <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= html_csrf_input() ?>
            <div class="grid">
                <div>
                    <label>Nom</label>
                    <input name="nom" value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Prénom</label>
                    <input name="prenom" value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Email professionnel</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Téléphone</label>
                    <input name="telephone" value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Spécialité</label>
                    <input name="specialite" value="<?= htmlspecialchars($_POST['specialite'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Numéro d'ordre</label>
                    <input name="numero_ordre" value="<?= htmlspecialchars($_POST['numero_ordre'] ?? '') ?>" required>
                </div>
                <div>
                    <label>Hôpital</label>
                    <select name="hospital_id" required>
                        <option value="">Sélectionnez un hôpital</option>
                        <?php foreach ($hospitals as $h): ?>
                            <option value="<?= (int)$h['id'] ?>" <?= (isset($_POST['hospital_id']) && (int)$_POST['hospital_id'] === (int)$h['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($h['nom'] . ' / ' . ($h['ville'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Mot de passe</label>
                    <input type="password" name="password" required>
                </div>
                <div>
                    <label>Confirmer le mot de passe</label>
                    <input type="password" name="password_confirmation" required>
                </div>
            </div>

            <div style="margin-top:18px" class="actions">
                <button class="btn" type="submit">Créer mon compte</button>
                <a href="/pulmocare/auth/login.php" style="color:#a8bdde; ">J'ai déjà un compte</a>
            </div>
        </form>
    </div>
</body>
</html>
