-- =====================================================================
--  College Management System - MySQL 8+ Schema
--  Engine: InnoDB, Charset: utf8mb4
--  Import this file first (phpMyAdmin > Import), then seed.sql.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Roles & permissions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          VARCHAR(30)  NOT NULL,
    name          VARCHAR(60)  NOT NULL,
    description   VARCHAR(255) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          VARCHAR(60)  NOT NULL,
    name          VARCHAR(120) NOT NULL,
    grantable     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = Super Admin only, can never be granted',
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       TINYINT UNSIGNED  NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Academic masters
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(20)  NOT NULL,
    name          VARCHAR(120) NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_code (code),
    UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_id   INT UNSIGNED NOT NULL,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    duration_years  TINYINT UNSIGNED NOT NULL DEFAULT 4,
    total_semesters TINYINT UNSIGNED NOT NULL DEFAULT 8,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_courses_code (code),
    KEY idx_courses_department (department_id),
    CONSTRAINT fk_courses_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_years (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(20) NOT NULL COMMENT 'e.g. 2026-27',
    start_date    DATE NOT NULL,
    end_date      DATE NOT NULL,
    is_current    TINYINT(1) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_academic_years_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Staff & users
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS staff (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id       VARCHAR(30)  NOT NULL,
    full_name         VARCHAR(150) NOT NULL,
    designation       VARCHAR(100) NOT NULL,
    department_id     INT UNSIGNED NULL,
    contact_number    VARCHAR(20)  NULL,
    email             VARCHAR(150) NULL,
    address           TEXT NULL,
    date_of_joining   DATE NULL,
    employment_status ENUM('Active','On Leave','Resigned','Retired','Terminated') NOT NULL DEFAULT 'Active',
    qualification     VARCHAR(255) NULL,
    photo             VARCHAR(120) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_employee_id (employee_id),
    KEY idx_staff_department (department_id),
    KEY idx_staff_status (employment_status),
    KEY idx_staff_name (full_name),
    CONSTRAINT fk_staff_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id             TINYINT UNSIGNED NOT NULL,
    staff_id            INT UNSIGNED NULL,
    username            VARCHAR(60)  NOT NULL,
    email               VARCHAR(150) NOT NULL,
    full_name           VARCHAR(150) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL DEFAULT '',
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at       DATETIME NULL,
    last_login_ip       VARCHAR(45) NULL,
    password_changed_at DATETIME NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_staff (staff_id),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_role  FOREIGN KEY (role_id)  REFERENCES roles (id) ON DELETE RESTRICT,
    CONSTRAINT fk_users_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id       INT UNSIGNED NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    granted_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_id),
    CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_up_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE,
    CONSTRAINT fk_up_granted_by FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL COMMENT 'SHA-256 of the emailed token; the raw token is never stored',
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    request_ip  VARCHAR(45) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(150) NOT NULL,
    ip_address    VARCHAR(45)  NOT NULL,
    success       TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempts_user (username, attempted_at),
    KEY idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Students
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admission_no          VARCHAR(30)  NOT NULL,
    full_name             VARCHAR(150) NOT NULL,
    father_name           VARCHAR(150) NULL,
    mother_name           VARCHAR(150) NULL,
    date_of_birth         DATE NULL,
    gender                ENUM('Male','Female','Other') NOT NULL,
    contact_number        VARCHAR(20)  NULL,
    parent_contact_number VARCHAR(20)  NULL,
    email                 VARCHAR(150) NULL,
    address               TEXT NULL,
    course_id             INT UNSIGNED NOT NULL,
    department_id         INT UNSIGNED NOT NULL,
    current_year          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    current_semester      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    admission_date        DATE NOT NULL,
    academic_year_id      INT UNSIGNED NOT NULL,
    photo                 VARCHAR(120) NULL,
    status                ENUM('Active','Inactive','Graduated') NOT NULL DEFAULT 'Active',
    created_by            INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_admission_no (admission_no),
    KEY idx_students_name (full_name),
    KEY idx_students_course (course_id),
    KEY idx_students_department (department_id),
    KEY idx_students_ay (academic_year_id),
    KEY idx_students_status (status),
    KEY idx_students_semester (current_semester),
    KEY idx_students_admission_date (admission_date),
    CONSTRAINT fk_students_course     FOREIGN KEY (course_id)        REFERENCES courses (id)        ON DELETE RESTRICT,
    CONSTRAINT fk_students_department FOREIGN KEY (department_id)    REFERENCES departments (id)    ON DELETE RESTRICT,
    CONSTRAINT fk_students_ay         FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE RESTRICT,
    CONSTRAINT fk_students_created_by FOREIGN KEY (created_by)       REFERENCES users (id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Fees
-- Monetary values: DECIMAL(12,2). Totals are STORED generated columns so
-- that they can never drift from their components.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fee_structures (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name             VARCHAR(150) NOT NULL,
    course_id        INT UNSIGNED NOT NULL,
    academic_year_id INT UNSIGNED NOT NULL,
    semester         TINYINT UNSIGNED NOT NULL,
    admission_fee    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tuition_fee      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    examination_fee  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    library_fee      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    laboratory_fee   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    transport_fee    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    hostel_fee       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    misc_fee         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_fee        DECIMAL(12,2) GENERATED ALWAYS AS
                     (admission_fee + tuition_fee + examination_fee + library_fee + laboratory_fee + transport_fee + hostel_fee + misc_fee) STORED,
    due_date         DATE NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_by       INT UNSIGNED NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fee_structures (course_id, academic_year_id, semester, name),
    KEY idx_fee_structures_ay (academic_year_id),
    CONSTRAINT fk_fs_course FOREIGN KEY (course_id)        REFERENCES courses (id)        ON DELETE RESTRICT,
    CONSTRAINT fk_fs_ay     FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE RESTRICT,
    CONSTRAINT fk_fs_user   FOREIGN KEY (created_by)       REFERENCES users (id)          ON DELETE SET NULL,
    CONSTRAINT chk_fs_non_negative CHECK (admission_fee >= 0 AND tuition_fee >= 0 AND examination_fee >= 0 AND library_fee >= 0
                                          AND laboratory_fee >= 0 AND transport_fee >= 0 AND hostel_fee >= 0 AND misc_fee >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A copy of the fee structure applied to one student (a "fee demand").
-- Components are copied so later edits to a structure never change what a
-- student already owes.
CREATE TABLE IF NOT EXISTS student_fee_assignments (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id         INT UNSIGNED NOT NULL,
    fee_structure_id   INT UNSIGNED NULL,
    academic_year_id   INT UNSIGNED NOT NULL,
    semester           TINYINT UNSIGNED NOT NULL,
    title              VARCHAR(150) NOT NULL,
    admission_fee      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tuition_fee        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    examination_fee    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    library_fee        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    laboratory_fee     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    transport_fee      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    hostel_fee         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    misc_fee           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    scholarship_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gross_amount       DECIMAL(12,2) GENERATED ALWAYS AS
                       (admission_fee + tuition_fee + examination_fee + library_fee + laboratory_fee + transport_fee + hostel_fee + misc_fee) STORED,
    total_payable      DECIMAL(12,2) GENERATED ALWAYS AS
                       ((admission_fee + tuition_fee + examination_fee + library_fee + laboratory_fee + transport_fee + hostel_fee + misc_fee)
                        - scholarship_amount - discount_amount) STORED,
    -- Running total of CONFIRMED payments. Maintained by App\Core\FeeLedger in the same
    -- locked transaction that changes a payment's status, so balances never need a full
    -- scan of fee_payments. The CHECK below makes overpayment impossible even for buggy code.
    amount_paid        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    balance            DECIMAL(12,2) GENERATED ALWAYS AS (total_payable - amount_paid) STORED,
    due_date           DATE NULL,
    remarks            VARCHAR(255) NULL,
    created_by         INT UNSIGNED NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sfa_student_structure (student_id, fee_structure_id),
    KEY idx_sfa_ay_sem (academic_year_id, semester),
    KEY idx_sfa_due (due_date),
    KEY idx_sfa_balance (balance),
    CONSTRAINT fk_sfa_student   FOREIGN KEY (student_id)       REFERENCES students (id)       ON DELETE RESTRICT,
    CONSTRAINT fk_sfa_structure FOREIGN KEY (fee_structure_id) REFERENCES fee_structures (id) ON DELETE SET NULL,
    CONSTRAINT fk_sfa_ay        FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sfa_user      FOREIGN KEY (created_by)       REFERENCES users (id)          ON DELETE SET NULL,
    CONSTRAINT chk_sfa_non_negative CHECK (admission_fee >= 0 AND tuition_fee >= 0 AND examination_fee >= 0 AND library_fee >= 0
                                           AND laboratory_fee >= 0 AND transport_fee >= 0 AND hostel_fee >= 0 AND misc_fee >= 0
                                           AND scholarship_amount >= 0 AND discount_amount >= 0),
    CONSTRAINT chk_sfa_paid CHECK (amount_paid >= 0 AND amount_paid <= total_payable),
    CONSTRAINT chk_sfa_concession CHECK (scholarship_amount + discount_amount <=
        admission_fee + tuition_fee + examination_fee + library_fee + laboratory_fee + transport_fee + hostel_fee + misc_fee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only rows with status = 'confirmed' count towards the amount paid.
CREATE TABLE IF NOT EXISTS fee_payments (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    receipt_no           VARCHAR(30) NULL,
    student_id           INT UNSIGNED NOT NULL,
    assignment_id        INT UNSIGNED NOT NULL,
    payment_date         DATE NOT NULL,
    amount               DECIMAL(12,2) NOT NULL,
    payment_mode         ENUM('Cash','UPI','Bank Transfer','Card') NOT NULL,
    transaction_ref      VARCHAR(100) NULL,
    transaction_ref_norm VARCHAR(100) NULL COMMENT 'Upper-cased, whitespace-stripped reference used for duplicate detection',
    remarks              VARCHAR(255) NULL,
    status               ENUM('confirmed','pending_review','rejected','cancelled') NOT NULL DEFAULT 'confirmed',
    review_reason        VARCHAR(255) NULL,
    review_note          VARCHAR(255) NULL,
    reviewed_by          INT UNSIGNED NULL,
    reviewed_at          DATETIME NULL,
    recorded_by          INT UNSIGNED NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fee_payments_receipt (receipt_no),
    KEY idx_fp_student (student_id),
    KEY idx_fp_assignment (assignment_id, status, amount),
    KEY idx_fp_status_date (status, payment_date, id, amount),
    KEY idx_fp_date (payment_date, id),
    KEY idx_fp_txn_ref (transaction_ref_norm),
    CONSTRAINT fk_fp_student     FOREIGN KEY (student_id)    REFERENCES students (id)                ON DELETE RESTRICT,
    CONSTRAINT fk_fp_assignment  FOREIGN KEY (assignment_id) REFERENCES student_fee_assignments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_fp_recorded_by FOREIGN KEY (recorded_by)   REFERENCES users (id)                   ON DELETE SET NULL,
    CONSTRAINT fk_fp_reviewed_by FOREIGN KEY (reviewed_by)   REFERENCES users (id)                   ON DELETE SET NULL,
    CONSTRAINT chk_fp_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Salary (Super Admin only)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS salary_structures (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id          INT UNSIGNED NOT NULL,
    basic_salary      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    hra               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    da                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ta                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    other_allowances  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pf_deduction      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    professional_tax  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    other_deductions  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gross_salary      DECIMAL(12,2) GENERATED ALWAYS AS (basic_salary + hra + da + ta + other_allowances) STORED,
    total_deductions  DECIMAL(12,2) GENERATED ALWAYS AS (pf_deduction + professional_tax + other_deductions) STORED,
    net_salary        DECIMAL(12,2) GENERATED ALWAYS AS
                      ((basic_salary + hra + da + ta + other_allowances) - (pf_deduction + professional_tax + other_deductions)) STORED,
    effective_date    DATE NOT NULL,
    is_current        TINYINT(1) NULL DEFAULT 1 COMMENT '1 = current structure, NULL = superseded (unique per staff)',
    created_by        INT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_salary_current (staff_id, is_current),
    KEY idx_salary_staff_effective (staff_id, effective_date),
    CONSTRAINT fk_ss_staff FOREIGN KEY (staff_id)   REFERENCES staff (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ss_user  FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_ss_non_negative CHECK (basic_salary >= 0 AND hra >= 0 AND da >= 0 AND ta >= 0 AND other_allowances >= 0
                                          AND pf_deduction >= 0 AND professional_tax >= 0 AND other_deductions >= 0),
    CONSTRAINT chk_ss_net CHECK (pf_deduction + professional_tax + other_deductions <= basic_salary + hra + da + ta + other_allowances)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salary_revisions (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id               INT UNSIGNED NOT NULL,
    previous_structure_id  INT UNSIGNED NULL,
    new_structure_id       INT UNSIGNED NOT NULL,
    previous_gross         DECIMAL(12,2) NULL,
    previous_net           DECIMAL(12,2) NULL,
    new_gross              DECIMAL(12,2) NOT NULL,
    new_net                DECIMAL(12,2) NOT NULL,
    effective_date         DATE NOT NULL,
    change_reason          VARCHAR(255) NULL,
    revised_by             INT UNSIGNED NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sr_staff (staff_id, created_at),
    CONSTRAINT fk_sr_staff FOREIGN KEY (staff_id)              REFERENCES staff (id)             ON DELETE RESTRICT,
    CONSTRAINT fk_sr_prev  FOREIGN KEY (previous_structure_id) REFERENCES salary_structures (id) ON DELETE SET NULL,
    CONSTRAINT fk_sr_new   FOREIGN KEY (new_structure_id)      REFERENCES salary_structures (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sr_user  FOREIGN KEY (revised_by)            REFERENCES users (id)             ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salary_payments (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slip_no              VARCHAR(30) NULL,
    staff_id             INT UNSIGNED NOT NULL,
    salary_structure_id  INT UNSIGNED NOT NULL,
    pay_month            DATE NOT NULL COMMENT 'Always the 1st day of the payroll month',
    gross_salary         DECIMAL(12,2) NOT NULL,
    total_deductions     DECIMAL(12,2) NOT NULL,
    net_salary           DECIMAL(12,2) NOT NULL,
    status               ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    paid_date            DATE NULL,
    payment_mode         ENUM('Bank Transfer','Cheque','Cash','UPI') NULL,
    transaction_ref      VARCHAR(100) NULL,
    remarks              VARCHAR(255) NULL,
    created_by           INT UNSIGNED NULL,
    paid_by              INT UNSIGNED NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_salary_payment_month (staff_id, pay_month),
    UNIQUE KEY uq_salary_slip_no (slip_no),
    KEY idx_salary_pay_month (pay_month, status),
    CONSTRAINT fk_sp_staff     FOREIGN KEY (staff_id)            REFERENCES staff (id)             ON DELETE RESTRICT,
    CONSTRAINT fk_sp_structure FOREIGN KEY (salary_structure_id) REFERENCES salary_structures (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sp_created   FOREIGN KEY (created_by)          REFERENCES users (id)             ON DELETE SET NULL,
    CONSTRAINT fk_sp_paid_by   FOREIGN KEY (paid_by)             REFERENCES users (id)             ON DELETE SET NULL,
    CONSTRAINT chk_sp_net CHECK (net_salary = gross_salary - total_deductions)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit log
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NULL,
    action       VARCHAR(60) NOT NULL,
    entity_type  VARCHAR(60) NOT NULL,
    entity_id    VARCHAR(40) NULL,
    old_values   JSON NULL,
    new_values   JSON NULL,
    ip_address   VARCHAR(45) NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_created (created_at),
    KEY idx_audit_action (action),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
