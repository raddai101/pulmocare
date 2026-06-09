<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Security — Centralise les opérations de sécurité
 * Sanitisation, headers, rate limiting, validation IP
 */
class Security
{
    // ─── Sanitisation ─────────────────────────────────────────────────────

    public static function sanitizeString(mixed $input): string
    {
        return htmlspecialchars(strip_tags(trim((string)$input)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function sanitizeEmail(string $email): string|false
    {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }

    public static function sanitizeInt(mixed $value): int
    {
        return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    public static function sanitizeArray(array $data): array
    {
        return array_map(function ($value) {
            if (is_array($value)) return self::sanitizeArray($value);
            return self::sanitizeString($value);
        }, $data);
    }

    // ─── Headers de sécurité HTTP ─────────────────────────────────────────

    public static function setSecurityHeaders(): void
    {
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "script-src 'self' 'nonce-" . self::getNonce() . "' https://cdnjs.cloudflare.com; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; " .
            "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
            "img-src 'self' data: blob:; " .
            "connect-src 'self';"
        );

        if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'production') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }
    }

    private static string $nonce = '';

    public static function getNonce(): string
    {
        if (empty(self::$nonce)) {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    // ─── Rate Limiting (basé fichier / adaptable Redis) ───────────────────

    public static function checkRateLimit(string $key, int $maxAttempts = 5, int $decaySeconds = 300): bool
    {
        $cacheDir  = sys_get_temp_dir() . '/cancer_ratelimit/';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0700, true);
        }

        $file = $cacheDir . md5($key) . '.json';
        $now  = time();
        $data = ['attempts' => 0, 'first_attempt' => $now, 'blocked_until' => 0];

        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true) ?? $data;
        }

        // Fenêtre expirée → reset
        if (($now - $data['first_attempt']) > $decaySeconds) {
            $data = ['attempts' => 0, 'first_attempt' => $now, 'blocked_until' => 0];
        }

        // Toujours bloqué ?
        if ($data['blocked_until'] > $now) {
            return false;
        }

        $data['attempts']++;

        if ($data['attempts'] > $maxAttempts) {
            $data['blocked_until'] = $now + $decaySeconds;
            file_put_contents($file, json_encode($data), LOCK_EX);
            return false;
        }

        file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }

    public static function clearRateLimit(string $key): void
    {
        $file = sys_get_temp_dir() . '/cancer_ratelimit/' . md5($key) . '.json';
        if (file_exists($file)) {
            unlink($file);
        }
    }

    // ─── IP & Requête ─────────────────────────────────────────────────────

    public static function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',   // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function isAjaxRequest(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    public static function getRequestMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    // ─── Chiffrement données sensibles ────────────────────────────────────

    public static function encrypt(string $data): string
    {
        $key    = hash('sha256', $_ENV['APP_SECRET'] ?? 'fallback_key');
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $encrypted): string|false
    {
        $key  = hash('sha256', $_ENV['APP_SECRET'] ?? 'fallback_key');
        $data = base64_decode($encrypted);
        $iv   = substr($data, 0, 16);
        $data = substr($data, 16);
        return openssl_decrypt($data, 'AES-256-CBC', $key, 0, $iv);
    }

    // ─── Génération tokens sécurisés ──────────────────────────────────────

    public static function generateToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
