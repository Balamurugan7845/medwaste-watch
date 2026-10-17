<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Core\AuditLogger;
use PDO;
use RuntimeException;

class TransferDisposalService
{
    /**
     * Initiate or complete transfer between storage locations
     */
    public static function createTransfer(array $data, int $userId): array
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            $batchId = (int) $data['batch_id'];
            $qty = (int) $data['quantity'];
            $destLocationId = (int) $data['destination_location_id'];

            if ($qty <= 0) {
                throw new RuntimeException("Transfer quantity must be greater than zero.");
            }

            // Lock batch
            $stmtB = $pdo->prepare("SELECT * FROM medicine_batches WHERE id = :id FOR UPDATE");
            $stmtB->execute(['id' => $batchId]);
            $batch = $stmtB->fetch();

            if (!$batch) {
                throw new RuntimeException("Batch not found.");
            }

            if ((int)$batch['storage_location_id'] === $destLocationId) {
                throw new RuntimeException("Destination storage location cannot be the same as source location.");
            }

            $avail = (int)$batch['current_quantity'] - (int)$batch['reserved_quantity'] - (int)$batch['quarantined_quantity'];
            if ($avail < $qty) {
                throw new RuntimeException("Insufficient unreserved stock to transfer. Available: {$avail} units.");
            }

            $transferNumber = "TRF-" . date('Ymd') . "-" . strtoupper(bin2hex(random_bytes(2)));

            // Record Transfer Header
            $stmtTr = $pdo->prepare("
                INSERT INTO transfers (
                    transfer_number, source_location_id, destination_location_id,
                    initiated_by, confirmed_by, status, notes, completed_at
                ) VALUES (
                    :num, :src, :dst,
                    :user, :conf, 'completed', :notes, NOW()
                )
            ");
            $stmtTr->execute([
                'num'   => $transferNumber,
                'src'   => $batch['storage_location_id'],
                'dst'   => $destLocationId,
                'user'  => $userId,
                'conf'  => $userId,
                'notes' => $data['notes'] ?? 'Inter-departmental storage transfer'
            ]);
            $transferId = (int) $pdo->lastInsertId();

            // Record Item
            $stmtItem = $pdo->prepare("INSERT INTO transfer_items (transfer_id, batch_id, quantity) VALUES (?, ?, ?)");
            $stmtItem->execute([$transferId, $batchId, $qty]);

            // Update batch location if moving entire batch, or split if partial
            if ($qty === (int)$batch['current_quantity']) {
                $stmtUpB = $pdo->prepare("UPDATE medicine_batches SET storage_location_id = :loc WHERE id = :id");
                $stmtUpB->execute(['loc' => $destLocationId, 'id' => $batchId]);
            } else {
                // Deduct from current batch
                $stmtDeduct = $pdo->prepare("UPDATE medicine_batches SET current_quantity = current_quantity - :qty WHERE id = :id");
                $stmtDeduct->execute(['qty' => $qty, 'id' => $batchId]);

                // Create or find destination batch lot
                $stmtDestBatch = $pdo->prepare("
                    INSERT INTO medicine_batches (
                        medicine_id, batch_number, supplier_id, storage_location_id,
                        received_quantity, current_quantity, reserved_quantity, quarantined_quantity,
                        unit_cost, received_date, expiry_date, batch_status, notes
                    ) VALUES (
                        :med, :num, :sup, :loc,
                        :qty, :qty, 0, 0,
                        :cost, :rec_date, :expiry, 'active', :notes
                    )
                ");
                $stmtDestBatch->execute([
                    'med'      => $batch['medicine_id'],
                    'num'      => $batch['batch_number'],
                    'sup'      => $batch['supplier_id'],
                    'loc'      => $destLocationId,
                    'qty'      => $qty,
                    'cost'     => $batch['unit_cost'],
                    'rec_date' => $batch['received_date'],
                    'expiry'   => $batch['expiry_date'],
                    'notes'    => "Transferred from {$batch['storage_location_id']} via {$transferNumber}"
                ]);
            }

            // Ledger movements (Transfer Out and Transfer In)
            $stmtMovOut = $pdo->prepare("
                INSERT INTO stock_movements (
                    batch_id, medicine_id, movement_type, quantity,
                    balance_before, balance_after, reference_type, reference_id,
                    reason, user_id
                ) VALUES (
                    :batch, :med, 'transfer_out', :qty,
                    :before, :after, 'transfer', :ref_id,
                    :reason, :user
                )
            ");
            $stmtMovOut->execute([
                'batch'  => $batchId,
                'med'    => $batch['medicine_id'],
                'qty'    => -$qty,
                'before' => $batch['current_quantity'],
                'after'  => $batch['current_quantity'] - $qty,
                'ref_id' => $transferId,
                'reason' => "Transfer Out to location #{$destLocationId}",
                'user'   => $userId
            ]);

            AuditLogger::log('STOCK_TRANSFERRED', 'Transfer', $transferId, null, [
                'transfer_number' => $transferNumber,
                'batch_id' => $batchId,
                'quantity' => $qty,
                'destination_location_id' => $destLocationId
            ], $userId);

            Database::commit();
            return ['id' => $transferId, 'transfer_number' => $transferNumber];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * Quarantine batches (expired, damaged, or recalled)
     */
    public static function quarantineBatch(int $batchId, int $quantity, string $reason, int $userId): array
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            $stmtB = $pdo->prepare("SELECT * FROM medicine_batches WHERE id = :id FOR UPDATE");
            $stmtB->execute(['id' => $batchId]);
            $batch = $stmtB->fetch();

            if (!$batch) {
                throw new RuntimeException("Batch not found.");
            }

            $currentQty = (int) $batch['current_quantity'];
            $reservedQty = (int) $batch['reserved_quantity'];
            $existingQuar = (int) $batch['quarantined_quantity'];

            $availToQuar = $currentQty - $reservedQty - $existingQuar;
            if ($quantity > $availToQuar) {
                throw new RuntimeException("Cannot quarantine {$quantity} units. Max unreserved eligible units: {$availToQuar}.");
            }

            $newQuar = $existingQuar + $quantity;

            $stmtUp = $pdo->prepare("
                UPDATE medicine_batches 
                SET quarantined_quantity = :quar,
                    batch_status = 'quarantined'
                WHERE id = :id
            ");
            $stmtUp->execute(['quar' => $newQuar, 'id' => $batchId]);

            // Movement Ledger
            $stmtMov = $pdo->prepare("
                INSERT INTO stock_movements (
                    batch_id, medicine_id, movement_type, quantity,
                    balance_before, balance_after, reference_type, reference_id,
                    reason, user_id
                ) VALUES (
                    :batch, :med, 'quarantine', :qty,
                    :before, :after, 'manual_adjustment', :batch,
                    :reason, :user
                )
            ");
            $stmtMov->execute([
                'batch'  => $batchId,
                'med'    => $batch['medicine_id'],
                'qty'    => $quantity,
                'before' => $existingQuar,
                'after'  => $newQuar,
                'reason' => "Quarantined: {$reason}",
                'user'   => $userId
            ]);

            AuditLogger::log('BATCH_QUARANTINED', 'MedicineBatch', $batchId, ['quarantined_quantity' => $existingQuar], [
                'quarantined_quantity' => $newQuar,
                'added' => $quantity,
                'reason' => $reason
            ], $userId);

            Database::commit();
            return ['batch_id' => $batchId, 'quarantined_total' => $newQuar];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * Record approved biomedical disposal of expired, contaminated or recalled stock
     */
    public static function recordDisposal(array $data, int $authorizedBy): array
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            $batchId = (int) $data['batch_id'];
            $qty = (int) $data['quantity'];

            if ($qty <= 0) {
                throw new RuntimeException("Disposal quantity must be greater than zero.");
            }

            $stmtB = $pdo->prepare("SELECT * FROM medicine_batches WHERE id = :id FOR UPDATE");
            $stmtB->execute(['id' => $batchId]);
            $batch = $stmtB->fetch();

            if (!$batch) {
                throw new RuntimeException("Batch not found.");
            }

            $curQty = (int) $batch['current_quantity'];
            if ($curQty < $qty) {
                throw new RuntimeException("Cannot dispose {$qty} units. Batch only has {$curQty} on-hand units.");
            }

            $disposalNumber = "DSP-" . date('Ymd') . "-" . strtoupper(bin2hex(random_bytes(2)));

            // Insert permanent disposal record
            $stmtDisp = $pdo->prepare("
                INSERT INTO disposal_records (
                    disposal_number, batch_id, medicine_id, quantity,
                    disposal_method, reason, authorized_by, disposed_at,
                    certificate_ref, notes
                ) VALUES (
                    :num, :batch, :med, :qty,
                    :method, :reason, :auth, NOW(),
                    :cert, :notes
                )
            ");
            $stmtDisp->execute([
                'num'    => $disposalNumber,
                'batch'  => $batchId,
                'med'    => $batch['medicine_id'],
                'qty'    => $qty,
                'method' => $data['disposal_method'] ?? 'incineration',
                'reason' => $data['reason'] ?? 'expired',
                'auth'   => $authorizedBy,
                'cert'   => $data['certificate_ref'] ?? null,
                'notes'  => $data['notes'] ?? null
            ]);
            $dispId = (int) $pdo->lastInsertId();

            // Deduct physical quantity and quarantined quantity from batch
            $newCur = $curQty - $qty;
            $newQuar = max(0, (int)$batch['quarantined_quantity'] - $qty);
            $batchStatus = ($newCur === 0) ? 'depleted' : $batch['batch_status'];

            $stmtUpB = $pdo->prepare("
                UPDATE medicine_batches 
                SET current_quantity = :cur,
                    quarantined_quantity = :quar,
                    batch_status = :status
                WHERE id = :id
            ");
            $stmtUpB->execute([
                'cur'    => $newCur,
                'quar'   => $newQuar,
                'status' => $batchStatus,
                'id'     => $batchId
            ]);

            // Movement Ledger
            $stmtMov = $pdo->prepare("
                INSERT INTO stock_movements (
                    batch_id, medicine_id, movement_type, quantity,
                    balance_before, balance_after, reference_type, reference_id,
                    reason, user_id
                ) VALUES (
                    :batch, :med, 'disposal', :qty,
                    :before, :after, 'disposal', :ref_id,
                    :reason, :user
                )
            ");
            $stmtMov->execute([
                'batch'  => $batchId,
                'med'    => $batch['medicine_id'],
                'qty'    => -$qty,
                'before' => $curQty,
                'after'  => $newCur,
                'ref_id' => $dispId,
                'reason' => "Authorized Waste Disposal: {$data['reason']} ({$disposalNumber})",
                'user'   => $authorizedBy
            ]);

            AuditLogger::log('STOCK_DISPOSAL', 'DisposalRecord', $dispId, null, [
                'disposal_number' => $disposalNumber,
                'batch_id' => $batchId,
                'quantity' => $qty,
                'method' => $data['disposal_method'] ?? 'incineration',
                'reason' => $data['reason'] ?? 'expired'
            ], $authorizedBy);

            Database::commit();
            return ['id' => $dispId, 'disposal_number' => $disposalNumber, 'remaining_stock' => $newCur];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
