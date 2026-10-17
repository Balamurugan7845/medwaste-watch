# MedWaste Watch — Role-Based Access Control (RBAC) Permissions Matrix

The backend enforces role authorizations at every protected endpoint via `RoleMiddleware` and `AuthMiddleware`. Client-side UI controls are tailored to match, ensuring no unauthorized operations can be triggered.

| Operational Module & Action | Super Admin (`super_admin`) | Pharmacist (`pharmacist`) | Department Staff (`department_staff`) | Doctor (`doctor`) | Auditor / QA (`auditor`) |
|---|:---:|:---:|:---:|:---:|:---:|
| **View Executive Dashboard** | Full | Full | Department Scope | Department Scope | Full Read-Only |
| **Manage Medicines Catalog** (Add/Edit) | Yes | Yes | No | No | Read-Only |
| **Register Inbound Batches** | Yes | Yes | No | No | Read-Only |
| **Physical Stock Count Adjustment** | Yes | Yes | No | No | Read-Only |
| **View Expiry Priority Queue** | Yes | Yes | Read-Only | Read-Only | Read-Only |
| **Quarantine Damaged / Expired Batches** | Yes | Yes | No | No | Read-Only |
| **Record Biomedical Waste Disposal** | Yes | Yes | No | No | Read-Only |
| **Inter-Location Storage Transfers** | Yes | Yes | No | No | Read-Only |
| **Submit Ward Medicine Request** | Yes | Yes | Yes | Yes | No |
| **Approve / Reject Reservation (FEFO)** | Yes | Yes | No | No | No |
| **Dispense / Issue Reserved Stock** | Yes | Yes | No | No | No |
| **Cancel Unissued Reservation** | Yes | Yes | Own Ward | Own Ward | No |
| **View Clinical Alternative Lookups** | Yes | Yes | Yes | Yes | Read-Only |
| **Authorize Physician Substitution** | No (Clinical Only)| Co-Review | No | Yes (Primary Approver)| No |
| **Generate Reports & Export Safe CSV**| Yes | Yes | Department Scope | Department Scope | Yes |
| **Staff & User Administration** | Yes | No | No | No | No |
| **Configure System Policies & Thresholds**| Yes | No | No | No | No |
| **Inspect Immutable Compliance Audit Trail**| Yes | Read-Only | No | No | Yes |

### Server-Side Enforcement Rules:
1. `super_admin` possesses universal administrative oversight across all system tables.
2. Pharmacists control inventory receipts, FEFO allocations, issuing, and disposal.
3. Department staff are isolated to submitting and reviewing requests within their own clinical department.
4. Doctors possess exclusive clinical privilege to sign off on therapeutic alternative substitutions.
5. Auditors maintain read-only access to all reports, ledger transactions, and audit logs without modification authority.
