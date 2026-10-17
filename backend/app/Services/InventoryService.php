<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Core\AuditLogger;
use PDO;
use RuntimeException;

class InventoryService
{
    /**
     * Get stock calculations for a specific medicine or all active medicines
     */
    public static function getMedicineStockSummary(?int $medicineId = null): array
    {
        $pdo = Database::getConnection();

        $where = $medicineId ? "WHERE m.id = :med_id AND m.is_active = 1" : "WHERE m.is_active = 1";
        
        $sql = "
            SELECT 
                m.id AS medicine_id,
                m.sku,
                m.brand_name,
                m.generic_name,
                m.strength,
                m.dosage_form,
                m.unit_of_measure,
                m.reorder_level,
                c.name AS category_name,
                COALESCE(SUM(b.current_quantity), 0) AS physical_on_hand,
                COALESCE(SUM(b.reserved_quantity), 0) AS total_reserved,
                COALESCE(SUM(b.quarantined_quantity), 0) AS total_quarantined,
                COALESCE(SUM(CASE 
                    WHEN b.expiry_date <= CURRENT_DATE() THEN b.current_quantity 
                    ELSE 0 
                END), 0) AS total_expired,
                COALESCE(SUM(CASE 
                    WHEN b.expiry_date > CURRENT_DATE() AND b.batch_status != 'quarantined' AND b.batch_status != 'expired'
                    THEN GREATEST(0, CAST(b.current_quantity AS SIGNED) - CAST(b.quarantined_quantity AS SIGNED) - CAST(b.reserved_quantity AS SIGNED))
                    ELSE 0 
                END), 0) AS available_to_reserve
            FROM medicines m
            LEFT JOIN medicine_categories c ON m.category_id = c.id
            LEFT JOIN medicine_batches b ON m.id = b.medicine_id
            {$where}
            GROUP BY m.id, m.sku, m.brand_name, m.generic_name, m.strength, m.dosage_form, m.unit_of_measure, m.reorder_level, c.name
            ORDER BY m.brand_name ASC
        ";

        $stmt = $pdo->prepare($sql);
        if ($medicineId) {
            $stmt->execute(['med_id' => $medicineId]);
            $res = $stmt->fetch();
            return $res ?: [];
        }

        $stmt->execute();
        $results = $stmt->fetchAll();

        // Calculate dynamic stock health status for each
        foreach ($results as &$r) {
            $avail = (int) $r['available_to_reserve'];
            $reorder = (int) $r['reorder_level'];
            if ($avail <= 0) {
                $r['stock_status'] = 'out_of_stock';
            } elseif ($avail <= $reorder) {
                $r['stock_status'] = 'low_stock';
            } else {
                $r['stock_status'] = 'adequate';
            }
        }

        return $results;
    }

    /**
     * Receive new batch or additional stock into a batch
     */
    public static function registerBatch(array $data, int $userId): array
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            // Check medicine exists
            $stmtMed = $pdo->prepare("SELECT id, brand_name, is_active FROM medicines WHERE id = :id FOR UPDATE");
            $stmtMed->execute(['id' => $data['medicine_id']]);
            $med = $stmtMed->fetch();
            if (!$med) {
                throw new RuntimeException("Selected medicine does not exist.");
            }
            if (!$med['is_active']) {
                throw new RuntimeException("Cannot add batch to an inactive medicine.");
            }

            // Check if batch number with same supplier already exists
            $stmtCheck = $pdo->prepare("
                SELECT id, current_quantity FROM medicine_batches 
                WHERE medicine_id = :med_id AND batch_number = :batch_num AND supplier_id = :sup_id
                FOR UPDATE
            ");
            $stmtCheck->execute([
                'med_id' => $data['medicine_id'],
                'batch_num' => $data['batch_number'],
                'sup_id' => $data['supplier_id'] ?? null
            ]);
            $existing = $stmtCheck->fetch();

            $qty = (int) $data['received_quantity'];
            if ($qty <= 0) {
                throw new RuntimeException("Received quantity must be greater than zero.");
            }

            $expiryDate = $data['expiry_date'];
            $today = date('Y-m-d');
            if ($expiryDate <= $today) {
                throw new RuntimeException("Cannot register a batch that is already expired.");
            }

            if ($existing) {
                // Add to existing batch
                $batchId = (int) $existing['id'];
                $oldQty = (int) $existing['current_quantity'];
                $newQty = $oldQty + $qty;

                $stmtUpdate = $pdo->prepare("
                    UPDATE medicine_batches 
                    SET current_quantity = :new_qty,
                        received_quantity = received_quantity + :add_qty,
                        storage_location_id = :loc_id,
                        unit_cost = :unit_cost,
                        expiry_date = :expiry,
                        batch_status = 'active'
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    'new_qty'   => $newQty,
                    'add_qty'   => $qty,
                    'loc_id'    => $data['storage_location_id'],
                    'unit_cost' => $data['unit_cost'] ?? 0.00,
                    'expiry'    => $expiryDate,
                    'id'        => $batchId
                ]);

                $balanceBefore = $oldQty;
                $balanceAfter = $newQty;
            } else {
                // Insert new batch
                $stmtInsert = $pdo->prepare("
                    INSERT INTO medicine_batches (
                        medicine_id, batch_number, supplier_id, storage_location_id,
                        received_quantity, current_quantity, reserved_quantity, quarantined_quantity,
                        unit_cost, received_date, expiry_date, batch_status, notes
                    ) VALUES (
                        :med_id, :batch_num, :sup_id, :loc_id,
                        :qty, :qty, 0, 0,
                        :cost, :rec_date, :expiry, 'active', :notes
                    )
                ");
                $stmtInsert->execute([
                    'med_id'    => $data['medicine_id'],
                    'batch_num' => $data['batch_number'],
                    'sup_id'    => $data['supplier_id'] ?? null,
                    'loc_id'    => $data['storage_location_id'],
                    'qty'       => $qty,
                    'cost'      => $data['unit_cost'] ?? 0.00,
                    'rec_date'  => $data['received_date'] ?? $today,
                    'expiry'    => $expiryDate,
                    'notes'     => $data['notes'] ?? null
                ]);
                $batchId = (int) $pdo->lastInsertId();

                $balanceBefore = 0;
                $balanceAfter = $qty;
            }

            // Ledger record in stock_movements
            $stmtMov = $pdo->prepare("
                INSERT INTO stock_movements (
                    batch_id, medicine_id, movement_type, quantity,
                    balance_before, balance_after, reference_type, reference_id,
                    reason, user_id
                ) VALUES (
                    :batch_id, :medicine_id, 'receipt', :qty,
                    :before, :after, 'receipt', :ref_id,
                    :reason, :user_id
                )
            ");
            $stmtMov->execute([
                'batch_id'    => $batchId,
                'medicine_id' => $data['medicine_id'],
                'qty'         => $qty,
                'before'      => $balanceBefore,
                'after'       => $balanceAfter,
                'ref_id'      => $batchId,
                'reason'      => $data['notes'] ?? 'Batch consignment receipt',
                'user_id'     => $userId
            ]);

            AuditLogger::log(
                'BATCH_REGISTERED',
                'MedicineBatch',
                $batchId,
                null,
                ['batch_number' => $data['batch_number'], 'quantity' => $qty, 'expiry_date' => $expiryDate],
                $userId
            );

            Database::commit();
            return ['batch_id' => $batchId, 'balance' => $balanceAfter];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * Get eligible batches ordered by FEFO (First Expired, First Out)
     */
    public static function getEligibleBatchesFEFO(int $medicineId): array
    {
        $pdo = Database::getConnection();

        $sql = "
            SELECT 
                b.id,
                b.medicine_id,
                b.batch_number,
                b.expiry_date,
                b.current_quantity,
                b.reserved_quantity,
                b.quarantined_quantity,
                b.storage_location_id,
                loc.name AS location_name,
                GREATEST(0, CAST(b.current_quantity AS SIGNED) - CAST(b.quarantined_quantity AS SIGNED) - CAST(b.reserved_quantity AS SIGNED)) AS available_units,
                DATEDIFF(b.expiry_date, CURRENT_DATE()) AS days_until_expiry
            FROM medicine_batches b
            JOIN storage_locations loc ON b.storage_location_id = loc.id
            WHERE b.medicine_id = :med_id
              AND b.expiry_date > CURRENT_DATE()
              AND b.batch_status NOT IN ('expired', 'quarantined', 'depleted')
              AND GREATEST(0, CAST(b.current_quantity AS SIGNED) - CAST(b.quarantined_quantity AS SIGNED) - CAST(b.reserved_quantity AS SIGNED)) > 0
            ORDER BY b.expiry_date ASC, b.id ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['med_id' => $medicineId]);
        return $stmt->fetchAll();
    }

    /**
     * Stock adjustment (e.g. physical count discrepancy, damaged container, recount)
     */
    public static function adjustStock(int $batchId, int $newQuantity, string $reason, int $userId): array
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            $stmt = $pdo->prepare("
                SELECT id, medicine_id, batch_number, current_quantity, reserved_quantity, quarantined_quantity 
                FROM medicine_batches 
                WHERE id = :id 
                FOR UPDATE
            ");
            $stmt->execute(['id' => $batchId]);
            $batch = $stmt->fetch();

            if (!$batch) {
                throw new RuntimeException("Batch not found.");
            }

            $currentQty = (int) $batch['current_quantity'];
            $reservedQty = (int) $batch['reserved_quantity'];
            $quarantinedQty = (int) $batch['quarantined_quantity'];

            if ($newQuantity < 0) {
                throw new RuntimeException("Stock quantity cannot be negative.");
            }

            if ($newQuantity < ($reservedQty + $quarantinedQty)) {
                throw new RuntimeException("Adjusted stock cannot be lower than currently reserved ({$reservedQty}) plus quarantined ({$quarantinedQty}) units.");
            }

            $diff = $newQuantity - $currentQty;
            if ($diff === 0) {
                Database::rollBack();
                return ['batch_id' => $batchId, 'current_quantity' => $currentQty];
            }

            // Update batch
            $status = $newQuantity === 0 ? 'depleted' : 'active';
            $stmtUpdate = $pdo->prepare("UPDATE medicine_batches SET current_quantity = :qty, batch_status = :status WHERE id = :id");
            $stmtUpdate->execute(['qty' => $newQuantity, 'status' => $status, 'id' => $batchId]);

            // Movement ledger
            $stmtMov = $pdo->prepare("
                INSERT INTO stock_movements (
                    batch_id, medicine_id, movement_type, quantity,
                    balance_before, balance_after, reference_type, reference_id,
                    reason, user_id
                ) VALUES (
                    :batch_id, :med_id, 'adjustment', :qty,
                    :before, :after, 'manual_adjustment', :batch_id,
                    :reason, :user_id
                )
            ");
            $stmtMov->execute([
                'batch_id' => $batchId,
                'med_id'   => $batch['medicine_id'],
                'qty'      => $diff,
                'before'   => $currentQty,
                'after'    => $newQuantity,
                'reason'   => $reason,
                'user_id'  => $userId
            ]);

            AuditLogger::log(
                'STOCK_ADJUSTMENT',
                'MedicineBatch',
                $batchId,
                ['quantity' => $currentQty],
                ['quantity' => $newQuantity, 'difference' => $diff, 'reason' => $reason],
                $userId
            );

            Database::commit();
            return ['batch_id' => $batchId, 'previous_quantity' => $currentQty, 'new_quantity' => $newQuantity];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
