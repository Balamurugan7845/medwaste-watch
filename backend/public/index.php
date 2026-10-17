<?php

declare(strict_types=1);

// MedWaste Watch — Unified Front Controller & REST API
// Smarter Medicine Management. Less Waste. Better Availability.

require_once __DIR__ . '/../app/Config/Config.php';
require_once __DIR__ . '/../app/Config/Database.php';
require_once __DIR__ . '/../app/Core/Request.php';
require_once __DIR__ . '/../app/Core/Response.php';
require_once __DIR__ . '/../app/Core/Session.php';
require_once __DIR__ . '/../app/Core/AuditLogger.php';
require_once __DIR__ . '/../app/Core/Router.php';

require_once __DIR__ . '/../app/Helpers/Sanitizer.php';
require_once __DIR__ . '/../app/Helpers/FormulaInjectionProtector.php';

require_once __DIR__ . '/../app/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../app/Middleware/RoleMiddleware.php';
require_once __DIR__ . '/../app/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../app/Middleware/RateLimiter.php';

require_once __DIR__ . '/../app/Services/InventoryService.php';
require_once __DIR__ . '/../app/Services/ExpiryService.php';
require_once __DIR__ . '/../app/Services/ReservationService.php';
require_once __DIR__ . '/../app/Services/AlternativeService.php';
require_once __DIR__ . '/../app/Services/TransferDisposalService.php';
require_once __DIR__ . '/../app/Services/ReportService.php';

require_once __DIR__ . '/../app/Controllers/AuthController.php';
require_once __DIR__ . '/../app/Controllers/DashboardController.php';
require_once __DIR__ . '/../app/Controllers/MedicineController.php';
require_once __DIR__ . '/../app/Controllers/BatchController.php';
require_once __DIR__ . '/../app/Controllers/ReservationController.php';
require_once __DIR__ . '/../app/Controllers/AlternativeController.php';
require_once __DIR__ . '/../app/Controllers/TransferDisposalController.php';
require_once __DIR__ . '/../app/Controllers/NotificationController.php';
require_once __DIR__ . '/../app/Controllers/ReportController.php';
require_once __DIR__ . '/../app/Controllers/UserController.php';
require_once __DIR__ . '/../app/Controllers/DepartmentController.php';
require_once __DIR__ . '/../app/Controllers/SettingController.php';
require_once __DIR__ . '/../app/Controllers/AuditController.php';

use App\Config\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\MedicineController;
use App\Controllers\BatchController;
use App\Controllers\ReservationController;
use App\Controllers\AlternativeController;
use App\Controllers\TransferDisposalController;
use App\Controllers\NotificationController;
use App\Controllers\ReportController;
use App\Controllers\UserController;
use App\Controllers\DepartmentController;
use App\Controllers\SettingController;
use App\Controllers\AuditController;

// Set hospital timezone
date_default_timezone_set(Config::get('APP_TIMEZONE', 'Asia/Kolkata'));

// Centralized error handling: Never leak file paths or stack traces in API responses
set_exception_handler(function (\Throwable $e) {
    error_log("Unhandled Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    $code = $e->getCode();
    $statusCode = ($code >= 400 && $code < 600) ? (int)$code : 500;
    Response::error($e->getMessage(), $statusCode);
});

Session::start();
$request = new Request();

// Global CSRF verification for state-changing requests
CsrfMiddleware::verify($request);

$router = new Router();

// Lightweight deployment probe; confirms the Render service is reaching the API front controller.
$router->get('/api', function() {
    Response::success(['service' => 'MedWaste Watch API']);
});

// Middleware helper callbacks
$auth = fn(Request $r) => AuthMiddleware::authenticate($r);

// --- 1. Authentication Routes ---
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout'], [$auth]);
$router->get('/api/auth/me', [AuthController::class, 'me'], [$auth]);
$router->post('/api/auth/change-password', [AuthController::class, 'changePassword'], [$auth]);

// --- 2. Dashboard Routes ---
$router->get('/api/dashboard/summary', [DashboardController::class, 'summary'], [$auth]);

// --- 3. Medicine Catalog Routes ---
$router->get('/api/medicines', [MedicineController::class, 'index'], [$auth]);
$router->post('/api/medicines', [MedicineController::class, 'store'], [$auth]);
$router->get('/api/medicines/categories', [MedicineController::class, 'categories'], [$auth]);
$router->get('/api/medicines/{id}', [MedicineController::class, 'show'], [$auth]);
$router->put('/api/medicines/{id}', [MedicineController::class, 'update'], [$auth]);
$router->patch('/api/medicines/{id}', [MedicineController::class, 'update'], [$auth]);

// --- 4. Batches & Inventory Routes ---
$router->get('/api/batches', [BatchController::class, 'index'], [$auth]);
$router->post('/api/batches', [BatchController::class, 'store'], [$auth]);
$router->post('/api/batches/{id}/adjust', [BatchController::class, 'adjust'], [$auth]);
$router->get('/api/locations', [BatchController::class, 'locations'], [$auth]);
$router->get('/api/suppliers', [BatchController::class, 'suppliers'], [$auth]);

// --- 5. Expiry Priority Routes ---
$router->get('/api/expiry', function(Request $r) {
    $filters = [
        'urgency'     => $r->getQuery('urgency'),
        'medicine_id' => $r->getQuery('medicine_id'),
        'location_id' => $r->getQuery('location_id')
    ];
    $items = \App\Services\ExpiryService::getExpiryList($filters);
    Response::success($items);
}, [$auth]);

$router->get('/api/expiry/summary', function(Request $r) {
    $summary = \App\Services\ExpiryService::getExpirySummary();
    Response::success($summary);
}, [$auth]);

// --- 6. Reservations Routes ---
$router->get('/api/reservations', [ReservationController::class, 'index'], [$auth]);
$router->post('/api/reservations', [ReservationController::class, 'store'], [$auth]);
$router->get('/api/reservations/{id}', [ReservationController::class, 'show'], [$auth]);
$router->post('/api/reservations/{id}/approve', [ReservationController::class, 'approve'], [$auth]);
$router->post('/api/reservations/{id}/reject', [ReservationController::class, 'reject'], [$auth]);
$router->post('/api/reservations/{id}/issue', [ReservationController::class, 'issue'], [$auth]);
$router->post('/api/reservations/{id}/cancel', [ReservationController::class, 'cancel'], [$auth]);

// --- 7. Clinical Alternatives Routes ---
$router->get('/api/alternatives/{medicine_id}', [AlternativeController::class, 'lookup'], [$auth]);
$router->post('/api/alternative-approvals', [AlternativeController::class, 'approve'], [$auth]);

// --- 8. Transfers & Disposal Routes ---
$router->get('/api/transfers', [TransferDisposalController::class, 'transfers'], [$auth]);
$router->post('/api/transfers', [TransferDisposalController::class, 'createTransfer'], [$auth]);
$router->get('/api/disposals', [TransferDisposalController::class, 'disposals'], [$auth]);
$router->post('/api/disposals', [TransferDisposalController::class, 'createDisposal'], [$auth]);
$router->post('/api/quarantine', [TransferDisposalController::class, 'quarantine'], [$auth]);

// --- 9. Notifications Routes ---
$router->get('/api/notifications', [NotificationController::class, 'index'], [$auth]);
$router->post('/api/notifications/read-all', [NotificationController::class, 'markAllRead'], [$auth]);
$router->post('/api/notifications/{id}/read', [NotificationController::class, 'markRead'], [$auth]);

// --- 10. Reports & Export Routes ---
$router->get('/api/reports', [ReportController::class, 'index'], [$auth]);
$router->get('/api/reports/export', [ReportController::class, 'export'], [$auth]);

// --- 11. Administration Routes ---
$router->get('/api/users', [UserController::class, 'index'], [$auth]);
$router->post('/api/users', [UserController::class, 'store'], [$auth]);
$router->put('/api/users/{id}', [UserController::class, 'update'], [$auth]);
$router->get('/api/roles', [UserController::class, 'roles'], [$auth]);

$router->get('/api/departments', [DepartmentController::class, 'index'], [$auth]);
$router->post('/api/departments', [DepartmentController::class, 'store'], [$auth]);

$router->get('/api/settings', [SettingController::class, 'index'], [$auth]);
$router->put('/api/settings', [SettingController::class, 'update'], [$auth]);

$router->get('/api/audit-logs', [AuditController::class, 'index'], [$auth]);

// Dispatch matching route
$router->dispatch($request);
