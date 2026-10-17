<?php

declare(strict_types=1);

// MedWaste Watch — Local Development Server Router
// Bridges the separate frontend/ and backend/ directories onto a single local origin

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// 1. API Route -> Backend Public Index
if (str_starts_with($uri, '/api/')) {
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
return;
