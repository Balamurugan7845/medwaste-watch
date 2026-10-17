<?php

declare(strict_types=1);

// MedWaste Watch — Periodic Reservation Expiration Daemon
// Cleans up uncollected department reservations past their expires_at deadline

require_once __DIR__ . '/../app/Config/Config.php';
require_once __DIR__ . '/../app/Config/Database.php';
require_once __DIR__ . '/../app/Core/AuditLogger.php';

use App\Config\Database;
use App\Core\AuditLogger;

echo "[" . date('Y-m-d H:i:s') . "] Starting Reservation Cleanup Daemon...\n";

$pdo = Database::getConnection();

// Find approved/partially_approved reservations past deadline
$stmt = $pdo->prepare("
    SELECT id, request_number, requesting_user_id 
    FROM reservations 
    WHERE status IN ('approved', 'partially_approved') 
      AND expires_at IS NOT NULL 
      AND expires_at <= NOW()
");
$stmt->execute();
$expiredReservations = $stmt->fetchAll();

foreach ($expiredReservations as $res) {
    Database::beginTransaction();
    try {
        $resId = (int)$res['id'];

        // Release batch allocations
        $stmtAlloc = $pdo->prepare("
            SELECT id, batch_id, allocated_quantity 
            FROM reservation_allocations 
            WHERE reservation_id = :id AND status = 'reserved'
            FOR UPDATE
        ");
        $stmtAlloc->execute(['id' => $resId]);
        $allocations = $stmtAlloc->fetchAll();

        foreach ($allocations as $al) {
            $pdo->prepare("
                UPDATE medicine_batches 
                SET reserved_quantity = GREATEST(0, CAST(reserved_quantity AS SIGNED) - :qty)
                WHERE id = :batch_id
            ")->execute(['qty' => $al['allocated_quantity'], 'batch_id' => $al['batch_id']]);

            $pdo->prepare("UPDATE reservation_allocations SET status = 'released' WHERE id = :id")->execute(['id' => $al['id']]);
        }

        // Update reservation status to expired
        $pdo->prepare("UPDATE reservations SET status = 'expired' WHERE id = :id")->execute(['id' => $resId]);

        // History
        $pdo->prepare("
            INSERT INTO reservation_history (reservation_id, action, actor_id, notes)
            VALUES (:id, 'EXPIRED', 1, 'Auto-expired: pickup window elapsed without dispensing')
        ")->execute(['id' => $resId]);

        // Notification to user
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, link_url)
            VALUES (:uid, 'reservation_decision', :title, :msg, :link)
        ")->execute([
            'uid'   => $res['requesting_user_id'],
            'title' => "Reservation Expired: {$res['request_number']}",
            'msg'   => "Reservation {$res['request_number']} expired because it was not collected within the designated window. Allocated units returned to hospital stock.",
            'link'  => "/reservations.html?id={$resId}"
        ]);

        Database::commit();
        echo "Cleaned up and released expired reservation: {$res['request_number']}\n";
    } catch (\Throwable $e) {
        Database::rollBack();
        echo "Error cleaning reservation #{$res['id']}: " . $e->getMessage() . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Reservation Cleanup Finished.\n";
