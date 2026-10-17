<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\RoleMiddleware;
use App\Services\ExpiryService;
use App\Services\InventoryService;
use PDO;

class BatchController
{
    public static function index(Request $request): void
    {
        $pdo = Database::getConnection();

        $medicineId = (int)$request->getQuery('medicine_id', 0);
        $locationId = (int)$request->getQuery('location_id', 0);
        $search = trim((string)$request->getQuery('search', ''));

        $where = ["b.current_quantity > 0"];
        $params = [];

        if ($medicineId > 0) {
            $where[] = "b.medicine_id = :med_id";
            $params['med_id'] = $medicineId;
        }

        if ($locationId > 0) {
            $where[] = "b.storage_location_id = :loc_id";
            $params['loc_id'] = $locationId;
        }

        if ($search !== '') {
            $where[] = "(b.batch_number LIKE :s1 OR m.brand_name LIKE :s2 OR m.generic_name LIKE :s3)";
            $params['s1'] = "%{$search}%";
            $params['s2'] = "%{$search}%";
            $params['s3'] = "%{$search}%";
        }

        $thresholds = ExpiryService::getThresholds();
        $urgentDays = $thresholds['urgent_days'];
        $warningDays = $thresholds['warning_days'];

        $whereClause = implode(' AND ', $where);

        $sql = "
            SELECT 
                b.*,
                m.brand_name,
                m.generic_name,
                m.sku,
                m.strength,
                m.dosage_form,
                m.unit_of_measure,
                loc.name AS location_name,
                loc.temp_zone,
                s.name AS supplier_name,
                DATEDIFF(b.expiry_date, CURRENT_DATE()) AS days_remaining,
                GREATEST(0, CAST(b.current_quantity AS SIGNED) - CAST(b.quarantined_quantity AS SIGNED) - CAST(b.reserved_quantity AS SIGNED)) AS available_units,
                CASE 
                    WHEN b.expiry_date <= CURRENT_DATE() THEN 'expired'
                    WHEN DATEDIFF(b.expiry_date, CURRENT_DATE()) <= {$urgentDays} THEN 'urgent'
                    WHEN DATEDIFF(b.expiry_date, CURRENT_DATE()) <= {$warningDays} THEN 'warning'
                    ELSE 'safe'
                END AS calculated_urgency
            FROM medicine_batches b
            JOIN medicines m ON b.medicine_id = m.id
            JOIN storage_locations loc ON b.storage_location_id = loc.id
            LEFT JOIN suppliers s ON b.supplier_id = s.id
            WHERE {$whereClause}
            ORDER BY b.expiry_date ASC, b.id ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll();

        Response::success($batches);
    }

    public static function store(Request $request): void
    {
        RoleMiddleware::authorize(['super_admin', 'pharmacist']);
        $body = $request->getAllBody();

        $medicineId = (int)($body['medicine_id'] ?? 0);
        $batchNumber = trim((string)($body['batch_number'] ?? ''));
        $receivedQuantity = (int)($body['received_quantity'] ?? 0);
        $expiryDate = trim((string)($body['expiry_date'] ?? ''));
        $storageLocationId = (int)($body['storage_location_id'] ?? 0);
        $supplierId = !empty($body['supplier_id']) ? (int)$body['supplier_id'] : null;
        $unitCost = (float)($body['unit_cost'] ?? 0.00);

        if ($medicineId <= 0 || $batchNumber === '' || $receivedQuantity <= 0 || $expiryDate === '' || $storageLocationId <= 0) {
            Response::error('Medicine, Batch Number, Quantity (>0), Expiry Date, and Storage Location are required.', 422);
        }

        $user = Session::get('user');
        $result = InventoryService::registerBatch([
            'medicine_id'         => $medicineId,
            'batch_number'        => $batchNumber,
            'received_quantity'   => $receivedQuantity,
            'expiry_date'         => $expiryDate,
            'storage_location_id' => $storageLocationId,
            'supplier_id'         => $supplierId,
            'unit_cost'           => $unitCost,
            'received_date'       => $body['received_date'] ?? date('Y-m-d'),
            'notes'               => $body['notes'] ?? null
        ], (int)$user['id']);

        Response::success($result, 'Batch consignment registered and stock updated successfully', 201);
    }

    public static function adjust(Request $request, array $routeParams): void
    {
        RoleMiddleware::authorize(['super_admin', 'pharmacist']);
        $batchId = (int)($routeParams['id'] ?? 0);
        $body = $request->getAllBody();

        $newQty = (int)($body['new_quantity'] ?? -1);
        $reason = trim((string)($body['reason'] ?? ''));

        if ($newQty < 0 || $reason === '') {
            Response::error('New quantity (>=0) and mandatory adjustment reason are required.', 422);
        }

        $user = Session::get('user');
        $result = InventoryService::adjustStock($batchId, $newQty, $reason, (int)$user['id']);
        Response::success($result, 'Stock adjusted and logged to immutable audit ledger');
    }

    public static function locations(Request $request): void
    {
        $pdo = Database::getConnection();
        $locs = $pdo->query("SELECT id, name, type, temp_zone FROM storage_locations WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
        Response::success($locs);
    }

    public static function suppliers(Request $request): void
    {
        $pdo = Database::getConnection();
        $sups = $pdo->query("SELECT id, name, contact_person, email, phone FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
        Response::success($sups);
    }
}
