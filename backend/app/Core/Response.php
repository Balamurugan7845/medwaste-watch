<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Config;

require_once __DIR__ . '/../Config/Config.php';

class Response
{
    public static function json(array $data, int $statusCode = 200, array $headers = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');

        // Reflect only explicitly configured origins when credentials are enabled.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = array_filter(array_map(
            'trim',
            explode(',', (string) Config::get('CORS_ALLOWED_ORIGIN', ''))
        ));
        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Vary: Origin');
        }

        foreach ($headers as $key => $val) {
            header("{$key}: {$val}");
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'Operation successful', int $statusCode = 200): void
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data
        ], $statusCode);
    }

    public static function error(string $message = 'An error occurred', int $statusCode = 400, ?array $errors = null): void
    {
        $payload = [
            'success' => false,
            'message' => $message
        ];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }
        self::json($payload, $statusCode);
    }

    public static function csvDownload(string $filename, string $csvContent): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        // Prepend UTF-8 BOM so Excel opens it with correct encoding
        echo "\xEF\xBB\xBF" . $csvContent;
        exit;
    }
}
