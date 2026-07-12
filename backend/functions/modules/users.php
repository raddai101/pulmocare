<?php

declare(strict_types=1);

use App\Config\SessionManager;
use App\Models\User;
use App\Helpers\Security;
use App\Helpers\Validator;

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

function user_update_avatar(int $userId, array $file): array
{
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $maxSize      = 2 * 1024 * 1024;

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

    $updated = $userModel->findById($userId);
    if ($updated) {
        SessionManager::setUser($updated);
    }

    return ['success' => true, 'message' => 'Avatar mis à jour.', 'avatar' => '/assets/uploads/avatars/' . $filename];
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
