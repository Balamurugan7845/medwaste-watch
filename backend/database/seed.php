<?php
/**
 * MedWaste Watch — Database Seeder
 * Populates realistic clinical inventory, roles, permissions, batches, and movements.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Config/Database.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();
    echo "Running database seeder...\n";

    // 1. Roles
    $roles = [
        ['name' => 'Super Administrator', 'slug' => 'super_admin', 'description' => 'Full administrative access to hospital inventory, users, and audit logs'],
        ['name' => 'Pharmacist / Pharmacy Manager', 'slug' => 'pharmacist', 'description' => 'Inventory control, batch registration, FEFO allocation, and reservation approval'],
        ['name' => 'Department Staff / Ward Nurse', 'slug' => 'department_staff', 'description' => 'Submit and track department medicine reservation requests'],
        ['name' => 'Doctor / Clinical Approver', 'slug' => 'doctor', 'description' => 'Clinical authority to approve medicine substitutions and urgent requests'],
        ['name' => 'Auditor / Quality Assurance', 'slug' => 'auditor', 'description' => 'Read-only access to inventory reports, wastage, and compliance audits']
    ];

    $stmtRole = $pdo->prepare("INSERT INTO roles (name, slug, description) VALUES (:name, :slug, :description) ON DUPLICATE KEY UPDATE name=VALUES(name)");
    foreach ($roles as $r) {
        $stmtRole->execute($r);
    }

    // 2. Permissions
    $perms = [
        ['slug' => 'dashboard.view', 'module' => 'Dashboard', 'description' => 'View operational dashboard KPIs'],
        ['slug' => 'medicines.manage', 'module' => 'Medicines', 'description' => 'Create and edit medicine catalog'],
        ['slug' => 'medicines.view', 'module' => 'Medicines', 'description' => 'View medicine catalog'],
        ['slug' => 'batches.manage', 'module' => 'Batches', 'description' => 'Receive and manage batch quantities'],
        ['slug' => 'batches.view', 'module' => 'Batches', 'description' => 'View batch quantities and expiry'],
        ['slug' => 'reservations.request', 'module' => 'Reservations', 'description' => 'Create reservation requests'],
        ['slug' => 'reservations.approve', 'module' => 'Reservations', 'description' => 'Approve or reject reservations'],
        ['slug' => 'reservations.issue', 'module' => 'Reservations', 'description' => 'Dispense and issue reserved stock'],
        ['slug' => 'alternatives.view', 'module' => 'Alternatives', 'description' => 'Search and view clinical alternatives'],
        ['slug' => 'alternatives.approve', 'module' => 'Alternatives', 'description' => 'Clinically authorize medicine substitution'],
        ['slug' => 'inventory.transfer', 'module' => 'Inventory', 'description' => 'Initiate and confirm storage transfers'],
        ['slug' => 'inventory.disposal', 'module' => 'Disposal', 'description' => 'Quarantine and record approved waste disposal'],
        ['slug' => 'reports.view', 'module' => 'Reports', 'description' => 'Generate and export inventory reports'],
        ['slug' => 'users.manage', 'module' => 'Administration', 'description' => 'Manage system users and access roles'],
        ['slug' => 'settings.manage', 'module' => 'Administration', 'description' => 'Configure hospital thresholds and policies'],
        ['slug' => 'audit.view', 'module' => 'Audit', 'description' => 'Review immutable system audit logs']
    ];

    $stmtPerm = $pdo->prepare("INSERT INTO permissions (slug, module, description) VALUES (:slug, :module, :description) ON DUPLICATE KEY UPDATE description=VALUES(description)");
    foreach ($perms as $p) {
        $stmtPerm->execute($p);
    }

    // Map Role Permissions
    $roleMap = $pdo->query("SELECT slug, id FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
    $permMap = $pdo->query("SELECT slug, id FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR);

    $rolePerms = [
        'super_admin' => array_keys($permMap),
        'pharmacist' => [
            'dashboard.view', 'medicines.manage', 'medicines.view', 'batches.manage', 'batches.view',
            'reservations.approve', 'reservations.issue', 'alternatives.view', 'inventory.transfer',
            'inventory.disposal', 'reports.view', 'audit.view'
        ],
        'department_staff' => [
            'dashboard.view', 'medicines.view', 'batches.view', 'reservations.request', 'alternatives.view', 'reports.view'
        ],
        'doctor' => [
            'dashboard.view', 'medicines.view', 'batches.view', 'reservations.request', 'alternatives.view', 'alternatives.approve'
        ],
        'auditor' => [
            'dashboard.view', 'medicines.view', 'batches.view', 'reports.view', 'audit.view'
        ]
    ];

    $stmtRP = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
    foreach ($rolePerms as $rSlug => $pSlugs) {
        if (!isset($roleMap[$rSlug])) continue;
        $rId = $roleMap[$rSlug];
        foreach ($pSlugs as $pSlug) {
            if (isset($permMap[$pSlug])) {
                $stmtRP->execute([$rId, $permMap[$pSlug]]);
            }
        }
    }

    // 3. Departments
    $departments = [
        ['name' => 'Emergency Medicine', 'code' => 'ER-01', 'location' => 'Ground Floor, Trauma Wing', 'phone' => '+91 44 2800 1001'],
        ['name' => 'Intensive Care Unit (ICU)', 'code' => 'ICU-02', 'location' => '2nd Floor, Critical Care Block', 'phone' => '+91 44 2800 1002'],
        ['name' => 'General Medicine Ward', 'code' => 'MED-03', 'location' => '3rd Floor, Tower A', 'phone' => '+91 44 2800 1003'],
        ['name' => 'Surgical Department & OT', 'code' => 'SURG-04', 'location' => '4th Floor, Surgical Complex', 'phone' => '+91 44 2800 1004'],
        ['name' => 'Pediatrics & NICU', 'code' => 'PED-05', 'location' => '2nd Floor, Maternal Wing', 'phone' => '+91 44 2800 1005'],
        ['name' => 'Central Pharmacy', 'code' => 'PHARM-00', 'location' => 'Ground Floor, Logistics Hub', 'phone' => '+91 44 2800 1000']
    ];
    $stmtDept = $pdo->prepare("INSERT INTO departments (name, code, location, phone) VALUES (:name, :code, :location, :phone) ON DUPLICATE KEY UPDATE location=VALUES(location)");
    foreach ($departments as $d) {
        $stmtDept->execute($d);
    }
    $deptMap = $pdo->query("SELECT code, id FROM departments")->fetchAll(PDO::FETCH_KEY_PAIR);

    // 4. Users (Password is 'Password@123' for all demo accounts)
    $passwordHash = password_hash('Password@123', PASSWORD_BCRYPT);
    $users = [
        [
            'username' => 'admin',
            'email' => 'admin@stjude-hospital.org',
            'password_hash' => $passwordHash,
            'full_name' => 'Dr. Robert Vance, MD',
            'role_id' => $roleMap['super_admin'],
            'department_id' => $deptMap['PHARM-00']
        ],
        [
            'username' => 'pharmacist',
            'email' => 'pharmacy.lead@stjude-hospital.org',
            'password_hash' => $passwordHash,
            'full_name' => 'Sarah Jenkins, RPh',
            'role_id' => $roleMap['pharmacist'],
            'department_id' => $deptMap['PHARM-00']
        ],
        [
            'username' => 'nurse_er',
            'email' => 'er.charge.nurse@stjude-hospital.org',
            'password_hash' => $passwordHash,
            'full_name' => 'Michael Chen, RN',
            'role_id' => $roleMap['department_staff'],
            'department_id' => $deptMap['ER-01']
        ],
        [
            'username' => 'nurse_icu',
            'email' => 'icu.nurse@stjude-hospital.org',
            'password_hash' => $passwordHash,
            'full_name' => 'Elena Rostova, RN',
            'role_id' => $roleMap['department_staff'],
            'department_id' => $deptMap['ICU-02']
        ],
        [
            'username' => 'doctor_icu',
            'email' => 'dr.kavita@stjude-hospital.org',
            'password_hash' => $passwordHash,
            'full_name' => 'Dr. Kavita Sharma, MD (Intensivist)',
            'role_id' => $roleMap['doctor'],
            'department_id' => $deptMap['ICU-02']
        ],
        [
            'username' => 'auditor',
            'email' => 'audit.compliance@stjude-hospital.org',
            'password_hash' => $passwordHash,
            'full_name' => 'Marcus Aurelius, CIA',
            'role_id' => $roleMap['auditor'],
            'department_id' => $deptMap['PHARM-00']
        ]
    ];
    $stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role_id, department_id, status) VALUES (:username, :email, :password_hash, :full_name, :role_id, :department_id, 'active') ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), password_hash=VALUES(password_hash)");
    foreach ($users as $u) {
        $stmtUser->execute($u);
    }
    $userMap = $pdo->query("SELECT username, id FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);

    // 5. Medicine Categories
    $categories = [
        ['name' => 'Broad-Spectrum Antibiotics', 'description' => 'Antibacterial agents for systemic bacterial infections'],
        ['name' => 'Analgesics & Antipyretics', 'description' => 'Pain relievers, antipyretics and anti-inflammatory medications'],
        ['name' => 'Cardiovascular & Emergency', 'description' => 'Vasopressors, inotropes, antiarrhythmics and blood pressure regulators'],
        ['name' => 'Anticoagulants & Thrombolytics', 'description' => 'Blood thinners and stroke/DVT prevention therapies'],
        ['name' => 'Respiratory & Bronchodilators', 'description' => 'Nebulizers, emergency asthma inhalers and mucolytics'],
        ['name' => 'Antidiabetics & Endocrine', 'description' => 'Insulins, oral hypoglycemics and hormonal regulators'],
        ['name' => 'Intravenous Fluids & Electrolytes', 'description' => 'Crystalloids, colloidal plasma expanders and electrolyte concentrates']
    ];
    $stmtCat = $pdo->prepare("INSERT INTO medicine_categories (name, description) VALUES (:name, :description) ON DUPLICATE KEY UPDATE description=VALUES(description)");
    foreach ($categories as $c) {
        $stmtCat->execute($c);
    }
    $catMap = $pdo->query("SELECT name, id FROM medicine_categories")->fetchAll(PDO::FETCH_KEY_PAIR);

    // 6. Suppliers
    $suppliers = [
        ['name' => 'Apex Healthcare Pharmaceuticals', 'contact_person' => 'Rajesh Mehra', 'email' => 'orders@apexhealth.in', 'phone' => '+91 98401 22334', 'address' => 'Plot 45, Guindy Industrial Estate, Chennai'],
        ['name' => 'BioCure Global Life Sciences', 'contact_person' => 'Amanda Miller', 'email' => 'hospitals@biocure.com', 'phone' => '+91 98402 33445', 'address' => '78 Peenya Tech Zone, Bengaluru'],
        ['name' => 'MediShield Clinical Supplies', 'contact_person' => 'Vikram Singhania', 'email' => 'supply@medishield.co.in', 'phone' => '+91 98403 44556', 'address' => '12 Okhla Phase III, New Delhi']
    ];
    $stmtSup = $pdo->prepare("INSERT INTO suppliers (name, contact_person, email, phone, address) VALUES (:name, :contact_person, :email, :phone, :address) ON DUPLICATE KEY UPDATE contact_person=VALUES(contact_person)");
    foreach ($suppliers as $s) {
        $stmtSup->execute($s);
    }
    $supMap = $pdo->query("SELECT name, id FROM suppliers")->fetchAll(PDO::FETCH_KEY_PAIR);

    // 7. Storage Locations
    $locations = [
        ['name' => 'Main Pharmacy Automated Vault', 'type' => 'pharmacy_main', 'temp_zone' => 'room_temp'],
        ['name' => 'Cold Chain Vaccine & Biologicals Fridge', 'type' => 'cold_storage', 'temp_zone' => 'refrigerated'],
        ['name' => 'Emergency Room Pyxis Satellite', 'type' => 'er_satellite', 'temp_zone' => 'room_temp'],
        ['name' => 'ICU Bedside Emergency Cart', 'type' => 'icu_ward', 'temp_zone' => 'room_temp'],
        ['name' => 'Quarantine & Damaged Goods Locker', 'type' => 'quarantine', 'temp_zone' => 'room_temp']
    ];
    $stmtLoc = $pdo->prepare("INSERT INTO storage_locations (name, type, temp_zone) VALUES (:name, :type, :temp_zone) ON DUPLICATE KEY UPDATE temp_zone=VALUES(temp_zone)");
    foreach ($locations as $l) {
        $stmtLoc->execute($l);
    }
    $locMap = $pdo->query("SELECT name, id FROM storage_locations")->fetchAll(PDO::FETCH_KEY_PAIR);

    // 8. Medicines Catalog
    $medicines = [
        [
            'sku' => 'MED-ABX-001',
            'brand_name' => 'Meronem',
            'generic_name' => 'Meropenem IV Injection',
            'strength' => '1g Vial',
            'dosage_form' => 'injection',
            'category_id' => $catMap['Broad-Spectrum Antibiotics'],
            'manufacturer' => 'Pfizer Laboratories',
            'unit_of_measure' => 'vial',
            'reorder_level' => 40,
            'description' => 'Ultra-broad-spectrum carbapenem for severe multi-drug resistant nosocomial infections.'
        ],
        [
            'sku' => 'MED-ABX-002',
            'brand_name' => 'Tienam',
            'generic_name' => 'Imipenem / Cilastatin IV',
            'strength' => '500mg / 500mg',
            'dosage_form' => 'injection',
            'category_id' => $catMap['Broad-Spectrum Antibiotics'],
            'manufacturer' => 'Merck Sharp & Dohme',
            'unit_of_measure' => 'vial',
            'reorder_level' => 30,
            'description' => 'Therapeutic alternative carbapenem antibiotic for polymicrobial intra-abdominal and respiratory sepsis.'
        ],
        [
            'sku' => 'MED-ABX-003',
            'brand_name' => 'Rocephin',
            'generic_name' => 'Ceftriaxone Sodium',
            'strength' => '1g Powder for Solution',
            'dosage_form' => 'injection',
            'category_id' => $catMap['Broad-Spectrum Antibiotics'],
            'manufacturer' => 'Roche Pharma',
            'unit_of_measure' => 'vial',
            'reorder_level' => 50,
            'description' => 'Third-generation cephalosporin for meningitis, pneumonia, and surgical prophylaxis.'
        ],
        [
            'sku' => 'MED-ABX-004',
            'brand_name' => 'Claforan',
            'generic_name' => 'Cefotaxime Sodium',
            'strength' => '1g Vial',
            'dosage_form' => 'injection',
            'category_id' => $catMap['Broad-Spectrum Antibiotics'],
            'manufacturer' => 'Sanofi Aventis',
            'unit_of_measure' => 'vial',
            'reorder_level' => 35,
            'description' => 'Equivalent 3rd-generation cephalosporin; safe alternative for neonates and hepatic-cleared patients.'
        ],
        [
            'sku' => 'MED-EMG-001',
            'brand_name' => 'Adrenaclick',
            'generic_name' => 'Epinephrine (Adrenaline)',
            'strength' => '1mg/1mL (1:1000)',
            'dosage_form' => 'injection',
            'category_id' => $catMap['Cardiovascular & Emergency'],
            'manufacturer' => 'AstraZeneca',
            'unit_of_measure' => 'ampoule',
            'reorder_level' => 60,
            'description' => 'Crucial ACLS emergency vasopressor for cardiac arrest, severe anaphylaxis, and refractory septic shock.'
        ],
        [
            'sku' => 'MED-EMG-002',
            'brand_name' => 'Levophed',
            'generic_name' => 'Norepinephrine Bitartrate',
            'strength' => '4mg/4mL (1mg/mL)',
            'dosage_form' => 'injection',
            'category_id' => $catMap['Cardiovascular & Emergency'],
            'manufacturer' => 'Pfizer Hospira',
            'unit_of_measure' => 'ampoule',
            'reorder_level' => 45,
            'description' => 'Primary first-line vasopressor in septic shock and acute cardiogenic hypotension.'
        ],
        [
            'sku' => 'MED-THM-001',
            'brand_name' => 'Clexane',
            'generic_name' => 'Enoxaparin Sodium',
            'strength' => '40mg / 0.4mL Prefilled Syringe',
            'dosage_form' => 'injection',
            'category_id' => $catMap['Anticoagulants & Thrombolytics'],
            'manufacturer' => 'Sanofi',
            'unit_of_measure' => 'box',
            'reorder_level' => 30,
            'description' => 'Low-molecular-weight heparin for prophylaxis and treatment of deep vein thrombosis & pulmonary embolism.'
        ],
        [
            'sku' => 'MED-THM-002',
            'brand_name' => 'Heparin Sodium',
            'generic_name' => 'Unfractionated Heparin',
            'strength' => '5000 IU/mL (5mL)',
            'dosage_form' => 'injection',
            'category_id' => $catMap['Anticoagulants & Thrombolytics'],
            'manufacturer' => 'Fresenius Kabi',
            'unit_of_measure' => 'vial',
            'reorder_level' => 25,
            'description' => 'Rapidly reversible systemic anticoagulant used during hemodialysis, bypass, and renal compromise.'
        ],
        [
            'sku' => 'MED-ANL-001',
            'brand_name' => 'Perfalgan',
            'generic_name' => 'Paracetamol (Acetaminophen) IV',
            'strength' => '10mg/mL (100mL Bag)',
            'dosage_form' => 'iv_fluid',
            'category_id' => $catMap['Analgesics & Antipyretics'],
            'manufacturer' => 'Bristol-Myers Squibb',
            'unit_of_measure' => 'bottle',
            'reorder_level' => 75,
            'description' => 'Intravenous non-opioid analgesic and antipyretic for acute postoperative pain and severe pyrexia.'
        ],
        [
            'sku' => 'MED-DIA-001',
            'brand_name' => 'Lantus SoloStar',
            'generic_name' => 'Insulin Glargine',
            'strength' => '100 units/mL (3mL Pen)',
            'dosage_form' => 'injection',
            'category_id' => $catMap['Antidiabetics & Endocrine'],
            'manufacturer' => 'Sanofi',
            'unit_of_measure' => 'box',
            'reorder_level' => 25,
            'description' => 'Long-acting basal insulin analog with steady 24-hour pharmacokinetic profile without pronounced peaks.'
        ],
        [
            'sku' => 'MED-RSP-001',
            'brand_name' => 'Ventolin Inhaler',
            'generic_name' => 'Salbutamol (Albuterol) HFA',
            'strength' => '100mcg / actuation (200 doses)',
            'dosage_form' => 'inhaler',
            'category_id' => $catMap['Respiratory & Bronchodilators'],
            'manufacturer' => 'GlaxoSmithKline',
            'unit_of_measure' => 'box',
            'reorder_level' => 40,
            'description' => 'Short-acting beta2-adrenergic agonist bronchodilator for relief of acute bronchospasm.'
        ],
        [
            'sku' => 'MED-IVF-001',
            'brand_name' => 'Sterile Normal Saline (0.9% NaCl)',
            'generic_name' => 'Sodium Chloride 0.9%',
            'strength' => '500mL Infusion Bag',
            'dosage_form' => 'iv_fluid',
            'category_id' => $catMap['Intravenous Fluids & Electrolytes'],
            'manufacturer' => 'Baxter Healthcare',
            'unit_of_measure' => 'bottle',
            'reorder_level' => 120,
            'description' => 'Isotonic crystalloid fluid resuscitation, medication reconstitution, and intravascular volume restoration.'
        ]
    ];

    $stmtMed = $pdo->prepare("INSERT INTO medicines (sku, brand_name, generic_name, strength, dosage_form, category_id, manufacturer, unit_of_measure, reorder_level, description) VALUES (:sku, :brand_name, :generic_name, :strength, :dosage_form, :category_id, :manufacturer, :unit_of_measure, :reorder_level, :description) ON DUPLICATE KEY UPDATE brand_name=VALUES(brand_name), reorder_level=VALUES(reorder_level)");
    foreach ($medicines as $m) {
        $stmtMed->execute($m);
    }
    $medMap = $pdo->query("SELECT sku, id FROM medicines")->fetchAll(PDO::FETCH_KEY_PAIR);

    // 9. Medicine Batches (Carefully crafted with Critical Expiry, Warning Expiry, Safe, and Expired dates)
    $today = new DateTime();
    $batches = [
        // CRITICAL URGENT (Expiring in 4 days) -> Triggers Critical Expiry Alert!
        [
            'medicine_id' => $medMap['MED-ABX-001'],
            'batch_number' => 'MER-2024-09A',
            'supplier_id' => $supMap['Apex Healthcare Pharmaceuticals'],
            'storage_location_id' => $locMap['Main Pharmacy Automated Vault'],
            'received_quantity' => 100,
            'current_quantity' => 28,
            'reserved_quantity' => 10,
            'quarantined_quantity' => 0,
            'unit_cost' => 1450.00,
            'received_date' => (clone $today)->modify('-11 months')->format('Y-m-d'),
            'expiry_date' => (clone $today)->modify('+4 days')->format('Y-m-d'),
            'batch_status' => 'expiring',
            'notes' => 'FEFO priority: Urgent dispatch recommended before expiration.'
        ],
        // SAFE BATCH of same medicine (Expiring in 14 months)
        [
            'medicine_id' => $medMap['MED-ABX-001'],
            'batch_number' => 'MER-2025-01C',
            'supplier_id' => $supMap['Apex Healthcare Pharmaceuticals'],
            'storage_location_id' => $locMap['Main Pharmacy Automated Vault'],
            'received_quantity' => 150,
            'current_quantity' => 150,
            'reserved_quantity' => 0,
            'quarantined_quantity' => 0,
            'unit_cost' => 1420.00,
            'received_date' => (clone $today)->modify('-1 month')->format('Y-m-d'),
            'expiry_date' => (clone $today)->modify('+420 days')->format('Y-m-d'),
            'batch_status' => 'active',
            'notes' => 'Fresh consignment stored in secure vault.'
        ],
        // WARNING WINDOW (Expiring in 18 days)
        [
            'medicine_id' => $medMap['MED-EMG-001'],
            'batch_number' => 'EPI-2024-03W',
            'supplier_id' => $supMap['BioCure Global Life Sciences'],
            'storage_location_id' => $locMap['Emergency Room Pyxis Satellite'],
            'received_quantity' => 80,
            'current_quantity' => 24,
            'reserved_quantity' => 5,
            'quarantined_quantity' => 0,
            'unit_cost' => 180.00,
            'received_date' => (clone $today)->modify('-10 months')->format('Y-m-d'),
            'expiry_date' => (clone $today)->modify('+18 days')->format('Y-m-d'),
            'batch_status' => 'expiring',
            'notes' => 'ER Satellite warning threshold active.'
        ],
        // SAFE BATCH of Epinephrine
        [
            'medicine_id' => $medMap['MED-EMG-001'],
            'batch_number' => 'EPI-2025-08S',
            'supplier_id' => $supMap['BioCure Global Life Sciences'],
            'storage_location_id' => $locMap['Main Pharmacy Automated Vault'],
            'received_quantity' => 200,
            'current_quantity' => 195,
            'reserved_quantity' => 0,
            'quarantined_quantity' => 0,
            'unit_cost' => 175.00,
            'received_date' => (clone $today)->modify('-20 days')->format('Y-m-d'),
            'expiry_date' => (clone $today)->modify('+500 days')->format('Y-m-d'),
            'batch_status' => 'active',
            'notes' => 'Central reserve stock.'
        ],
        // REFRIGERATED COLD CHAIN BATCH (Insulin Glargine - Safe)
        [
            'medicine_id' => $medMap['MED-DIA-001'],
            'batch_number' => 'INS-2025-CC1',
            'supplier_id' => $supMap['Apex Healthcare Pharmaceuticals'],
            'storage_location_id' => $locMap['Cold Chain Vaccine & Biologicals Fridge'],
            'received_quantity' => 60,
            'current_quantity' => 18, // BELOW REORDER LEVEL of 25! (Low Stock Trigger)
            'reserved_quantity' => 4,
            'quarantined_quantity' => 0,
            'unit_cost' => 680.00,
            'received_date' => (clone $today)->modify('-2 months')->format('Y-m-d'),
            'expiry_date' => (clone $today)->modify('+240 days')->format('Y-m-d'),
            'batch_status' => 'low',
            'notes' => 'Temperature logged continuously at 3.8°C.'
        ],
        // EXPIRED BATCH (Expired 8 days ago) -> Triggers Expired Disposal Workflow!
        [
            'medicine_id' => $medMap['MED-ABX-003'],
            'batch_number' => 'ROCE-2023-EX9',
            'supplier_id' => $supMap['MediShield Clinical Supplies'],
            'storage_location_id' => $locMap['Quarantine & Damaged Goods Locker'],
            'received_quantity' => 50,
            'current_quantity' => 12,
            'reserved_quantity' => 0,
            'quarantined_quantity' => 12,
            'unit_cost' => 220.00,
            'received_date' => (clone $today)->modify('-18 months')->format('Y-m-d'),
            'expiry_date' => (clone $today)->modify('-8 days')->format('Y-m-d'),
            'batch_status' => 'expired',
            'notes' => 'Automatically quarantined on expiry detection. Awaiting biohazard incineration authorization.'
        ],
        // ACTIVE SAFE BATCH OF CEFTRIAXONE
        [
            'medicine_id' => $medMap['MED-ABX-003'],
            'batch_number' => 'ROCE-2025-11B',
            'supplier_id' => $supMap['MediShield Clinical Supplies'],
            'storage_location_id' => $locMap['Main Pharmacy Automated Vault'],
            'received_quantity' => 200,
            'current_quantity' => 180,
            'reserved_quantity' => 20,
            'quarantined_quantity' => 0,
            'unit_cost' => 210.00,
            'received_date' => (clone $today)->modify('-1 month')->format('Y-m-d'),
            'expiry_date' => (clone $today)->modify('+360 days')->format('Y-m-d'),
            'batch_status' => 'active',
            'notes' => 'Standard dispensing lot.'
        ],
        // THERAPEUTIC ALTERNATIVE: TIENAM (Imipenem)
        [
            'medicine_id' => $medMap['MED-ABX-002'],
            'batch_number' => 'TIE-2024-44Z',
            'supplier_id' => $supMap['BioCure Global Life Sciences'],
            'storage_location_id' => $locMap['Main Pharmacy Automated Vault'],
            'received_quantity' => 90,
            'current_quantity' => 85,
            'reserved_quantity' => 0,
            'quarantined_quantity' => 0,
            'unit_cost' => 1380.00,
            'received_date' => (clone $today)->modify('-3 months')->format('Y-m-d'),
            'expiry_date' => (clone $today)->modify('+300 days')->format('Y-m-d'),
            'batch_status' => 'active',
            'notes' => 'Approved substitute for Meropenem shortage.'
        ],
        // CRITICAL ICU VASOPRESSOR: NOREPINEPHRINE (Low Stock & Warning Expiry in 28 days)
        [
            'medicine_id' => $medMap['MED-EMG-002'],
            'batch_number' => 'NOR-2024-ICU',
            'supplier_id' => $supMap['MediShield Clinical Supplies'],
            'storage_location_id' => $locMap['ICU Bedside Emergency Cart'],
            'received_quantity' => 40,
            'current_quantity' => 14, // Below reorder level 45!
            'reserved_quantity' => 6,
            'quarantined_quantity' => 0,
            'unit_cost' => 310.00,
            'received_date' => (clone $today)->modify('-8 months')->format('Y-m-d'),
            'expiry_date' => (clone $today)->modify('+28 days')->format('Y-m-d'),
            'batch_status' => 'low',
            'notes' => 'Critical shortage alert: Request PO replenishment.'
        ],
        // PARACETAMOL IV (High volume stock)
        [
            'medicine_id' => $medMap['MED-ANL-001'],
            'batch_number' => 'PCM-2025-055',
            'supplier_id' => $supMap['Apex Healthcare Pharmaceuticals'],
            'storage_location_id' => $locMap['Main Pharmacy Automated Vault'],
            'received_quantity' => 300,
            'current_quantity' => 240,
            'reserved_quantity' => 15,
            'quarantined_quantity' => 0,
            'unit_cost' => 75.00,
            'received_date' => (clone $today)->modify('-15 days')->format('Y-m-d'),
            'expiry_date' => (clone $today)->modify('+480 days')->format('Y-m-d'),
            'batch_status' => 'active',
            'notes' => 'Fast-moving general ward stock.'
        ],
        // NORMAL SALINE (Bulk fluids)
        [
            'medicine_id' => $medMap['MED-IVF-001'],
            'batch_number' => 'NS-2025-778',
            'supplier_id' => $supMap['BioCure Global Life Sciences'],
            'storage_location_id' => $locMap['Main Pharmacy Automated Vault'],
            'received_quantity' => 500,
            'current_quantity' => 420,
            'reserved_quantity' => 50,
            'quarantined_quantity' => 0,
            'unit_cost' => 45.00,
            'received_date' => (clone $today)->modify('-1 month')->format('Y-m-d'),
            'expiry_date' => (clone $today)->modify('+600 days')->format('Y-m-d'),
            'batch_status' => 'active',
            'notes' => 'Bulk IV hydration pallets.'
        ]
    ];

    $stmtBatch = $pdo->prepare("INSERT INTO medicine_batches (medicine_id, batch_number, supplier_id, storage_location_id, received_quantity, current_quantity, reserved_quantity, quarantined_quantity, unit_cost, received_date, expiry_date, batch_status, notes) VALUES (:medicine_id, :batch_number, :supplier_id, :storage_location_id, :received_quantity, :current_quantity, :reserved_quantity, :quarantined_quantity, :unit_cost, :received_date, :expiry_date, :batch_status, :notes) ON DUPLICATE KEY UPDATE current_quantity=VALUES(current_quantity), reserved_quantity=VALUES(reserved_quantity), expiry_date=VALUES(expiry_date), batch_status=VALUES(batch_status)");
    foreach ($batches as $b) {
        $stmtBatch->execute($b);
    }
    $batchMap = $pdo->query("SELECT batch_number, id FROM medicine_batches")->fetchAll(PDO::FETCH_KEY_PAIR);

    // 10. Initial Stock Movements Ledger
    $movements = [
        [
            'batch_id' => $batchMap['MER-2024-09A'],
            'medicine_id' => $medMap['MED-ABX-001'],
            'movement_type' => 'receipt',
            'quantity' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
            'reference_type' => 'receipt',
            'reference_id' => 1001,
            'reason' => 'Initial vendor shipment receipt PO-2024-88',
            'user_id' => $userMap['pharmacist']
        ],
        [
            'batch_id' => $batchMap['MER-2024-09A'],
            'medicine_id' => $medMap['MED-ABX-001'],
            'movement_type' => 'issue',
            'quantity' => -72,
            'balance_before' => 100,
            'balance_after' => 28,
            'reference_type' => 'reservation',
            'reference_id' => 1,
            'reason' => 'Routine surgical ward dispensing',
            'user_id' => $userMap['pharmacist']
        ],
        [
            'batch_id' => $batchMap['ROCE-2023-EX9'],
            'medicine_id' => $medMap['MED-ABX-003'],
            'movement_type' => 'quarantine',
            'quantity' => 12,
            'balance_before' => 12,
            'balance_after' => 12,
            'reference_type' => 'manual_adjustment',
            'reference_id' => 1,
            'reason' => 'Batch expiry reached - transferred to quarantine locker',
            'user_id' => $userMap['admin']
        ]
    ];
    $stmtMov = $pdo->prepare("INSERT INTO stock_movements (batch_id, medicine_id, movement_type, quantity, balance_before, balance_after, reference_type, reference_id, reason, user_id) VALUES (:batch_id, :medicine_id, :movement_type, :quantity, :balance_before, :balance_after, :reference_type, :reference_id, :reason, :user_id)");
    foreach ($movements as $m) {
        $stmtMov->execute($m);
    }

    // 11. Clinical Alternative Medicines Mapping
    $alternatives = [
        [
            'primary_medicine_id' => $medMap['MED-ABX-001'], // Meropenem
            'alternative_medicine_id' => $medMap['MED-ABX-002'], // Imipenem/Cilastatin
            'therapeutic_equivalence_notes' => 'Equivalent broad-spectrum carbapenem. Note: Imipenem has lower seizure threshold in severe renal clearance impairment.',
            'requires_clinical_approval' => 1,
            'created_by' => $userMap['admin']
        ],
        [
            'primary_medicine_id' => $medMap['MED-ABX-003'], // Ceftriaxone
            'alternative_medicine_id' => $medMap['MED-ABX-004'], // Cefotaxime
            'therapeutic_equivalence_notes' => 'Third-generation cephalosporin with identical spectrum. Preferred in neonates with hyperbilirubinemia.',
            'requires_clinical_approval' => 1,
            'created_by' => $userMap['admin']
        ],
        [
            'primary_medicine_id' => $medMap['MED-THM-001'], // Enoxaparin LMWH
            'alternative_medicine_id' => $medMap['MED-THM-002'], // Unfractionated Heparin
            'therapeutic_equivalence_notes' => 'Rapidly reversible anticoagulant. Requires aPTT ratio therapeutic monitoring protocol.',
            'requires_clinical_approval' => 1,
            'created_by' => $userMap['admin']
        ]
    ];
    $stmtAlt = $pdo->prepare("INSERT INTO alternative_medicines (primary_medicine_id, alternative_medicine_id, therapeutic_equivalence_notes, requires_clinical_approval, created_by) VALUES (:primary_medicine_id, :alternative_medicine_id, :therapeutic_equivalence_notes, :requires_clinical_approval, :created_by) ON DUPLICATE KEY UPDATE therapeutic_equivalence_notes=VALUES(therapeutic_equivalence_notes)");
    foreach ($alternatives as $a) {
        $stmtAlt->execute($a);
    }

    // 12. Sample Reservations
    $reservations = [
        [
            'request_number' => 'REQ-2026-1001',
            'department_id' => $deptMap['ER-01'],
            'requesting_user_id' => $userMap['nurse_er'],
            'medicine_id' => $medMap['MED-EMG-001'],
            'requested_quantity' => 5,
            'approved_quantity' => 5,
            'issued_quantity' => 0,
            'priority' => 'urgent',
            'justification' => 'Replenishing ER Trauma Bay resuscitation crash cart after multiple resuscitations.',
            'status' => 'approved',
            'approver_id' => $userMap['pharmacist'],
            'approved_at' => (clone $today)->format('Y-m-d H:i:s'),
            'expires_at' => (clone $today)->modify('+24 hours')->format('Y-m-d H:i:s')
        ],
        [
            'request_number' => 'REQ-2026-1002',
            'department_id' => $deptMap['ICU-02'],
            'requesting_user_id' => $userMap['nurse_icu'],
            'medicine_id' => $medMap['MED-EMG-002'],
            'requested_quantity' => 6,
            'approved_quantity' => 6,
            'issued_quantity' => 0,
            'priority' => 'stat',
            'justification' => 'Septic shock patient Bed 4 with mean arterial pressure < 50 mmHg.',
            'status' => 'approved',
            'approver_id' => $userMap['pharmacist'],
            'approved_at' => (clone $today)->format('Y-m-d H:i:s'),
            'expires_at' => (clone $today)->modify('+12 hours')->format('Y-m-d H:i:s')
        ],
        [
            'request_number' => 'REQ-2026-1003',
            'department_id' => $deptMap['MED-03'],
            'requesting_user_id' => $userMap['nurse_er'],
            'medicine_id' => $medMap['MED-ABX-001'],
            'requested_quantity' => 10,
            'approved_quantity' => 10,
            'issued_quantity' => 0,
            'priority' => 'routine',
            'justification' => 'Pre-allocated for Ward 3B multidrug-resistant bacteremia patients.',
            'status' => 'approved',
            'approver_id' => $userMap['pharmacist'],
            'approved_at' => (clone $today)->format('Y-m-d H:i:s'),
            'expires_at' => (clone $today)->modify('+48 hours')->format('Y-m-d H:i:s')
        ],
        [
            'request_number' => 'REQ-2026-1004',
            'department_id' => $deptMap['PED-05'],
            'requesting_user_id' => $userMap['nurse_icu'],
            'medicine_id' => $medMap['MED-DIA-001'],
            'requested_quantity' => 4,
            'approved_quantity' => 0,
            'issued_quantity' => 0,
            'priority' => 'routine',
            'justification' => 'Type 1 pediatric diabetic admission stabilization protocol.',
            'status' => 'pending',
            'approver_id' => null,
            'approved_at' => null,
            'expires_at' => (clone $today)->modify('+24 hours')->format('Y-m-d H:i:s')
        ]
    ];
    $stmtRes = $pdo->prepare("INSERT INTO reservations (request_number, department_id, requesting_user_id, medicine_id, requested_quantity, approved_quantity, issued_quantity, priority, justification, status, approver_id, approved_at, expires_at) VALUES (:request_number, :department_id, :requesting_user_id, :medicine_id, :requested_quantity, :approved_quantity, :issued_quantity, :priority, :justification, :status, :approver_id, :approved_at, :expires_at) ON DUPLICATE KEY UPDATE status=VALUES(status)");
    foreach ($reservations as $r) {
        $stmtRes->execute($r);
    }
    $resMap = $pdo->query("SELECT request_number, id FROM reservations")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Reservation Allocations for approved ones
    $allocations = [
        ['reservation_id' => $resMap['REQ-2026-1001'], 'batch_id' => $batchMap['EPI-2024-03W'], 'allocated_quantity' => 5],
        ['reservation_id' => $resMap['REQ-2026-1002'], 'batch_id' => $batchMap['NOR-2024-ICU'], 'allocated_quantity' => 6],
        ['reservation_id' => $resMap['REQ-2026-1003'], 'batch_id' => $batchMap['MER-2024-09A'], 'allocated_quantity' => 10]
    ];
    $stmtAlloc = $pdo->prepare("INSERT IGNORE INTO reservation_allocations (reservation_id, batch_id, allocated_quantity, status) VALUES (:reservation_id, :batch_id, :allocated_quantity, 'reserved')");
    foreach ($allocations as $al) {
        $stmtAlloc->execute($al);
    }

    // 13. System Settings
    $settings = [
        ['setting_key' => 'hospital_name', 'setting_value' => 'St. Jude Metropolitan Hospital & Medical Center', 'category' => 'general', 'description' => 'Official hospital enterprise name'],
        ['setting_key' => 'hospital_code', 'setting_value' => 'SJM-MED-09', 'category' => 'general', 'description' => 'Hospital licensing and facility identifier'],
        ['setting_key' => 'timezone', 'setting_value' => 'Asia/Kolkata', 'category' => 'localization', 'description' => 'Server hospital operational timezone'],
        ['setting_key' => 'expiry_urgent_days', 'setting_value' => '7', 'category' => 'inventory', 'description' => 'Critical window (days) before expiry triggering urgent alert'],
        ['setting_key' => 'expiry_warning_days', 'setting_value' => '30', 'category' => 'inventory', 'description' => 'Early advisory window (days) before expiry'],
        ['setting_key' => 'reservation_timeout_hours', 'setting_value' => '24', 'category' => 'inventory', 'description' => 'Automatic expiration duration for uncollected reservations'],
        ['setting_key' => 'fefo_strict_mode', 'setting_value' => '1', 'category' => 'inventory', 'description' => 'Enforce FEFO allocation during batch approval'],
        ['setting_key' => 'require_doctor_sub_approval', 'setting_value' => '1', 'category' => 'clinical', 'description' => 'Mandate doctor sign-off before dispensing alternative medicines'],
        ['setting_key' => 'quarantine_auto_on_expiry', 'setting_value' => '1', 'category' => 'compliance', 'description' => 'Automatically restrict expired batches from reservation']
    ];
    $stmtSet = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, category, description) VALUES (:setting_key, :setting_value, :category, :description) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    foreach ($settings as $s) {
        $stmtSet->execute($s);
    }

    // 14. Initial Notifications
    $notifications = [
        [
            'user_id' => null,
            'target_role_id' => $roleMap['pharmacist'],
            'type' => 'critical_expiry',
            'title' => 'Critical Expiry Alert: Meropenem (Batch MER-2024-09A)',
            'message' => 'Batch MER-2024-09A of Meropenem 1g Vial expires in 4 days. 18 available units pending FEFO utilization or transfer.',
            'link_url' => '/expiry.html?filter=urgent'
        ],
        [
            'user_id' => null,
            'target_role_id' => $roleMap['pharmacist'],
            'type' => 'low_stock',
            'title' => 'Low Stock Warning: Norepinephrine 4mg/4mL',
            'message' => 'Norepinephrine on-hand quantity (14 units) has dropped below hospital reorder threshold of 45 units.',
            'link_url' => '/low-stock.html'
        ],
        [
            'user_id' => null,
            'target_role_id' => $roleMap['pharmacist'],
            'type' => 'new_reservation',
            'title' => 'New Reservation Request: REQ-2026-1004 (Pediatrics)',
            'message' => 'Pediatrics ward submitted reservation for 4 units of Insulin Glargine. Needs pharmacist allocation.',
            'link_url' => '/reservations.html?id=' . $resMap['REQ-2026-1004']
        ],
        [
            'user_id' => $userMap['nurse_er'],
            'target_role_id' => null,
            'type' => 'reservation_decision',
            'title' => 'Reservation Approved: REQ-2026-1001',
            'message' => 'Your request for 5 units of Epinephrine has been approved and reserved. Ready for pickup at Central Pharmacy.',
            'link_url' => '/reservations.html?id=' . $resMap['REQ-2026-1001']
        ]
    ];
    $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, target_role_id, type, title, message, link_url, is_read) VALUES (:user_id, :target_role_id, :type, :title, :message, :link_url, 0)");
    foreach ($notifications as $n) {
        $stmtNotif->execute($n);
    }

    // 15. Initial Audit Log
    $stmtAudit = $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtAudit->execute([
        $userMap['admin'],
        'SYSTEM_INITIALIZATION',
        'SystemSettings',
        1,
        json_encode(['status' => 'initialized', 'hospital' => 'St. Jude Metropolitan Hospital']),
        '127.0.0.1',
        'MedWaste Watch CLI Seeder'
    ]);

    echo "Seeding completed successfully!\n";
    echo "Default Demo Credentials:\n";
    echo " - Super Admin: admin@stjude-hospital.org / Password@123\n";
    echo " - Pharmacist:  er.charge.nurse@stjude-hospital.org / Password@123\n";
    echo " - ER Staff:    er.charge.nurse@stjude-hospital.org / Password@123\n";
    echo " - ICU Staff:   icu.nurse@stjude-hospital.org / Password@123\n";
    echo " - Doctor:      dr.kavita@stjude-hospital.org / Password@123\n";
    echo " - Auditor:     audit.compliance@stjude-hospital.org / Password@123\n";

} catch (Throwable $e) {
    echo "Seeder Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
