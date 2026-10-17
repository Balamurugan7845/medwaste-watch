<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\RoleMiddleware;
use PDO;

class AuditController
{
    public static function index(Request $request): void
    {
        RoleMiddleware::authorize(['super_admin', 'auditor']);
        $pdo = Database::getConnection();

        $action = trim((string)$request->getQuery('action', ''));
        $entityType = trim((string)$request->getQuery('entity_type', ''));
        $limit = min(200, max(10, (int)$request->getQuery('limit', 50)));

        $where = ["1=1"];
        $params = [];

        if ($action !== '') {
            $where[] = "al.action LIKE :act";
            $params['act'] = "%{$action}%";
        }

        if ($entityType !== '') {
            $where[] = "al.entity_type = :ent";
            $params['ent'] = $entityType;
        }

        $whereClause = implode(' AND ', $where);

        $sql = "
            SELECT 
                al.*,
                u.full_name AS user_full_name,
                u.username
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE {$whereClause}
            ORDER BY al.created_at DESC
            LIMIT {$limit}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        Response::success($logs);
    }
}
