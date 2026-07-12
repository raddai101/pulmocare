<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Detection — Modèle d'analyse CT Scan
 * Stocke chaque analyse IA avec résultats, scores et métadonnées image
 */
class Detection extends BaseModel
{
    protected string $table    = 'detections';
    protected array  $fillable = [
        'user_id', 'patient_nom', 'patient_prenom', 'patient_age',
        'patient_sexe', 'patient_code', 'image_path', 'image_original_name',
        'image_size', 'image_hash',
        'result_type', 'confidence_score', 'stage',
        'regions_json', 'model_version', 'processing_time_ms',
        'gradcam_path',
        'notes_medecin', 'is_reviewed', 'status',
    ];

    // ─── Requêtes métier ──────────────────────────────────────────────────

    public function getWithUser(int $detectionId): ?array
    {
        return $this->db->fetchOne(
            "SELECT d.*,
                    u.nom AS medecin_nom, u.prenom AS medecin_prenom,
                    u.specialite, u.email AS medecin_email,
                    h.nom AS hospital_nom
             FROM {$this->table} d
             JOIN users u ON u.id = d.user_id
             LEFT JOIN hospitals h ON h.id = u.hospital_id
             WHERE d.id = ? AND d.deleted_at IS NULL",
            [$detectionId]
        );
    }

    public function getByUserPaginated(int $userId, int $page = 1, int $perPage = 10): array
    {
        return $this->paginate($page, $perPage, ['user_id' => $userId]);
    }

    public function getRecentByUser(int $userId, int $limit = 5): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table}
             WHERE user_id = ? AND deleted_at IS NULL
             ORDER BY created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * Statistiques globales de la plateforme (admin / dashboard)
     */
    public function getGlobalStats(): array
    {
        $stats = $this->db->fetchOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN result_type = 'cancereux' THEN 1 ELSE 0 END) AS cancereux,
                SUM(CASE WHEN result_type = 'suspect'   THEN 1 ELSE 0 END) AS suspects,
                SUM(CASE WHEN result_type = 'normal'    THEN 1 ELSE 0 END) AS normaux,
                ROUND(AVG(confidence_score), 2)   AS confidence_moyenne,
                ROUND(AVG(processing_time_ms), 0) AS temps_moyen_ms
             FROM {$this->table}
             WHERE deleted_at IS NULL"
        ) ?? [];

        $monthly = $this->db->fetchAll(
            "SELECT
                DATE_FORMAT(created_at, '%Y-%m') AS mois,
                COUNT(*) AS total
             FROM {$this->table}
             WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             GROUP BY mois
             ORDER BY mois ASC"
        );

        $stats['evolution_mensuelle'] = $monthly;
        return $stats;
    }

    /**
     * Recherche multicritères
     */
    public function search(array $filters, int $userId, int $page = 1, int $perPage = 10): array
    {
        $where    = ['d.user_id = ?', 'd.deleted_at IS NULL'];
        $params   = [$userId];

        if (!empty($filters['result_type'])) {
            $where[]  = 'd.result_type = ?';
            $params[] = $filters['result_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'DATE(d.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'DATE(d.created_at) <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['patient'])) {
            $where[]  = "(d.patient_nom LIKE ? OR d.patient_prenom LIKE ? OR d.patient_code LIKE ?)";
            $like     = '%' . $filters['patient'] . '%';
            $params   = array_merge($params, [$like, $like, $like]);
        }
        if (!empty($filters['stage'])) {
            $where[]  = 'd.stage = ?';
            $params[] = $filters['stage'];
        }

        $whereClause = implode(' AND ', $where);
        $offset      = ($page - 1) * $perPage;

        $total = (int)($this->db->fetchOne(
            "SELECT COUNT(*) as c FROM {$this->table} d WHERE {$whereClause}",
            $params
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            "SELECT d.* FROM {$this->table} d
             WHERE {$whereClause}
             ORDER BY d.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'data'         => $rows,
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => (int)ceil($total / $perPage),
        ];
    }

    public function markAsReviewed(int $id, string $notes = ''): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET is_reviewed = 1, notes_medecin = ?, updated_at = NOW() WHERE id = ?",
            [$notes, $id]
        );
    }

    public function checkDuplicate(string $imageHash, int $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE image_hash = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1",
            [$imageHash, $userId]
        );
    }
}
