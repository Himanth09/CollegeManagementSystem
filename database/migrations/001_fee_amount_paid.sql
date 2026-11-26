-- Migration 001 (v1.1.0): running paid totals on fee assignments + covering indexes.
-- For installations created from schema.sql before v1.1.0. Run ONCE in phpMyAdmin
-- (Import tab) after taking a backup. New installations already include this.
-- Safe to run while nobody is recording payments.

ALTER TABLE student_fee_assignments
    ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_payable,
    ADD COLUMN balance DECIMAL(12,2) GENERATED ALWAYS AS (total_payable - amount_paid) STORED AFTER amount_paid;

UPDATE student_fee_assignments a
   SET amount_paid = (SELECT COALESCE(SUM(p.amount), 0) FROM fee_payments p
                       WHERE p.assignment_id = a.id AND p.status = 'confirmed');

ALTER TABLE student_fee_assignments
    ADD CONSTRAINT chk_sfa_paid CHECK (amount_paid >= 0 AND amount_paid <= total_payable),
    ADD KEY idx_sfa_balance (balance);

ALTER TABLE fee_payments
    ADD KEY idx_fp_assignment_v2 (assignment_id, status, amount),
    ADD KEY idx_fp_status_date_v2 (status, payment_date, id, amount),
    ADD KEY idx_fp_date (payment_date, id);
ALTER TABLE fee_payments
    DROP KEY idx_fp_assignment,
    DROP KEY idx_fp_status_date;
ALTER TABLE fee_payments
    RENAME KEY idx_fp_assignment_v2 TO idx_fp_assignment,
    RENAME KEY idx_fp_status_date_v2 TO idx_fp_status_date;
