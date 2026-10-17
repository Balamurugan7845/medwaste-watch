-- =====================================================================
-- MedWaste Watch — MySQL Database Schema (InnoDB)
-- Smarter Medicine Management. Less Waste. Better Availability.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `medwaste_db` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `medwaste_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- Drop in reverse dependency order if recreating
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `disposal_records`;
DROP TABLE IF EXISTS `transfer_items`;
DROP TABLE IF EXISTS `transfers`;
DROP TABLE IF EXISTS `alternative_approvals`;
DROP TABLE IF EXISTS `alternative_medicines`;
DROP TABLE IF EXISTS `reservation_history`;
DROP TABLE IF EXISTS `reservation_allocations`;
DROP TABLE IF EXISTS `reservation_items`;
DROP TABLE IF EXISTS `reservations`;
DROP TABLE IF EXISTS `stock_movements`;
DROP TABLE IF EXISTS `medicine_batches`;
DROP TABLE IF EXISTS `medicines`;
DROP TABLE IF EXISTS `storage_locations`;
DROP TABLE IF EXISTS `suppliers`;
DROP TABLE IF EXISTS `medicine_categories`;
DROP TABLE IF EXISTS `user_departments`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `departments`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. Roles
CREATE TABLE `roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Permissions
CREATE TABLE `permissions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `module` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Role Permissions
CREATE TABLE `role_permissions` (
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Departments
CREATE TABLE `departments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `location` VARCHAR(100) NULL,
    `phone` VARCHAR(30) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Users
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED NULL,
    `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    `last_login_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_users_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
    INDEX `idx_users_role` (`role_id`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. User Departments (secondary or multiple assigned wards)
CREATE TABLE `user_departments` (
    `user_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`user_id`, `department_id`),
    CONSTRAINT `fk_ud_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ud_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Medicine Categories
CREATE TABLE `medicine_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Suppliers
CREATE TABLE `suppliers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL UNIQUE,
    `contact_person` VARCHAR(100) NULL,
    `email` VARCHAR(100) NULL,
    `phone` VARCHAR(30) NULL,
    `address` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Storage Locations
CREATE TABLE `storage_locations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `type` ENUM('pharmacy_main', 'er_satellite', 'icu_ward', 'cold_storage', 'quarantine') NOT NULL DEFAULT 'pharmacy_main',
    `temp_zone` ENUM('room_temp', 'refrigerated', 'frozen') NOT NULL DEFAULT 'room_temp',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Medicines (Master Catalog)
CREATE TABLE `medicines` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sku` VARCHAR(50) NOT NULL UNIQUE,
    `brand_name` VARCHAR(120) NOT NULL,
    `generic_name` VARCHAR(120) NOT NULL,
    `strength` VARCHAR(50) NOT NULL,
    `dosage_form` ENUM('tablet', 'capsule', 'injection', 'syrup', 'suspension', 'ointment', 'inhaler', 'drops', 'iv_fluid') NOT NULL DEFAULT 'tablet',
    `category_id` INT UNSIGNED NOT NULL,
    `manufacturer` VARCHAR(120) NULL,
    `unit_of_measure` VARCHAR(30) NOT NULL DEFAULT 'box',
    `reorder_level` INT UNSIGNED NOT NULL DEFAULT 20,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_medicines_category` FOREIGN KEY (`category_id`) REFERENCES `medicine_categories` (`id`) ON UPDATE CASCADE,
    INDEX `idx_medicines_sku` (`sku`),
    INDEX `idx_medicines_brand` (`brand_name`),
    INDEX `idx_medicines_generic` (`generic_name`),
    INDEX `idx_medicines_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Medicine Batches (Physical Inventory)
CREATE TABLE `medicine_batches` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `medicine_id` INT UNSIGNED NOT NULL,
    `batch_number` VARCHAR(60) NOT NULL,
    `supplier_id` INT UNSIGNED NULL,
    `storage_location_id` INT UNSIGNED NOT NULL,
    `received_quantity` INT UNSIGNED NOT NULL,
    `current_quantity` INT UNSIGNED NOT NULL,
    `reserved_quantity` INT UNSIGNED NOT NULL DEFAULT 0,
    `quarantined_quantity` INT UNSIGNED NOT NULL DEFAULT 0,
    `unit_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `received_date` DATE NOT NULL,
    `expiry_date` DATE NOT NULL,
    `batch_status` ENUM('active', 'low', 'expiring', 'expired', 'quarantined', 'depleted') NOT NULL DEFAULT 'active',
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_batch_medicine` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_batch_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_batch_location` FOREIGN KEY (`storage_location_id`) REFERENCES `storage_locations` (`id`) ON DELETE RESTRICT,
    UNIQUE KEY `uk_med_batch_supplier` (`medicine_id`, `batch_number`, `supplier_id`),
    INDEX `idx_batch_expiry` (`expiry_date`),
    INDEX `idx_batch_status` (`batch_status`),
    INDEX `idx_batch_fefo` (`medicine_id`, `batch_status`, `expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Stock Movements (Immutable Ledger)
CREATE TABLE `stock_movements` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `batch_id` INT UNSIGNED NOT NULL,
    `medicine_id` INT UNSIGNED NOT NULL,
    `movement_type` ENUM('receipt', 'issue', 'reservation_hold', 'reservation_release', 'transfer_out', 'transfer_in', 'quarantine', 'unquarantine', 'disposal', 'adjustment') NOT NULL,
    `quantity` INT NOT NULL,
    `balance_before` INT UNSIGNED NOT NULL,
    `balance_after` INT UNSIGNED NOT NULL,
    `reference_type` ENUM('receipt', 'reservation', 'transfer', 'disposal', 'manual_adjustment') NOT NULL,
    `reference_id` INT UNSIGNED NULL,
    `reason` VARCHAR(255) NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_sm_batch` FOREIGN KEY (`batch_id`) REFERENCES `medicine_batches` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sm_med` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    INDEX `idx_sm_created` (`created_at`),
    INDEX `idx_sm_batch` (`batch_id`),
    INDEX `idx_sm_type` (`movement_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Reservations (Department Medicine Requests)
CREATE TABLE `reservations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_number` VARCHAR(30) NOT NULL UNIQUE,
    `department_id` INT UNSIGNED NOT NULL,
    `requesting_user_id` INT UNSIGNED NOT NULL,
    `medicine_id` INT UNSIGNED NOT NULL,
    `requested_quantity` INT UNSIGNED NOT NULL,
    `approved_quantity` INT UNSIGNED NOT NULL DEFAULT 0,
    `issued_quantity` INT UNSIGNED NOT NULL DEFAULT 0,
    `priority` ENUM('routine', 'urgent', 'stat') NOT NULL DEFAULT 'routine',
    `justification` TEXT NULL,
    `status` ENUM('pending', 'approved', 'partially_approved', 'rejected', 'cancelled', 'issued', 'expired') NOT NULL DEFAULT 'pending',
    `approver_id` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    `rejection_reason` VARCHAR(255) NULL,
    `expires_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_res_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
    CONSTRAINT `fk_res_user` FOREIGN KEY (`requesting_user_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_res_med` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`),
    CONSTRAINT `fk_res_approver` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`),
    INDEX `idx_res_status` (`status`),
    INDEX `idx_res_dept` (`department_id`),
    INDEX `idx_res_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Reservation Allocations (Batch Level Locks)
CREATE TABLE `reservation_allocations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reservation_id` INT UNSIGNED NOT NULL,
    `batch_id` INT UNSIGNED NOT NULL,
    `allocated_quantity` INT UNSIGNED NOT NULL,
    `status` ENUM('reserved', 'issued', 'released') NOT NULL DEFAULT 'reserved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ra_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ra_batch` FOREIGN KEY (`batch_id`) REFERENCES `medicine_batches` (`id`) ON DELETE RESTRICT,
    INDEX `idx_ra_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Reservation History (State Transitions)
CREATE TABLE `reservation_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reservation_id` INT UNSIGNED NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `actor_id` INT UNSIGNED NOT NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_rh_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Alternative Medicines (Clinical Equivalence Mapping)
CREATE TABLE `alternative_medicines` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `primary_medicine_id` INT UNSIGNED NOT NULL,
    `alternative_medicine_id` INT UNSIGNED NOT NULL,
    `therapeutic_equivalence_notes` TEXT NULL,
    `requires_clinical_approval` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_alt_prim` FOREIGN KEY (`primary_medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_alt_repl` FOREIGN KEY (`alternative_medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_alt_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
    UNIQUE KEY `uk_alt_pair` (`primary_medicine_id`, `alternative_medicine_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Alternative Approvals (Doctor / Clinician Substitution Sign-off)
CREATE TABLE `alternative_approvals` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reservation_id` INT UNSIGNED NOT NULL,
    `primary_medicine_id` INT UNSIGNED NOT NULL,
    `alternative_medicine_id` INT UNSIGNED NOT NULL,
    `doctor_id` INT UNSIGNED NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `clinical_justification` TEXT NOT NULL,
    `approved_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_aa_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_aa_prim` FOREIGN KEY (`primary_medicine_id`) REFERENCES `medicines` (`id`),
    CONSTRAINT `fk_aa_alt` FOREIGN KEY (`alternative_medicine_id`) REFERENCES `medicines` (`id`),
    CONSTRAINT `fk_aa_doc` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Storage Transfers
CREATE TABLE `transfers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transfer_number` VARCHAR(30) NOT NULL UNIQUE,
    `source_location_id` INT UNSIGNED NOT NULL,
    `destination_location_id` INT UNSIGNED NOT NULL,
    `initiated_by` INT UNSIGNED NOT NULL,
    `confirmed_by` INT UNSIGNED NULL,
    `status` ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    CONSTRAINT `fk_tr_src` FOREIGN KEY (`source_location_id`) REFERENCES `storage_locations` (`id`),
    CONSTRAINT `fk_tr_dst` FOREIGN KEY (`destination_location_id`) REFERENCES `storage_locations` (`id`),
    CONSTRAINT `fk_tr_init` FOREIGN KEY (`initiated_by`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_tr_conf` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Transfer Items
CREATE TABLE `transfer_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transfer_id` INT UNSIGNED NOT NULL,
    `batch_id` INT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL,
    CONSTRAINT `fk_ti_tr` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ti_batch` FOREIGN KEY (`batch_id`) REFERENCES `medicine_batches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Disposal Records (Wastage Tracking)
CREATE TABLE `disposal_records` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `disposal_number` VARCHAR(30) NOT NULL UNIQUE,
    `batch_id` INT UNSIGNED NOT NULL,
    `medicine_id` INT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL,
    `disposal_method` ENUM('incineration', 'chemical_neutralization', 'return_to_supplier', 'certified_hazmat') NOT NULL,
    `reason` ENUM('expired', 'contaminated', 'damaged', 'recalled') NOT NULL,
    `authorized_by` INT UNSIGNED NOT NULL,
    `disposed_at` DATETIME NOT NULL,
    `certificate_ref` VARCHAR(100) NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_disp_batch` FOREIGN KEY (`batch_id`) REFERENCES `medicine_batches` (`id`),
    CONSTRAINT `fk_disp_med` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`),
    CONSTRAINT `fk_disp_auth` FOREIGN KEY (`authorized_by`) REFERENCES `users` (`id`),
    INDEX `idx_disp_reason` (`reason`),
    INDEX `idx_disp_date` (`disposed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Notifications & Alerts
CREATE TABLE `notifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `target_role_id` INT UNSIGNED NULL,
    `type` ENUM('critical_expiry', 'upcoming_expiry', 'low_stock', 'out_of_stock', 'new_reservation', 'reservation_decision', 'stock_alert') NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `link_url` VARCHAR(255) NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_role` FOREIGN KEY (`target_role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    INDEX `idx_notif_lookup` (`user_id`, `target_role_id`, `is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Audit Logs
CREATE TABLE `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `action` VARCHAR(80) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT UNSIGNED NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_al_entity` (`entity_type`, `entity_id`),
    INDEX `idx_al_user` (`user_id`),
    INDEX `idx_al_action` (`action`),
    INDEX `idx_al_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. System Settings
CREATE TABLE `system_settings` (
    `setting_key` VARCHAR(50) PRIMARY KEY,
    `setting_value` TEXT NOT NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
    `description` VARCHAR(255) NULL,
    `updated_by` INT UNSIGNED NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. Password Reset Tokens
CREATE TABLE `password_reset_tokens` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(100) NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_prt_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
