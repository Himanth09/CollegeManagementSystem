# College Management System

A complete college administration portal: students, fees (with receipts and dues), staff, an admin-only payroll module, dashboards, reports, audit logs and backups.

- **Backend:** Core PHP 8.2+ (no framework, no Composer), PDO with native prepared statements
- **Database:** MySQL 8.0.16+ (MariaDB 10.4+ also works)
- **Frontend:** React 18 + [htm](https://github.com/developit/htm), shipped as plain ES modules. **No Node.js and no build step.** The React, htm and Chart.js files are included in `assets/vendor/`, so the app does not depend on a CDN.
- **Hosting:** any Apache/LiteSpeed shared host (Hostinger, GoDaddy cPanel, etc.)

---

## 1. Features

| Module | Highlights |
|---|---|
| **Roles** | Super Admin, Accountant and Staff. Every API endpoint checks permissions on the server. Admins can grant extra permissions to individual users. Salary, user management, audit, backup and fee configuration **cannot** be granted to anyone else. |
| **Students** | Add, edit and view students with a photo. Search by name, ID, course or department. Filter by academic year, semester, status, department and course. Sortable, paginated lists, CSV export and fee history on each profile. |
| **Fees** | Semester fee structures cover admission, tuition, exam, library, lab, transport, hostel and miscellaneous charges, plus scholarships and discounts. Assign fees per student or in bulk. Partial payments and instalments are supported, with printable receipts (amount in words, balance after payment), dues and overdue lists, and course/semester-wise collection reports. |
| **Duplicate protection** | References are compared case- and space-insensitively (`UPI 123` = `upi123`). A reused transaction reference is blocked. An Accountant can only send it to a **pending review** queue, where it does not count toward the balance until a Super Admin approves it. Accidental double-submission of identical cash payments is also caught. |
| **Staff** | Full CRUD with photos, a department-wise view and CSV export. Staff users see only their own profile plus anything an admin grants them. |
| **Salary (Super Admin only)** | Versioned salary structures with revision history, monthly payroll generation, individual or bulk "mark paid", paid/pending view, printable salary slips and payroll CSV. |
| **Dashboard** | Summary cards and charts. Each block is returned only if the user may see it (for example, no salary data for Accountants). |
| **Reports** | Admissions, fee collection, pending dues, overdue, course-wise financial, semester-wise collection, staff, salary expenditure and monthly financial summary. All can be viewed, printed or downloaded as CSV. |
| **Security** | `password_hash`/`password_verify`, session ID regeneration on login, HttpOnly/SameSite cookies, idle timeout, CSRF tokens on every POST, login throttling, secure password reset (hashed single-use tokens, no user enumeration), content-sniffed photo uploads stored outside the web root, output escaping, CSV-injection protection, CSP headers, audit log of every financial change, and one-click SQL backups. |

**Accuracy of money:** totals are `STORED GENERATED` columns in MySQL (`total_payable`, `gross_salary`, `net_salary`, …), so they always match their components. PHP arithmetic uses integer paise. Outstanding Balance = Total Payable − Total **confirmed** payments, calculated in one shared place (`app/Core/FeeLedger.php`). A payment can never exceed the outstanding balance: the fee row is locked during the check.

---

## 2. Folder structure

```
/                     ← upload everything here (public_html or a sub-folder)
├── index.php         SPA entry page
├── .htaccess         security rules
├── api/index.php     JSON API router (all routes listed here)
├── app/              PHP code – blocked from the web
│   ├── Core/         Database, Auth, Csrf, Validator, Money, FeeLedger, Upload, Audit…
│   ├── Controllers/  Student, Fee, Staff, Salary, Report, User, Dashboard, Backup…
│   └── Views/print/  receipt & salary-slip templates
├── assets/           css, js (React pages), vendor libraries
├── config/           config.php (credentials) – blocked from the web
├── database/         schema.sql, seed.sql – blocked from the web
├── install/          one-time installer – DELETE after installing
└── storage/          uploads, backups, logs – blocked from the web
```

---

## 3. Installation on Hostinger / GoDaddy (cPanel or hPanel)

### Step 1 – Create the database
1. **Hostinger hPanel:** *Websites → Manage → Databases → MySQL Databases*.
   **GoDaddy cPanel:** *Databases → MySQL® Database Wizard*.
2. Create a database (e.g. `u123456789_cms`) and a user with a strong password.
3. Give the user **ALL PRIVILEGES** on the database. Write down the database name, user name and password.

### Step 2 – Upload the files
1. Zip the project folder on your computer (without the `database/tools` folder if you prefer).
2. Open **File Manager** and go to `public_html` (or a sub-folder such as `public_html/cms`).
3. **Upload** the zip, then right-click → **Extract**. Make sure `index.php` ends up directly inside the target folder, not in an extra nested folder.
4. Folder permissions: `755` for folders and `644` for files. `storage/` and its sub-folders must be writable (`755` is enough on most hosts).

### Step 3 – Configure
1. In File Manager, copy `config/config.sample.php` to `config/config.php`. If a `config.php` is already present, just edit it.
2. Edit `config/config.php`:
   ```php
   'url'  => 'https://yourdomain.com',        // or https://yourdomain.com/cms, no trailing slash
   'college_name' => 'Your College Name',
   'db' => [
       'host' => 'localhost',                  // Hostinger & GoDaddy: localhost
       'port' => 3306,
       'name' => 'u123456789_cms',
       'user' => 'u123456789_cmsuser',
       'pass' => 'your-db-password',
   ],
   'mail' => ['from_email' => 'no-reply@yourdomain.com'],   // must be an address on your domain
   ```
3. Keep `'debug' => false` on a live site.
4. In **hPanel → Advanced → PHP Configuration** or **cPanel → Select PHP Version / MultiPHP Manager**, choose **PHP 8.2 or newer** and enable `pdo_mysql`, `mbstring` and `fileinfo` (usually on by default).

### Step 4 – Create the tables

**Option A – Web installer (easiest)**
1. Visit `https://yourdomain.com/install/`.
2. The installer checks requirements and the database connection.
3. Tick **Load sample data** if you want demo records, then enter the Super Admin username, email and password.
4. Optionally set a password for the demo `accountant` and `staff` accounts.
5. Click **Install now**.

**Option B – phpMyAdmin import**
1. Open **phpMyAdmin**, select your database and go to the **Import** tab.
2. Import `database/schema.sql` first, then `database/seed.sql` (sample data, roles and permissions).
   If you do not want sample data, import only the `roles`, `permissions` and `role_permissions` sections of `seed.sql`, or use Option A.
3. Then visit `https://yourdomain.com/install/` to **initialize the admin account**. The installer sees that the tables already exist and only sets the passwords. The seeded users have blank passwords and cannot sign in until this is done.

### Step 5 – Secure the installation
1. **Delete the `install/` folder** in File Manager. The installer also locks itself with `storage/install.lock`.
2. Enable SSL (Hostinger: *Security → SSL*; GoDaddy: *SSL/TLS Status* or AutoSSL). Then uncomment the *Force HTTPS* lines in `.htaccess`. Once HTTPS works everywhere, also uncomment the HSTS line.
3. Sign in. Then go to **Administration → User Accounts** and change or deactivate the demo users.

### Step 6 – Verify
- Open `https://yourdomain.com/config/config.php`. It must return **403 Forbidden**.
- Do the same for `/database/schema.sql` and `/storage/`.

---

## 4. Default accounts (sample data)

| Username | Role | Password |
|---|---|---|
| `admin` | Super Admin | set in the installer |
| `accountant` | Accountant (linked to staff EMP010) | demo password from the installer |
| `staff` | Staff (EMP004; has been granted *View students*) | demo password from the installer |

Password rule: at least 8 characters, with upper- and lower-case letters, a number and a symbol.

---

## 5. Operating notes

- **Changing the application URL or moving folders:** update `app.url` in `config/config.php`. It is used in password-reset links. All other links are relative, so the app works from a sub-folder without further changes.
- **Password reset emails** are sent with PHP `mail()`. On Hostinger and GoDaddy, `from_email` must be a mailbox or alias on your own domain. If emails don't arrive, a Super Admin can set a new password under *User Accounts*.
- **Backups:** go to *Administration → Database Backup* and click *Create backup now*. This creates a gzip-compressed `.sql.gz` in `storage/backups`. Download it and keep copies off the server. To restore, import it in phpMyAdmin, which accepts `.gz`.
- **New academic year:** *Administration → Academic Setup*. Add the year and mark it *Current*. Then create fee structures under *Fees → Fee Structures* and use the "assign to all students" button on each structure.
- **Payroll:** *Salary → Monthly Payroll*. Pick a month, click *Generate payroll*, then mark individual staff as paid or use *Pay all pending*. Salary slips print from the same screen.
- **Duplicate references:** pending items appear on the dashboard and under *Fees → Payments → Pending review* for the Super Admin to approve or reject.
- **Logs:** PHP errors go to `storage/logs/php-error.log`. This folder is not readable from the web.
- **Updating JS/CSS:** browsers cache assets for 1 hour. After an update, change `$v` in `index.php` to force a refresh.

---

## 6. Security model (summary)

1. **Authorization on every request.** Each controller method begins with `Auth::requirePermission()` or `Auth::requireSuperAdmin()`. Hidden menu items are only cosmetic.
2. **Salary isolation.** Every `SalaryController` method and the salary-slip print check the Super Admin **role** directly, not a permission that could be granted. Salary data never appears in staff or student endpoints, in the Accountant's dashboard, or in non-admin reports.
3. **Database access.** All SQL uses PDO prepared statements with emulation turned off. Sort columns come from a whitelist.
4. **Sessions.** HttpOnly, SameSite=Lax and Secure (on HTTPS) cookies, strict mode, ID regeneration at login and every 15 minutes, a 30-minute idle timeout, and an immediate stop for deactivated users.
5. **CSRF.** A synchronizer token is sent in the `X-CSRF-Token` header on every POST. The installer uses the same protection.
6. **Uploads.** MIME type is detected from file contents (finfo), checked with `getimagesize`, limited to 2 MB, and saved under a random name in `storage/` (outside web access). Photos are served only through the authenticated API.
7. **Audit trail.** Payments (create, review, cancel), fee structures and assignments, salary structures, payroll, exports, logins, user and permission changes, and backups are all recorded with before/after values, user, IP and time.

---

## 7. Running the tests

The `tests/` folder holds a dependency-free suite. It needs no Composer or PHPUnit, and it is blocked from the web.

```bash
php tests/run.php --unit      # unit tests only, no database needed
php tests/run.php             # unit + integration tests
php tests/run.php -v          # list every test
php tests/run.php --filter=Money
```

Integration tests (Database, FeeLedger balances, Auth/RBAC) need a **dedicated** test database loaded with `schema.sql` and then `seed.sql`. Each test runs in a transaction that is rolled back. Point the suite at the test database with `tests/config.test.php`, which is git-ignored:

```php
<?php return ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'cms_unittest', 'user' => 'cms', 'pass' => 'secret'];
```

You can also set the `CMS_TEST_DB_HOST`, `_PORT`, `_NAME`, `_USER` and `_PASS` environment variables. If neither is configured, the integration tests are skipped. The runner exits with code 1 if any test fails, so it can be used in CI.

**End-to-end HTTP tests** (`tests/Http`, run with `php tests/run.php --http`) send real requests to a running copy of the app. They go through the router, sessions, CSRF, controllers and MySQL, just like the browser does. They cover:
- login, logout and throttling
- role-based access for all 3 roles
- the student lifecycle
- the full fee workflow (partial payments, duplicate-reference review, cancellation, receipts, dues)
- salary revisions and payroll
- reports, audit log, user permissions and backups

These tests **commit data**, so run them against a disposable test install, never a live site. Add an `http` section to `tests/config.test.php`. In that file, the database settings go under a `'db'` key:

```php
<?php return [
    'db'   => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'cms_unittest', 'user' => 'cms', 'pass' => 'secret'],
    'http' => ['url' => 'http://127.0.0.1:8098', 'users' => [
        'super_admin' => ['admin', 'Admin@12345'], 'accountant' => ['accountant', 'Demo@12345'], 'staff' => ['staff', 'Demo@12345'],
    ]],
];
```

The test server must use the seed data (`seed.sql`) with those passwords set. For example: `php -S 127.0.0.1:8098` in a copy of the site whose `config.php` points to a fresh test database. Each run uses unique IDs, so the suite can be run repeatedly. The login-throttle test adds failed attempts, though: after about 3 runs within 15 minutes, logins from your machine's IP address are blocked for 15 minutes, so reload the database between runs.

---

## 8. Regenerating the sample data

`database/tools/generate_seed.py` builds `seed.sql` and checks that every total adds up (no overpayment, correct payable and net amounts). Run `python database/tools/generate_seed.py` and re-import.
