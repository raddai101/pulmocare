<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');


// Charger l'autoload Composer puis les fonctions de l'application
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/backend/functions/functions.php';

if (auth_is_logged()) {
    header('Location: /pulmocare/auth/dashboard.php');
} else {
    header('Location: /pulmocare/auth/login.php');
}
exit();