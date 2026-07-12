<?php

declare(strict_types=1);

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
    $appPath = parse_url((string)env('APP_URL', ''), PHP_URL_PATH) ?: '';
    if ($appPath && str_starts_with($url, '/')) {
        $normalizedAppPath = rtrim($appPath, '/');
        if ($normalizedAppPath !== '' && !str_starts_with($url, $normalizedAppPath . '/')) {
            $url = $normalizedAppPath . $url;
        }
    }

    header("Location: {$url}", true, $status);
    exit;
}

function response_set_header(string $name, string $value): void
{
    header("{$name}: {$value}");
}
