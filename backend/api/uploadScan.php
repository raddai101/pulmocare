<?php
declare(strict_types=1);
require_once __DIR__ . '/../../backend/functions/functions.php';

if (!auth_is_logged()) response_json_error('Non authentifié.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') response_json_error('Méthode non autorisée.', 405);
if (!security_verify_csrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))
    response_json_error('CSRF invalide.', 403);

$userId = (int)(auth_current_user()['id'] ?? 0);
if (!$userId) response_json_error('Session expirée.', 401);

if (empty($_FILES['scan_file']['tmp_name'])) response_json_error('Aucun fichier reçu.');

$result = scan_upload($_FILES['scan_file'], $userId);

if (!$result['success']) response_json_error($result['message']);

response_json_success([
    'url'      => $result['url'],
    'filename' => $result['filename'],
    'original' => $result['original'],
    'hash'     => $result['hash'],
    'size'     => $result['size'],
], 'Fichier uploadé avec succès.');
