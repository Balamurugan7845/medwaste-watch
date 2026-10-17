<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\FormulaInjectionProtector;
use PDO;

class ReportService
{
    public static function getCurrentStockReport(array $filters = []): array
    {
        $pdo = Database::getConnection();
        $sql = "
            SELECT 
                m.sku,
                m.brand_name,
                m.generic_name,
                c.name AS category,
                m.strength,
                m.dosage_form,
                m.unit_of_measure,
                m.reorder_level,
                COALESCE(SUM(b.current_quantity), 0) AS on_hand_quantity,
                COALESCE(SUM(b.reserved_quantity), 0) AS reserved_quantity,
                COALESCE(SUM(b.quarantined_quantity), 0) AS quarantined_quantity,
                COALESCE(SUM(CASE 
                    WHEN b.expiry_date > CURRENT_DATE() AND b.batch_status NOT IN ('quarantined', 'expired')
                    THEN GREATEST(0, CAST(b.current_quantity AS SIGNED) - CAST(b.quarantined_quantity AS SIGNED) - CAST(b.reserved_quantity AS SIGNED))
                    ELSE 0 
                END), 0) AS available_quantity
            FROM medicines m
            JOIN medicine_categories c ON m.category_id = c.id
            LEFT JOIN medicine_batches b ON m.id = b.medicine_id
            WHERE m.is_active = 1
            GROUP BY m.id, c.name
            ORDER BY m.brand_name ASC
        ";

        return $pdo->query($sql)->fetchAll();
    }

    public static function getBatchWiseReport(array $filters = []): array
    {
        $pdo = Database::getConnection();
        $sql = "
            SELECT 
                b.batch_number,
                m.brand_name,
                m.generic_name,
                m.sku,
                loc.name AS location,
                b.received_date,
                b.expiry_date,
                DATEDIFF(b.expiry_date, CURRENT_DATE()) AS days_to_expiry,
                b.current_quantity,
                b.reserved_quantity,
                b.quarantined_quantity,
                b.unit_cost,
                (b.current_quantity * b.unit_cost) AS total_valuation,
                b.batch_status
            FROM medicine_batches b
            JOIN medicines m ON b.medicine_id = m.id
            JOIN storage_locations loc ON b.storage_location_id = loc.id
            WHERE b.current_quantity > 0
            ORDER BY b.expiry_date ASC
        ";

        return $pdo->query($sql)->fetchAll();
    }

    public static function getWastageReport(array $filters = []): array
    {
        $pdo = Database::getConnection();
        $sql = "
            SELECT 
                d.disposal_number,
                d.disposed_at,
                m.brand_name,
                m.generic_name,
                b.batch_number,
                d.quantity,
                b.unit_cost,
                (d.quantity * b.unit_cost) AS estimated_loss_value,
                d.disposal_method,
                d.reason,
                u.full_name AS authorized_by,
                d.certificate_ref,
                d.notes
            FROM disposal_records d
            JOIN medicines m ON d.medicine_id = m.id
            JOIN medicine_batches b ON d.batch_id = b.id
            JOIN users u ON d.authorized_by = u.id
            ORDER BY d.disposed_at DESC
        ";

        return $pdo->query($sql)->fetchAll();
    }

    public static function getMovementsReport(array $filters = []): array
    {
        $pdo = Database::getConnection();
        $sql = "
            SELECT 
                sm.id,
                sm.created_at,
                m.brand_name,
                b.batch_number,
                sm.movement_type,
                sm.quantity,
                sm.balance_before,
                sm.balance_after,
                sm.reason,
                u.full_name AS performed_by
            FROM stock_movements sm
            JOIN medicines m ON sm.medicine_id = m.id
            JOIN medicine_batches b ON sm.batch_id = b.id
            JOIN users u ON sm.user_id = u.id
            ORDER BY sm.created_at DESC
            LIMIT 500
        ";

        return $pdo->query($sql)->fetchAll();
    }

    public static function generateCsv(string $reportType): array
    {
        switch ($reportType) {
            case 'current_stock':
                $data = self::getCurrentStockReport();
                $headers = ['SKU', 'Brand Name', 'Generic Name', 'Category', 'Strength', 'Form', 'UOM', 'Reorder Level', 'On Hand', 'Reserved', 'Quarantined', 'Available'];
                $filename = "medwaste_stock_inventory_" . date('Ymd_His') . ".csv";
                break;
            case 'batch_wise':
                $data = self::getBatchWiseReport();
                $headers = ['Batch #', 'Brand Name', 'Generic Name', 'SKU', 'Storage Location', 'Received Date', 'Expiry Date', 'Days to Expiry', 'Current Qty', 'Reserved Qty', 'Quarantined Qty', 'Unit Cost (INR)', 'Total Value (INR)', 'Status'];
                $filename = "medwaste_batch_expiry_" . date('Ymd_His') . ".csv";
                break;
            case 'wastage':
                $data = self::getWastageReport();
                $headers = ['Disposal #', 'Date Disposed', 'Brand Name', 'Generic Name', 'Batch #', 'Disposed Qty', 'Unit Cost', 'Loss Value', 'Method', 'Reason', 'Authorized By', 'Certificate Ref', 'Notes'];
                $filename = "medwaste_disposal_wastage_" . date('Ymd_His') . ".csv";
                break;
            case 'movements':
                $data = self::getMovementsReport();
                $headers = ['Movement ID', 'Timestamp', 'Medicine', 'Batch #', 'Type', 'Quantity', 'Balance Before', 'Balance After', 'Reason', 'Performed By'];
                $filename = "medwaste_stock_movements_" . date('Ymd_His') . ".csv";
                break;
            default:
                throw new \InvalidArgumentException("Unsupported report type: {$reportType}");
        }

        $csv = FormulaInjectionProtector::buildCsv($headers, $data);
        return ['filename' => $filename, 'csv' => $csv];
    }
}
