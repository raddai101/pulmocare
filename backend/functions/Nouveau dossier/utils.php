<?php

declare(strict_types=1);

function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

function config_get(string $key, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $configFile = __DIR__ . '/../../../config.php';
        $config     = file_exists($configFile) ? require $configFile : [];
    }
    return $config[$key] ?? $default;
}

function str_truncate(string $str, int $length = 80, string $suffix = '…'): string
{
    if (mb_strlen($str) <= $length) return $str;
    return mb_substr($str, 0, $length) . $suffix;
}

function str_slug(string $str): string
{
    $str = strtolower(trim($str));
    $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    return trim($str, '-');
}

function arr_get(array $array, string $key, mixed $default = null): mixed
{
    return $array[$key] ?? $default;
}

function pagination_links(array $paginator, string $baseUrl = ''): string
{
    if ($paginator['last_page'] <= 1) return '';

    $current = (int)$paginator['current_page'];
    $last    = (int)$paginator['last_page'];
    $base    = $baseUrl ?: strtok($_SERVER['REQUEST_URI'], '?');
    $html    = '<nav class="pagination" aria-label="Pagination"><ul class="pagination__list">';

    if ($current > 1) {
        $html .= "<li><a href=\"{$base}?page=" . ($current - 1) . "\" class=\"pagination__btn\" aria-label=\"Précédent\"><i class=\"fa-solid fa-chevron-left\"></i></a></li>";
    }

    $range = range(max(1, $current - 2), min($last, $current + 2));
    if (!in_array(1, $range)) {
        $html .= "<li><a href=\"{$base}?page=1\" class=\"pagination__btn\">1</a></li>";
        if (!in_array(2, $range)) $html .= "<li><span class=\"pagination__dots\">…</span></li>";
    }

    foreach ($range as $page) {
        $active = $page === $current ? ' pagination__btn--active' : '';
        $html  .= "<li><a href=\"{$base}?page={$page}\" class=\"pagination__btn{$active}\" aria-current=\"" . ($page === $current ? 'page' : 'false') . "\">{$page}</a></li>";
    }

    if (!in_array($last, $range)) {
        if (!in_array($last - 1, $range)) $html .= "<li><span class=\"pagination__dots\">…</span></li>";
        $html .= "<li><a href=\"{$base}?page={$last}\" class=\"pagination__btn\">{$last}</a></li>";
    }

    if ($current < $last) {
        $html .= "<li><a href=\"{$base}?page=" . ($current + 1) . "\" class=\"pagination__btn\" aria-label=\"Suivant\"><i class=\"fa-solid fa-chevron-right\"></i></a></li>";
    }

    $html .= '</ul></nav>';
    return $html;
}
