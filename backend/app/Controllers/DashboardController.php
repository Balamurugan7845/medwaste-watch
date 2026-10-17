<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ExpiryService;
use App\Services\InventoryService;
use PDO;

class DashboardController
{
    public static function summary(Request $request): void
    {
        $pdo = Database::getConnection();

        // 1. Expiry metrics
        $expirySummary = ExpiryService::getExpirySummary();

        // 2. Medicine & Stock Metrics
        $stockSummary = InventoryService::getMedicineStockSummary();
        $totalActiveMedicines = count($stockSummary);
        $totalOnHandUnits = array_sum(array_column($stockSummary, 'physical_on_hand'));
        $totalAvailableToReserve = array_sum(array_column($stockSummary, 'available_to_reserve'));
        $totalQuarantinedUnits = array_sum(array_column($stockSummary, 'total_quarantined'));

        $lowStockMedicines = array_filter($stockSummary, fn($m) => $m['stock_status'] === 'low_stock');
        $outOfStockMedicines = array_filter($stockSummary, fn($m) => $m['stock_status'] === 'out_of_stock');

        // 3. Pending Reservations Count
        $pendingResCount = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();

        // 4. Category breakdown for chart
        $stmtCat = $pdo->query("
            SELECT 
                c.name AS category_name,
                COUNT(m.id) AS medicine_count,
                COALESCE(SUM(b.current_quantity), 0) AS total_units
            FROM medicine_categories c
            LEFT JOIN medicines m ON c.id = m.category_id AND m.is_active = 1
            LEFT JOIN medicine_batches b ON m.id = b.medicine_id
            GROUP BY c.id, c.name
            ORDER BY total_units DESC
        ");
        $categoryBreakdown = $stmtCat->fetchAll();

        // 5. Critical & Warning Expiry Batches (Upcoming top 6)
        $stmtExp = $pdo->query("
            SELECT 
                b.id,
                b.batch_number,
                b.expiry_date,
                b.current_quantity,
                m.brand_name,
                m.strength,
                loc.name AS location_name,
                DATEDIFF(b.expiry_date, CURRENT_DATE()) AS days_remaining
            FROM medicine_batches b
            JOIN medicines m ON b.medicine_id = m.id
            JOIN storage_locations loc ON b.storage_location_id = loc.id
            WHERE b.current_quantity > 0 AND b.expiry_date > CURRENT_DATE()
            ORDER BY b.expiry_date ASC
            LIMIT 6
        ");
        $upcomingExpiries = $stmtExp->fetchAll();

        // 6. Recent Stock Movements (top 6)
        $stmtMov = $pdo->query("
            SELECT 
                sm.id,
                sm.movement_type,
                sm.quantity,
                sm.balance_after,
                sm.reason,
                sm.created_at,
                m.brand_name,
                b.batch_number,
                u.full_name AS user_name
            FROM stock_movements sm
            JOIN medicines m ON sm.medicine_id = m.id
            JOIN medicine_batches b ON sm.batch_id = b.id
            JOIN users u ON sm.user_id = u.id
            ORDER BY sm.created_at DESC
            LIMIT 6
        ");
        $recentMovements = $stmtMov->fetchAll();

        // 7. Recent Reservation Activities
        $stmtRes = $pdo->query("
            SELECT 
                r.id,
                r.request_number,
                r.requested_quantity,
                r.priority,
                r.status,
                r.created_at,
                d.name AS department_name,
                m.brand_name
            FROM reservations r
            JOIN departments d ON r.department_id = d.id
            JOIN medicines m ON r.medicine_id = m.id
            ORDER BY r.created_at DESC
            LIMIT 5
        ");
        $recentReservations = $stmtRes->fetchAll();

        Response::success([
            'kpis' => [
                'total_medicines'           => $totalActiveMedicines,
                'total_on_hand_units'       => (int) $totalOnHandUnits,
                'available_to_reserve'      => (int) $totalAvailableToReserve,
                'low_stock_count'           => count($lowStockMedicines),
                'out_of_stock_count'        => count($outOfStockMedicines),
                'urgent_expiry_count'       => (int) $expirySummary['urgent_batches_count'],
                'warning_expiry_count'      => (int) $expirySummary['warning_batches_count'],
                'expired_batches_count'     => (int) $expirySummary['expired_batches_count'],
                'quarantined_units'         => (int) $totalQuarantinedUnits,
                'pending_reservations'      => $pendingResCount
            ],
            'expiry_distribution' => [
                'urgent'  => (int) $expirySummary['urgent_batches_count'],
                'warning' => (int) $expirySummary['warning_batches_count'],
                'safe'    => (int) $expirySummary['safe_batches_count'],
                'expired' => (int) $expirySummary['expired_batches_count']
            ],
            'category_breakdown'   => $categoryBreakdown,
            'upcoming_expiries'    => $upcomingExpiries,
            'low_stock_medicines'  => array_slice($lowStockMedicines, 0, 6),
            'recent_movements'     => $recentMovements,
            'recent_reservations'  => $recentReservations
        ]);
    }
}
