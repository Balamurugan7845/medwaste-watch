<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\AuditLogger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\RoleMiddleware;
use PDO;

class SettingController
{
    public static function index(Request $request): void
    {
        $pdo = Database::getConnection();
        $settings = $pdo->query("SELECT * FROM system_settings ORDER BY category ASC, setting_key ASC")->fetchAll();
        Response::success($settings);
    }

    public static function update(Request $request): void
    {
        RoleMiddleware::authorize(['super_admin']);
        $body = $request->getAllBody();
        $settings = $body['settings'] ?? [];

        if (!is_array($settings)) {
            Response::error('Settings must be an array of key-value pairs', 422);
        }

        $pdo = Database::getConnection();
        $user = Session::get('user');

        $stmt = $pdo->prepare("
            UPDATE system_settings 
            SET setting_value = :val, updated_by = :uid, updated_at = NOW() 
            WHERE setting_key = :k
        ");

        foreach ($settings as $key => $val) {
            $stmt->execute([
                'val' => (string)$val,
                'uid' => $user['id'],
                'k'   => $key
            ]);
        }

        AuditLogger::log('SETTINGS_UPDATED', 'SystemSettings', null, null, $settings, $user['id'] ?? null);

        Response::success(null, 'System settings updated successfully');
    }
}
