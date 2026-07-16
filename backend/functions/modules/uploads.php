<?php
declare(strict_types=1);

if (!defined('FUNCTIONS_LOADED')) {
    throw new \RuntimeException('Include functions.php first.');
}

use App\Helpers\Validator;

function scan_upload(array $file, int $userId): array
{
    $errors = Validator::validateScanFile($file);
    if (!empty($errors)) {
        return ['success' => false, 'message' => implode(' ', $errors)];
    }

    $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $hash      = scan_compute_hash($file['tmp_name']);
    $filename  = scan_generate_filename($userId, $ext);
    $projectRoot = dirname(__DIR__, 2);
    $uploadDir = $projectRoot . '/assets/uploads/scans/' . date('Y/m/') . $userId . '/';

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

function scan_delete_file(string $relativePath): bool
{
    $fullPath = dirname(__DIR__, 2) . '/' . ltrim($relativePath, '/');
    if (file_exists($fullPath) && is_file($fullPath)) {
        return unlink($fullPath);
    }
    return false;
}

function scan_get_url(string $relativePath): string
{
    $path = '/' . ltrim($relativePath, '/');
    $appPath = parse_url((string)env('APP_URL', ''), PHP_URL_PATH) ?: '';
    if ($appPath && $appPath !== '/' && !str_starts_with($path, rtrim($appPath, '/') . '/')) {
        return rtrim($appPath, '/') . $path;
    }
    return $path;
}

function scan_compute_hash(string $filePath): string
{
    return hash_file('sha256', $filePath);
}

function scan_generate_filename(int $userId, string $ext): string
{
    return sprintf('scan_%d_%s_%s.%s', $userId, date('Ymd_His'), bin2hex(random_bytes(4)), $ext);
}
