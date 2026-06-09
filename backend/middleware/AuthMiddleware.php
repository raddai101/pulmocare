<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Config\SessionManager;
use App\Helpers\Security;

/**
 * AuthMiddleware — Middleware centralisé de protection des routes
 */
class AuthMiddleware
{
    public static function requireAuth(string $role = ''): void
    {
        SessionManager::start();
        if (!SessionManager::isAuthenticated() || SessionManager::isExpired()) {
            SessionManager::setFlash('warning', 'Veuillez vous connecter.');
            header('Location: /auth/login.php', true, 302); exit;
        }
        if ($role && SessionManager::getUserRole() !== $role && SessionManager::getUserRole() !== 'admin') {
            header('Location: /auth/dashboard.php', true, 302); exit;
        }
        Security::setSecurityHeaders();
    }

    public static function requireAuthApi(string $role = ''): void
    {
        SessionManager::start();
        if (!SessionManager::isAuthenticated() || SessionManager::isExpired()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success'=>false,'message'=>'Non authentifié.']); exit;
        }
        if ($role && SessionManager::getUserRole() !== $role && SessionManager::getUserRole() !== 'admin') {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success'=>false,'message'=>'Accès refusé.']); exit;
        }
    }

    public static function verifyCsrf(): void
    {
        $token = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!SessionManager::validateCsrfToken($token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success'=>false,'message'=>'CSRF invalide.']); exit;
        }
    }

    public static function rateLimit(string $key, int $max = 60, int $decay = 60): void
    {
        if (!Security::checkRateLimit($key.':'.Security::getClientIp(), $max, $decay)) {
            http_response_code(429); header('Retry-After: '.$decay);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success'=>false,'message'=>'Trop de requêtes.']); exit;
        }
    }
}
