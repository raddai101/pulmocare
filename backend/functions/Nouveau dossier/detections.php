<?php

declare(strict_types=1);

use App\Models\Detection;

function detection_create(int $userId, array $scanData, array $aiResult, array $patientData): array
{
    $model = new Detection();

    $duplicate = $model->checkDuplicate($scanData['hash'], $userId);
    if ($duplicate) {
        return [
            'success'     => true,
            'detection_id'=> $duplicate['id'],
            'is_duplicate'=> true,
            'message'     => 'Ce scan a déjà été analysé.',
        ];
    }

    $gradcamPath = null;
    if (!empty($aiResult['gradcam_b64'])) {
        $gradcamPath = ai_save_gradcam($aiResult['gradcam_b64'], $userId);
    }

    $id = $model->create([
        'user_id'            => $userId,
        'patient_nom'        => security_sanitize($patientData['nom'] ?? ''),
        'patient_prenom'     => security_sanitize($patientData['prenom'] ?? ''),
        'patient_age'        => (int)($patientData['age'] ?? 0),
        'patient_sexe'       => security_sanitize($patientData['sexe'] ?? ''),
        'patient_code'       => security_sanitize($patientData['code'] ?? ''),
        'image_path'         => $scanData['url'],
        'image_original_name'=> $scanData['original'],
        'image_size'         => $scanData['size'],
        'image_hash'         => $scanData['hash'],
        'result_type'        => $aiResult['result_type'],
        'confidence_score'   => $aiResult['confidence_score'],
        'stage'              => $aiResult['stage'],
        'regions_json'       => $aiResult['regions_json'],
        'model_version'      => $aiResult['model_version'],
        'processing_time_ms' => $aiResult['processing_time_ms'],
        'gradcam_path'       => $gradcamPath,
        'status'             => 'completed',
    ]);

    log_activity('detection_created', ['user_id' => $userId, 'detection_id' => $id, 'result' => $aiResult['result_type']]);

    return ['success' => true, 'detection_id' => (int)$id, 'is_duplicate' => false];
}

function detection_get(int $detectionId): ?array
{
    return (new Detection())->getWithUser($detectionId);
}

function detection_get_all_by_user(int $userId, int $page = 1, int $perPage = 10): array
{
    return (new Detection())->getByUserPaginated($userId, $page, $perPage);
}

function detection_get_recent(int $userId, int $limit = 5): array
{
    return (new Detection())->getRecentByUser($userId, $limit);
}

function detection_search(array $filters, int $userId, int $page = 1): array
{
    return (new Detection())->search($filters, $userId, $page);
}

function detection_mark_reviewed(int $detectionId, string $notes = ''): void
{
    (new Detection())->markAsReviewed($detectionId, $notes);
    log_activity('detection_reviewed', ['detection_id' => $detectionId]);
}

function detection_delete(int $detectionId, int $userId): array
{
    $model     = new Detection();
    $detection = $model->findById($detectionId);

    if (!$detection || (int)$detection['user_id'] !== $userId) {
        return ['success' => false, 'message' => 'Analyse introuvable ou accès refusé.'];
    }

    $model->delete($detectionId);
    log_activity('detection_deleted', ['detection_id' => $detectionId, 'user_id' => $userId]);

    return ['success' => true, 'message' => 'Analyse supprimée.'];
}

function detection_get_global_stats(): array
{
    return (new Detection())->getGlobalStats();
}
