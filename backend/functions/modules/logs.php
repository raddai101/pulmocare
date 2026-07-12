<?php

declare(strict_types=1);

function log_activity(string $action, array $context = []): void
{
    $logDir  = __DIR__ . '/../../../storage/logs/';
    $logFile = $logDir . 'activity_' . date('Y-m-d') . '.log';

    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    $entry = json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'action'    => $action,
        'ip'        => security_get_ip(),
        'context'   => $context,
    ]);

    file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function log_error(string $type, array $context = []): void
{
    $logDir  = __DIR__ . '/../../../storage/logs/';
    $logFile = $logDir . 'error_' . date('Y-m-d') . '.log';

    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    $entry = json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'type'      => $type,
        'ip'        => security_get_ip(),
        'context'   => $context,
    ]);

    file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function log_detection(string $imagePath, array $result): void
{
    log_activity('ai_prediction', [
        'image'       => basename($imagePath),
        'result_type' => $result['result_type'],
        'confidence'  => $result['confidence_score'],
        'model'       => $result['model_version'],
        'time_ms'     => $result['processing_time_ms'],
    ]);
}
