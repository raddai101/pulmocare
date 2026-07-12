<?php

declare(strict_types=1);

function html_set_flash(string $type, string $message): void
{
    SessionManager::setFlash($type, $message);
}

function html_flash(): string
{
    $types = ['success', 'error', 'warning', 'info'];
    $icons = [
        'success' => 'fa-circle-check',
        'error'   => 'fa-circle-xmark',
        'warning' => 'fa-triangle-exclamation',
        'info'    => 'fa-circle-info',
    ];
    $output = '';

    foreach ($types as $type) {
        foreach (SessionManager::getFlash($type) as $message) {
            $icon = $icons[$type] ?? 'fa-bell';
            $output .= <<<HTML
            <div class="alert alert--{$type}" role="alert" data-auto-dismiss="5000">
                <i class="fa-solid {$icon}"></i>
                <span>{$message}</span>
                <button class="alert__close" aria-label="Fermer">&times;</button>
            </div>
            HTML;
        }
    }

    return $output;
}

function html_csrf_input(): string
{
    $token = security_csrf_token();
    return "<input type=\"hidden\" name=\"_token\" value=\"{$token}\">";
}

function html_active_class(string $page, string $current, string $class = 'active'): string
{
    return basename($current) === $page ? $class : '';
}

function html_avatar_url(?string $avatar): string
{
    if ($avatar) {
        $projectRoot = dirname(__DIR__, 2);
        $fullPath = $projectRoot . '/' . ltrim($avatar, '/');
        if (file_exists($fullPath)) {
            $path = '/' . ltrim($avatar, '/');
            $appPath = parse_url((string)env('APP_URL', ''), PHP_URL_PATH) ?: '';
            if ($appPath && $appPath !== '/' && !str_starts_with($path, rtrim($appPath, '/') . '/')) {
                return rtrim($appPath, '/') . $path;
            }
            return $path;
        }
    }

    $default = '/assets/images/default-avatar.svg';
    $appPath = parse_url((string)env('APP_URL', ''), PHP_URL_PATH) ?: '';
    if ($appPath && $appPath !== '/' && !str_starts_with($default, rtrim($appPath, '/') . '/')) {
        return rtrim($appPath, '/') . $default;
    }
    return $default;
}

function html_result_badge(string $type): string
{
    $label = match ($type) {
        'normal'    => 'Normal',
        'suspect'   => 'Suspect',
        'cancereux' => 'Cancéreux',
        default     => 'Inconnu',
    };
    return "<span class=\"badge badge--{$type}\">{$label}</span>";
}

function html_confidence_bar(float $score): string
{
    $color = $score >= 85 ? '#22c55e' : ($score >= 60 ? '#f59e0b' : '#ef4444');
    $pct   = round($score, 1);
    return <<<HTML
    <div class="confidence-bar" title="Confiance : {$pct}%">
        <div class="confidence-bar__fill" style="width:{$pct}%; background:{$color}"></div>
        <span class="confidence-bar__label">{$pct}%</span>
    </div>
    HTML;
}

function html_format_date(string $dateStr, string $format = 'd/m/Y à H:i'): string
{
    $date = new DateTime($dateStr);
    return $date->format($format);
}

function html_format_size(int $bytes): string
{
    if ($bytes < 1024)       return "{$bytes} o";
    if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' Ko';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' Mo';
    return round($bytes / 1073741824, 2) . ' Go';
}

function html_page_title(string $page): string
{
    return $page . ' — PulmoCare IA';
}
