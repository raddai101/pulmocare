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

file_put_contents('C:/xamp/htdocs/pulmocare/debug_phase_before_first_if.txt','BEFORE_FIRST_IF');
if (defined('FUNCTIONS_LOADED')) return;
define('FUNCTIONS_LOADED', true);

if (function_exists('auth_login')) {
    return;
}

// ════════════════════════════════════════════════════════════
//  BOOTSTRAP — Chargement automatique
// ════════════════════════════════════════════════════════════

$autoloadFile = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($autoloadFile)) {
    throw new \RuntimeException('Composer autoload introuvable : ' . $autoloadFile);
}
require_once $autoloadFile;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

if (!class_exists(SessionManager::class, true)) {
    throw new \RuntimeException('Classe App\\Config\\SessionManager introuvable après chargement de l\'autoload Composer.');
}

SessionManager::start();
Security::setSecurityHeaders();


// ════════════════════════════════════════════════════════════
//  [1] AUTHENTIFICATION & SESSION
// ════════════════════════════════════════════════════════════

/**
 * Authentifie un médecin via email + mot de passe.
 * Applique le rate limiting, vérifie le compte actif.
 *
 * @return array{success: bool, message: string, user?: array}
 */
function auth_login(string $email, string $password, bool $remember = false): array
{
    $ip  = security_get_ip();
    $key = "login:{$ip}:{$email}";

    if (!security_rate_limit($key, 5, 300)) {
        log_activity('login_blocked', ['email' => $email, 'ip' => $ip]);
        return ['success' => false, 'message' => 'Trop de tentatives. Réessayez dans 5 minutes.'];
    }

    $email = strtolower(trim($email));
    if (!validate_email($email)) {
        return ['success' => false, 'message' => 'Adresse email invalide.'];
    }

    $userModel = new User();
    $user      = $userModel->findByEmail($email);

    if (!$user || !$userModel->verifyPassword($password, $user['password'])) {
        log_activity('login_failed', ['email' => $email, 'ip' => $ip]);
        return ['success' => false, 'message' => 'Identifiants incorrects.'];
    }

    if (!(bool)$user['is_active']) {
        return ['success' => false, 'message' => 'Compte inactif. Contactez l\'administrateur.'];
    }

    // Réinitialise le rate limit en cas de succès
    Security::clearRateLimit($key);

    SessionManager::setUser($user);
    $userModel->updateLastLogin((int)$user['id'], $ip);

    if ($remember) {
        $token = Security::generateToken();
        $userModel->update((int)$user['id'], ['remember_token' => hash('sha256', $token)]);
        setcookie('remember_token', $token, time() + (86400 * 30), '/', '', true, true);
    }

    log_activity('login_success', ['user_id' => $user['id'], 'ip' => $ip]);

    return ['success' => true, 'message' => 'Connexion réussie.', 'user' => $user];
}

/**
 * Déconnecte l'utilisateur et détruit la session.
 */
function auth_logout(): void
{
    $userId = SessionManager::getUserId();
    if ($userId) {
        log_activity('logout', ['user_id' => $userId]);
        // Supprimer remember_token en BDD
        $userModel = new User();
        $userModel->update($userId, ['remember_token' => null]);
    }

    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }

    SessionManager::destroy();
    response_redirect('/auth/login.php');
}

/**
 * Protège une page — redirige vers login si non authentifié.
 */
function auth_require(string $role = 'medecin'): void
{
    if (!auth_is_logged()) {
        html_set_flash('warning', 'Veuillez vous connecter pour accéder à cette page.');
        response_redirect('/auth/login.php');
    }

    if ($role && SessionManager::getUserRole() !== $role && SessionManager::getUserRole() !== 'admin') {
        response_redirect('/auth/dashboard.php');
    }
}

/**
 * Vérifie si un utilisateur est connecté.
 */
function auth_is_logged(): bool
{
    if (!SessionManager::isAuthenticated()) {
        // Tentative de reconnexion via remember token
        if (!empty($_COOKIE['remember_token'])) {
            return auth_remember_login($_COOKIE['remember_token']);
        }
        return false;
    }
    return true;
}

/**
 * Reconnexion automatique via cookie remember.
 */
function auth_remember_login(string $token): bool
{
    $userModel = new User();
    $hash      = hash('sha256', $token);
    $user      = $userModel->findBy('remember_token', $hash);

    if ($user && (bool)$user['is_active']) {
        SessionManager::setUser($user);
        $userModel->updateLastLogin((int)$user['id'], security_get_ip());
        return true;
    }

    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    return false;
}

/**
 * Retourne les données de l'utilisateur connecté.
 */
function auth_current_user(): ?array
{
    return SessionManager::getUser();
}

/**
 * Envoie un email de réinitialisation de mot de passe.
 */
function auth_send_reset_link(string $email): array
{
    $userModel = new User();
    $user      = $userModel->findByEmail(strtolower(trim($email)));

    // Réponse générique pour ne pas révéler si l'email existe
    $genericMsg = 'Si cet email est associé à un compte, un lien de réinitialisation vous a été envoyé.';

    if (!$user || !(bool)$user['is_active']) {
        return ['success' => true, 'message' => $genericMsg];
    }

    $token = $userModel->setResetToken((int)$user['id']);
    $sent  = mail_reset_password($user['email'], $user['prenom'] . ' ' . $user['nom'], $token);

    log_activity('password_reset_request', ['user_id' => $user['id']]);

    return ['success' => true, 'message' => $genericMsg];
}

/**
 * Réinitialise le mot de passe avec le token reçu par email.
 */
function auth_reset_password(string $token, string $newPassword, string $confirmation): array
{
    if ($newPassword !== $confirmation) {
        return ['success' => false, 'message' => 'Les mots de passe ne correspondent pas.'];
    }

    if (!validate_password_strength($newPassword)) {
        return ['success' => false, 'message' => 'Le mot de passe ne respecte pas les critères de sécurité.'];
    }

    $userModel = new User();
    $user      = $userModel->findByResetToken($token);

    if (!$user) {
        return ['success' => false, 'message' => 'Lien invalide ou expiré.'];
    }

    $userModel->updatePassword((int)$user['id'], $newPassword);
    $userModel->clearResetToken((int)$user['id']);
    log_activity('password_reset_success', ['user_id' => $user['id']]);

    return ['success' => true, 'message' => 'Mot de passe réinitialisé avec succès.'];
}


// ════════════════════════════════════════════════════════════
//  [2] GESTION DES MÉDECINS (USERS)
// ════════════════════════════════════════════════════════════

/**
 * Inscrit un nouveau médecin.
 */
function user_register(array $data): array
{
    $rules = [
        'nom'            => 'required|min:2|max:80',
        'prenom'         => 'required|min:2|max:80',
        'email'          => 'required|email|max:150',
        'password'       => 'required|password_strength|confirmed',
        'telephone'      => 'required|min:8|max:20',
        'specialite'     => 'required|min:3|max:100',
        'numero_ordre'   => 'required|min:4|max:50',
        'hospital_id'    => 'required|integer',
    ];

    $v = Validator::make($data, $rules);
    if ($v->fails()) {
        return ['success' => false, 'errors' => $v->getErrors()];
    }

    $userModel = new User();

    if ($userModel->isEmailTaken($data['email'])) {
        return ['success' => false, 'errors' => ['email' => ['Cet email est déjà utilisé.']]];
    }

    $userId = $userModel->create([
        'nom'          => security_sanitize($data['nom']),
        'prenom'       => security_sanitize($data['prenom']),
        'email'        => strtolower(trim($data['email'])),
        'password'     => (new User())->hashPassword($data['password']),
        'telephone'    => security_sanitize($data['telephone']),
        'specialite'   => security_sanitize($data['specialite']),
        'numero_ordre' => security_sanitize($data['numero_ordre']),
        'hospital_id'  => (int)$data['hospital_id'],
        'role'         => 'medecin',
        'is_active'    => 0, // En attente de validation admin
    ]);

    log_activity('user_registered', ['user_id' => $userId, 'email' => $data['email']]);
    mail_welcome($data['email'], $data['prenom'] . ' ' . $data['nom']);

    return ['success' => true, 'message' => 'Compte créé. En attente de validation.', 'user_id' => $userId];
}

/**
 * Met à jour le profil d'un médecin.
 */
function user_update_profile(int $userId, array $data): array
{
    $rules = [
        'nom'        => 'required|min:2|max:80',
        'prenom'     => 'required|min:2|max:80',
        'telephone'  => 'required|min:8|max:20',
        'specialite' => 'required|min:3|max:100',
    ];

    $v = Validator::make($data, $rules);
    if ($v->fails()) {
        return ['success' => false, 'errors' => $v->getErrors()];
    }

    $userModel = new User();
    $userModel->update($userId, [
        'nom'        => security_sanitize($data['nom']),
        'prenom'     => security_sanitize($data['prenom']),
        'telephone'  => security_sanitize($data['telephone']),
        'specialite' => security_sanitize($data['specialite']),
    ]);

    // Met à jour la session
    $updated = $userModel->findById($userId);
    if ($updated) SessionManager::setUser($updated);

    log_activity('profile_updated', ['user_id' => $userId]);
    return ['success' => true, 'message' => 'Profil mis à jour avec succès.'];
}

/**
 * Met à jour l'avatar du médecin.
 */
function user_update_avatar(int $userId, array $file): array
{
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $maxSize      = 2 * 1024 * 1024; // 2 Mo

    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'Image trop lourde (max 2 Mo).'];
    }

    $finfo    = new \finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);

    if (!in_array($realMime, $allowedMimes, true)) {
        return ['success' => false, 'message' => 'Format non autorisé (JPG, PNG, WEBP uniquement).'];
    }

    $ext      = match ($realMime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $filename  = 'avatar_' . $userId . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/../../../assets/uploads/avatars/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        return ['success' => false, 'message' => 'Échec du téléchargement de l\'avatar.'];
    }

    $userModel = new User();
    $userModel->updateAvatar($userId, '/assets/uploads/avatars/' . $filename);

    return ['success' => true, 'message' => 'Avatar mis à jour.', 'avatar' => '/assets/uploads/avatars/' . $filename];
}

/**
 * Change le mot de passe d'un médecin (depuis son profil).
 */
function user_change_password(int $userId, string $currentPwd, string $newPwd, string $confirmation): array
{
    if ($newPwd !== $confirmation) {
        return ['success' => false, 'message' => 'Les mots de passe ne correspondent pas.'];
    }
    if (!validate_password_strength($newPwd)) {
        return ['success' => false, 'message' => 'Le nouveau mot de passe ne respecte pas les critères.'];
    }

    $userModel = new User();
    $user      = $userModel->db->fetchOne(
        "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1", [$userId]
    );

    if (!$user || !$userModel->verifyPassword($currentPwd, $user['password'])) {
        return ['success' => false, 'message' => 'Mot de passe actuel incorrect.'];
    }

    $userModel->updatePassword($userId, $newPwd);
    log_activity('password_changed', ['user_id' => $userId]);

    return ['success' => true, 'message' => 'Mot de passe modifié avec succès.'];
}

function user_get_stats(int $userId): array
{
    return (new User())->getStatsByUser($userId);
}

function user_get_with_hospital(int $userId): ?array
{
    return (new User())->getWithHospital($userId);
}


// ════════════════════════════════════════════════════════════
//  [3] UPLOAD & GESTION DES IMAGES CT SCAN
// ════════════════════════════════════════════════════════════

/**
 * Gère l'upload sécurisé d'une image CT Scan.
 *
 * @return array{success: bool, path?: string, filename?: string, hash?: string, size?: int, message?: string}
 */
function scan_upload(array $file, int $userId): array
{
    $errors = Validator::validateScanFile($file);
    if (!empty($errors)) {
        return ['success' => false, 'message' => implode(' ', $errors)];
    }

    $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $hash      = scan_compute_hash($file['tmp_name']);
    $filename  = scan_generate_filename($userId, $ext);
    $uploadDir = __DIR__ . '/../../../assets/uploads/scans/' . date('Y/m/') . $userId . '/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fullPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        log_error('scan_upload_failed', ['user_id' => $userId, 'file' => $file['name']]);
        return ['success' => false, 'message' => 'Erreur lors du téléchargement du fichier.'];
    }

    $relativePath = '/assets/uploads/scans/' . date('Y/m/') . $userId . '/' . $filename;

    log_activity('scan_uploaded', ['user_id' => $userId, 'file' => $filename, 'size' => $file['size']]);

    return [
        'success'  => true,
        'path'     => $fullPath,
        'url'      => $relativePath,
        'filename' => $filename,
        'original' => $file['name'],
        'hash'     => $hash,
        'size'     => $file['size'],
    ];
}

/**
 * Supprime physiquement un fichier scan.
 */
function scan_delete_file(string $relativePath): bool
{
    $fullPath = __DIR__ . '/../../../' . ltrim($relativePath, '/');
    if (file_exists($fullPath) && is_file($fullPath)) {
        return unlink($fullPath);
    }
    return false;
}

/**
 * Retourne l'URL publique d'un scan.
 */
function scan_get_url(string $relativePath): string
{
    return env('APP_URL', '') . $relativePath;
}

/**
 * Calcule le hash SHA-256 du fichier pour détecter les doublons.
 */
function scan_compute_hash(string $filePath): string
{
    return hash_file('sha256', $filePath);
}

/**
 * Génère un nom de fichier unique et sécurisé.
 */
function scan_generate_filename(int $userId, string $ext): string
{
    return sprintf('scan_%d_%s_%s.%s', $userId, date('Ymd_His'), bin2hex(random_bytes(4)), $ext);
}


// ════════════════════════════════════════════════════════════
//  [4] DÉTECTION IA — COMMUNICATION PYTHON
// ════════════════════════════════════════════════════════════

/**
 * Point d'entrée principal : lance la prédiction IA sur une image.
 *
 * @return array{success: bool, result?: array, message?: string}
 */
function ai_predict(string $imagePath): array
{
    if (!file_exists($imagePath)) {
        return ['success' => false, 'message' => 'Fichier image introuvable.'];
    }

    $raw = ai_call_python($imagePath);

    if ($raw === null) {
        return ['success' => false, 'message' => 'Le service IA est indisponible. Réessayez plus tard.'];
    }

    $result = ai_parse_response($raw);

    if (!$result) {
        return ['success' => false, 'message' => 'Réponse IA invalide.'];
    }

    log_detection($imagePath, $result);

    return ['success' => true, 'result' => $result];
}

/**
 * Appelle le script Python via exec() ou cURL (API Flask selon config).
 */
function ai_call_python(string $imagePath): ?string
{
    $method = env('AI_METHOD', 'exec');

    if ($method === 'api') {
        return ai_call_flask_api($imagePath);
    }

    return ai_call_exec($imagePath);
}

/**
 * Appel via exec() — script Python local.
 */
function ai_call_exec(string $imagePath): ?string
{
    $pythonBin = env('PYTHON_BIN', 'python3');
    $scriptPath = escapeshellarg(__DIR__ . '/../../../ai_model/scripts/predict.py');
    $imageArg   = escapeshellarg($imagePath);
    $command    = "{$pythonBin} {$scriptPath} --image {$imageArg} 2>&1";

    $output   = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        log_error('ai_exec_failed', ['exit_code' => $exitCode, 'output' => $output]);
        return null;
    }

    return implode('', $output);
}

/**
 * Appel via API Flask (Python microservice).
 */
function ai_call_flask_api(string $imagePath): ?string
{
    $apiUrl = env('AI_API_URL', 'http://localhost:5000/predict');

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['image' => new CURLFile($imagePath)],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $httpCode !== 200) {
        log_error('ai_flask_failed', ['http_code' => $httpCode, 'curl_error' => $curlErr]);
        return null;
    }

    return $response;
}

/**
 * Parse la réponse JSON du modèle IA.
 */
function ai_parse_response(string $raw): ?array
{
    $data = json_decode(trim($raw), true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['result_type'])) {
        log_error('ai_parse_error', ['raw' => substr($raw, 0, 500)]);
        return null;
    }

    return [
        'result_type'      => $data['result_type']      ?? 'inconnu',
        'confidence_score' => round((float)($data['confidence'] ?? 0) * 100, 2),
        'stage'            => $data['stage']             ?? null,
        'regions_json'     => json_encode($data['regions'] ?? []),
        'model_version'    => $data['model_version']     ?? '1.0',
        'processing_time_ms' => (int)($data['processing_time_ms'] ?? 0),
        'probabilities'    => $data['probabilities']     ?? [],
    ];
}

/**
 * Formate le résultat IA pour l'affichage.
 */
function ai_format_result(array $result): array
{
    return [
        ...$result,
        'label'      => ai_get_result_label($result['result_type']),
        'color'      => ai_get_result_color($result['result_type']),
        'icon'       => ai_get_result_icon($result['result_type']),
        'stage_label'=> ai_get_stage_label($result['stage']),
    ];
}

function ai_get_result_label(string $type): string
{
    return match ($type) {
        'normal'    => 'Normal — Aucune anomalie détectée',
        'suspect'   => 'Suspect — Anomalie à surveiller',
        'cancereux' => 'Cancéreux — Anomalie maligne détectée',
        default     => 'Résultat indéterminé',
    };
}

function ai_get_result_color(string $type): string
{
    return match ($type) {
        'normal'    => '#22c55e',   // green-500
        'suspect'   => '#f59e0b',   // amber-500
        'cancereux' => '#ef4444',   // red-500
        default     => '#6b7280',
    };
}

function ai_get_result_icon(string $type): string
{
    return match ($type) {
        'normal'    => 'fa-circle-check',
        'suspect'   => 'fa-triangle-exclamation',
        'cancereux' => 'fa-circle-xmark',
        default     => 'fa-circle-question',
    };
}

function ai_get_stage_label(?string $stage): string
{
    if ($stage === null) return '—';
    return match ($stage) {
        'I'   => 'Stade I  — Localisation précoce',
        'II'  => 'Stade II — Extension limitée',
        'III' => 'Stade III — Extension régionale',
        'IV'  => 'Stade IV  — Métastase avancée',
        default => $stage,
    };
}


// ════════════════════════════════════════════════════════════
//  [5] DETECTIONS (CRUD / HISTORIQUE)
// ════════════════════════════════════════════════════════════

/**
 * Crée une nouvelle entrée de détection en base.
 */
function detection_create(int $userId, array $scanData, array $aiResult, array $patientData): array
{
    $model = new Detection();

    // Vérification doublon (même hash, même médecin)
    $duplicate = $model->checkDuplicate($scanData['hash'], $userId);
    if ($duplicate) {
        return [
            'success'     => true,
            'detection_id'=> $duplicate['id'],
            'is_duplicate'=> true,
            'message'     => 'Ce scan a déjà été analysé.',
        ];
    }

    $id = $model->create([
        'user_id'            => $userId,
        'patient_nom'        => security_sanitize($patientData['nom'] ?? ''),
        'patient_prenom'     => security_sanitize($patientData['prenom'] ?? ''),
        'patient_age'        => (int)($patientData['age'] ?? 0),
        'patient_sexe'       => security_sanitize($patientData['sexe'] ?? ''),
        'patient_code'       => security_sanitize($patientData['code'] ?? ''),
        'image_path'         => $scanData['url'],
        'image_original_name'=> $scanData['original'],
        'image_size'         => $scanData['size'],
        'image_hash'         => $scanData['hash'],
        'result_type'        => $aiResult['result_type'],
        'confidence_score'   => $aiResult['confidence_score'],
        'stage'              => $aiResult['stage'],
        'regions_json'       => $aiResult['regions_json'],
        'model_version'      => $aiResult['model_version'],
        'processing_time_ms' => $aiResult['processing_time_ms'],
        'status'             => 'completed',
    ]);

    log_activity('detection_created', ['user_id' => $userId, 'detection_id' => $id, 'result' => $aiResult['result_type']]);

    return ['success' => true, 'detection_id' => (int)$id, 'is_duplicate' => false];
}

function detection_get(int $detectionId): ?array
{
    return (new Detection())->getWithUser($detectionId);
}

function detection_get_all_by_user(int $userId, int $page = 1, int $perPage = 10): array
{
    return (new Detection())->getByUserPaginated($userId, $page, $perPage);
}

function detection_get_recent(int $userId, int $limit = 5): array
{
    return (new Detection())->getRecentByUser($userId, $limit);
}

function detection_search(array $filters, int $userId, int $page = 1): array
{
    return (new Detection())->search($filters, $userId, $page);
}

function detection_mark_reviewed(int $detectionId, string $notes = ''): void
{
    (new Detection())->markAsReviewed($detectionId, $notes);
    log_activity('detection_reviewed', ['detection_id' => $detectionId]);
}

function detection_delete(int $detectionId, int $userId): array
{
    $model     = new Detection();
    $detection = $model->findById($detectionId);

    if (!$detection || (int)$detection['user_id'] !== $userId) {
        return ['success' => false, 'message' => 'Analyse introuvable ou accès refusé.'];
    }

    $model->delete($detectionId);
    log_activity('detection_deleted', ['detection_id' => $detectionId, 'user_id' => $userId]);

    return ['success' => true, 'message' => 'Analyse supprimée.'];
}

function detection_get_global_stats(): array
{
    return (new Detection())->getGlobalStats();
}


// ════════════════════════════════════════════════════════════
//  [6] SÉCURITÉ & VALIDATION
// ════════════════════════════════════════════════════════════

function security_csrf_token(): string
{
    return SessionManager::generateCsrfToken();
}

function security_verify_csrf(string $token): bool
{
    return SessionManager::validateCsrfToken($token);
}

function security_sanitize(mixed $input): string
{
    return Security::sanitizeString($input);
}

function security_rate_limit(string $key, int $max = 5, int $decay = 300): bool
{
    return Security::checkRateLimit($key, $max, $decay);
}

function security_get_ip(): string
{
    return Security::getClientIp();
}

/**
 * Valide un tableau de champs selon des règles.
 * @return array{valid: bool, errors: array}
 */
function validate_fields(array $data, array $rules): array
{
    $v = Validator::make($data, $rules);
    return ['valid' => $v->passes(), 'errors' => $v->getErrors()];
}

function validate_email(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validate_password_strength(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

function validate_scan_file(array $file): array
{
    return Validator::validateScanFile($file);
}


// ════════════════════════════════════════════════════════════
//  [7] RÉPONSES HTTP & API
// ════════════════════════════════════════════════════════════

function response_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function response_json_success(array $data = [], string $message = 'Succès'): never
{
    response_json(['success' => true, 'message' => $message, 'data' => $data]);
}

function response_json_error(string $message, int $status = 400, array $errors = []): never
{
    response_json(['success' => false, 'message' => $message, 'errors' => $errors], $status);
}

function response_redirect(string $url, int $status = 302): never
{
    header("Location: {$url}", true, $status);
    exit;
}

function response_set_header(string $name, string $value): void
{
    header("{$name}: {$value}");
}


// ════════════════════════════════════════════════════════════
//  [8] RENDU HTML / TEMPLATE HELPERS
// ════════════════════════════════════════════════════════════

function html_set_flash(string $type, string $message): void
{
    SessionManager::setFlash($type, $message);
}

/**
 * Affiche et vide les messages flash.
 * Types : success | error | warning | info
 */
function html_flash(): string
{
    $types = ['success', 'error', 'warning', 'info'];
    $icons = [
        'success' => 'fa-circle-check',
        'error'   => 'fa-circle-xmark',
        'warning' => 'fa-triangle-exclamation',
        'info'    => 'fa-circle-info',
    ];
    $output = '';

    foreach ($types as $type) {
        foreach (SessionManager::getFlash($type) as $message) {
            $icon = $icons[$type] ?? 'fa-bell';
            $output .= <<<HTML
            <div class="alert alert--{$type}" role="alert" data-auto-dismiss="5000">
                <i class="fa-solid {$icon}"></i>
                <span>{$message}</span>
                <button class="alert__close" aria-label="Fermer">&times;</button>
            </div>
            HTML;
        }
    }

    return $output;
}

function html_csrf_input(): string
{
    $token = security_csrf_token();
    return "<input type=\"hidden\" name=\"_token\" value=\"{$token}\">";
}

function html_active_class(string $page, string $current, string $class = 'active'): string
{
    return basename($current) === $page ? $class : '';
}

function html_avatar_url(?string $avatar): string
{
    if ($avatar && file_exists(__DIR__ . '/../../../' . ltrim($avatar, '/'))) {
        return $avatar;
    }
    return '/assets/images/default-avatar.svg';
}

/**
 * Badge coloré pour le type de résultat IA.
 */
function html_result_badge(string $type): string
{
    $label = match ($type) {
        'normal'    => 'Normal',
        'suspect'   => 'Suspect',
        'cancereux' => 'Cancéreux',
        default     => 'Inconnu',
    };
    return "<span class=\"badge badge--{$type}\">{$label}</span>";
}

/**
 * Barre de confiance IA en HTML.
 */
function html_confidence_bar(float $score): string
{
    $color = $score >= 85 ? '#22c55e' : ($score >= 60 ? '#f59e0b' : '#ef4444');
    $pct   = round($score, 1);
    return <<<HTML
    <div class="confidence-bar" title="Confiance : {$pct}%">
        <div class="confidence-bar__fill" style="width:{$pct}%; background:{$color}"></div>
        <span class="confidence-bar__label">{$pct}%</span>
    </div>
    HTML;
}

function html_format_date(string $dateStr, string $format = 'd/m/Y à H:i'): string
{
    $date = new DateTime($dateStr);
    return $date->format($format);
}

function html_format_size(int $bytes): string
{
    if ($bytes < 1024)       return "{$bytes} o";
    if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' Ko';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' Mo';
    return round($bytes / 1073741824, 2) . ' Go';
}

function html_page_title(string $page): string
{
    return $page . ' — PulmoCare IA';
}


// ════════════════════════════════════════════════════════════
//  [9] EMAILS
// ════════════════════════════════════════════════════════════

/**
 * Envoi générique de mail HTML.
 */
function mail_send(string $to, string $subject, string $htmlBody): bool
{
    $from    = env('MAIL_FROM', 'noreply@pulmocare.local');
    $fromName= env('MAIL_FROM_NAME', 'PulmoCare IA');
    $headers = implode("\r\n", [
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "From: {$fromName} <{$from}>",
        "X-Mailer: PulmoCare-Mailer/1.0",
    ]);
    return mail($to, $subject, $htmlBody, $headers);
}

function mail_reset_password(string $to, string $name, string $token): bool
{
    $url     = env('APP_URL') . '/auth/forgot-password.php?token=' . urlencode($token);
    $subject = '🔐 Réinitialisation de votre mot de passe — PulmoCare';
    $body    = <<<HTML
    <div style="font-family:sans-serif;max-width:560px;margin:auto;padding:32px">
        <h2 style="color:#1e40af">PulmoCare IA</h2>
        <p>Bonjour Dr. <strong>{$name}</strong>,</p>
        <p>Vous avez demandé la réinitialisation de votre mot de passe. Cliquez sur le bouton ci-dessous :</p>
        <a href="{$url}" style="display:inline-block;margin:16px 0;padding:12px 24px;background:#1e40af;color:#fff;border-radius:6px;text-decoration:none">
            Réinitialiser mon mot de passe
        </a>
        <p style="color:#6b7280;font-size:13px">Ce lien expire dans 1 heure. Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.</p>
    </div>
    HTML;
    return mail_send($to, $subject, $body);
}

function mail_welcome(string $to, string $name): bool
{
    $subject = '✅ Bienvenue sur PulmoCare IA — Compte en cours de validation';
    $body    = <<<HTML
    <div style="font-family:sans-serif;max-width:560px;margin:auto;padding:32px">
        <h2 style="color:#1e40af">PulmoCare IA</h2>
        <p>Bonjour Dr. <strong>{$name}</strong>,</p>
        <p>Votre compte a bien été créé. Il sera activé après validation par un administrateur.</p>
        <p>Vous recevrez un email de confirmation dès que votre compte sera approuvé.</p>
        <p style="color:#6b7280;font-size:13px">L'équipe PulmoCare</p>
    </div>
    HTML;
    return mail_send($to, $subject, $body);
}

function mail_detection_report(string $to, string $name, array $detection): bool
{
    $badge   = ucfirst($detection['result_type']);
    $date    = html_format_date($detection['created_at']);
    $subject = "📋 Rapport d'analyse CT Scan — {$detection['patient_nom']} {$detection['patient_prenom']}";
    $body    = <<<HTML
    <div style="font-family:sans-serif;max-width:600px;margin:auto;padding:32px">
        <h2 style="color:#1e40af">PulmoCare IA — Rapport d'analyse</h2>
        <p>Dr. <strong>{$name}</strong>,</p>
        <table style="width:100%;border-collapse:collapse;margin:16px 0">
            <tr><td style="padding:8px;border:1px solid #e5e7eb">Patient</td>
                <td style="padding:8px;border:1px solid #e5e7eb">{$detection['patient_nom']} {$detection['patient_prenom']}</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb">Date</td>
                <td style="padding:8px;border:1px solid #e5e7eb">{$date}</td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb">Résultat</td>
                <td style="padding:8px;border:1px solid #e5e7eb"><strong>{$badge}</strong></td></tr>
            <tr><td style="padding:8px;border:1px solid #e5e7eb">Confiance</td>
                <td style="padding:8px;border:1px solid #e5e7eb">{$detection['confidence_score']}%</td></tr>
        </table>
        <p style="color:#6b7280;font-size:12px">Ce rapport est généré automatiquement. Consultez la plateforme pour plus de détails.</p>
    </div>
    HTML;
    return mail_send($to, $subject, $body);
}


// ════════════════════════════════════════════════════════════
//  [10] LOGS & AUDIT
// ════════════════════════════════════════════════════════════

function log_activity(string $action, array $context = []): void
{
    $logDir  = __DIR__ . '/../../../storage/logs/';
    $logFile = $logDir . 'activity_' . date('Y-m-d') . '.log';

    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    $entry = json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'action'    => $action,
        'ip'        => security_get_ip(),
        'context'   => $context,
    ]);

    file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function log_error(string $type, array $context = []): void
{
    $logDir  = __DIR__ . '/../../../storage/logs/';
    $logFile = $logDir . 'error_' . date('Y-m-d') . '.log';

    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    $entry = json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'type'      => $type,
        'ip'        => security_get_ip(),
        'context'   => $context,
    ]);

    file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function log_detection(string $imagePath, array $result): void
{
    log_activity('ai_prediction', [
        'image'       => basename($imagePath),
        'result_type' => $result['result_type'],
        'confidence'  => $result['confidence_score'],
        'model'       => $result['model_version'],
        'time_ms'     => $result['processing_time_ms'],
    ]);
}


// ════════════════════════════════════════════════════════════
//  [11] UTILITAIRES GÉNÉRAUX
// ════════════════════════════════════════════════════════════

function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

function config_get(string $key, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $configFile = __DIR__ . '/../../../config.php';
        $config     = file_exists($configFile) ? require $configFile : [];
    }
    return $config[$key] ?? $default;
}

function str_truncate(string $str, int $length = 80, string $suffix = '…'): string
{
    if (mb_strlen($str) <= $length) return $str;
    return mb_substr($str, 0, $length) . $suffix;
}

function str_slug(string $str): string
{
    $str = strtolower(trim($str));
    $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    return trim($str, '-');
}

function arr_get(array $array, string $key, mixed $default = null): mixed
{
    return $array[$key] ?? $default;
}

/**
 * Génère les liens de pagination HTML.
 */
function pagination_links(array $paginator, string $baseUrl = ''): string
{
    if ($paginator['last_page'] <= 1) return '';

    $current = (int)$paginator['current_page'];
    $last    = (int)$paginator['last_page'];
    $base    = $baseUrl ?: strtok($_SERVER['REQUEST_URI'], '?');
    $html    = '<nav class="pagination" aria-label="Pagination"><ul class="pagination__list">';

    // Précédent
    if ($current > 1) {
        $html .= "<li><a href=\"{$base}?page=" . ($current - 1) . "\" class=\"pagination__btn\" aria-label=\"Précédent\"><i class=\"fa-solid fa-chevron-left\"></i></a></li>";
    }

    // Pages
    $range = range(max(1, $current - 2), min($last, $current + 2));
    if (!in_array(1, $range)) {
        $html .= "<li><a href=\"{$base}?page=1\" class=\"pagination__btn\">1</a></li>";
        if (!in_array(2, $range)) $html .= "<li><span class=\"pagination__dots\">…</span></li>";
    }

    foreach ($range as $page) {
        $active = $page === $current ? ' pagination__btn--active' : '';
        $html  .= "<li><a href=\"{$base}?page={$page}\" class=\"pagination__btn{$active}\" aria-current=\"" . ($page === $current ? 'page' : 'false') . "\">{$page}</a></li>";
    }

    if (!in_array($last, $range)) {
        if (!in_array($last - 1, $range)) $html .= "<li><span class=\"pagination__dots\">…</span></li>";
        $html .= "<li><a href=\"{$base}?page={$last}\" class=\"pagination__btn\">{$last}</a></li>";
    }

    // Suivant
    if ($current < $last) {
        $html .= "<li><a href=\"{$base}?page=" . ($current + 1) . "\" class=\"pagination__btn\" aria-label=\"Suivant\"><i class=\"fa-solid fa-chevron-right\"></i></a></li>";
    }

    $html .= '</ul></nav>';
    return $html;
}
