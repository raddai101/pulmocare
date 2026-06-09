<?php
declare(strict_types=1);
require_once __DIR__ . '/../../backend/functions/functions.php';

// Endpoint public — liste des hôpitaux pour le formulaire d'inscription
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=300');

use App\Models\Hospital;
$hospitals = (new Hospital())->getActiveList();
response_json_success(['hospitals' => $hospitals], 'OK');
