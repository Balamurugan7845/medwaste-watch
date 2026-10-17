<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\AuditLogger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\RoleMiddleware;
use App\Services\InventoryService;
use PDO;

class MedicineController
{
    public static function index(Request $request): void
    {
        $pdo = Database::getConnection();

        $search = trim((string)$request->getQuery('search', ''));
        $categoryId = (int)$request->getQuery('category_id', 0);
        $status = (string)$request->getQuery('status', 'all'); // all, active, inactive, low_stock, out_of_stock
        $page = max(1, (int)$request->getQuery('page', 1));
        $limit = min(100, max(5, (int)$request->getQuery('limit', 15)));
        $offset = ($page - 1) * $limit;

        $where = ["1=1"];
        $params = [];

        if ($search !== '') {
            $where[] = "(m.brand_name LIKE :s1 OR m.generic_name LIKE :s2 OR m.sku LIKE :s3)";
            $params['s1'] = "%{$search}%";
            $params['s2'] = "%{$search}%";
            $params['s3'] = "%{$search}%";
        }

        if ($categoryId > 0) {
            $where[] = "m.category_id = :cat_id";
            $params['cat_id'] = $categoryId;
        }

        if ($status === 'active') {
            $where[] = "m.is_active = 1";
        } elseif ($status === 'inactive') {
            $where[] = "m.is_active = 0";
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM medicines m WHERE {$whereClause}");
        $stmtCount->execute($params);
        $totalItems = (int) $stmtCount->fetchColumn();

        // Query with stock aggregations
        $sql = "
            SELECT 
                m.*,
                c.name AS category_name,
                COALESCE(SUM(b.current_quantity), 0) AS physical_on_hand,
                COALESCE(SUM(b.reserved_quantity), 0) AS reserved_quantity,
                COALESCE(SUM(b.quarantined_quantity), 0) AS quarantined_quantity,
                COALESCE(SUM(CASE 
                    WHEN b.expiry_date > CURRENT_DATE() AND b.batch_status NOT IN ('quarantined', 'expired')
                    THEN GREATEST(0, CAST(b.current_quantity AS SIGNED) - CAST(b.quarantined_quantity AS SIGNED) - CAST(b.reserved_quantity AS SIGNED))
                    ELSE 0 
                END), 0) AS available_to_reserve,
                COUNT(b.id) AS total_batches_count
            FROM medicines m
            JOIN medicine_categories c ON m.category_id = c.id
            LEFT JOIN medicine_batches b ON m.id = b.medicine_id
            WHERE {$whereClause}
            GROUP BY m.id, c.name
            ORDER BY m.brand_name ASC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $medicines = $stmt->fetchAll();

        foreach ($medicines as &$med) {
            $avail = (int)$med['available_to_reserve'];
            $reorder = (int)$med['reorder_level'];
            if ($avail <= 0) {
                $med['stock_health'] = 'out_of_stock';
            } elseif ($avail <= $reorder) {
                $med['stock_health'] = 'low_stock';
            } else {
                $med['stock_health'] = 'adequate';
            }
        }

        Response::success([
            'items'       => $medicines,
            'total'       => $totalItems,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($totalItems / $limit)
        ]);
    }

    public static function show(Request $request, array $routeParams): void
    {
        $id = (int) ($routeParams['id'] ?? 0);
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT m.*, c.name AS category_name
            FROM medicines m
            JOIN medicine_categories c ON m.category_id = c.id
            WHERE m.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $medicine = $stmt->fetch();

        if (!$medicine) {
            Response::error('Medicine not found', 404);
        }

        // Fetch all batches
        $stmtB = $pdo->prepare("
            SELECT 
                b.*,
                loc.name AS location_name,
                s.name AS supplier_name,
                DATEDIFF(b.expiry_date, CURRENT_DATE()) AS days_to_expiry,
                GREATEST(0, CAST(b.current_quantity AS SIGNED) - CAST(b.quarantined_quantity AS SIGNED) - CAST(b.reserved_quantity AS SIGNED)) AS available_units
            FROM medicine_batches b
            JOIN storage_locations loc ON b.storage_location_id = loc.id
            LEFT JOIN suppliers s ON b.supplier_id = s.id
            WHERE b.medicine_id = :id
            ORDER BY b.expiry_date ASC, b.id ASC
        ");
        $stmtB->execute(['id' => $id]);
        $batches = $stmtB->fetchAll();

        // Calculate aggregate sums
        $onHand = array_sum(array_column($batches, 'current_quantity'));
        $reserved = array_sum(array_column($batches, 'reserved_quantity'));
        $quarantined = array_sum(array_column($batches, 'quarantined_quantity'));
        $available = array_sum(array_column($batches, 'available_units'));

        $medicine['physical_on_hand'] = $onHand;
        $medicine['reserved_quantity'] = $reserved;
        $medicine['quarantined_quantity'] = $quarantined;
        $medicine['available_to_reserve'] = $available;
        $medicine['batches'] = $batches;

        Response::success($medicine);
    }

    public static function store(Request $request): void
    {
        RoleMiddleware::authorize(['super_admin', 'pharmacist']);
        $body = $request->getAllBody();

        $sku = trim((string)($body['sku'] ?? ''));
        $brandName = trim((string)($body['brand_name'] ?? ''));
        $genericName = trim((string)($body['generic_name'] ?? ''));
        $strength = trim((string)($body['strength'] ?? ''));
        $dosageForm = trim((string)($body['dosage_form'] ?? 'tablet'));
        $categoryId = (int)($body['category_id'] ?? 0);
        $reorderLevel = (int)($body['reorder_level'] ?? 20);
        $uom = trim((string)($body['unit_of_measure'] ?? 'box'));
        $manufacturer = trim((string)($body['manufacturer'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));

        if ($sku === '' || $brandName === '' || $genericName === '' || $categoryId <= 0) {
            Response::error('SKU, Brand Name, Generic Name, and Category are required.', 422);
        }

        $pdo = Database::getConnection();

        // Check duplicate SKU
        $stmtCheck = $pdo->prepare("SELECT id FROM medicines WHERE sku = :sku");
        $stmtCheck->execute(['sku' => $sku]);
        if ($stmtCheck->fetch()) {
            Response::error("A medicine with SKU '{$sku}' already exists.", 422);
        }

        $stmt = $pdo->prepare("
            INSERT INTO medicines (
                sku, brand_name, generic_name, strength, dosage_form,
                category_id, manufacturer, unit_of_measure, reorder_level, description
            ) VALUES (
                :sku, :brand, :generic, :strength, :dosage,
                :cat, :mfg, :uom, :reorder, :desc
            )
        ");
        $stmt->execute([
            'sku'      => $sku,
            'brand'    => $brandName,
            'generic'  => $genericName,
            'strength' => $strength,
            'dosage'   => $dosageForm,
            'cat'      => $categoryId,
            'mfg'      => $manufacturer,
            'uom'      => $uom,
            'reorder'  => $reorderLevel,
            'desc'     => $description
        ]);
        $id = (int)$pdo->lastInsertId();

        $user = Session::get('user');
        AuditLogger::log('MEDICINE_CREATED', 'Medicine', $id, null, ['sku' => $sku, 'brand_name' => $brandName], $user['id'] ?? null);

        Response::success(['id' => $id], 'Medicine added to catalog successfully', 201);
    }

    public static function update(Request $request, array $routeParams): void
    {
        RoleMiddleware::authorize(['super_admin', 'pharmacist']);
        $id = (int)($routeParams['id'] ?? 0);
        $body = $request->getAllBody();

        $pdo = Database::getConnection();
        $stmtCur = $pdo->prepare("SELECT * FROM medicines WHERE id = :id");
        $stmtCur->execute(['id' => $id]);
        $current = $stmtCur->fetch();

        if (!$current) {
            Response::error('Medicine not found', 404);
        }

        $brandName = trim((string)($body['brand_name'] ?? $current['brand_name']));
        $genericName = trim((string)($body['generic_name'] ?? $current['generic_name']));
        $strength = trim((string)($body['strength'] ?? $current['strength']));
        $dosageForm = trim((string)($body['dosage_form'] ?? $current['dosage_form']));
        $categoryId = (int)($body['category_id'] ?? $current['category_id']);
        $reorderLevel = (int)($body['reorder_level'] ?? $current['reorder_level']);
        $uom = trim((string)($body['unit_of_measure'] ?? $current['unit_of_measure']));
        $manufacturer = trim((string)($body['manufacturer'] ?? $current['manufacturer']));
        $description = trim((string)($body['description'] ?? $current['description']));
        $isActive = isset($body['is_active']) ? (int)(bool)$body['is_active'] : (int)$current['is_active'];

        $stmtUp = $pdo->prepare("
            UPDATE medicines 
            SET brand_name = :brand,
                generic_name = :generic,
                strength = :strength,
                dosage_form = :dosage,
                category_id = :cat,
                manufacturer = :mfg,
                unit_of_measure = :uom,
                reorder_level = :reorder,
                description = :desc,
                is_active = :active
            WHERE id = :id
        ");
        $stmtUp->execute([
            'brand'   => $brandName,
            'generic' => $genericName,
            'strength'=> $strength,
            'dosage'  => $dosageForm,
            'cat'     => $categoryId,
            'mfg'     => $manufacturer,
            'uom'     => $uom,
            'reorder' => $reorderLevel,
            'desc'    => $description,
            'active'  => $isActive,
            'id'      => $id
        ]);

        $user = Session::get('user');
        AuditLogger::log('MEDICINE_UPDATED', 'Medicine', $id, $current, $body, $user['id'] ?? null);

        Response::success(null, 'Medicine updated successfully');
    }

    public static function categories(Request $request): void
    {
        $pdo = Database::getConnection();
        $categories = $pdo->query("SELECT id, name, description FROM medicine_categories WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
        Response::success($categories);
    }
}
