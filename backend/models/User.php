<?php

declare(strict_types=1);

namespace App\Models;

/**
 * User — Modèle médecin / utilisateur de la plateforme
 */
class User extends BaseModel
{
    protected string $table      = 'users';
    protected array  $fillable   = [
        'nom', 'prenom', 'email', 'password', 'telephone',
        'specialite', 'numero_ordre', 'hospital_id', 'role',
        'avatar', 'is_active', 'email_verified_at',
        'reset_token', 'reset_token_expires_at', 'remember_token',
        'last_login_at', 'last_login_ip',
    ];
    protected array $hidden = ['password', 'reset_token', 'remember_token'];

    // ─── Authentification ─────────────────────────────────────────────────

    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE email = ? AND deleted_at IS NULL LIMIT 1",
            [strtolower(trim($email))]
        );
    }

    public function verifyPassword(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }

    public function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);
    }

    public function updateLastLogin(int $userId, string $ip): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET last_login_at = ?, last_login_ip = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), $ip, $userId]
        );
    }

    // ─── Réinitialisation mot de passe ────────────────────────────────────

    public function setResetToken(int $userId): string
    {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);

        $this->db->execute(
            "UPDATE {$this->table} SET reset_token = ?, reset_token_expires_at = ? WHERE id = ?",
            [hash('sha256', $token), $expires, $userId]
        );

        return $token; // Token brut à envoyer par mail
    }

    public function findByResetToken(string $token): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table}
             WHERE reset_token = ?
               AND reset_token_expires_at > NOW()
               AND deleted_at IS NULL
             LIMIT 1",
            [hash('sha256', $token)]
        );
    }

    public function clearResetToken(int $userId): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET reset_token = NULL, reset_token_expires_at = NULL WHERE id = ?",
            [$userId]
        );
    }

    public function updatePassword(int $userId, string $newPassword): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET password = ?, updated_at = NOW() WHERE id = ?",
            [$this->hashPassword($newPassword), $userId]
        );
    }

    // ─── Gestion du profil ────────────────────────────────────────────────

    public function getWithHospital(int $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT u.*, h.nom AS hospital_nom, h.ville AS hospital_ville
             FROM {$this->table} u
             LEFT JOIN hospitals h ON h.id = u.hospital_id
             WHERE u.id = ? AND u.deleted_at IS NULL",
            [$userId]
        );
    }

    public function getStatsByUser(int $userId): array
    {
        return $this->db->fetchOne(
            "SELECT
                COUNT(*) AS total_analyses,
                SUM(CASE WHEN d.result_type = 'cancereux' THEN 1 ELSE 0 END) AS total_cancereux,
                SUM(CASE WHEN d.result_type = 'suspect'   THEN 1 ELSE 0 END) AS total_suspects,
                SUM(CASE WHEN d.result_type = 'normal'    THEN 1 ELSE 0 END) AS total_normaux,
                ROUND(AVG(d.confidence_score), 2) AS confidence_moyenne
             FROM detections d
             WHERE d.user_id = ? AND d.deleted_at IS NULL",
            [$userId]
        ) ?? [];
    }

    public function updateAvatar(int $userId, string $avatarPath): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET avatar = ?, updated_at = NOW() WHERE id = ?",
            [$avatarPath, $userId]
        );
    }

    public function activate(int $userId): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET is_active = 1, email_verified_at = NOW() WHERE id = ?",
            [$userId]
        );
    }

    public function isEmailTaken(string $email, ?int $excludeId = null): bool
    {
        return $this->exists('email', strtolower(trim($email)), $excludeId);
    }
}
