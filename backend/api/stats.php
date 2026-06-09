<?php
declare(strict_types=1);
require_once __DIR__ . '/../../backend/functions/functions.php';

if (!auth_is_logged()) response_json_error('Non authentifié.', 401);

$userId = (int)(auth_current_user()['id'] ?? 0);
$stats  = user_get_stats($userId);
response_json_success(['stats' => $stats]);
