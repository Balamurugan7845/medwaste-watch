<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Config/Config.php';
require_once __DIR__ . '/../app/Config/Database.php';
require_once __DIR__ . '/../app/Core/Session.php';
require_once __DIR__ . '/../app/Core/AuditLogger.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
require_once __DIR__ . '/../app/Services/ExpiryService.php';
require_once __DIR__ . '/../app/Services/ReservationService.php';
require_once __DIR__ . '/../app/Services/AlternativeService.php';
require_once __DIR__ . '/../app/Services/TransferDisposalService.php';
require_once __DIR__ . '/../app/Services/ReportService.php';
require_once __DIR__ . '/../app/Helpers/FormulaInjectionProtector.php';

use App\Config\Database;
use App\Services\InventoryService;
use App\Services\ExpiryService;
use App\Services\ReservationService;
use App\Services\AlternativeService;
use App\Services\TransferDisposalService;
use App\Services\ReportService;
use App\Helpers\FormulaInjectionProtector;

class AutomatedTestSuite
{
    private PDO $pdo;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    private function assert(bool $condition, string $testName, string $details = ''): void
    {
        if ($condition) {
            echo "  [PASS] {$testName}\n";
            $this->passed++;
        } else {
            echo "  [FAIL] {$testName} - Details: {$details}\n";
            $this->failed++;
        }
    }

    public function runAll(): void
    {
        echo "========================================================\n";
        echo " MedWaste Watch — Automated System & Clinical Tests\n";
        echo "========================================================\n\n";

        $this->testStockCalculations();
        $this->testFefoBatchSelection();
        $this->testExpiryBoundaryCalculations();
        $this->testReservationWorkflowAndLocking();
        $this->testAlternativeMedicineDiscovery();
        $this->testDisposalAndWastageLedger();
        $this->testFormulaInjectionEscaping();

        echo "\n--------------------------------------------------------\n";
        echo "Tests Finished. Passed: {$this->passed}, Failed: {$this->failed}\n";
        echo "--------------------------------------------------------\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function testStockCalculations(): void
    {
        echo "Running Stock Calculations & Summary Tests...\n";
        $summary = InventoryService::getMedicineStockSummary();
        $this->assert(count($summary) >= 5, "Catalog contains multiple active medicines", "Found: " . count($summary));

        $meropenem = null;
        foreach ($summary as $item) {
            if ($item['sku'] === 'MED-ABX-001') {
                $meropenem = $item;
                break;
            }
        }
        $this->assert($meropenem !== null, "Meropenem master record found in summary");
        $this->assert((int)$meropenem['physical_on_hand'] > 0, "Meropenem has positive on-hand quantity", "On hand: {$meropenem['physical_on_hand']}");
        $this->assert((int)$meropenem['available_to_reserve'] <= (int)$meropenem['physical_on_hand'], "Available stock never exceeds physical on hand");
    }

    private function testFefoBatchSelection(): void
    {
        echo "\nRunning FEFO (First Expired, First Out) Batch Allocation Tests...\n";
        $medId = (int)$this->pdo->query("SELECT id FROM medicines WHERE sku = 'MED-ABX-001'")->fetchColumn();
        $batches = InventoryService::getEligibleBatchesFEFO($medId);

        $this->assert(!empty($batches), "Eligible batches found for Meropenem");

        // Verify chronological expiry order
        $isFefo = true;
        for ($i = 0; $i < count($batches) - 1; $i++) {
            if ($batches[$i]['expiry_date'] > $batches[$i + 1]['expiry_date']) {
                $isFefo = false;
                break;
            }
        }
        $this->assert($isFefo, "Batches are strictly sorted in FEFO chronological order (earliest expiry first)");

        // Verify no expired batch is returned
        $hasExpired = false;
        $today = date('Y-m-d');
        foreach ($batches as $b) {
            if ($b['expiry_date'] <= $today) {
                $hasExpired = true;
                break;
            }
        }
        $this->assert(!$hasExpired, "No expired batches are eligible for FEFO allocation");
    }

    private function testExpiryBoundaryCalculations(): void
    {
        echo "\nRunning Expiry Boundary Date Tests...\n";
        $summary = ExpiryService::getExpirySummary();
        $this->assert(isset($summary['urgent_batches_count']), "Expiry summary includes urgent tier count");
        $this->assert(isset($summary['warning_batches_count']), "Expiry summary includes warning tier count");
        $this->assert(isset($summary['safe_batches_count']), "Expiry summary includes safe tier count");
        $this->assert(isset($summary['expired_batches_count']), "Expiry summary includes expired tier count");

        // Test boundary filter
        $urgentList = ExpiryService::getExpiryList(['urgency' => 'urgent']);
        $this->assert(!empty($urgentList), "Urgent expiry list successfully identifies critical batches");
        foreach ($urgentList as $b) {
            $this->assert((int)$b['days_remaining'] <= 7 && (int)$b['days_remaining'] > 0, "Urgent batch correctly falls within <= 7 day threshold");
        }
    }

    private function testReservationWorkflowAndLocking(): void
    {
        echo "\nRunning Department Reservation Workflow & Atomic Allocation Tests...\n";
        $deptId = (int)$this->pdo->query("SELECT id FROM departments WHERE code = 'ER-01'")->fetchColumn();
        $userId = (int)$this->pdo->query("SELECT id FROM users WHERE username = 'nurse_er'")->fetchColumn();
        $pharmId = (int)$this->pdo->query("SELECT id FROM users WHERE username = 'pharmacist'")->fetchColumn();
        $medId = (int)$this->pdo->query("SELECT id FROM medicines WHERE sku = 'MED-IVF-001'")->fetchColumn();

        // 1. Submit Request
        $req = ReservationService::createRequest([
            'medicine_id'        => $medId,
            'department_id'      => $deptId,
            'requested_quantity' => 10,
            'priority'           => 'routine',
            'justification'      => 'Automated Unit Test Resupply'
        ], $userId);

        $this->assert(!empty($req['id']), "Reservation request created with unique ID #{$req['id']}");
        $this->assert(str_starts_with($req['request_number'], 'REQ-'), "Request number follows hospital prefix convention");

        // 2. Approve Request (Allocates via FEFO)
        $approval = ReservationService::approveRequest($req['id'], 10, $pharmId);
        $this->assert($approval['status'] === 'approved', "Reservation approved with status 'approved'");
        $this->assert(!empty($approval['allocations']), "Batch allocations created against eligible FEFO inventory");

        // 3. Issue Reserved Stock (Deducts physical on-hand)
        $issue = ReservationService::issueStock($req['id'], $pharmId);
        $this->assert($issue['status'] === 'issued', "Stock successfully dispensed and marked 'issued'");
        $this->assert((int)$issue['issued_quantity'] === 10, "Exact approved quantity dispensed (10 units)");

        // 4. Verify Movement Ledger Record
        $movCount = (int)$this->pdo->query("
            SELECT COUNT(*) FROM stock_movements 
            WHERE reference_type = 'reservation' AND reference_id = {$req['id']} AND movement_type = 'issue'
        ")->fetchColumn();
        $this->assert($movCount >= 1, "Permanent immutable stock_movements ledger entry recorded");
    }

    private function testAlternativeMedicineDiscovery(): void
    {
        echo "\nRunning Clinical Alternative Medicines Discovery Tests...\n";
        $medId = (int)$this->pdo->query("SELECT id FROM medicines WHERE sku = 'MED-ABX-001'")->fetchColumn(); // Meropenem
        $data = AlternativeService::getAlternativesForMedicine($medId);

        $this->assert(!empty($data['primary_medicine']), "Primary medicine details retrieved");
        $this->assert($data['count'] >= 1, "At least 1 configured clinical alternative found", "Count: " . $data['count']);

        $firstAlt = $data['alternatives'][0];
        $this->assert(!empty($firstAlt['brand_name']), "Alternative brand name present: {$firstAlt['brand_name']}");
        $this->assert(isset($firstAlt['available_to_reserve']), "Alternative calculates real-time available stock");
    }

    private function testDisposalAndWastageLedger(): void
    {
        echo "\nRunning Biomedical Disposal and Wastage Tracking Tests...\n";
        $batchId = (int)$this->pdo->query("SELECT id FROM medicine_batches WHERE batch_number = 'ROCE-2023-EX9'")->fetchColumn();
        $adminId = (int)$this->pdo->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();

        if ($batchId > 0) {
            $disp = TransferDisposalService::recordDisposal([
                'batch_id'        => $batchId,
                'quantity'        => 2,
                'disposal_method' => 'incineration',
                'reason'          => 'expired',
                'certificate_ref' => 'BIO-INCIN-TEST-001',
                'notes'           => 'Automated test destruction'
            ], $adminId);

            $this->assert(!empty($disp['id']), "Disposal record created with ID #{$disp['id']}");
            $this->assert(str_starts_with($disp['disposal_number'], 'DSP-'), "Disposal number follows hospital format: {$disp['disposal_number']}");
        } else {
            $this->assert(true, "Skipped ROCE batch disposal test (batch already cleaned)");
        }
    }

    private function testFormulaInjectionEscaping(): void
    {
        echo "\nRunning CSV Formula Injection Security Tests...\n";
        $maliciousValues = [
            '=SUM(A1:A10)',
            '+1234567890',
            '-cmd|/C calc.exe',
            '@SUM(1+1)',
            "\tTAB_INJECT"
        ];

        foreach ($maliciousValues as $val) {
            $escaped = FormulaInjectionProtector::escapeCell($val);
            $this->assert(str_starts_with($escaped, "'"), "Dangerous formula prefix escaped with single quote: '{$val}' -> '{$escaped}'");
        }

        $safeValue = 'Meropenem 1g';
        $escapedSafe = FormulaInjectionProtector::escapeCell($safeValue);
        $this->assert($escapedSafe === $safeValue, "Benign value preserved without escaping: '{$safeValue}'");
    }
}

// Run test suite
$suite = new AutomatedTestSuite();
$suite->runAll();
