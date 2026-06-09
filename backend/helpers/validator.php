<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Validator — Validation des données entrantes avec règles chaînables
 */
class Validator
{
    private array $errors  = [];
    private array $data    = [];
    private array $rules   = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function make(array $data, array $rules): self
    {
        $instance = new self($data);
        $instance->rules = $rules;
        $instance->validate();
        return $instance;
    }

    private function validate(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;

            foreach ($rules as $rule) {
                $this->applyRule($field, $value, $rule);
            }
        }
    }

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

        switch ($name) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    $this->addError($field, "Le champ {$field} est obligatoire.");
                }
                break;

            case 'email':
                if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "L'adresse email est invalide.");
                }
                break;

            case 'min':
                if ($value !== null && strlen((string)$value) < (int)$param) {
                    $this->addError($field, "Le champ {$field} doit contenir au minimum {$param} caractères.");
                }
                break;

            case 'max':
                if ($value !== null && strlen((string)$value) > (int)$param) {
                    $this->addError($field, "Le champ {$field} ne doit pas dépasser {$param} caractères.");
                }
                break;

            case 'numeric':
                if ($value !== null && $value !== '' && !is_numeric($value)) {
                    $this->addError($field, "Le champ {$field} doit être un nombre.");
                }
                break;

            case 'integer':
                if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_INT)) {
                    $this->addError($field, "Le champ {$field} doit être un entier.");
                }
                break;

            case 'in':
                $allowed = explode(',', (string)$param);
                if ($value !== null && !in_array($value, $allowed, true)) {
                    $this->addError($field, "La valeur du champ {$field} est invalide.");
                }
                break;

            case 'regex':
                if ($value && !preg_match((string)$param, (string)$value)) {
                    $this->addError($field, "Le format du champ {$field} est invalide.");
                }
                break;

            case 'password_strength':
                if ($value && !self::isStrongPassword((string)$value)) {
                    $this->addError($field,
                        "Le mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial."
                    );
                }
                break;

            case 'confirmed':
                $confirmKey   = $field . '_confirmation';
                $confirmValue = $this->data[$confirmKey] ?? null;
                if ($value !== $confirmValue) {
                    $this->addError($field, "Les mots de passe ne correspondent pas.");
                }
                break;

            case 'date':
                if ($value && !strtotime((string)$value)) {
                    $this->addError($field, "La date du champ {$field} est invalide.");
                }
                break;

            case 'age':
                if ($value !== null && $value !== '') {
                    $age = (int)$value;
                    [$min, $max] = explode(',', (string)$param);
                    if ($age < (int)$min || $age > (int)$max) {
                        $this->addError($field, "L'âge doit être compris entre {$min} et {$max} ans.");
                    }
                }
                break;
        }
    }

    // ─── Validation fichier image CT Scan ────────────────────────────────

    public static function validateScanFile(array $file): array
    {
        $errors = [];
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/dicom',
            'application/dicom', 'image/tiff',
        ];
        $allowedExt   = ['jpg', 'jpeg', 'png', 'dcm', 'tiff', 'tif'];
        $maxSize      = 20 * 1024 * 1024; // 20 MB

        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            $errors[] = "Aucun fichier reçu.";
            return $errors;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = self::getUploadError($file['error']);
            return $errors;
        }

        if ($file['size'] > $maxSize) {
            $errors[] = "Le fichier dépasse la taille maximale autorisée (20 Mo).";
        }

        // Extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $errors[] = "Format de fichier non autorisé. Formats acceptés : " . implode(', ', $allowedExt);
        }

        // MIME type réel (pas celui envoyé par le client)
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, $allowedMimes, true) && !in_array($ext, ['dcm'], true)) {
            $errors[] = "Le type de fichier détecté ({$realMime}) n'est pas autorisé.";
        }

        return $errors;
    }

    private static function getUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Le fichier dépasse la taille maximale.",
            UPLOAD_ERR_PARTIAL  => "Le téléchargement a été interrompu.",
            UPLOAD_ERR_NO_FILE  => "Aucun fichier sélectionné.",
            UPLOAD_ERR_NO_TMP_DIR => "Répertoire temporaire manquant.",
            UPLOAD_ERR_CANT_WRITE => "Erreur d'écriture du fichier.",
            default             => "Erreur inconnue lors du téléchargement.",
        };
    }

    private static function isStrongPassword(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/[0-9]/', $password)
            && preg_match('/[^A-Za-z0-9]/', $password);
    }

    // ─── API publique ─────────────────────────────────────────────────────

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    public function getAllErrorMessages(): array
    {
        $messages = [];
        foreach ($this->errors as $fieldErrors) {
            foreach ($fieldErrors as $msg) {
                $messages[] = $msg;
            }
        }
        return $messages;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
