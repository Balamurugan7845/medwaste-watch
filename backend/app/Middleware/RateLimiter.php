<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

class RateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 300; // 5 minutes

    public static function checkLogin(Request $request): void
    {
        Session::start();
        $ip = $request->getIp();
        $key = 'rate_limit_login_' . md5($ip);

        $attempts = Session::get($key . '_attempts', 0);
        $firstAttempt = Session::get($key . '_time', time());

        if (time() - $firstAttempt > self::DECAY_SECONDS) {
            // Reset
            Session::set($key . '_attempts', 0);
            Session::set($key . '_time', time());
            return;
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            $retryAfter = self::DECAY_SECONDS - (time() - $firstAttempt);
            Response::error("Too many failed login attempts. Please wait {$retryAfter} seconds before retrying.", 429);
        }
    }

    public static function incrementLogin(Request $request): void
    {
        Session::start();
        $ip = $request->getIp();
        $key = 'rate_limit_login_' . md5($ip);

        $attempts = Session::get($key . '_attempts', 0);
        if ($attempts === 0) {
            Session::set($key . '_time', time());
        }
        Session::set($key . '_attempts', $attempts + 1);
    }

    public static function clearLogin(Request $request): void
    {
        Session::start();
        $ip = $request->getIp();
        $key = 'rate_limit_login_' . md5($ip);
        Session::remove($key . '_attempts');
        Session::remove($key . '_time');
    }
}
