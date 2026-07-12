<?php

declare(strict_types=1);

use App\Helpers\Validator;

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
