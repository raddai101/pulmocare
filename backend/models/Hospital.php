<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Hospital — Modèle établissement hospitalier
 */
class Hospital extends BaseModel
{
    protected string $table    = 'hospitals';
    protected array  $fillable = [
        'nom', 'adresse', 'ville', 'pays',
        'telephone', 'email', 'site_web',
        'is_active',
    ];

    public function getWithDoctorsCount(): array
    {
        return $this->db->fetchAll(
            "SELECT h.*, COUNT(u.id) AS nb_medecins
             FROM {$this->table} h
             LEFT JOIN users u ON u.hospital_id = h.id AND u.deleted_at IS NULL
             WHERE h.deleted_at IS NULL
             GROUP BY h.id
             ORDER BY h.nom ASC"
        );
    }

    public function getActiveList(): array
    {
        return $this->db->fetchAll(
            "SELECT id, nom, ville FROM {$this->table} WHERE is_active = 1 AND deleted_at IS NULL ORDER BY nom ASC"
        );
    }
}
