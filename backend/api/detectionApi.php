<?php
declare(strict_types=1);
require_once __DIR__ . '/../../backend/functions/functions.php';

if (!auth_is_logged()) response_json_error('Non authentifié.', 401);

$method = Security::getRequestMethod();
$userId = (int)(auth_current_user()['id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

match ([$method, $action]) {
    ['GET',  'list']   => handleList($userId),
    ['GET',  'get']    => handleGet($userId),
    ['POST', 'delete'] => handleDelete($userId),
    ['POST', 'review'] => handleReview($userId),
    default            => response_json_error('Action inconnue.', 404),
};

function handleList(int $userId): never {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $filters = array_filter(array_map('trim', [
        'result_type' => $_GET['result_type'] ?? '',
        'date_from'   => $_GET['date_from']   ?? '',
        'date_to'     => $_GET['date_to']     ?? '',
        'patient'     => $_GET['patient']     ?? '',
        'stage'       => $_GET['stage']       ?? '',
    ]));
    response_json_success(detection_search($filters, $userId, $page));
}

function handleGet(int $userId): never {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) response_json_error('ID manquant.');
    $d = detection_get($id);
    if (!$d || (int)$d['user_id'] !== $userId) response_json_error('Introuvable.', 404);
    response_json_success(['detection' => $d]);
}

function handleDelete(int $userId): never {
    if (!security_verify_csrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))
        response_json_error('CSRF invalide.', 403);
    $r = detection_delete((int)($_POST['detection_id'] ?? 0), $userId);
    if (!$r['success']) response_json_error($r['message'], 403);
    response_json_success([], $r['message']);
}

function handleReview(int $userId): never {
    if (!security_verify_csrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))
        response_json_error('CSRF invalide.', 403);
    $id = (int)($_POST['detection_id'] ?? 0);
    $d  = detection_get($id);
    if (!$d || (int)$d['user_id'] !== $userId) response_json_error('Introuvable.', 404);
    detection_mark_reviewed($id, security_sanitize($_POST['notes'] ?? ''));
    response_json_success([], 'Annotation enregistrée.');
}
