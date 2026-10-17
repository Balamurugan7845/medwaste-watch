# MedWaste Watch — Database Design & Entity Relationships

## 1. Engine & Schema Standards
- **Engine**: InnoDB (Full ACID transaction support, row-level locking, foreign keys).
- **Charset**: `utf8mb4` with `utf8mb4_unicode_ci` collation.
- **Identifiers**: `INT UNSIGNED` or `BIGINT UNSIGNED` primary keys.
- **Amounts**: `DECIMAL(10,2)` for monetary values (never floating-point).
- **Quantities**: `INT UNSIGNED` for stock counts.
- **Dates**: `DATE` for medicine expiry; `DATETIME` / `TIMESTAMP` for operational audit stamps.

---

## 2. Entity Overview (24 Tables)

| # | Table Name | Purpose | Primary Key | Key Foreign Keys |
|---|---|---|---|---|
| 1 | `roles` | Hospital authorization roles | `id` | None |
| 2 | `permissions` | Granular operational permissions | `id` | None |
| 3 | `role_permissions` | RBAC mapping | (`role_id`, `permission_id`) | `roles(id)`, `permissions(id)` |
| 4 | `departments` | Hospital wards & clinical units | `id` | None |
| 5 | `users` | Staff credentials & roles | `id` | `roles(id)`, `departments(id)` |
| 6 | `user_departments` | Multi-ward staff assignments | (`user_id`, `department_id`) | `users(id)`, `departments(id)` |
| 7 | `medicine_categories` | Formulary classifications | `id` | None |
| 8 | `suppliers` | Pharmaceutical vendors | `id` | None |
| 9 | `storage_locations` | Physical storage vaults & fridges | `id` | None |
| 10 | `medicines` | Formulary master catalog | `id` | `medicine_categories(id)` |
| 11 | `medicine_batches` | Physical lot-level inventory | `id` | `medicines(id)`, `suppliers(id)`, `storage_locations(id)` |
| 12 | `stock_movements` | Immutable movement audit ledger | `id` | `medicine_batches(id)`, `medicines(id)`, `users(id)` |
| 13 | `reservations` | Ward medication request orders | `id` | `departments(id)`, `users(id)`, `medicines(id)` |
| 14 | `reservation_allocations` | Batch-level reservation locks | `id` | `reservations(id)`, `medicine_batches(id)` |
| 15 | `reservation_history` | State transitions & timestamps | `id` | `reservations(id)`, `users(id)` |
| 16 | `alternative_medicines` | Verified clinical substitutions | `id` | `medicines(id)` (primary & alternative) |
| 17 | `alternative_approvals` | Physician clinical sign-off records | `id` | `reservations(id)`, `medicines(id)`, `users(id)` |
| 18 | `transfers` | Inter-location storage moves | `id` | `storage_locations(id)` (src & dst), `users(id)` |
| 19 | `transfer_items` | Batches included in a transfer | `id` | `transfers(id)`, `medicine_batches(id)` |
| 20 | `disposal_records` | Permanent hazardous waste log | `id` | `medicine_batches(id)`, `medicines(id)`, `users(id)` |
| 21 | `notifications` | Targeted clinical & expiry alerts | `id` | `users(id)`, `roles(id)` |
| 22 | `audit_logs` | Forensic security & change audit | `id` | `users(id)` |
| 23 | `system_settings` | Hospital thresholds & policies | `setting_key` | `users(id)` |
| 24 | `password_reset_tokens` | Secure token hashes | `id` | None |

---

## 3. High-Performance Indexing Strategy
- `medicines`: Indexes on `sku`, `brand_name`, `generic_name`, `is_active`.
- `medicine_batches`: Composite index on `(medicine_id, batch_status, expiry_date)` for instantaneous FEFO lookups; unique index on `(medicine_id, batch_number, supplier_id)` to prevent consignment duplication.
- `reservations`: Indexes on `status`, `department_id`, and `created_at`.
- `stock_movements`: Indexed by `created_at`, `batch_id`, and `movement_type`.
- `notifications`: Composite index on `(user_id, target_role_id, is_read, created_at)`.
