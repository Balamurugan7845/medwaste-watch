<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\RoleMiddleware;
use App\Services\TransferDisposalService;
use PDO;

class TransferDisposalController
{
    public static function transfers(Request $request): void
    {
        $pdo = Database::getConnection();
        $sql = "
            SELECT 
                t.*,
                src.name AS source_location_name,
                dst.name AS destination_location_name,
                u.full_name AS initiated_by_name,
                c.full_name AS confirmed_by_name,
                ti.quantity,
                b.batch_number,
                m.brand_name
            FROM transfers t
            JOIN storage_locations src ON t.source_location_id = src.id
            JOIN storage_locations dst ON t.destination_location_id = dst.id
            JOIN users u ON t.initiated_by = u.id
            LEFT JOIN users c ON t.confirmed_by = c.id
            LEFT JOIN transfer_items ti ON t.id = ti.transfer_id
            LEFT JOIN medicine_batches b ON ti.batch_id = b.id
            LEFT JOIN medicines m ON b.medicine_id = m.id
            ORDER BY t.created_at DESC
        ";
        $transfers = $pdo->query($sql)->fetchAll();
        Response::success($transfers);
    }

    public static function createTransfer(Request $request): void
    {
        RoleMiddleware::authorize(['super_admin', 'pharmacist']);
        $user = Session::get('user');
        $body = $request->getAllBody();

        $batchId = (int)($body['batch_id'] ?? 0);
        $qty = (int)($body['quantity'] ?? 0);
        $destLocationId = (int)($body['destination_location_id'] ?? 0);

        if ($batchId <= 0 || $qty <= 0 || $destLocationId <= 0) {
            Response::error('Batch ID, Quantity (>0), and Destination Location are required.', 422);
        }

        $result = TransferDisposalService::createTransfer([
            'batch_id'                => $batchId,
            'quantity'                => $qty,
            'destination_location_id' => $destLocationId,
            'notes'                   => $body['notes'] ?? null
        ], (int)$user['id']);

        Response::success($result, 'Storage transfer executed successfully', 201);
    }

    public static function disposals(Request $request): void
    {
        $pdo = Database::getConnection();
        $sql = "
            SELECT 
                d.*,
                m.brand_name,
                m.generic_name,
                m.strength,
                b.batch_number,
                b.unit_cost,
                (d.quantity * b.unit_cost) AS total_financial_loss,
                u.full_name AS authorized_by_name
            FROM disposal_records d
            JOIN medicines m ON d.medicine_id = m.id
            JOIN medicine_batches b ON d.batch_id = b.id
            JOIN users u ON d.authorized_by = u.id
            ORDER BY d.disposed_at DESC
        ";
        $disposals = $pdo->query($sql)->fetchAll();
        Response::success($disposals);
    }

    public static function createDisposal(Request $request): void
    {
        RoleMiddleware::authorize(['super_admin', 'pharmacist']);
        $user = Session::get('user');
        $body = $request->getAllBody();

        $batchId = (int)($body['batch_id'] ?? 0);
        $qty = (int)($body['quantity'] ?? 0);
        $method = trim((string)($body['disposal_method'] ?? 'incineration'));
        $reason = trim((string)($body['reason'] ?? 'expired'));

        if ($batchId <= 0 || $qty <= 0) {
            Response::error('Batch ID and Quantity (>0) are required.', 422);
        }

        $result = TransferDisposalService::recordDisposal([
            'batch_id'        => $batchId,
            'quantity'        => $qty,
            'disposal_method' => $method,
            'reason'          => $reason,
            'certificate_ref' => $body['certificate_ref'] ?? null,
            'notes'           => $body['notes'] ?? null
        ], (int)$user['id']);

        Response::success($result, 'Permanent disposal record and ledger movement created successfully', 201);
    }

    public static function quarantine(Request $request): void
    {
        RoleMiddleware::authorize(['super_admin', 'pharmacist']);
        $user = Session::get('user');
        $body = $request->getAllBody();

        $batchId = (int)($body['batch_id'] ?? 0);
        $qty = (int)($body['quantity'] ?? 0);
        $reason = trim((string)($body['reason'] ?? 'Quality quarantine hold'));

        if ($batchId <= 0 || $qty <= 0) {
            Response::error('Batch ID and Quantity (>0) are required.', 422);
        }

        $result = TransferDisposalService::quarantineBatch($batchId, $qty, $reason, (int)$user['id']);
        Response::success($result, 'Batch units quarantined successfully');
    }
}
