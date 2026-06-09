<?php
declare(strict_types=1);
require_once __DIR__ . '/../../backend/functions/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') response_json_error('Méthode non autorisée.', 405);
if (!security_verify_csrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))
    response_json_error('CSRF invalide.', 403);

$email    = security_sanitize($_POST['email']    ?? '');
$password = $_POST['password'] ?? '';
$remember = isset($_POST['remember']);

$result = auth_login($email, $password, $remember);

if (!$result['success']) response_json_error($result['message'], 401);

response_json_success(['redirect' => '/auth/dashboard.php'], $result['message']);
