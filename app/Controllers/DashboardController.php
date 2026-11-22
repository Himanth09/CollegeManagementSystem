<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\FeeLedger;
use App\Core\Response;

/** Dashboard data. Each block is included only if the user may see it. */
final class DashboardController
{
    /** Last N months as ['YYYY-MM' => 0]. */
    public static function months(int $n = 12): array
    {
        $out = [];
        $base = new \DateTimeImmutable('first day of this month');
        for ($i = $n - 1; $i >= 0; $i--) {
            $out[$base->modify("-$i months")->format('Y-m')] = '0.00';
        }
        return $out;
    }

    public static function index(): never
    {
        $u = Auth::requireLogin();
        $cards = [];
        $charts = [];
        $lists = [];
        $monthStart = date('Y-m-01');
        $months = self::months();
        $from = array_key_first($months) . '-01';

        if (Auth::canAny('students.view', 'fees.view')) {
            $s = Database::one("SELECT COUNT(*) AS total, SUM(status = 'Active') AS active FROM students");
            $cards['total_students'] = (int) $s['total'];
            $cards['active_students'] = (int) $s['active'];
            $charts['students_by_department'] = Database::all(
                "SELECT d.name AS label, COUNT(s.id) AS value FROM departments d
                   LEFT JOIN students s ON s.department_id = d.id AND s.status = 'Active'
               GROUP BY d.id, d.name HAVING value > 0 ORDER BY value DESC"
            );
        }
        if (Auth::canAny('staff.view') || Auth::isSuperAdmin()) {
            $cards['total_staff'] = (int) Database::value("SELECT COUNT(*) FROM staff WHERE employment_status IN ('Active','On Leave')");
        }
        if (Auth::can('fees.view')) {
            $cards['total_fee_collection'] = (string) Database::value("SELECT COALESCE(SUM(amount), 0) FROM fee_payments WHERE status = 'confirmed'");
            $cards['total_outstanding'] = (string) Database::value(
                'SELECT COALESCE(SUM(GREATEST(a.total_payable - COALESCE(p.paid, 0), 0)), 0)
                   FROM student_fee_assignments a LEFT JOIN ' . FeeLedger::PAID_SUBQUERY . ' p ON p.assignment_id = a.id'
            );
            $cards['monthly_fee_collection'] = (string) Database::value(
                "SELECT COALESCE(SUM(amount), 0) FROM fee_payments WHERE status = 'confirmed' AND payment_date >= ?", [$monthStart]
            );
            $cards['pending_review'] = (int) Database::value("SELECT COUNT(*) FROM fee_payments WHERE status = 'pending_review'");

            $series = $months;
            foreach (Database::all(
                "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS m, SUM(amount) AS total FROM fee_payments
                  WHERE status = 'confirmed' AND payment_date >= ? GROUP BY m", [$from]
            ) as $r) {
                if (isset($series[$r['m']])) {
                    $series[$r['m']] = $r['total'];
                }
            }
            $charts['fee_collection_by_month'] = self::series($series);
            $charts['outstanding_by_course'] = Database::all(
                'SELECT c.code AS label, c.name, SUM(a.total_payable - COALESCE(p.paid, 0)) AS value
                   FROM student_fee_assignments a
                   JOIN students s ON s.id = a.student_id JOIN courses c ON c.id = s.course_id
              LEFT JOIN ' . FeeLedger::PAID_SUBQUERY . ' p ON p.assignment_id = a.id
                  WHERE a.total_payable - COALESCE(p.paid, 0) > 0
               GROUP BY c.id, c.code, c.name ORDER BY value DESC'
            );
            $lists['recent_payments'] = Database::all(
                "SELECT fp.id, fp.receipt_no, fp.payment_date, fp.amount, fp.payment_mode, s.id AS student_id, s.full_name AS student_name, s.admission_no
                   FROM fee_payments fp JOIN students s ON s.id = fp.student_id
                  WHERE fp.status = 'confirmed' ORDER BY fp.payment_date DESC, fp.id DESC LIMIT 8"
            );
            $lists['pending_dues'] = Database::all(
                'SELECT s.id AS student_id, s.full_name AS student_name, s.admission_no, c.code AS course_code, a.title, a.due_date,
                        a.total_payable - COALESCE(p.paid, 0) AS balance, (a.due_date < CURDATE()) AS is_overdue
                   FROM student_fee_assignments a
                   JOIN students s ON s.id = a.student_id JOIN courses c ON c.id = s.course_id
              LEFT JOIN ' . FeeLedger::PAID_SUBQUERY . ' p ON p.assignment_id = a.id
                  WHERE a.total_payable - COALESCE(p.paid, 0) > 0
               ORDER BY a.due_date IS NULL, a.due_date, balance DESC LIMIT 8'
            );
        }
        // Salary figures: Super Admin only, decided by role.
        if (Auth::isSuperAdmin()) {
            $cards['monthly_salary_expense'] = (string) Database::value(
                "SELECT COALESCE(SUM(net_salary), 0) FROM salary_payments WHERE pay_month = ?", [$monthStart]
            );
            $series = $months;
            foreach (Database::all(
                "SELECT DATE_FORMAT(pay_month, '%Y-%m') AS m, SUM(net_salary) AS total FROM salary_payments
                  WHERE status = 'paid' AND pay_month >= ? GROUP BY m", [$from]
            ) as $r) {
                if (isset($series[$r['m']])) {
                    $series[$r['m']] = $r['total'];
                }
            }
            $charts['salary_by_month'] = self::series($series);
        }
        Response::ok(['cards' => $cards, 'charts' => $charts, 'lists' => $lists, 'role' => $u['role']]);
    }

    public static function series(array $map): array
    {
        $out = [];
        foreach ($map as $m => $v) {
            $out[] = ['label' => date('M Y', strtotime($m . '-01')), 'month' => $m, 'value' => $v];
        }
        return $out;
    }
}
