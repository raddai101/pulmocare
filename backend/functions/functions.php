<?php

declare(strict_types=1);

/**
 * ============================================================
 *  FUNCTIONS.PHP — Bibliothèque centrale de fonctions
 *  Plateforme de détection du cancer du poumon (CT Scan IA)
 * ============================================================
 *
 *  INDEX DES FONCTIONS PAR SECTION :
 *
 *  [1]  AUTHENTIFICATION & SESSION
 *       - auth_login()
 *       - auth_logout()
 *       - auth_require()
 *       - auth_is_logged()
 *       - auth_current_user()
 *       - auth_send_reset_link()
 *       - auth_reset_password()
 *
 *  [2]  GESTION DES MÉDECINS (USERS)
 *       - user_register()
 *       - user_update_profile()
 *       - user_update_avatar()
 *       - user_change_password()
 *       - user_get_stats()
 *       - user_get_with_hospital()
 *
 *  [3]  UPLOAD & GESTION DES IMAGES CT SCAN
 *       - scan_upload()
 *       - scan_delete_file()
 *       - scan_get_url()
 *       - scan_compute_hash()
 *       - scan_generate_filename()
 *
 *  [4]  DÉTECTION IA — COMMUNICATION PYTHON
 *       - ai_predict()
 *       - ai_call_python()
 *       - ai_parse_response()
 *       - ai_format_result()
 *       - ai_get_stage_label()
 *       - ai_get_result_color()
 *
 *  [5]  DETECTIONS (CRUD / HISTORIQUE)
 *       - detection_create()
 *       - detection_get()
 *       - detection_get_all_by_user()
 *       - detection_search()
 *       - detection_mark_reviewed()
 *       - detection_delete()
 *       - detection_get_global_stats()
 *
 *  [6]  SÉCURITÉ & VALIDATION
 *       - security_csrf_token()
 *       - security_verify_csrf()
 *       - security_sanitize()
 *       - security_rate_limit()
 *       - security_get_ip()
 *       - validate_fields()
 *       - validate_email()
 *       - validate_password_strength()
 *       - validate_scan_file()
 *
 *  [7]  RÉPONSES HTTP & API
 *       - response_json()
 *       - response_json_success()
 *       - response_json_error()
 *       - response_redirect()
 *       - response_set_header()
 *
 *  [8]  RENDU HTML / TEMPLATE HELPERS
 *       - html_flash()
 *       - html_set_flash()
 *       - html_csrf_input()
 *       - html_active_class()
 *       - html_avatar_url()
 *       - html_result_badge()
 *       - html_confidence_bar()
 *       - html_format_date()
 *       - html_format_size()
 *       - html_page_title()
 *
 *  [9]  EMAILS
 *       - mail_send()
 *       - mail_reset_password()
 *       - mail_welcome()
 *       - mail_detection_report()
 *
 *  [10] LOGS & AUDIT
 *       - log_activity()
 *       - log_error()
 *       - log_detection()
 *
 *  [11] UTILITAIRES GÉNÉRAUX
 *       - env()
 *       - config_get()
 *       - str_truncate()
 *       - str_slug()
 *       - arr_get()
 *       - pagination_links()
 *
 * ============================================================
 */

use App\Config\Database;
use App\Config\SessionManager;
use App\Models\User;
use App\Models\Detection;
use App\Helpers\Security;
use App\Helpers\Validator;

if (defined('FUNCTIONS_LOADED')) return;

// BOOTSTRAP — Chargement automatique et initialisation minimale
$autoloadFile = dirname(__DIR__, 2) . '/vendor/autoload.php';
$autoloadLoaded = false;
foreach (get_included_files() as $includedFile) {
    if (realpath($includedFile) === realpath($autoloadFile)) {
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded) {
    if (!file_exists($autoloadFile)) {
        throw new \RuntimeException('Composer autoload introuvable : ' . $autoloadFile);
    }
    require_once $autoloadFile;
}

if (!class_exists(SessionManager::class, true)) {
    throw new \RuntimeException('Classe App\\Config\\SessionManager introuvable après chargement de l\'autoload Composer.');
}

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

SessionManager::start();
Security::setSecurityHeaders();

define('FUNCTIONS_LOADED', true);

// Inclure les modules (chaque fichier contient les fonctions de sa section)
$base = __DIR__ . '/modules/';
require_once $base . 'auth.php';
require_once $base . 'users.php';
require_once $base . 'uploads.php';
require_once $base . 'ai.php';
require_once $base . 'detections.php';
require_once $base . 'security.php';
require_once $base . 'responses.php';
require_once $base . 'html.php';
require_once $base . 'mail.php';
require_once $base . 'logs.php';
require_once $base . 'utils.php';

// Fin — `functions.php` sert maintenant de conteneur qui charge les modules
