<?php

declare(strict_types=1);

use App\Config\SessionManager;
use App\Models\User;
use App\Helpers\Security;
use App\Helpers\Validator;

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

function auth_logout(): void
{
    $userId = SessionManager::getUserId();
    if ($userId) {
        log_activity('logout', ['user_id' => $userId]);
        $userModel = new User();
        $userModel->update($userId, ['remember_token' => null]);
    }

    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }

    SessionManager::destroy();
    response_redirect('/auth/login.php');
}

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

function auth_is_logged(): bool
{
    if (!SessionManager::isAuthenticated()) {
        if (!empty($_COOKIE['remember_token'])) {
            return auth_remember_login($_COOKIE['remember_token']);
        }
        return false;
    }
    return true;
}

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

function auth_current_user(): ?array
{
    return SessionManager::getUser();
}
