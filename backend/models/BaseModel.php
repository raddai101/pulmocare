<?php

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDOStatement;

/**
 * BaseModel — Classe abstraite parente de tous les modèles
 * Fournit les opérations CRUD génériques via Active Record léger
 */
abstract class BaseModel
{
    protected Database $db;
    protected string $table;
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $hidden = ['password', 'remember_token', 'reset_token'];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ─── CRUD Générique ──────────────────────────────────────────────────────

    public function findById(int $id): ?array
    {
        $result = $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? AND deleted_at IS NULL LIMIT 1",
            [$id]
        );
        return $result ? $this->sanitize($result) : null;
    }

    public function findAll(string $orderBy = 'created_at', string $direction = 'DESC', int $limit = 100): array
    {
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $rows = $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE deleted_at IS NULL ORDER BY {$orderBy} {$direction} LIMIT {$limit}"
        );
        return array_map([$this, 'sanitize'], $rows);
    }

    public function findBy(string $column, mixed $value): ?array
    {
        $result = $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE {$column} = ? AND deleted_at IS NULL LIMIT 1",
            [$value]
        );
        return $result ? $this->sanitize($result) : null;
    }

    public function findAllBy(string $column, mixed $value): array
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE {$column} = ? AND deleted_at IS NULL ORDER BY created_at DESC",
            [$value]
        );
        return array_map([$this, 'sanitize'], $rows);
    }

    public function create(array $data): string
    {
        $data = $this->filterFillable($data);
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        $columns      = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        return $this->db->insert(
            "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})",
            array_values($data)
        );
    }

    public function update(int $id, array $data): int
    {
        $data = $this->filterFillable($data);
        $data['updated_at'] = date('Y-m-d H:i:s');

        $set = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        $values = array_values($data);
        $values[] = $id;

        return $this->db->execute(
            "UPDATE {$this->table} SET {$set} WHERE {$this->primaryKey} = ?",
            $values
        );
    }

    /**
     * Soft delete — ne supprime pas physiquement
     */
    public function delete(int $id): int
    {
        return $this->db->execute(
            "UPDATE {$this->table} SET deleted_at = ? WHERE {$this->primaryKey} = ?",
            [date('Y-m-d H:i:s'), $id]
        );
    }

    public function hardDelete(int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?",
            [$id]
        );
    }

    public function count(array $conditions = []): int
    {
        $sql    = "SELECT COUNT(*) as total FROM {$this->table} WHERE deleted_at IS NULL";
        $params = [];

        foreach ($conditions as $col => $val) {
            $sql     .= " AND {$col} = ?";
            $params[] = $val;
        }

        $result = $this->db->fetchOne($sql, $params);
        return (int)($result['total'] ?? 0);
    }

    public function exists(string $column, mixed $value, ?int $excludeId = null): bool
    {
        $sql    = "SELECT COUNT(*) as c FROM {$this->table} WHERE {$column} = ? AND deleted_at IS NULL";
        $params = [$value];

        if ($excludeId !== null) {
            $sql     .= " AND {$this->primaryKey} != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->fetchOne($sql, $params);
        return (int)($result['c'] ?? 0) > 0;
    }

    // ─── Pagination ──────────────────────────────────────────────────────────

    public function paginate(int $page = 1, int $perPage = 15, array $conditions = []): array
    {
        $page   = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $where  = 'WHERE deleted_at IS NULL';
        $params = [];

        foreach ($conditions as $col => $val) {
            $where   .= " AND {$col} = ?";
            $params[] = $val;
        }

        $total = (int)($this->db->fetchOne(
            "SELECT COUNT(*) as c FROM {$this->table} {$where}", $params
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            "SELECT * FROM {$this->table} {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'data'         => array_map([$this, 'sanitize'], $rows),
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int)ceil($total / $perPage),
            'from'         => $offset + 1,
            'to'           => min($offset + $perPage, $total),
        ];
    }

    // ─── Helpers internes ────────────────────────────────────────────────────

    protected function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            return $data;
        }
        return array_intersect_key($data, array_flip($this->fillable));
    }

    protected function sanitize(array $row): array
    {
        foreach ($this->hidden as $field) {
            unset($row[$field]);
        }
        return $row;
    }

    protected function rawQuery(string $sql, array $params = []): PDOStatement
    {
        return $this->db->query($sql, $params);
    }
}
