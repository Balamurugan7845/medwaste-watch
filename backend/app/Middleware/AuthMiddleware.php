<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

class AuthMiddleware
{
    public static function authenticate(Request $request): array
    {
        Session::start();
        $user = Session::get('user');

        if (!$user || empty($user['id'])) {
            Response::error('Authentication required. Please log in.', 401);
        }

        if (($user['status'] ?? 'active') !== 'active') {
            Session::destroy();
            Response::error('Your account is inactive or suspended. Contact administrator.', 403);
        }

        return $user;
    }
}
