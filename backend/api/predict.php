<?php
declare(strict_types=1);
require_once __DIR__ . '/../../backend/functions/functions.php';

if (!auth_is_logged()) response_json_error('Non authentifié.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') response_json_error('Méthode non autorisée.', 405);
if (!security_verify_csrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))
    response_json_error('CSRF invalide.', 403);

$userId   = (int)(auth_current_user()['id'] ?? 0);
$imagePath = security_sanitize($_POST['image_path'] ?? '');

if (!$imagePath) response_json_error('Chemin image manquant.');

// Sécurité : vérifier que le path appartient bien à cet utilisateur
$safePath = realpath(__DIR__ . '/../../' . ltrim($imagePath, '/'));
$allowed  = realpath(__DIR__ . '/../../assets/uploads/scans/');
if (!$safePath || !str_starts_with($safePath, $allowed)) {
    response_json_error('Chemin non autorisé.', 403);
}

$aiResponse = ai_predict($safePath);

if (!$aiResponse['success']) response_json_error($aiResponse['message']);

$formatted = ai_format_result($aiResponse['result']);
response_json_success(['result' => $formatted], 'Analyse IA terminée.');
