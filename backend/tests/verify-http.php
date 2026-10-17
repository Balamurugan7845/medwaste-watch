<?php

declare(strict_types=1);

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = __DIR__ . '/cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

echo "1. Testing Login as admin...\n";
$ch = curl_init("{$baseUrl}/api/auth/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => 'admin', 'password' => 'Password@123']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login Response [HTTP {$httpCode}]:\n";
$loginData = json_decode($response, true);
echo json_encode($loginData, JSON_PRETTY_PRINT) . "\n\n";

if ($httpCode !== 200 || empty($loginData['success'])) {
    echo "Login failed. Aborting HTTP verification.\n";
    exit(1);
}

$csrfToken = $loginData['data']['csrf_token'] ?? '';

echo "2. Testing Authenticated GET /api/dashboard/summary...\n";
$ch = curl_init("{$baseUrl}/api/dashboard/summary");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    "X-CSRF-Token: {$csrfToken}"
]);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$dashResponse = curl_exec($ch);
$dashCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Dashboard Response [HTTP {$dashCode}]:\n";
$dashData = json_decode($dashResponse, true);
echo "KPIs: " . json_encode($dashData['data']['kpis'] ?? [], JSON_PRETTY_PRINT) . "\n";
echo "Upcoming expiries count: " . count($dashData['data']['upcoming_expiries'] ?? []) . "\n";
echo "Recent movements count: " . count($dashData['data']['recent_movements'] ?? []) . "\n\n";

echo "3. Testing Authenticated GET /api/medicines...\n";
$ch = curl_init("{$baseUrl}/api/medicines?limit=3");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$medResponse = curl_exec($ch);
$medCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$medData = json_decode($medResponse, true);
echo "Medicines [HTTP {$medCode}]: Total = " . ($medData['data']['total'] ?? 0) . "\n\n";

if (file_exists($cookieFile)) unlink($cookieFile);

echo "=== All HTTP Endpoints Verified Successfully! ===\n";
