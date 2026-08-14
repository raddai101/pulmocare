<?php
declare(strict_types=1);

if (!defined('FUNCTIONS_LOADED')) {
    throw new \RuntimeException('Include functions.php first.');
}

use App\Models\User;
use App\Config\SessionManager;
use App\Helpers\Validator;
use App\Helpers\Security;

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
        'is_active'    => 0,
    ]);

    log_activity('user_registered', ['user_id' => $userId, 'email' => $data['email']]);
    mail_welcome($data['email'], $data['prenom'] . ' ' . $data['nom']);

    return ['success' => true, 'message' => 'Compte créé. En attente de validation.', 'user_id' => $userId];
}

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

    $updated = $userModel->findById($userId);
    if ($updated) SessionManager::setUser($updated);

    log_activity('profile_updated', ['user_id' => $userId]);
    return ['success' => true, 'message' => 'Profil mis à jour avec succès.'];
}

/**
 * Met à jour l'avatar d'un médecin.
 *
 * BUGFIX (par rapport à la version précédente) :
 *  - vérifie le code d'erreur PHP d'upload avant toute chose (fichier trop
 *    gros pour php.ini, upload interrompu, etc.) au lieu de suivre en
 *    silence avec un tmp_name potentiellement invalide ;
 *  - vérifie que le fichier est bien une vraie soumission HTTP
 *    (is_uploaded_file) ;
 *  - construit le chemin projet avec dirname(__DIR__, 3) — cohérent avec
 *    html_avatar_url() désormais corrigé lui aussi — au lieu d'une chaîne
 *    littérale '../../../' qui, elle, était correcte mais divergente ;
 *  - journalise précisément la cause d'un échec (mkdir, droits d'écriture,
 *    move_uploaded_file) pour un diagnostic rapide côté logs ;
 *  - supprime l'ancien fichier avatar physique lors du remplacement, pour
 *    ne pas accumuler des images orphelines sur le disque.
 */
function user_update_avatar(int $userId, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        log_error('avatar_upload_error_code', ['user_id' => $userId, 'code' => $file['error'] ?? null]);
        return ['success' => false, 'message' => "Échec de l'envoi du fichier (upload interrompu ou trop volumineux)."];
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $maxSize      = 2 * 1024 * 1024;

    if (($file['size'] ?? 0) > $maxSize) {
        return ['success' => false, 'message' => 'Image trop lourde (max 2 Mo).'];
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => 'Fichier invalide ou non reçu.'];
    }

    $finfo    = new \finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);

    if (!in_array($realMime, $allowedMimes, true)) {
        return ['success' => false, 'message' => 'Format non autorisé (JPG, PNG, WEBP uniquement).'];
    }

    $ext = match ($realMime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $filename    = 'avatar_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $projectRoot = dirname(__DIR__, 3); // modules -> functions -> backend -> racine projet
    $uploadDir   = $projectRoot . '/assets/uploads/avatars/';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        log_error('avatar_mkdir_failed', ['user_id' => $userId, 'dir' => $uploadDir]);
        return ['success' => false, 'message' => "Impossible de créer le dossier d'upload sur le serveur."];
    }

    if (!is_writable($uploadDir)) {
        log_error('avatar_dir_not_writable', ['user_id' => $userId, 'dir' => $uploadDir]);
        return ['success' => false, 'message' => "Le dossier d'upload n'est pas accessible en écriture (droits serveur)."];
    }

    $fullPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        log_error('avatar_move_failed', ['user_id' => $userId, 'target' => $fullPath]);
        return ['success' => false, 'message' => "Échec du téléchargement de l'avatar."];
    }

    $userModel  = new User();
    $previous   = $userModel->findById($userId);
    $avatarPath = '/assets/uploads/avatars/' . $filename;

    $userModel->updateAvatar($userId, $avatarPath);

    // Nettoyage : supprime l'ancien fichier avatar physique s'il existait.
    if (!empty($previous['avatar']) && $previous['avatar'] !== $avatarPath) {
        $oldFile = $projectRoot . '/' . ltrim((string)$previous['avatar'], '/');
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    $updated = $userModel->findById($userId);
    if ($updated) {
        SessionManager::setUser($updated);
    }

    log_activity('avatar_updated', ['user_id' => $userId, 'file' => $filename]);

    return ['success' => true, 'message' => 'Avatar mis à jour.', 'avatar' => $avatarPath];
}

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