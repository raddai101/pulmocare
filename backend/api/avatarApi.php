<?php
declare(strict_types=1);
require_once __DIR__ . '/../../backend/functions/functions.php';

/**
 * avatarApi.php — Upload asynchrone de la photo de profil.
 *
 * Appelé en AJAX depuis pages/profil.php dès que l'utilisateur sélectionne
 * un fichier : ne touche à AUCUN autre champ du profil (nom, téléphone,
 * spécialité...), contrairement à l'ancien flux qui auto-soumettait un
 * formulaire complet et déclenchait par erreur la validation du profil.
 */

if (!auth_is_logged()) response_json_error('Non authentifié.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') response_json_error('Méthode non autorisée.', 405);
if (!security_verify_csrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))
    response_json_error('CSRF invalide.', 403);

$userId = (int)(auth_current_user()['id'] ?? 0);
if (!$userId) response_json_error('Session expirée.', 401);

if (empty($_FILES['avatar']['tmp_name'])) response_json_error('Aucun fichier reçu.');

$result = user_update_avatar($userId, $_FILES['avatar']);

if (!$result['success']) response_json_error($result['message']);

// Cache-busting (?v=timestamp) pour forcer le navigateur à charger la
// nouvelle image immédiatement, même si le nom de fichier était identique.
response_json_success([
    'avatar'     => $result['avatar'],
    'avatar_url' => scan_get_url($result['avatar']) . '?v=' . time(),
], $result['message']);
