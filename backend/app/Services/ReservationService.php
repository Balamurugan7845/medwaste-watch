<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Core\AuditLogger;
use PDO;
use RuntimeException;

class ReservationService
{
    /**
     * Submit a department medicine reservation request
     */
    public static function createRequest(array $data, int $userId): array
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            // Check medicine exists and is active
            $stmtMed = $pdo->prepare("SELECT id, brand_name, is_active FROM medicines WHERE id = :id");
            $stmtMed->execute(['id' => $data['medicine_id']]);
            $med = $stmtMed->fetch();
            if (!$med || !$med['is_active']) {
                throw new RuntimeException("Selected medicine is inactive or unavailable.");
            }

            $qty = (int) ($data['requested_quantity'] ?? 0);
            if ($qty <= 0) {
                throw new RuntimeException("Requested quantity must be at least 1 unit.");
            }

            // Generate unique request number REQ-YYYY-XXXX
            $year = date('Y');
            $randomSuffix = strtoupper(bin2hex(random_bytes(2)));
            $requestNumber = sprintf("REQ-%s-%04d%s", $year, mt_rand(1000, 9999), $randomSuffix);

            $stmtInsert = $pdo->prepare("
                INSERT INTO reservations (
                    request_number, department_id, requesting_user_id, medicine_id,
                    requested_quantity, approved_quantity, issued_quantity, priority,
                    justification, status
                ) VALUES (
                    :req_num, :dept_id, :user_id, :med_id,
                    :qty, 0, 0, :priority,
                    :justification, 'pending'
                )
            ");

            $stmtInsert->execute([
                'req_num'       => $requestNumber,
                'dept_id'       => $data['department_id'],
                'user_id'       => $userId,
                'med_id'        => $data['medicine_id'],
                'qty'           => $qty,
                'priority'      => in_array($data['priority'] ?? '', ['urgent', 'stat'], true) ? $data['priority'] : 'routine',
                'justification' => $data['justification'] ?? null
            ]);
            $resId = (int) $pdo->lastInsertId();

            // Record history
            $stmtHist = $pdo->prepare("
                INSERT INTO reservation_history (reservation_id, action, actor_id, notes)
                VALUES (:res_id, 'CREATED', :actor_id, :notes)
            ");
            $stmtHist->execute([
                'res_id'   => $resId,
                'actor_id' => $userId,
                'notes'    => "Request created for {$qty} units ({$data['priority']})"
            ]);

            // Notification for Pharmacists
            $roleStmt = $pdo->query("SELECT id FROM roles WHERE slug = 'pharmacist'");
            $pharmRoleId = $roleStmt->fetchColumn();
            if ($pharmRoleId) {
                $stmtNotif = $pdo->prepare("
                    INSERT INTO notifications (target_role_id, type, title, message, link_url)
                    VALUES (:role_id, 'new_reservation', :title, :message, :link)
                ");
                $stmtNotif->execute([
                    'role_id' => $pharmRoleId,
                    'title'   => "New Reservation Request: {$requestNumber}",
                    'message' => "Department requested {$qty} units of {$med['brand_name']}.",
                    'link'    => "/reservations.html?id={$resId}"
                ]);
            }

            AuditLogger::log('RESERVATION_CREATED', 'Reservation', $resId, null, [
                'request_number' => $requestNumber,
                'quantity' => $qty,
                'medicine_id' => $data['medicine_id']
            ], $userId);

            Database::commit();
            return ['id' => $resId, 'request_number' => $requestNumber];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * Pharmacist approves (or partially approves) reservation and allocates batches using FEFO
     */
    public static function approveRequest(int $reservationId, ?int $approvedQty, int $approverId): array
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            $stmtRes = $pdo->prepare("
                SELECT r.*, m.brand_name 
                FROM reservations r
                JOIN medicines m ON r.medicine_id = m.id
                WHERE r.id = :id 
                FOR UPDATE
            ");
            $stmtRes->execute(['id' => $reservationId]);
            $res = $stmtRes->fetch();

            if (!$res) {
                throw new RuntimeException("Reservation not found.");
            }
            if ($res['status'] !== 'pending') {
                throw new RuntimeException("Only pending reservations can be approved. Current status: {$res['status']}");
            }

            $requestedQty = (int) $res['requested_quantity'];
            $qtyToApprove = ($approvedQty === null || $approvedQty <= 0) ? $requestedQty : min($approvedQty, $requestedQty);

            // Fetch eligible batches with row locks
            $sqlBatches = "
                SELECT 
                    id, batch_number, current_quantity, reserved_quantity, quarantined_quantity, expiry_date,
                    GREATEST(0, CAST(current_quantity AS SIGNED) - CAST(quarantined_quantity AS SIGNED) - CAST(reserved_quantity AS SIGNED)) AS available_units
                FROM medicine_batches
                WHERE medicine_id = :med_id
                  AND expiry_date > CURRENT_DATE()
                  AND batch_status NOT IN ('expired', 'quarantined', 'depleted')
                  AND GREATEST(0, CAST(current_quantity AS SIGNED) - CAST(quarantined_quantity AS SIGNED) - CAST(reserved_quantity AS SIGNED)) > 0
                ORDER BY expiry_date ASC, id ASC
                FOR UPDATE
            ";
            $stmtB = $pdo->prepare($sqlBatches);
            $stmtB->execute(['med_id' => $res['medicine_id']]);
            $batches = $stmtB->fetchAll();

            $totalAvailable = array_sum(array_column($batches, 'available_units'));
            if ($totalAvailable < $qtyToApprove) {
                throw new RuntimeException("Insufficient available stock to approve. Total eligible unreserved available: {$totalAvailable} units.");
            }

            // FEFO Allocation
            $remainingToAllocate = $qtyToApprove;
            $allocations = [];

            foreach ($batches as $batch) {
                if ($remainingToAllocate <= 0) {
                    break;
                }
                $avail = (int) $batch['available_units'];
                $alloc = min($avail, $remainingToAllocate);

                // Update batch reserved quantity
                $stmtUpBatch = $pdo->prepare("
                    UPDATE medicine_batches 
                    SET reserved_quantity = reserved_quantity + :alloc 
                    WHERE id = :id
                ");
                $stmtUpBatch->execute(['alloc' => $alloc, 'id' => $batch['id']]);

                // Record allocation
                $stmtAlloc = $pdo->prepare("
                    INSERT INTO reservation_allocations (reservation_id, batch_id, allocated_quantity, status)
                    VALUES (:res_id, :batch_id, :qty, 'reserved')
                ");
                $stmtAlloc->execute([
                    'res_id'   => $reservationId,
                    'batch_id' => $batch['id'],
                    'qty'      => $alloc
                ]);

                $allocations[] = [
                    'batch_id' => $batch['id'],
                    'batch_number' => $batch['batch_number'],
                    'allocated' => $alloc
                ];

                $remainingToAllocate -= $alloc;
            }

            // Determine status
            $newStatus = ($qtyToApprove < $requestedQty) ? 'partially_approved' : 'approved';
            $timeoutHours = 24; // Default 24 hours reservation window
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$timeoutHours} hours"));

            $stmtUpRes = $pdo->prepare("
                UPDATE reservations 
                SET status = :status,
                    approved_quantity = :approved_qty,
                    approver_id = :approver_id,
                    approved_at = NOW(),
                    expires_at = :expires_at
                WHERE id = :id
            ");
            $stmtUpRes->execute([
                'status'       => $newStatus,
                'approved_qty' => $qtyToApprove,
                'approver_id'  => $approverId,
                'expires_at'   => $expiresAt,
                'id'           => $reservationId
            ]);

            // History
            $stmtHist = $pdo->prepare("
                INSERT INTO reservation_history (reservation_id, action, actor_id, notes)
                VALUES (:res_id, :action, :actor_id, :notes)
            ");
            $stmtHist->execute([
                'res_id'   => $reservationId,
                'action'   => strtoupper($newStatus),
                'actor_id' => $approverId,
                'notes'    => "Approved {$qtyToApprove} of {$requestedQty} units via FEFO allocation."
            ]);

            // Notify requesting user
            $stmtNotif = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, link_url)
                VALUES (:user_id, 'reservation_decision', :title, :message, :link)
            ");
            $stmtNotif->execute([
                'user_id' => $res['requesting_user_id'],
                'title'   => "Reservation {$res['request_number']} {$newStatus}",
                'message' => "{$qtyToApprove} units of {$res['brand_name']} approved and reserved until {$expiresAt}.",
                'link'    => "/reservations.html?id={$reservationId}"
            ]);

            AuditLogger::log('RESERVATION_APPROVED', 'Reservation', $reservationId, null, [
                'approved_quantity' => $qtyToApprove,
                'allocations' => $allocations,
                'expires_at' => $expiresAt
            ], $approverId);

            Database::commit();
            return [
                'status' => $newStatus,
                'approved_quantity' => $qtyToApprove,
                'allocations' => $allocations,
                'expires_at' => $expiresAt
            ];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * Pharmacist rejects reservation
     */
    public static function rejectRequest(int $reservationId, string $reason, int $approverId): array
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            $stmtRes = $pdo->prepare("SELECT * FROM reservations WHERE id = :id FOR UPDATE");
            $stmtRes->execute(['id' => $reservationId]);
            $res = $stmtRes->fetch();

            if (!$res) {
                throw new RuntimeException("Reservation not found.");
            }
            if ($res['status'] !== 'pending') {
                throw new RuntimeException("Only pending reservations can be rejected.");
            }

            $stmtUp = $pdo->prepare("
                UPDATE reservations 
                SET status = 'rejected',
                    approver_id = :approver_id,
                    rejection_reason = :reason,
                    approved_at = NOW()
                WHERE id = :id
            ");
            $stmtUp->execute([
                'approver_id' => $approverId,
                'reason'      => $reason,
                'id'          => $reservationId
            ]);

            // History
            $stmtHist = $pdo->prepare("
                INSERT INTO reservation_history (reservation_id, action, actor_id, notes)
                VALUES (:res_id, 'REJECTED', :actor_id, :notes)
            ");
            $stmtHist->execute([
                'res_id'   => $reservationId,
                'actor_id' => $approverId,
                'notes'    => "Rejected: " . $reason
            ]);

            // Notify user
            $stmtNotif = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, link_url)
                VALUES (:user_id, 'reservation_decision', :title, :message, :link)
            ");
            $stmtNotif->execute([
                'user_id' => $res['requesting_user_id'],
                'title'   => "Reservation {$res['request_number']} Rejected",
                'message' => "Reason: {$reason}",
                'link'    => "/reservations.html?id={$reservationId}"
            ]);

            AuditLogger::log('RESERVATION_REJECTED', 'Reservation', $reservationId, null, ['reason' => $reason], $approverId);

            Database::commit();
            return ['status' => 'rejected', 'reason' => $reason];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * Dispense and issue reserved stock to the department
     */
    public static function issueStock(int $reservationId, int $issuerId): array
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            $stmtRes = $pdo->prepare("SELECT * FROM reservations WHERE id = :id FOR UPDATE");
            $stmtRes->execute(['id' => $reservationId]);
            $res = $stmtRes->fetch();

            if (!$res) {
                throw new RuntimeException("Reservation not found.");
            }
            if (!in_array($res['status'], ['approved', 'partially_approved'], true)) {
                throw new RuntimeException("Only approved reservations can be issued. Current status: {$res['status']}");
            }

            // Fetch allocations
            $stmtAlloc = $pdo->prepare("
                SELECT * FROM reservation_allocations 
                WHERE reservation_id = :id AND status = 'reserved'
                FOR UPDATE
            ");
            $stmtAlloc->execute(['id' => $reservationId]);
            $allocations = $stmtAlloc->fetchAll();

            if (empty($allocations)) {
                throw new RuntimeException("No active allocations found for this reservation.");
            }

            $totalIssued = 0;
            foreach ($allocations as $al) {
                $batchId = (int) $al['batch_id'];
                $qty = (int) $al['allocated_quantity'];

                // Lock batch
                $stmtB = $pdo->prepare("SELECT current_quantity, reserved_quantity, medicine_id FROM medicine_batches WHERE id = :id FOR UPDATE");
                $stmtB->execute(['id' => $batchId]);
                $b = $stmtB->fetch();

                if (!$b) {
                    throw new RuntimeException("Batch #{$batchId} not found.");
                }

                $curQty = (int) $b['current_quantity'];
                $resQty = (int) $b['reserved_quantity'];

                if ($curQty < $qty || $resQty < $qty) {
                    throw new RuntimeException("Stock inconsistency on batch #{$batchId}. Cannot issue {$qty} units.");
                }

                $newCur = $curQty - $qty;
                $newRes = $resQty - $qty;
                $batchStatus = $newCur === 0 ? 'depleted' : 'active';

                // Update batch
                $stmtUpB = $pdo->prepare("UPDATE medicine_batches SET current_quantity = :cur, reserved_quantity = :res, batch_status = :status WHERE id = :id");
                $stmtUpB->execute(['cur' => $newCur, 'res' => $newRes, 'status' => $batchStatus, 'id' => $batchId]);

                // Update allocation status
                $stmtUpAl = $pdo->prepare("UPDATE reservation_allocations SET status = 'issued' WHERE id = :id");
                $stmtUpAl->execute(['id' => $al['id']]);

                // Immutable Stock Movement
                $stmtMov = $pdo->prepare("
                    INSERT INTO stock_movements (
                        batch_id, medicine_id, movement_type, quantity,
                        balance_before, balance_after, reference_type, reference_id,
                        reason, user_id
                    ) VALUES (
                        :batch_id, :med_id, 'issue', :qty,
                        :before, :after, 'reservation', :res_id,
                        :reason, :user_id
                    )
                ");
                $stmtMov->execute([
                    'batch_id' => $batchId,
                    'med_id'   => $b['medicine_id'],
                    'qty'      => -$qty,
                    'before'   => $curQty,
                    'after'    => $newCur,
                    'res_id'   => $reservationId,
                    'reason'   => "Dispensed against reservation {$res['request_number']}",
                    'user_id'  => $issuerId
                ]);

                $totalIssued += $qty;
            }

            // Update reservation status to issued
            $stmtUpRes = $pdo->prepare("
                UPDATE reservations 
                SET status = 'issued',
                    issued_quantity = :issued_qty,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpRes->execute(['issued_qty' => $totalIssued, 'id' => $reservationId]);

            // History
            $stmtHist = $pdo->prepare("
                INSERT INTO reservation_history (reservation_id, action, actor_id, notes)
                VALUES (:res_id, 'ISSUED', :actor_id, :notes)
            ");
            $stmtHist->execute([
                'res_id'   => $reservationId,
                'actor_id' => $issuerId,
                'notes'    => "Successfully dispensed {$totalIssued} units to department."
            ]);

            AuditLogger::log('RESERVATION_ISSUED', 'Reservation', $reservationId, null, ['issued_quantity' => $totalIssued], $issuerId);

            Database::commit();
            return ['status' => 'issued', 'issued_quantity' => $totalIssued];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * Cancel an active or pending reservation and release reserved stock back to availability
     */
    public static function cancelRequest(int $reservationId, string $reason, int $actorId): array
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            $stmtRes = $pdo->prepare("SELECT * FROM reservations WHERE id = :id FOR UPDATE");
            $stmtRes->execute(['id' => $reservationId]);
            $res = $stmtRes->fetch();

            if (!$res) {
                throw new RuntimeException("Reservation not found.");
            }
            if (in_array($res['status'], ['issued', 'cancelled', 'expired'], true)) {
                throw new RuntimeException("Cannot cancel reservation with status: {$res['status']}");
            }

            // If approved/partially_approved, release batch reservations
            if (in_array($res['status'], ['approved', 'partially_approved'], true)) {
                $stmtAlloc = $pdo->prepare("SELECT * FROM reservation_allocations WHERE reservation_id = :id AND status = 'reserved' FOR UPDATE");
                $stmtAlloc->execute(['id' => $reservationId]);
                $allocations = $stmtAlloc->fetchAll();

                foreach ($allocations as $al) {
                    $stmtRelB = $pdo->prepare("
                        UPDATE medicine_batches 
                        SET reserved_quantity = GREATEST(0, CAST(reserved_quantity AS SIGNED) - :qty)
                        WHERE id = :batch_id
                    ");
                    $stmtRelB->execute(['qty' => $al['allocated_quantity'], 'batch_id' => $al['batch_id']]);

                    $stmtUpAl = $pdo->prepare("UPDATE reservation_allocations SET status = 'released' WHERE id = :id");
                    $stmtUpAl->execute(['id' => $al['id']]);
                }
            }

            $stmtUpRes = $pdo->prepare("UPDATE reservations SET status = 'cancelled', rejection_reason = :reason WHERE id = :id");
            $stmtUpRes->execute(['reason' => $reason, 'id' => $reservationId]);

            // History
            $stmtHist = $pdo->prepare("
                INSERT INTO reservation_history (reservation_id, action, actor_id, notes)
                VALUES (:res_id, 'CANCELLED', :actor_id, :notes)
            ");
            $stmtHist->execute([
                'res_id'   => $reservationId,
                'actor_id' => $actorId,
                'notes'    => "Cancelled: {$reason}"
            ]);

            AuditLogger::log('RESERVATION_CANCELLED', 'Reservation', $reservationId, null, ['reason' => $reason], $actorId);

            Database::commit();
            return ['status' => 'cancelled', 'reason' => $reason];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
