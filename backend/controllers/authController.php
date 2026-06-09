<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\SessionManager;
use App\Helpers\Security;

/**
 * AuthController — Gestion complète de l'authentification
 * Utilisé par les pages PHP via les fonctions centralisées.
 * Peut aussi être appelé directement pour des besoins avancés (API REST, etc.)
 */
class AuthController
{
    /**
     * Traite la connexion d'un médecin.
     * Appelé depuis auth/login.php via auth_login().
     */
    public function login(): array
    {
        $this->requirePost();
        $this->verifyCsrf();

        $email    = Security::sanitizeString($_POST['email']    ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        if (empty($email) || empty($password)) {
            return $this->error('Email et mot de passe obligatoires.');
        }

        return auth_login($email, $password, $remember);
    }

    /**
     * Déconnexion.
     */
    public function logout(): void
    {
        auth_logout();
    }

    /**
     * Demande de lien de réinitialisation.
     */
    public function forgotPassword(): array
    {
        $this->requirePost();
        $this->verifyCsrf();

        $email = Security::sanitizeString($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Adresse email invalide.');
        }

        return auth_send_reset_link($email);
    }

    /**
     * Réinitialisation effective du mot de passe.
     */
    public function resetPassword(): array
    {
        $this->requirePost();
        $this->verifyCsrf();

        return auth_reset_password(
            Security::sanitizeString($_POST['token']                ?? ''),
            $_POST['new_password']         ?? '',
            $_POST['new_password_confirm'] ?? ''
        );
    }

    /**
     * Inscription d'un nouveau médecin.
     */
    public function register(): array
    {
        $this->requirePost();
        $this->verifyCsrf();

        return user_register($_POST);
    }

    // ── Helpers privés ────────────────────────────────────────

    private function requirePost(): void
    {
        if (Security::getRequestMethod() !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
    }

    private function verifyCsrf(): void
    {
        if (!security_verify_csrf($_POST['_token'] ?? '')) {
            if (Security::isAjaxRequest()) {
                response_json_error('Jeton CSRF invalide.', 403);
            }
            html_set_flash('error', 'Requête invalide. Veuillez réessayer.');
            response_redirect(($_SERVER['HTTP_REFERER'] ?? '/auth/login.php'));
        }
    }

    private function error(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
