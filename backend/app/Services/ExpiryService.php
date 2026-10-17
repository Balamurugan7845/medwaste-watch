<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Core\AuditLogger;
use PDO;

class ExpiryService
{
    public static function getThresholds(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('expiry_urgent_days', 'expiry_warning_days')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'urgent_days'  => (int) ($settings['expiry_urgent_days'] ?? 7),
            'warning_days' => (int) ($settings['expiry_warning_days'] ?? 30)
        ];
    }

    /**
     * Get all batches with dynamic urgency calculation
     */
    public static function getExpiryList(array $filters = []): array
    {
        $pdo = Database::getConnection();
        $thresholds = self::getThresholds();
        $urgentDays = $thresholds['urgent_days'];
        $warningDays = $thresholds['warning_days'];

        $sql = "
            SELECT 
                b.id AS batch_id,
                b.batch_number,
                b.expiry_date,
                b.current_quantity,
                b.reserved_quantity,
                b.quarantined_quantity,
                b.unit_cost,
                b.batch_status,
                b.storage_location_id,
                loc.name AS location_name,
                loc.temp_zone,
                m.id AS medicine_id,
                m.sku,
                m.brand_name,
                m.generic_name,
                m.strength,
                m.dosage_form,
                m.unit_of_measure,
                c.name AS category_name,
                s.name AS supplier_name,
                DATEDIFF(b.expiry_date, CURRENT_DATE()) AS days_remaining,
                CASE 
                    WHEN b.expiry_date <= CURRENT_DATE() THEN 'expired'
                    WHEN DATEDIFF(b.expiry_date, CURRENT_DATE()) <= {$urgentDays} THEN 'urgent'
                    WHEN DATEDIFF(b.expiry_date, CURRENT_DATE()) <= {$warningDays} THEN 'warning'
                    ELSE 'safe'
                END AS calculated_urgency
            FROM medicine_batches b
            JOIN medicines m ON b.medicine_id = m.id
            JOIN medicine_categories c ON m.category_id = c.id
            JOIN storage_locations loc ON b.storage_location_id = loc.id
            LEFT JOIN suppliers s ON b.supplier_id = s.id
            WHERE b.current_quantity > 0
        ";

        $params = [];

        if (!empty($filters['medicine_id'])) {
            $sql .= " AND m.id = :med_id";
            $params['med_id'] = $filters['medicine_id'];
        }

        if (!empty($filters['location_id'])) {
            $sql .= " AND b.storage_location_id = :loc_id";
            $params['loc_id'] = $filters['location_id'];
        }

        if (!empty($filters['urgency'])) {
            $u = $filters['urgency'];
            if ($u === 'expired') {
                $sql .= " AND b.expiry_date <= CURRENT_DATE()";
            } elseif ($u === 'urgent') {
                $sql .= " AND b.expiry_date > CURRENT_DATE() AND DATEDIFF(b.expiry_date, CURRENT_DATE()) <= :filter_urgent";
                $params['filter_urgent'] = $urgentDays;
            } elseif ($u === 'warning') {
                $sql .= " AND DATEDIFF(b.expiry_date, CURRENT_DATE()) > :warn_low AND DATEDIFF(b.expiry_date, CURRENT_DATE()) <= :warn_high";
                $params['warn_low'] = $urgentDays;
                $params['warn_high'] = $warningDays;
            } elseif ($u === 'safe') {
                $sql .= " AND DATEDIFF(b.expiry_date, CURRENT_DATE()) > :filter_safe";
                $params['filter_safe'] = $warningDays;
            }
        }

        $sql .= " ORDER BY b.expiry_date ASC, b.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get summary counts for KPI cards and distribution charts
     */
    public static function getExpirySummary(): array
    {
        $pdo = Database::getConnection();
        $thresholds = self::getThresholds();
        $urgentDays = $thresholds['urgent_days'];
        $warningDays = $thresholds['warning_days'];

        $sql = "
            SELECT 
                COUNT(CASE WHEN b.expiry_date <= CURRENT_DATE() THEN 1 END) AS expired_batches_count,
                COALESCE(SUM(CASE WHEN b.expiry_date <= CURRENT_DATE() THEN b.current_quantity ELSE 0 END), 0) AS expired_units,
                
                COUNT(CASE WHEN b.expiry_date > CURRENT_DATE() AND DATEDIFF(b.expiry_date, CURRENT_DATE()) <= {$urgentDays} THEN 1 END) AS urgent_batches_count,
                COALESCE(SUM(CASE WHEN b.expiry_date > CURRENT_DATE() AND DATEDIFF(b.expiry_date, CURRENT_DATE()) <= {$urgentDays} THEN b.current_quantity ELSE 0 END), 0) AS urgent_units,
                
                COUNT(CASE WHEN DATEDIFF(b.expiry_date, CURRENT_DATE()) > {$urgentDays} AND DATEDIFF(b.expiry_date, CURRENT_DATE()) <= {$warningDays} THEN 1 END) AS warning_batches_count,
                COALESCE(SUM(CASE WHEN DATEDIFF(b.expiry_date, CURRENT_DATE()) > {$urgentDays} AND DATEDIFF(b.expiry_date, CURRENT_DATE()) <= {$warningDays} THEN b.current_quantity ELSE 0 END), 0) AS warning_units,
                
                COUNT(CASE WHEN DATEDIFF(b.expiry_date, CURRENT_DATE()) > {$warningDays} THEN 1 END) AS safe_batches_count,
                COALESCE(SUM(CASE WHEN DATEDIFF(b.expiry_date, CURRENT_DATE()) > {$warningDays} THEN b.current_quantity ELSE 0 END), 0) AS safe_units
            FROM medicine_batches b
            WHERE b.current_quantity > 0
        ";

        $stmt = $pdo->query($sql);
        $summary = $stmt->fetch();

        return array_merge($summary, $thresholds);
    }
}
