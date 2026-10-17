<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Response;
use App\Core\Session;

class RoleMiddleware
{
    public static function authorize(array $allowedRoles): void
    {
        $user = Session::get('user');
        if (!$user) {
            Response::error('Unauthorized. Session not found.', 401);
        }

        $userRole = $user['role_slug'] ?? '';

        // Super admin and admin have universal bypass
        if ($userRole === 'super_admin' || $userRole === 'admin') {
            return;
        }

        if (!in_array($userRole, $allowedRoles, true)) {
            Response::error('Access denied. You do not have permission to perform this action.', 403);
        }
    }

    public static function hasPermission(string $permissionSlug): void
    {
        $user = Session::get('user');
        if (!$user) {
            Response::error('Unauthorized.', 401);
        }

        $userRole = $user['role_slug'] ?? '';
        if ($userRole === 'super_admin' || $userRole === 'admin') {
            return;
        }

        $permissions = Session::get('permissions', []);
        if (!in_array($permissionSlug, $permissions, true)) {
            Response::error("Access denied. Missing required permission: {$permissionSlug}", 403);
        }
    }
}
