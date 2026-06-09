<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\SessionManager;
use App\Helpers\Security;
use App\Helpers\Validator;
use App\Models\User;
use App\Models\Hospital;

/**
 * UserController — Gestion du profil médecin (AJAX)
 */
class UserController
{
    private int  $userId;
    private User $userModel;

    public function __construct()
    {
        SessionManager::start();
        if (!SessionManager::isAuthenticated()) {
            $this->jsonError('Non authentifié.', 401);
        }
        $this->userId    = (int)SessionManager::getUserId();
        $this->userModel = new User();
        require_once __DIR__ . '/../functions/functions.php';
    }

    public function updateProfile(): never
    {
        $this->requirePost();
        $this->verifyCsrf();
        $r = user_update_profile($this->userId, $_POST);
        if (!$r['success']) $this->jsonError('Données invalides.', 422, $r['errors']);
        $this->jsonSuccess([], $r['message']);
    }

    public function updateAvatar(): never
    {
        $this->requirePost();
        $this->verifyCsrf();
        if (empty($_FILES['avatar']['tmp_name'])) $this->jsonError('Aucun fichier reçu.');
        $r = user_update_avatar($this->userId, $_FILES['avatar']);
        if (!$r['success']) $this->jsonError($r['message']);
        $this->jsonSuccess(['avatar' => $r['avatar']], $r['message']);
    }

    public function changePassword(): never
    {
        $this->requirePost();
        $this->verifyCsrf();
        $r = user_change_password(
            $this->userId,
            $_POST['current_password']      ?? '',
            $_POST['new_password']          ?? '',
            $_POST['new_password_confirmation'] ?? ''
        );
        if (!$r['success']) $this->jsonError($r['message']);
        $this->jsonSuccess([], $r['message']);
    }

    public function getProfile(): never
    {
        $data = user_get_with_hospital($this->userId);
        $stats = user_get_stats($this->userId);
        $this->jsonSuccess(['profile' => $data, 'stats' => $stats]);
    }

    public function getHospitals(): never
    {
        $list = (new Hospital())->getActiveList();
        $this->jsonSuccess(['hospitals' => $list]);
    }

    private function requirePost(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
            $this->jsonError('Méthode non autorisée.', 405);
    }

    private function verifyCsrf(): void
    {
        $token = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!SessionManager::validateCsrfToken($token))
            $this->jsonError('Jeton CSRF invalide.', 403);
    }

    private function jsonSuccess(array $data = [], string $msg = 'OK'): never
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success'=>true,'message'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function jsonError(string $msg, int $status = 400, array $errors = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success'=>false,'message'=>$msg,'errors'=>$errors], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
