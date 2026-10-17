<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Core\AuditLogger;
use PDO;
use RuntimeException;

class AlternativeService
{
    /**
     * Look up primary medicine and all clinically approved alternatives with live batch availability
     */
    public static function getAlternativesForMedicine(int $medicineId): array
    {
        $pdo = Database::getConnection();

        // 1. Fetch Primary Medicine
        $stmtMed = $pdo->prepare("
            SELECT 
                m.*,
                c.name AS category_name,
                COALESCE(SUM(b.current_quantity), 0) AS physical_on_hand,
                COALESCE(SUM(CASE 
                    WHEN b.expiry_date > CURRENT_DATE() AND b.batch_status != 'quarantined' AND b.batch_status != 'expired'
                    THEN GREATEST(0, CAST(b.current_quantity AS SIGNED) - CAST(b.quarantined_quantity AS SIGNED) - CAST(b.reserved_quantity AS SIGNED))
                    ELSE 0 
                END), 0) AS available_to_reserve
            FROM medicines m
            JOIN medicine_categories c ON m.category_id = c.id
            LEFT JOIN medicine_batches b ON m.id = b.medicine_id
            WHERE m.id = :id
            GROUP BY m.id, c.name
        ");
        $stmtMed->execute(['id' => $medicineId]);
        $primary = $stmtMed->fetch();

        if (!$primary) {
            throw new RuntimeException("Primary medicine not found.");
        }

        // 2. Fetch Configured Therapeutic Alternatives
        $sqlAlt = "
            SELECT 
                alt.id AS mapping_id,
                alt.therapeutic_equivalence_notes,
                alt.requires_clinical_approval,
                m.id AS alternative_id,
                m.sku,
                m.brand_name,
                m.generic_name,
                m.strength,
                m.dosage_form,
                m.manufacturer,
                m.unit_of_measure,
                m.reorder_level,
                c.name AS category_name,
                COALESCE(SUM(b.current_quantity), 0) AS physical_on_hand,
                COALESCE(SUM(CASE 
                    WHEN b.expiry_date > CURRENT_DATE() AND b.batch_status != 'quarantined' AND b.batch_status != 'expired'
                    THEN GREATEST(0, CAST(b.current_quantity AS SIGNED) - CAST(b.quarantined_quantity AS SIGNED) - CAST(b.reserved_quantity AS SIGNED))
                    ELSE 0 
                END), 0) AS available_to_reserve
            FROM alternative_medicines alt
            JOIN medicines m ON alt.alternative_medicine_id = m.id
            JOIN medicine_categories c ON m.category_id = c.id
            LEFT JOIN medicine_batches b ON m.id = b.medicine_id
            WHERE alt.primary_medicine_id = :prim_id
              AND alt.is_active = 1
              AND m.is_active = 1
            GROUP BY alt.id, alt.therapeutic_equivalence_notes, alt.requires_clinical_approval, m.id, c.name
            ORDER BY available_to_reserve DESC, m.brand_name ASC
        ";

        $stmtAlt = $pdo->prepare($sqlAlt);
        $stmtAlt->execute(['prim_id' => $medicineId]);
        $alternatives = $stmtAlt->fetchAll();

        // For each alternative, attach eligible batches (FEFO)
        foreach ($alternatives as &$alt) {
            $alt['batches'] = InventoryService::getEligibleBatchesFEFO((int)$alt['alternative_id']);
        }

        return [
            'primary_medicine' => $primary,
            'alternatives'     => $alternatives,
            'count'            => count($alternatives)
        ];
    }

    /**
     * Doctor or authorized clinical approver submits substitution sign-off
     */
    public static function approveSubstitution(array $data, int $doctorId): array
    {
        $pdo = Database::getConnection();
        Database::beginTransaction();

        try {
            $stmt = $pdo->prepare("
                INSERT INTO alternative_approvals (
                    reservation_id, primary_medicine_id, alternative_medicine_id,
                    doctor_id, status, clinical_justification, approved_at
                ) VALUES (
                    :res_id, :prim_id, :alt_id,
                    :doc_id, :status, :justification, NOW()
                )
            ");

            $status = in_array($data['status'] ?? '', ['approved', 'rejected'], true) ? $data['status'] : 'approved';

            $stmt->execute([
                'res_id'        => $data['reservation_id'],
                'prim_id'       => $data['primary_medicine_id'],
                'alt_id'        => $data['alternative_medicine_id'],
                'doc_id'        => $doctorId,
                'status'        => $status,
                'justification' => $data['clinical_justification']
            ]);
            $approvalId = (int) $pdo->lastInsertId();

            AuditLogger::log('ALTERNATIVE_SUBSTITUTION_' . strtoupper($status), 'AlternativeApproval', $approvalId, null, [
                'reservation_id' => $data['reservation_id'],
                'primary_medicine_id' => $data['primary_medicine_id'],
                'alternative_medicine_id' => $data['alternative_medicine_id'],
                'status' => $status,
                'justification' => $data['clinical_justification']
            ], $doctorId);

            Database::commit();
            return ['id' => $approvalId, 'status' => $status];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
