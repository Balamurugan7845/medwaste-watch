# MedWaste Watch — Architecture Documentation

## 1. System Vision & Architecture
**MedWaste Watch** (*Smarter Medicine Management. Less Waste. Better Availability.*) is an enterprise hospital medicine availability, inventory, and expiry tracking system.

### Core Architectural Separation
The application enforces a strict separation between the Presentation (Frontend) and Business / Data layers (Backend):
- **Frontend Layer (`frontend/`)**: HTML5, Vanilla CSS3 with CSS custom properties, and Vanilla ES6+ JavaScript modules. No Node.js runtime or build tool dependencies are required. All dynamic updates are performed via standard Fetch API/AJAX consuming backend JSON endpoints.
- **Backend Layer (`backend/`)**: Core PHP 8.2+ with PDO database abstractions, structured into a layered architecture:
  - `Core/`: Router, Request, Response, Session, AuditLogger.
  - `Middleware/`: AuthMiddleware, RoleMiddleware, CsrfMiddleware, RateLimiter.
  - `Controllers/`: HTTP handlers parsing parameters and dispatching to business services.
  - `Services/`: Domain logic implementations (FEFO batch allocation, transactional row locks, expiry window evaluations).
  - `Helpers/`: Sanitizer, FormulaInjectionProtector.
  - `Config/`: Environment loader and PDO connection manager.
- **Database Layer (`MySQL 8.0 with InnoDB`)**: 24 normalized tables enforcing primary keys, foreign key constraints with cascade/restrict behaviors, and composite indexes designed for operational query performance.

---

## 2. Inventory Calculation Formulas
To prevent ineligible, damaged, or expired stock from being mistakenly allocated or dispensed, the system strictly separates quantities at the batch level:

1. **Total Physical On-Hand ($Q_{on\_hand}$)**:
   $$\sum_{b \in \text{batches}} b.\text{current\_quantity}$$

2. **Quarantined Stock ($Q_{quar}$)**:
   $$\sum_{b \in \text{batches}} b.\text{quarantined\_quantity}$$

3. **Expired Stock ($Q_{exp}$)**:
   $$\sum_{b \in \text{batches}, b.\text{expiry\_date} \le \text{CURRENT\_DATE}()} b.\text{current\_quantity}$$

4. **Eligible Unreserved Available-to-Reserve ($Q_{available}$)**:
   $$\sum_{b \in \text{eligible}} \max(0, b.\text{current\_quantity} - b.\text{quarantined\_quantity} - b.\text{reserved\_quantity})$$
   *Where a batch is eligible only if:*
   - $b.\text{expiry\_date} > \text{CURRENT\_DATE}()$
   - $b.\text{batch\_status} \notin ('\text{expired}', '\text{quarantined}', '\text{depleted}')$
   - $b.\text{current\_quantity} - b.\text{quarantined\_quantity} - b.\text{reserved\_quantity} > 0$

5. **Shortage Deficit ($D_{reorder}$)**:
   $$\max(0, \text{reorder\_level} - Q_{available})$$

---

## 3. Concurrency Control & FEFO Rules
- **First Expired, First Out (FEFO)**: When a reservation is approved, candidate batches are ordered by:
  `ORDER BY expiry_date ASC, id ASC`
- **Pessimistic Row Locking**: Any stock reservation, issue, transfer, or adjustment is wrapped in a `PDO::beginTransaction()` with `SELECT ... FOR UPDATE` row locks, preventing race conditions or double allocation under concurrent multi-user load.
- **Permanent Ledgering**: Every stock delta writes an immutable row into `stock_movements`. Physical inventory is never silently altered.
