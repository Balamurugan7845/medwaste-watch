<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use PDO;

class NotificationController
{
    public static function index(Request $request): void
    {
        $user = Session::get('user');
        $userId = $user['id'] ?? 0;
        $roleId = $user['role_id'] ?? 0;

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT * FROM notifications
            WHERE (user_id = :uid OR target_role_id = :rid)
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute(['uid' => $userId, 'rid' => $roleId]);
        $notifications = $stmt->fetchAll();

        $unreadCount = 0;
        foreach ($notifications as $n) {
            if (!$n['is_read']) {
                $unreadCount++;
            }
        }

        Response::success([
            'items'        => $notifications,
            'unread_count' => $unreadCount
        ]);
    }

    public static function markRead(Request $request, array $routeParams): void
    {
        $id = (int)($routeParams['id'] ?? 0);
        $user = Session::get('user');

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = :id AND (user_id = :uid OR target_role_id = :rid)
        ");
        $stmt->execute([
            'id'  => $id,
            'uid' => $user['id'],
            'rid' => $user['role_id']
        ]);

        Response::success(null, 'Notification marked as read');
    }

    public static function markAllRead(Request $request): void
    {
        $user = Session::get('user');
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE (user_id = :uid OR target_role_id = :rid)
        ");
        $stmt->execute([
            'uid' => $user['id'],
            'rid' => $user['role_id']
        ]);

        Response::success(null, 'All notifications marked as read');
    }
}
