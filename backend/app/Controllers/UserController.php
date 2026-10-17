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

class UserController
{
    public static function index(Request $request): void
    {
        RoleMiddleware::authorize(['super_admin', 'admin']);
        $pdo = Database::getConnection();

        $sql = "
            SELECT 
                u.id, u.username, u.email, u.full_name, u.role_id, u.department_id,
                u.status, u.last_login_at, u.created_at,
                r.name AS role_name, r.slug AS role_slug,
                d.name AS department_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN departments d ON u.department_id = d.id
            ORDER BY u.id ASC
        ";

        $users = $pdo->query($sql)->fetchAll();
        Response::success($users);
    }

    public static function store(Request $request): void
    {
        RoleMiddleware::authorize(['super_admin', 'admin']);
        $body = $request->getAllBody();

        $fullName = trim((string)($body['full_name'] ?? ''));
        $email = trim((string)($body['email'] ?? ''));
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $roleId = (int)($body['role_id'] ?? 0);
        $departmentId = !empty($body['department_id']) ? (int)$body['department_id'] : null;

        if ($fullName === '' || $email === '' || $roleId <= 0) {
            Response::error('Full Name, Official Email, and Assigned Role are required.', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Please provide a valid clinical email address.', 422);
        }

        // Auto-generate clean username from email if not provided
        if ($username === '') {
            $prefix = explode('@', $email)[0];
            $cleanUser = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $prefix));
            $username = $cleanUser !== '' ? $cleanUser : 'user_' . time();
        }

        if ($password === '') {
            $password = 'Password@123';
        }

        if (strlen($password) < 8) {
            Response::error('Password must be at least 8 characters long.', 422);
        }

        $pdo = Database::getConnection();

        // Check if email already exists
        $stmtCheckEmail = $pdo->prepare("SELECT id FROM users WHERE email = :e");
        $stmtCheckEmail->execute(['e' => $email]);
        if ($stmtCheckEmail->fetch()) {
            Response::error("A member with email '{$email}' already exists.", 422);
        }

        // Check if username exists, make it unique if collision
        $stmtCheckUser = $pdo->prepare("SELECT id FROM users WHERE username = :u");
        $stmtCheckUser->execute(['u' => $username]);
        if ($stmtCheckUser->fetch()) {
            $username .= '_' . rand(100, 999);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, full_name, role_id, department_id, status)
            VALUES (:u, :e, :p, :fn, :rid, :did, 'active')
        ");
        $stmt->execute([
            'u'   => $username,
            'e'   => $email,
            'p'   => $hash,
            'fn'  => $fullName,
            'rid' => $roleId,
            'did' => $departmentId
        ]);
        $id = (int)$pdo->lastInsertId();

        $curUser = Session::get('user');
        AuditLogger::log('USER_CREATED', 'User', $id, null, ['username' => $username, 'email' => $email, 'role_id' => $roleId], $curUser['id'] ?? null);

        Response::success([
            'id'        => $id,
            'full_name' => $fullName,
            'email'     => $email,
            'username'  => $username
        ], 'Staff member account created successfully', 201);
    }

    public static function update(Request $request, array $routeParams): void
    {
        RoleMiddleware::authorize(['super_admin', 'admin']);
        $id = (int)($routeParams['id'] ?? 0);
        $body = $request->getAllBody();

        $pdo = Database::getConnection();
        $stmtCur = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmtCur->execute(['id' => $id]);
        $current = $stmtCur->fetch();

        if (!$current) {
            Response::error('User not found', 404);
        }

        $fullName = trim((string)($body['full_name'] ?? $current['full_name']));
        $roleId = (int)($body['role_id'] ?? $current['role_id']);
        $departmentId = isset($body['department_id']) ? ($body['department_id'] ? (int)$body['department_id'] : null) : $current['department_id'];
        $status = in_array($body['status'] ?? '', ['active', 'inactive', 'suspended'], true) ? $body['status'] : $current['status'];

        $stmtUp = $pdo->prepare("
            UPDATE users 
            SET full_name = :fn, role_id = :rid, department_id = :did, status = :status
            WHERE id = :id
        ");
        $stmtUp->execute([
            'fn'     => $fullName,
            'rid'    => $roleId,
            'did'    => $departmentId,
            'status' => $status,
            'id'     => $id
        ]);

        $curUser = Session::get('user');
        AuditLogger::log('USER_UPDATED', 'User', $id, $current, $body, $curUser['id'] ?? null);

        Response::success(null, 'User updated successfully');
    }

    public static function roles(Request $request): void
    {
        $pdo = Database::getConnection();
        $roles = $pdo->query("SELECT id, name, slug, description FROM roles ORDER BY id ASC")->fetchAll();
        Response::success($roles);
    }
}
