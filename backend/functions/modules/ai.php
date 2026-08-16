<?php
declare(strict_types=1);

if (!defined('FUNCTIONS_LOADED')) {
    throw new \RuntimeException('Include functions.php first.');
}

function ai_predict(string $imagePath): array
{
    if (!file_exists($imagePath)) {
        return ['success' => false, 'message' => 'Fichier image introuvable.'];
    }

    $raw = ai_call_python($imagePath);

    if ($raw === null) {
        return ['success' => false, 'message' => 'Le service IA est indisponible. Réessayez plus tard.'];
    }

    $result = ai_parse_response($raw);

    if (!$result) {
        return ['success' => false, 'message' => 'Réponse IA invalide.'];
    }

    log_detection($imagePath, $result);

    return ['success' => true, 'result' => $result];
}

function ai_call_python(string $imagePath): ?string
{
    // BUGFIX : la valeur par défaut était 'exec', qui invoque predict.py en
    // ligne de commande. Or predict.py --image X ne parse pas réellement
    // ce flag (pas d'argparse dans son __main__) : l'appel échoue à chaque
    // fois et on repartait systématiquement sur l'appel API en repli, en
    // perdant un aller-retour process pour rien. On appelle directement
    // l'API Flask, qui est le chemin réellement utilisé et fonctionnel.
    $method = env('AI_METHOD', 'api');

    if ($method === 'api') {
        return ai_call_flask_api($imagePath);
    }

    $response = ai_call_exec($imagePath);
    if ($response !== null) {
        return $response;
    }

    return ai_call_flask_api($imagePath);
}

function ai_call_exec(string $imagePath): ?string
{
    $pythonBin = env('PYTHON_BIN', 'python3');
    $scriptPath = escapeshellarg(dirname(__DIR__, 2) . '/ai_model/scripts/predict.py');
    $imageArg   = escapeshellarg($imagePath);
    $command    = "{$pythonBin} {$scriptPath} --image {$imageArg} 2>&1";

    $output   = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        log_error('ai_exec_failed', ['exit_code' => $exitCode, 'output' => $output]);
        return null;
    }

    return implode('', $output);
}

function ai_call_flask_api(string $imagePath): ?string
{
    $apiUrl = env('AI_API_URL', 'http://localhost:5000/predict/gradcam');

    if (!function_exists('curl_init')) {
        log_error('ai_flask_unavailable', ['reason' => 'extension_curl_missing']);
        return null;
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'image'        => new CURLFile($imagePath),
            'localisation' => 'variable',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $httpCode !== 200) {
        log_error('ai_flask_failed', [
            'http_code' => $httpCode,
            'curl_error' => $curlErr,
            'response' => is_string($response) ? substr($response, 0, 600) : null,
        ]);
        return null;
    }

    return $response;
}

function ai_parse_response(string $raw): ?array
{
    $data = json_decode(trim($raw), true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        log_error('ai_parse_error', ['raw' => substr($raw, 0, 500)]);
        return null;
    }

    if (isset($data['donnees']) && is_array($data['donnees'])) {
        $data = ai_normalize_flask_report($data['donnees']);
    }

    if (!isset($data['result_type'])) {
        log_error('ai_parse_error', ['raw' => substr($raw, 0, 500)]);
        return null;
    }

    return [
        'result_type'      => $data['result_type']      ?? 'inconnu',
        'confidence_score' => round((float)($data['confidence'] ?? 0) * 100, 2),
        'stage'            => $data['stage']             ?? null,
        'regions_json'     => json_encode($data['regions'] ?? []),
        'model_version'    => $data['model_version']     ?? '1.0',
        'processing_time_ms' => (int)($data['processing_time_ms'] ?? 0),
        'probabilities'    => $data['probabilities']     ?? [],
        'gradcam_b64'      => $data['gradcam_b64']      ?? null,
        'gradcam_bbox'     => $data['gradcam_bbox']     ?? null,
        'gradcam_localisation' => $data['gradcam_localisation'] ?? null,
    ];
}

function ai_normalize_flask_report(array $report): array
{
    $cnn = $report['niveau1_cnn'] ?? [];
    $diag = $report['niveau2_diagnostic'] ?? [];
    $label = strtolower((string)($cnn['classe_predite'] ?? ''));
    $risk = strtolower((string)($diag['niveau_risque'] ?? ''));

    $resultType = match (true) {
        str_contains($label, 'malignant'), str_contains($label, 'malin'), str_contains($risk, 'eleve'), str_contains($risk, 'élevé') => 'cancereux',
        str_contains($label, 'benign'), str_contains($label, 'bénin'), str_contains($risk, 'modere'), str_contains($risk, 'modéré') => 'suspect',
        str_contains($label, 'normal'), str_contains($risk, 'faible') => 'normal',
        default => 'suspect',
    };

    $stageInfo = $diag['stade_tnm'] ?? [];
    $stage = $stageInfo['numero'] ?? $stageInfo['stade'] ?? null;
    if ($stage !== null) {
        $stage = strtoupper(trim(str_replace('Stade', '', (string)$stage)));
    }

    return [
        'result_type' => $resultType,
        'confidence' => ((float)($cnn['confiance'] ?? 0)) / 100,
        'stage' => $stage ?: null,
        'regions' => $report['regions'] ?? [],
        'model_version' => $report['meta']['version_modele'] ?? '1.0',
        'processing_time_ms' => $report['meta']['processing_time_ms'] ?? 0,
        'probabilities' => $cnn['confiances_detail'] ?? [],
        'gradcam_b64' => $report['gradcam']['superposition_b64'] ?? null,
        'gradcam_bbox' => $report['gradcam']['bounding_box'] ?? null,
        'gradcam_localisation' => $report['gradcam']['localisation'] ?? null,
    ];
}

function ai_save_gradcam(?string $base64, int $userId): ?string
{
    if (empty($base64)) return null;
    if (str_starts_with($base64, 'data:')) {
        $parts = explode(',', $base64, 2);
        $base64 = $parts[1] ?? $base64;
    }

    // BUGFIX : depuis backend/functions/modules/, il faut remonter 3 niveaux
    // (modules -> functions -> backend -> racine du projet), pas 2, pour
    // atteindre le vrai dossier /assets/ servi publiquement.
    $projectRoot = dirname(__DIR__, 3);
    $uploadDir = $projectRoot . '/assets/uploads/scans/gradcam/' . date('Y/m/') . $userId . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $filename = 'gradcam_' . bin2hex(random_bytes(6)) . '.jpg';
    $fullPath = $uploadDir . $filename;

    $decoded = base64_decode($base64);
    if ($decoded === false) return null;

    file_put_contents($fullPath, $decoded);
    return '/assets/uploads/scans/gradcam/' . date('Y/m/') . $userId . '/' . $filename;
}

function ai_format_result(array $result): array
{
    return [
        ...$result,
        'label'      => ai_get_result_label($result['result_type']),
        'color'      => ai_get_result_color($result['result_type']),
        'icon'       => ai_get_result_icon($result['result_type']),
        'stage_label'=> ai_get_stage_label($result['stage']),
    ];
}

function ai_get_result_label(string $type): string
{
    return match ($type) {
        'normal'    => 'Normal — Aucune anomalie détectée',
        'suspect'   => 'Suspect — Anomalie à surveiller',
        'cancereux' => 'Cancéreux — Anomalie maligne détectée',
        default     => 'Résultat indéterminé',
    };
}

function ai_get_result_color(string $type): string
{
    return match ($type) {
        'normal'    => '#22c55e',
        'suspect'   => '#f59e0b',
        'cancereux' => '#ef4444',
        default     => '#6b7280',
    };
}

function ai_get_result_icon(string $type): string
{
    return match ($type) {
        'normal'    => 'fa-circle-check',
        'suspect'   => 'fa-triangle-exclamation',
        'cancereux' => 'fa-circle-xmark',
        default     => 'fa-circle-question',
    };
}

function ai_get_stage_label(?string $stage): string
{
    if ($stage === null) return '—';
    return match ($stage) {
        'I'   => 'Stade I  — Localisation précoce',
        'II'  => 'Stade II — Extension limitée',
        'III' => 'Stade III — Extension régionale',
        'IV'  => 'Stade IV  — Métastase avancée',
        default => $stage,
    };
}