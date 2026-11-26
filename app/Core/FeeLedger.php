<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Single source of truth for fee balances:
 *   Outstanding Balance = Total Payable - Total Amount Paid
 * where "paid" counts only payments with status = 'confirmed'.
 *
 * For scalability the confirmed total is kept as a running figure in
 * student_fee_assignments.amount_paid (and `balance` is a STORED generated
 * column), so dues, dashboards and reports never re-add the whole
 * fee_payments table. The figure is changed ONLY through addPaid(), inside
 * the same transaction that changes a payment's status and after the
 * assignment row has been locked with lockAssignment(). A CHECK constraint
 * (0 <= amount_paid <= total_payable) makes overpayment impossible at the
 * database level, and reconcile() verifies the running totals against the
 * payment history.
 */
final class FeeLedger
{
    /** Fee assignments of one student with paid / balance figures. */
    public static function assignmentsForStudent(int $studentId): array
    {
        return Database::all(
            'SELECT a.*, ay.name AS academic_year,
                    (SELECT COUNT(*) FROM fee_payments fp WHERE fp.assignment_id = a.id AND fp.status = \'pending_review\') AS pending_review_count
               FROM student_fee_assignments a
               JOIN academic_years ay ON ay.id = a.academic_year_id
              WHERE a.student_id = ?
           ORDER BY ay.start_date DESC, a.semester DESC, a.id DESC',
            [$studentId]
        );
    }

    /** Totals across a student's assignments. */
    public static function studentSummary(int $studentId): array
    {
        $r = Database::one(
            'SELECT COALESCE(SUM(gross_amount), 0) AS gross,
                    COALESCE(SUM(scholarship_amount), 0) AS scholarship,
                    COALESCE(SUM(discount_amount), 0) AS discount,
                    COALESCE(SUM(total_payable), 0) AS total_payable,
                    COALESCE(SUM(amount_paid), 0) AS total_paid
               FROM student_fee_assignments
              WHERE student_id = ?',
            [$studentId]
        );
        $r['outstanding'] = Money::sub($r['total_payable'], $r['total_paid']);
        return $r;
    }

    /**
     * Locks an assignment row (SELECT ... FOR UPDATE) and returns it with its
     * current paid amount and balance. Every change to a balance must happen
     * after this call, inside the same transaction.
     */
    public static function lockAssignment(int $assignmentId): array
    {
        $a = Database::one('SELECT * FROM student_fee_assignments WHERE id = ? FOR UPDATE', [$assignmentId]);
        if (!$a) {
            throw HttpException::notFound('Fee assignment not found.');
        }
        $a['amount_paid'] = Money::normalize($a['amount_paid']);
        $a['balance'] = Money::sub($a['total_payable'], $a['amount_paid']);
        return $a;
    }

    /**
     * Adds (or, with a negative amount, removes) a confirmed amount to the
     * assignment's running total. Call only after lockAssignment() in the same
     * transaction. The CHECK constraint rejects any result outside 0..payable.
     */
    public static function addPaid(int $assignmentId, string $amount): void
    {
        if (!Database::pdo()->inTransaction()) {
            throw new \LogicException('FeeLedger::addPaid must run inside a transaction.');
        }
        Database::execute(
            'UPDATE student_fee_assignments SET amount_paid = amount_paid + ? WHERE id = ?',
            [Money::normalize($amount), $assignmentId]
        );
    }

    /**
     * Assignments whose running total differs from the sum of their confirmed
     * payments. Should always be empty; used by tests and health checks.
     * @return list<array{id:int, amount_paid:string, confirmed:string}>
     */
    public static function reconcile(int $limit = 100): array
    {
        return Database::all(
            "SELECT a.id, a.amount_paid, COALESCE(p.confirmed, 0) AS confirmed
               FROM student_fee_assignments a
          LEFT JOIN (SELECT assignment_id, SUM(amount) AS confirmed FROM fee_payments
                      WHERE status = 'confirmed' GROUP BY assignment_id) p ON p.assignment_id = a.id
              WHERE a.amount_paid <> COALESCE(p.confirmed, 0)
              LIMIT " . max(1, $limit)
        );
    }

    public static function normalizeRef(?string $ref): ?string
    {
        if ($ref === null) {
            return null;
        }
        $n = strtoupper(preg_replace('/\s+/', '', $ref));
        return $n === '' ? null : mb_substr($n, 0, 100);
    }

    public static function receiptNo(int $paymentId, string $paymentDate): string
    {
        return sprintf('RCP-%s-%06d', substr($paymentDate, 0, 4), $paymentId);
    }
}
