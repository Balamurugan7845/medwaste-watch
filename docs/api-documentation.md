# MedWaste Watch — REST API Specification

All API endpoints return standard JSON responses with appropriate HTTP status codes:

```json
{
  "success": true,
  "message": "Operation description",
  "data": { ... }
}
```

---

## 1. Authentication (`/api/auth`)
- `POST /api/auth/login`: Authenticates username & password. Issues cookie session & returns user profile with CSRF token. Protected with rate limiting (5 attempts / 5 mins).
- `POST /api/auth/logout`: Invalidates server session and clears session cookie.
- `GET /api/auth/me`: Returns current authenticated user and permissions list.
- `POST /api/auth/change-password`: Verifies current password and sets new bcrypt password hash.

---

## 2. Dashboard (`/api/dashboard`)
- `GET /api/dashboard/summary`: Returns live KPI counts, category inventory distribution, expiry urgency distribution, upcoming critical expiries, low stock items, and recent ledger movements.

---

## 3. Medicine Formulary (`/api/medicines`)
- `GET /api/medicines`: Paginated, searchable by SKU/brand/generic, filterable by category and active status.
- `GET /api/medicines/{id}`: Master medicine metadata with all associated batch lot breakdowns in FEFO sequence.
- `POST /api/medicines`: Registers new formulary medicine (Admin/Pharmacist).
- `PUT /api/medicines/{id}`: Updates medicine metadata or reorder levels.
- `GET /api/medicines/categories`: Lists active therapeutic categories.

---

## 4. Batches & Inventory (`/api/batches`)
- `GET /api/batches`: List of physical batches with live days-to-expiry and storage locations.
- `POST /api/batches`: Inbound batch consignment registration with stock movement ledger entry.
- `POST /api/batches/{id}/adjust`: Physical count adjustment with mandatory audit reason.
- `GET /api/locations`: Lists hospital storage locations (vaults, cold chain, wards).
- `GET /api/suppliers`: Lists approved pharmaceutical vendors.

---

## 5. Expiry Monitoring (`/api/expiry`)
- `GET /api/expiry`: Lists batches filtered by urgency (`urgent`, `warning`, `safe`, `expired`).
- `GET /api/expiry/summary`: Urgency tier batch counts and total units for dashboard tiles and charts.

---

## 6. Reservations (`/api/reservations`)
- `GET /api/reservations`: Filterable by status (`pending`, `approved`, `issued`, `rejected`, `cancelled`) and ward.
- `POST /api/reservations`: Ward nurse submits medication request.
- `GET /api/reservations/{id}`: Detailed request view with batch allocations and historical state log.
- `POST /api/reservations/{id}/approve`: Pharmacist FEFO batch approval and row locking.
- `POST /api/reservations/{id}/reject`: Pharmacist rejection with clinical reason.
- `POST /api/reservations/{id}/issue`: Pharmacist dispensing of reserved stock; deducts physical inventory.
- `POST /api/reservations/{id}/cancel`: Cancellation releasing allocated units back to hospital availability.

---

## 7. Clinical Alternatives (`/api/alternatives`)
- `GET /api/alternatives/{medicine_id}`: Primary medicine availability and mapped bioequivalent alternatives with live batch stock.
- `POST /api/alternative-approvals`: Physician substitution sign-off with clinical justification.

---

## 8. Transfers & Disposal (`/api/transfers`, `/api/disposals`)
- `GET /api/transfers`: Internal storage transfer history.
- `POST /api/transfers`: Moves batch units between hospital storage locations.
- `GET /api/disposals`: Permanent biomedical waste records.
- `POST /api/disposals`: Authorized hazardous waste destruction with certification reference.
- `POST /api/quarantine`: Isolates expired or damaged batches from ward availability.

---

## 9. Reports & CSV Export (`/api/reports`)
- `GET /api/reports?type={current_stock|batch_wise|wastage|movements}`: Returns structured data.
- `GET /api/reports/export?type={type}`: Downloads CSV file protected against spreadsheet formula-injection.

---

## 10. Administration (`/api/users`, `/api/departments`, `/api/settings`, `/api/audit-logs`)
- `GET /api/users`: Staff directory and role assignments.
- `POST /api/users`: Creates new staff user account with bcrypt password.
- `PUT /api/users/{id}`: Modifies user status (active / suspended) or assigned department.
- `GET /api/roles`: Lists system roles.
- `GET /api/departments`: Lists clinical wards and staff counts.
- `POST /api/departments`: Registers new department.
- `GET /api/settings`: Hospital thresholds, timezone, and dispensing policies.
- `PUT /api/settings`: Updates hospital settings.
- `GET /api/audit-logs`: Paginated compliance security and operational audit trail.
