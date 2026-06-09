<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Security;

/**
 * DetectionController — Gestion des analyses CT Scan
 */
class DetectionController
{
    /**
     * Lance une nouvelle analyse IA.
     * Chaîne : upload → prédiction → sauvegarde BDD.
     */
    public function analyze(): array
    {
        $this->requireAuth();
        $this->requirePost();
        $this->verifyCsrf();

        $userId = SessionManager::getUserId();

        // Validation patient
        $patientData = [
            'nom'    => $_POST['patient_nom']    ?? '',
            'prenom' => $_POST['patient_prenom'] ?? '',
            'age'    => $_POST['patient_age']    ?? '',
            'sexe'   => $_POST['patient_sexe']   ?? '',
            'code'   => $_POST['patient_code']   ?? '',
        ];

        $v = validate_fields($patientData, [
            'nom'    => 'required|min:2|max:80',
            'prenom' => 'required|min:2|max:80',
            'age'    => 'required|age:0,120',
            'sexe'   => 'required|in:M,F,Autre',
        ]);

        if (!$v['valid']) {
            $msgs = [];
            foreach ($v['errors'] as $fieldErrors) {
                $msgs = array_merge($msgs, $fieldErrors);
            }
            return ['success' => false, 'message' => implode(' ', $msgs)];
        }

        if (empty($_FILES['scan_file']['tmp_name'])) {
            return ['success' => false, 'message' => 'Aucune image CT Scan fournie.'];
        }

        // Upload
        $scanResult = scan_upload($_FILES['scan_file'], $userId);
        if (!$scanResult['success']) {
            return $scanResult;
        }

        // IA
        $aiResponse = ai_predict($scanResult['path']);
        if (!$aiResponse['success']) {
            scan_delete_file($scanResult['url']);
            return $aiResponse;
        }

        // Sauvegarde
        return detection_create($userId, $scanResult, $aiResponse['result'], $patientData);
    }

    /**
     * Retourne le détail d'une analyse (JSON pour AJAX).
     */
    public function getDetail(int $id): array
    {
        $this->requireAuth();
        $userId    = SessionManager::getUserId();
        $detection = detection_get($id);

        if (!$detection || (int)$detection['user_id'] !== $userId) {
            return ['success' => false, 'message' => 'Analyse introuvable.'];
        }

        return ['success' => true, 'data' => $detection];
    }

    /**
     * Supprime une analyse.
     */
    public function delete(int $id): array
    {
        $this->requireAuth();
        $this->requirePost();
        $this->verifyCsrf();
        return detection_delete($id, SessionManager::getUserId());
    }

    /**
     * Sauvegarde les notes cliniques d'une analyse.
     */
    public function saveNotes(int $id, string $notes): array
    {
        $this->requireAuth();
        $this->requirePost();
        $this->verifyCsrf();

        $userId    = SessionManager::getUserId();
        $detection = detection_get($id);

        if (!$detection || (int)$detection['user_id'] !== $userId) {
            return ['success' => false, 'message' => 'Analyse introuvable.'];
        }

        detection_mark_reviewed($id, security_sanitize($notes));
        return ['success' => true, 'message' => 'Notes sauvegardées.'];
    }

    /**
     * Recherche paginée avec filtres.
     */
    public function search(array $filters, int $page = 1): array
    {
        $this->requireAuth();
        return detection_search($filters, SessionManager::getUserId(), $page);
    }

    // ── Helpers ───────────────────────────────────────────────

    private function requireAuth(): void
    {
        if (!auth_is_logged()) {
            if (Security::isAjaxRequest()) {
                response_json_error('Non authentifié.', 401);
            }
            response_redirect('/auth/login.php');
        }
    }

    private function requirePost(): void
    {
        if (Security::getRequestMethod() !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
    }

    private function verifyCsrf(): void
    {
        if (!security_verify_csrf($_POST['_token'] ?? '')) {
            if (Security::isAjaxRequest()) {
                response_json_error('Jeton CSRF invalide.', 403);
            }
            html_set_flash('error', 'Requête invalide.');
            response_redirect($_SERVER['HTTP_REFERER'] ?? '/pages/detection.php');
        }
    }
}


/**
 * UserController — Gestion des médecins
 */
class UserController
{
    public function updateProfile(): array
    {
        $this->requireAuth();
        $this->requirePost();
        $this->verifyCsrf();
        return user_update_profile(SessionManager::getUserId(), $_POST);
    }

    public function changePassword(): array
    {
        $this->requireAuth();
        $this->requirePost();
        $this->verifyCsrf();

        return user_change_password(
            SessionManager::getUserId(),
            $_POST['current_password']    ?? '',
            $_POST['new_password']         ?? '',
            $_POST['new_password_confirm'] ?? ''
        );
    }

    public function updateAvatar(): array
    {
        $this->requireAuth();
        $this->requirePost();
        $this->verifyCsrf();

        if (empty($_FILES['avatar']['tmp_name'])) {
            return ['success' => false, 'message' => 'Aucun fichier reçu.'];
        }

        return user_update_avatar(SessionManager::getUserId(), $_FILES['avatar']);
    }

    public function getStats(): array
    {
        $this->requireAuth();
        return user_get_stats(SessionManager::getUserId());
    }

    private function requireAuth(): void
    {
        if (!auth_is_logged()) {
            if (Security::isAjaxRequest()) {
                response_json_error('Non authentifié.', 401);
            }
            response_redirect('/auth/login.php');
        }
    }

    private function requirePost(): void
    {
        if (Security::getRequestMethod() !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
    }

    private function verifyCsrf(): void
    {
        if (!security_verify_csrf($_POST['_token'] ?? '')) {
            if (Security::isAjaxRequest()) {
                response_json_error('Jeton CSRF invalide.', 403);
            }
            html_set_flash('error', 'Requête invalide.');
            response_redirect($_SERVER['HTTP_REFERER'] ?? '/pages/profil.php');
        }
    }
}
