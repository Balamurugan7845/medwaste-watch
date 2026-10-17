<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\AuditLogger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\RateLimiter;
use PDO;

class AuthController
{
    public static function login(Request $request): void
    {
        RateLimiter::checkLogin($request);

        $body = $request->getAllBody();
        $identifier = trim((string)($body['email'] ?? $body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');

        if ($identifier === '' || $password === '') {
            Response::error('Email address and password are required.', 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT u.*, r.name AS role_name, r.slug AS role_slug, d.name AS department_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.email = :email OR u.username = :uname
            LIMIT 1
        ");
        $stmt->execute(['email' => $identifier, 'uname' => $identifier]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            RateLimiter::incrementLogin($request);
            Response::error('Invalid credentials. Please check your email and password.', 401);
        }

        if ($user['status'] !== 'active') {
            Response::error('Your account is inactive or suspended. Please contact hospital administrator.', 403);
        }

        // Fetch User Permissions
        $stmtPerms = $pdo->prepare("
            SELECT p.slug
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = :role_id
        ");
        $stmtPerms->execute(['role_id' => $user['role_id']]);
        $permissions = $stmtPerms->fetchAll(PDO::FETCH_COLUMN);

        // Clear rate limiter
        RateLimiter::clearLogin($request);

        // Secure Session Initialization
        Session::start();
        Session::regenerate();

        $userData = [
            'id'              => (int) $user['id'],
            'username'        => $user['username'],
            'email'           => $user['email'],
            'full_name'       => $user['full_name'],
            'role_id'         => (int) $user['role_id'],
            'role_name'       => $user['role_name'],
            'role_slug'       => $user['role_slug'],
            'department_id'   => $user['department_id'] ? (int) $user['department_id'] : null,
            'department_name' => $user['department_name'] ?? 'Hospital General'
        ];

        Session::set('user', $userData);
        Session::set('permissions', $permissions);

        // Update last login
        $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")->execute(['id' => $user['id']]);

        AuditLogger::log('USER_LOGIN', 'User', (int)$user['id'], null, ['username' => $user['username']], (int)$user['id']);

        Response::success([
            'user'        => $userData,
            'permissions' => $permissions,
            'csrf_token'  => Session::getCsrfToken()
        ], 'Authentication successful');
    }

    public static function me(Request $request): void
    {
        Session::start();
        $user = Session::get('user');

        if (!$user) {
            Response::error('Not authenticated', 401);
        }

        Response::success([
            'user'        => $user,
            'permissions' => Session::get('permissions', []),
            'csrf_token'  => Session::getCsrfToken()
        ]);
    }

    public static function logout(Request $request): void
    {
        Session::start();
        $user = Session::get('user');
        if ($user) {
            AuditLogger::log('USER_LOGOUT', 'User', (int)$user['id'], null, null, (int)$user['id']);
        }
        Session::destroy();
        Response::success(null, 'Logged out successfully');
    }

    public static function changePassword(Request $request): void
    {
        Session::start();
        $user = Session::get('user');
        if (!$user) {
            Response::error('Not authenticated', 401);
        }

        $body = $request->getAllBody();
        $currentPass = (string)($body['current_password'] ?? '');
        $newPass = (string)($body['new_password'] ?? '');

        if (strlen($newPass) < 8) {
            Response::error('New password must be at least 8 characters long.', 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
        $stmt->execute(['id' => $user['id']]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($currentPass, $hash)) {
            Response::error('Current password does not match.', 422);
        }

        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")->execute([
            'hash' => $newHash,
            'id'   => $user['id']
        ]);

        AuditLogger::log('PASSWORD_CHANGED', 'User', (int)$user['id'], null, null, (int)$user['id']);

        Response::success(null, 'Password updated successfully');
    }
}
