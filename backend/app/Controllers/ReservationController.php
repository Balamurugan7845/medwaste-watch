<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\RoleMiddleware;
use App\Services\ReservationService;
use PDO;

class ReservationController
{
    public static function index(Request $request): void
    {
        $pdo = Database::getConnection();

        $status = trim((string)$request->getQuery('status', ''));
        $departmentId = (int)$request->getQuery('department_id', 0);
        $priority = trim((string)$request->getQuery('priority', ''));

        // If user is department_staff, default to their department
        $user = Session::get('user');
        if (($user['role_slug'] ?? '') === 'department_staff' && !empty($user['department_id'])) {
            $departmentId = (int)$user['department_id'];
        }

        $where = ["1=1"];
        $params = [];

        if ($status !== '' && $status !== 'all') {
            $where[] = "r.status = :status";
            $params['status'] = $status;
        }

        if ($departmentId > 0) {
            $where[] = "r.department_id = :dept_id";
            $params['dept_id'] = $departmentId;
        }

        if ($priority !== '' && $priority !== 'all') {
            $where[] = "r.priority = :priority";
            $params['priority'] = $priority;
        }

        $whereClause = implode(' AND ', $where);

        $sql = "
            SELECT 
                r.*,
                d.name AS department_name,
                d.code AS department_code,
                m.brand_name,
                m.generic_name,
                m.sku,
                m.unit_of_measure,
                req_u.full_name AS requesting_user_name,
                app_u.full_name AS approver_name
            FROM reservations r
            JOIN departments d ON r.department_id = d.id
            JOIN medicines m ON r.medicine_id = m.id
            JOIN users req_u ON r.requesting_user_id = req_u.id
            LEFT JOIN users app_u ON r.approver_id = app_u.id
            WHERE {$whereClause}
            ORDER BY 
                CASE r.priority WHEN 'stat' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END,
                r.created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reservations = $stmt->fetchAll();

        Response::success($reservations);
    }

    public static function show(Request $request, array $routeParams): void
    {
        $id = (int)($routeParams['id'] ?? 0);
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT 
                r.*,
                d.name AS department_name,
                d.code AS department_code,
                m.brand_name,
                m.generic_name,
                m.sku,
                m.strength,
                m.dosage_form,
                m.unit_of_measure,
                req_u.full_name AS requesting_user_name,
                app_u.full_name AS approver_name
            FROM reservations r
            JOIN departments d ON r.department_id = d.id
            JOIN medicines m ON r.medicine_id = m.id
            JOIN users req_u ON r.requesting_user_id = req_u.id
            LEFT JOIN users app_u ON r.approver_id = app_u.id
            WHERE r.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $res = $stmt->fetch();

        if (!$res) {
            Response::error('Reservation not found', 404);
        }

        // Fetch Allocations
        $stmtAl = $pdo->prepare("
            SELECT 
                ra.*,
                b.batch_number,
                b.expiry_date,
                loc.name AS location_name
            FROM reservation_allocations ra
            JOIN medicine_batches b ON ra.batch_id = b.id
            JOIN storage_locations loc ON b.storage_location_id = loc.id
            WHERE ra.reservation_id = :id
        ");
        $stmtAl->execute(['id' => $id]);
        $res['allocations'] = $stmtAl->fetchAll();

        // Fetch History
        $stmtH = $pdo->prepare("
            SELECT rh.*, u.full_name AS actor_name
            FROM reservation_history rh
            JOIN users u ON rh.actor_id = u.id
            WHERE rh.reservation_id = :id
            ORDER BY rh.created_at ASC
        ");
        $stmtH->execute(['id' => $id]);
        $res['history'] = $stmtH->fetchAll();

        Response::success($res);
    }

    public static function store(Request $request): void
    {
        $user = Session::get('user');
        $body = $request->getAllBody();

        $medicineId = (int)($body['medicine_id'] ?? 0);
        $departmentId = (int)($body['department_id'] ?? ($user['department_id'] ?? 0));
        $qty = (int)($body['requested_quantity'] ?? 0);
        $priority = trim((string)($body['priority'] ?? 'routine'));
        $justification = trim((string)($body['justification'] ?? ''));

        if ($medicineId <= 0 || $departmentId <= 0 || $qty <= 0) {
            Response::error('Medicine, Department, and Requested Quantity (>0) are required.', 422);
        }

        $result = ReservationService::createRequest([
            'medicine_id'        => $medicineId,
            'department_id'      => $departmentId,
            'requested_quantity' => $qty,
            'priority'           => $priority,
            'justification'      => $justification
        ], (int)$user['id']);

        Response::success($result, 'Reservation request submitted successfully', 201);
    }

    public static function approve(Request $request, array $routeParams): void
    {
        RoleMiddleware::authorize(['super_admin', 'pharmacist']);
        $id = (int)($routeParams['id'] ?? 0);
        $user = Session::get('user');
        $body = $request->getAllBody();

        $approvedQty = isset($body['approved_quantity']) ? (int)$body['approved_quantity'] : null;

        $result = ReservationService::approveRequest($id, $approvedQty, (int)$user['id']);
        Response::success($result, 'Reservation approved and batches allocated via FEFO');
    }

    public static function reject(Request $request, array $routeParams): void
    {
        RoleMiddleware::authorize(['super_admin', 'pharmacist']);
        $id = (int)($routeParams['id'] ?? 0);
        $user = Session::get('user');
        $body = $request->getAllBody();

        $reason = trim((string)($body['reason'] ?? ''));
        if ($reason === '') {
            Response::error('A rejection reason is required.', 422);
        }

        $result = ReservationService::rejectRequest($id, $reason, (int)$user['id']);
        Response::success($result, 'Reservation rejected');
    }

    public static function issue(Request $request, array $routeParams): void
    {
        RoleMiddleware::authorize(['super_admin', 'pharmacist']);
        $id = (int)($routeParams['id'] ?? 0);
        $user = Session::get('user');

        $result = ReservationService::issueStock($id, (int)$user['id']);
        Response::success($result, 'Stock issued successfully and physical inventory deducted');
    }

    public static function cancel(Request $request, array $routeParams): void
    {
        $id = (int)($routeParams['id'] ?? 0);
        $user = Session::get('user');
        $body = $request->getAllBody();

        $reason = trim((string)($body['reason'] ?? 'Cancelled by user'));
        $result = ReservationService::cancelRequest($id, $reason, (int)$user['id']);
        Response::success($result, 'Reservation cancelled and reserved stock released back to availability');
    }
}
