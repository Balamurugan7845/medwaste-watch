<?php

declare(strict_types=1);

// MedWaste Watch — Local Development Server Router
// Bridges the separate frontend/ and backend/ directories onto a single local origin

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Inject the public API URL from backend/.env into the browser configuration.
// Environment files never become publicly readable; only this non-secret URL is exposed.
if ($uri === '/assets/js/config.js') {
    require_once __DIR__ . '/backend/app/Config/Config.php';

    $appUrl = rtrim((string) \App\Config\Config::get('APP_URL', ''), '/');
    $apiBase = $appUrl !== '' ? $appUrl . '/api' : '/api';
    $frontendEnvFile = __DIR__ . '/frontend/.env';
    if (file_exists($frontendEnvFile)) {
        $frontendEnv = parse_ini_file($frontendEnvFile, false, INI_SCANNER_RAW);
        if (is_array($frontendEnv) && !empty($frontendEnv['API_BASE_URL'])) {
            $apiBase = rtrim((string) $frontendEnv['API_BASE_URL'], '/');
        }
    }

    header('Content-Type: application/javascript; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'window.__MEDWASTE_ENV__ = ' . json_encode(
        ['API_BASE' => $apiBase],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . ";\n";
    readfile(__DIR__ . '/frontend/assets/js/config.js');
    return;
}

// 1. API Route -> Backend Public Index
if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    require_once __DIR__ . '/backend/public/index.php';
    return;
}

// 2. Static File Serving from Frontend Directory
$frontendRoot = __DIR__ . '/frontend';

if ($uri === '/' || $uri === '') {
    $filePath = $frontendRoot . '/index.html';
} else {
    $filePath = $frontendRoot . $uri;
}

if (file_exists($filePath) && !is_dir($filePath)) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'html' => 'text/html; charset=UTF-8',
        'css'  => 'text/css; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf'
    ];

    $mime = $mimeTypes[$extension] ?? 'application/octet-stream';
    header("Content-Type: {$mime}");
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    return;
}

// If direct html file without extension was requested (e.g. /dashboard -> /dashboard.html)
if (file_exists($filePath . '.html')) {
    header("Content-Type: text/html; charset=UTF-8");
    readfile($filePath . '.html');
    return;
}

// Fallback: 404
http_response_code(404);
header("Content-Type: text/html; charset=UTF-8");
echo "<h1>404 Not Found</h1><p>Requested resource '{$uri}' was not found.</p>";
