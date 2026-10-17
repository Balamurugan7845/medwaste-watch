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

class DepartmentController
{
    public static function index(Request $request): void
    {
        $pdo = Database::getConnection();
        $departments = $pdo->query("
            SELECT d.*, COUNT(u.id) AS total_staff
            FROM departments d
            LEFT JOIN users u ON d.id = u.department_id AND u.status = 'active'
            GROUP BY d.id
            ORDER BY d.name ASC
        ")->fetchAll();

        Response::success($departments);
    }

    public static function store(Request $request): void
    {
        RoleMiddleware::authorize(['super_admin']);
        $body = $request->getAllBody();

        $name = trim((string)($body['name'] ?? ''));
        $code = trim((string)($body['code'] ?? ''));
        $location = trim((string)($body['location'] ?? ''));
        $phone = trim((string)($body['phone'] ?? ''));

        if ($name === '' || $code === '') {
            Response::error('Department Name and Code are required.', 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("INSERT INTO departments (name, code, location, phone) VALUES (:n, :c, :l, :p)");
        $stmt->execute(['n' => $name, 'c' => $code, 'l' => $location, 'p' => $phone]);
        $id = (int)$pdo->lastInsertId();

        $user = Session::get('user');
        AuditLogger::log('DEPARTMENT_CREATED', 'Department', $id, null, ['code' => $code, 'name' => $name], $user['id'] ?? null);

        Response::success(['id' => $id], 'Department created successfully', 201);
    }
}
