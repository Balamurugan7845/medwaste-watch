<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

class CsrfMiddleware
{
    private static array $exemptRoutes = [
        '/api/auth/login',
        '/api/auth/forgot-password'
    ];

    public static function verify(Request $request): void
    {
        $method = $request->getMethod();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $uri = rtrim($request->getUri(), '/');
        foreach (self::$exemptRoutes as $exempt) {
            if ($uri === $exempt) {
                return;
            }
        }

        // Only enforce CSRF if a session already exists
        Session::start();
        if (!Session::get('user')) {
            return;
        }

        $token = $request->getHeader('x-csrf-token') ?? $request->getBody('_csrf_token');

        if (!Session::validateCsrfToken($token)) {
            Response::error('Invalid or missing CSRF token. Please refresh the page.', 419);
        }
    }
}
