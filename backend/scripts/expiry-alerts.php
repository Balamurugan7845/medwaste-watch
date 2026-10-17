<?php

declare(strict_types=1);

// MedWaste Watch — Periodic Expiry & Stock Alert Daemon
// Can be executed via Windows Task Scheduler or cron

require_once __DIR__ . '/../app/Config/Config.php';
require_once __DIR__ . '/../app/Config/Database.php';
require_once __DIR__ . '/../app/Core/AuditLogger.php';
require_once __DIR__ . '/../app/Services/ExpiryService.php';

use App\Config\Database;
use App\Core\AuditLogger;
use App\Services\ExpiryService;

echo "[" . date('Y-m-d H:i:s') . "] Starting MedWaste Watch Expiry Alert Scan...\n";

$pdo = Database::getConnection();
$thresholds = ExpiryService::getThresholds();
$urgentDays = $thresholds['urgent_days'];
$warningDays = $thresholds['warning_days'];

// 1. Fetch batches nearing expiry
$urgentBatches = ExpiryService::getExpiryList(['urgency' => 'urgent']);
$warningBatches = ExpiryService::getExpiryList(['urgency' => 'warning']);
$expiredBatches = ExpiryService::getExpiryList(['urgency' => 'expired']);

// Pharmacist role
$pharmRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'pharmacist'")->fetchColumn();

// Auto-quarantine expired batches if policy enabled
$autoQuarantine = (bool) $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'quarantine_auto_on_expiry'")->fetchColumn();

if ($autoQuarantine) {
    foreach ($expiredBatches as $exp) {
        if ($exp['batch_status'] !== 'quarantined' && $exp['batch_status'] !== 'expired') {
            $pdo->prepare("
                UPDATE medicine_batches 
                SET batch_status = 'expired', quarantined_quantity = current_quantity 
                WHERE id = :id
            ")->execute(['id' => $exp['batch_id']]);

            echo "Auto-quarantined expired batch: {$exp['batch_number']} ({$exp['brand_name']})\n";
        }
    }
}

// Generate notification for urgent expiries (prevent duplicate if notified in last 24h)
foreach ($urgentBatches as $u) {
    $existing = $pdo->prepare("
        SELECT id FROM notifications 
        WHERE type = 'critical_expiry' 
          AND title LIKE :title
          AND created_at >= NOW() - INTERVAL 24 HOUR
    ");
    $existing->execute(['title' => "%{$u['batch_number']}%"]);
    if (!$existing->fetch()) {
        $stmtNotif = $pdo->prepare("
            INSERT INTO notifications (target_role_id, type, title, message, link_url)
            VALUES (:rid, 'critical_expiry', :title, :msg, :link)
        ");
        $stmtNotif->execute([
            'rid'   => $pharmRoleId,
            'title' => "Critical Expiry Alert: {$u['brand_name']} ({$u['batch_number']})",
            'msg'   => "Batch {$u['batch_number']} ({$u['current_quantity']} units) expires in {$u['days_remaining']} days. Immediate FEFO dispatch recommended.",
            'link'  => "/expiry.html?filter=urgent"
        ]);
        echo "Created urgent expiry notification for batch {$u['batch_number']}\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Expiry Scan Completed.\n";
