<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Single source of truth for fee balances:
 *   Outstanding Balance = Total Payable - Total Amount Paid
 * where "paid" counts only payments with status = 'confirmed'.
 */
final class FeeLedger
{
    /** Derived table: confirmed paid amount per assignment. */
    public const PAID_SUBQUERY =
        "(SELECT assignment_id, SUM(amount) AS paid FROM fee_payments WHERE status = 'confirmed' GROUP BY assignment_id)";

    /** Fee assignments of one student with paid / balance figures. */
    public static function assignmentsForStudent(int $studentId): array
    {
        return Database::all(
            'SELECT a.*, ay.name AS academic_year,
                    COALESCE(p.paid, 0) AS amount_paid,
                    a.total_payable - COALESCE(p.paid, 0) AS balance,
                    (SELECT COUNT(*) FROM fee_payments fp WHERE fp.assignment_id = a.id AND fp.status = \'pending_review\') AS pending_review_count
               FROM student_fee_assignments a
               JOIN academic_years ay ON ay.id = a.academic_year_id
          LEFT JOIN ' . self::PAID_SUBQUERY . ' p ON p.assignment_id = a.id
              WHERE a.student_id = ?
           ORDER BY ay.start_date DESC, a.semester DESC, a.id DESC',
            [$studentId]
        );
    }

    /** Totals across a student's assignments. */
    public static function studentSummary(int $studentId): array
    {
        $r = Database::one(
            'SELECT COALESCE(SUM(a.gross_amount), 0) AS gross,
                    COALESCE(SUM(a.scholarship_amount), 0) AS scholarship,
                    COALESCE(SUM(a.discount_amount), 0) AS discount,
                    COALESCE(SUM(a.total_payable), 0) AS total_payable,
                    COALESCE(SUM(COALESCE(p.paid, 0)), 0) AS total_paid
               FROM student_fee_assignments a
          LEFT JOIN ' . self::PAID_SUBQUERY . ' p ON p.assignment_id = a.id
              WHERE a.student_id = ?',
            [$studentId]
        );
        $r['outstanding'] = Money::sub($r['total_payable'], $r['total_paid']);
        return $r;
    }

    /** Locks an assignment row and returns it with its current paid amount and balance. */
    public static function lockAssignment(int $assignmentId): array
    {
        $a = Database::one('SELECT * FROM student_fee_assignments WHERE id = ? FOR UPDATE', [$assignmentId]);
        if (!$a) {
            throw HttpException::notFound('Fee assignment not found.');
        }
        $paid = (string) Database::value(
            "SELECT COALESCE(SUM(amount), 0) FROM fee_payments WHERE assignment_id = ? AND status = 'confirmed'",
            [$assignmentId]
        );
        $a['amount_paid'] = Money::normalize($paid);
        $a['balance'] = Money::sub($a['total_payable'], $paid);
        return $a;
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
