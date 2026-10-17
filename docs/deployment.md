# MedWaste Watch — Deployment & Local Setup Guide

## 1. System Requirements
- **PHP**: Version 8.2 or higher with extensions: `pdo_mysql`, `curl`, `mbstring`, `json`, `session`.
- **Database**: MySQL 8.0+ running with InnoDB storage engine.
- **Web Server**: Built-in PHP development server, Apache (with `mod_rewrite` enabled), or Nginx reverse proxy.
- **Client**: Any modern web browser (Chrome, Edge, Firefox, Safari) with JavaScript enabled.

---

## 2. Local Development Server (Recommended Quickstart)

Using the included unified `router.php`, both `frontend/` static assets and `backend/public/index.php` REST endpoints are served seamlessly on the same origin (`http://127.0.0.1:8000`), completely eliminating cross-origin CORS and cookie domain complexities.

### Step 1: Clone or Navigate to Directory
```bash
cd MedWaste
```

### Step 2: Configure Database Environment
Ensure `backend/.env` exists and contains your local MySQL credentials:
```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=medwaste_db
DB_USERNAME=root
DB_PASSWORD=your_mysql_password
```

### Step 3: Run Database Migrations and Seeder
Initialize the 24 MySQL tables and demo clinical data:
```bash
php backend/database/migrate.php
```
*(On Windows with XAMPP: `"C:\xampp\php\php.exe" backend/database/migrate.php`)*

### Step 4: Run the Automated Clinical Test Suite
Verify that all 32 automated tests pass:
```bash
php backend/tests/run-tests.php
```

### Step 5: Start the Development Server
```bash
php -S 127.0.0.1:8000 router.php
```

Open your browser to: **`http://127.0.0.1:8000/`**

---

## 3. Demo Credentials
All seeded demo accounts use password **`Password@123`**:

| Role | Username | Email | Department |
|---|---|---|---|
| **Super Admin** | `admin` | `admin@stjude-hospital.org` | Central Administration |
| **Pharmacist** | `pharmacist` | `pharmacy.lead@stjude-hospital.org` | Central Pharmacy |
| **ER Charge Nurse** | `nurse_er` | `er.charge.nurse@stjude-hospital.org` | Emergency Department |
| **ICU Staff Nurse** | `nurse_icu` | `icu.nurse@stjude-hospital.org` | Intensive Care Unit |
| **ICU Physician** | `doctor_icu` | `dr.kavita@stjude-hospital.org` | Critical Care |
| **Auditor / QA** | `auditor` | `audit.compliance@stjude-hospital.org` | Quality Assurance |

*(On the login screen, click any of the role cards to auto-fill the credentials.)*

---

## 4. Production Apache VirtualHost Configuration
For production deployments pointing to an Apache server, configure the virtual host as follows:

```apache
<VirtualHost *:443>
    ServerName medwaste.hospital.org
    DocumentRoot /var/www/MedWaste/frontend

    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/medwaste.crt
    SSLCertificateKeyFile /etc/ssl/private/medwaste.key

    # Route /api/* to the backend public front controller
    Alias /api /var/www/MedWaste/backend/public

    <Directory /var/www/MedWaste/frontend>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    <Directory /var/www/MedWaste/backend/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Protect internal application directories
    <DirectoryMatch "/var/www/MedWaste/backend/(app|database|storage|tests|config)">
        Require all denied
    </DirectoryMatch>
</VirtualHost>
```

---

## 5. Scheduled Background Tasks (Cron / Task Scheduler)
Schedule periodic execution of the maintenance scripts:

### Expiry Alerts (Runs hourly)
```bash
0 * * * * /usr/bin/php /var/www/MedWaste/backend/scripts/expiry-alerts.php >> /var/log/medwaste_expiry.log 2>&1
```

### Reservation Expiry Cleanup (Runs every 15 minutes)
```bash
*/15 * * * * /usr/bin/php /var/www/MedWaste/backend/scripts/reservation-cleanup.php >> /var/log/medwaste_res.log 2>&1
```

---

## 6. Backup & Recovery Procedures
- **Database Backup**:
  ```bash
  mysqldump -u root -p --single-transaction --routines --triggers medwaste_db > backup_$(date +%Y%m%d_%H%M%S).sql
  ```
- **Database Restore**:
  ```bash
  mysql -u root -p medwaste_db < backup_file.sql
  ```
