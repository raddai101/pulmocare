<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * SessionManager — Gestion sécurisée des sessions PHP
 * CSRF tokens, fingerprinting, régénération anti-fixation
 */
final class SessionManager
{
    private static bool $started = false;
    private const SESSION_LIFETIME = 3600;       // 1 heure
    private const REGEN_INTERVAL   = 300;        // Régénère l'ID toutes les 5 min
    private const CSRF_TOKEN_LENGTH = 64;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }

        self::configure();
        session_start();
        self::$started = true;

        self::validateFingerprint();
        self::regenerateIfNeeded();
        self::refreshActivity();
    }

    private static function configure(): void
    {
        ini_set('session.use_strict_mode',     '1');
        ini_set('session.use_only_cookies',    '1');
        ini_set('session.use_trans_sid',       '0');
        ini_set('session.cookie_httponly',     '1');
        ini_set('session.cookie_samesite',     'Strict');
        ini_set('session.gc_maxlifetime',      (string)self::SESSION_LIFETIME);
        ini_set('session.entropy_length',      '32');

        if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'production') {
            ini_set('session.cookie_secure', '1');
        }

        session_name('CANCER_DETECT_SESS');
    }

    private static function validateFingerprint(): void
    {
        $fingerprint = self::generateFingerprint();

        if (isset($_SESSION['_fingerprint'])) {
            if (!hash_equals($_SESSION['_fingerprint'], $fingerprint)) {
                self::destroy();
                throw new RuntimeException('Session invalidée : fingerprint mismatch.');
            }
        } else {
            $_SESSION['_fingerprint'] = $fingerprint;
        }
    }

    private static function generateFingerprint(): string
    {
        return hash('sha256',
            ($_SERVER['HTTP_USER_AGENT'] ?? '') .
            ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') .
            ($_ENV['APP_SECRET'] ?? 'default_secret')
        );
    }

    private static function regenerateIfNeeded(): void
    {
        $lastRegen = $_SESSION['_last_regen'] ?? 0;

        if ((time() - $lastRegen) >= self::REGEN_INTERVAL) {
            session_regenerate_id(true);
            $_SESSION['_last_regen'] = time();
        }
    }

    private static function refreshActivity(): void
    {
        $_SESSION['_last_activity'] = time();
    }

    public static function isExpired(): bool
    {
        if (!isset($_SESSION['_last_activity'])) {
            return true;
        }
        return (time() - $_SESSION['_last_activity']) > self::SESSION_LIFETIME;
    }

    public static function setUser(array $userData): void
    {
        $_SESSION['user'] = [
            'id'         => $userData['id'],
            'nom'        => $userData['nom'],
            'prenom'     => $userData['prenom'],
            'email'      => $userData['email'],
            'role'       => $userData['role'],
            'specialite' => $userData['specialite'] ?? null,
            'hospital_id'=> $userData['hospital_id'] ?? null,
        ];
        $_SESSION['_auth_time'] = time();
    }

    public static function getUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function isAuthenticated(): bool
    {
        return isset($_SESSION['user']) && !self::isExpired();
    }

    public static function getUserId(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    }

    public static function getUserRole(): ?string
    {
        return $_SESSION['user']['role'] ?? null;
    }

    public static function generateCsrfToken(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(self::CSRF_TOKEN_LENGTH / 2));
        }
        return $_SESSION['_csrf_token'];
    }

    public static function validateCsrfToken(string $token): bool
    {
        if (empty($_SESSION['_csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['_csrf_token'], $token);
    }

    public static function rotateCsrfToken(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(self::CSRF_TOKEN_LENGTH / 2));
    }

    public static function setFlash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    public static function getFlash(string $type): array
    {
        $messages = $_SESSION['_flash'][$type] ?? [];
        unset($_SESSION['_flash'][$type]);
        return $messages;
    }

    public static function hasFlash(string $type): bool
    {
        return !empty($_SESSION['_flash'][$type]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
        self::$started = false;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }
}
