<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Money;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Salary management. EVERY method starts with Auth::requireSuperAdmin():
 * access is tied to the Super Admin role itself and cannot be granted.
 */
final class SalaryController
{
    public const EARNINGS = ['basic_salary' => 'Basic Salary', 'hra' => 'House Rent Allowance', 'da' => 'Dearness Allowance',
        'ta' => 'Travel Allowance', 'other_allowances' => 'Other Allowances'];
    public const DEDUCTIONS = ['pf_deduction' => 'Provident Fund', 'professional_tax' => 'Professional Tax',
        'other_deductions' => 'Other Deductions'];
    public const MODES = ['Bank Transfer', 'Cheque', 'Cash', 'UPI'];

    /** All staff with their current structure (if any). */
    public static function structures(): never
    {
        Auth::requireSuperAdmin();
        $q = Request::queryStr('q');
        $params = [];
        $where = '';
        if ($q) {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where = 'WHERE t.full_name LIKE ? OR t.employee_id LIKE ? OR t.designation LIKE ?';
            $params = [$like, $like, $like];
        }
        $rows = Database::all(
            "SELECT t.id AS staff_id, t.employee_id, t.full_name, t.designation, t.employment_status, d.name AS department_name,
                    ss.id AS structure_id, ss.basic_salary, ss.hra, ss.da, ss.ta, ss.other_allowances, ss.pf_deduction,
                    ss.professional_tax, ss.other_deductions, ss.gross_salary, ss.total_deductions, ss.net_salary, ss.effective_date,
                    (SELECT COUNT(*) FROM salary_revisions r WHERE r.staff_id = t.id) AS revision_count
               FROM staff t
          LEFT JOIN departments d ON d.id = t.department_id
          LEFT JOIN salary_structures ss ON ss.staff_id = t.id AND ss.is_current = 1
               $where
           ORDER BY t.full_name",
            $params
        );
        $totals = Database::one(
            "SELECT COUNT(*) AS count, COALESCE(SUM(ss.gross_salary), 0) AS gross, COALESCE(SUM(ss.net_salary), 0) AS net
               FROM salary_structures ss JOIN staff t ON t.id = ss.staff_id
              WHERE ss.is_current = 1 AND t.employment_status IN ('Active', 'On Leave')"
        );
        Response::ok(['data' => $rows, 'totals' => $totals]);
    }

    public static function history(int $id): never
    {
        Auth::requireSuperAdmin();
        $staff = Database::one('SELECT id, employee_id, full_name, designation FROM staff WHERE id = ?', [$id])
            ?? throw HttpException::notFound('Staff member not found.');
        Response::ok([
            'staff'      => $staff,
            'structures' => Database::all('SELECT ss.*, u.full_name AS created_by_name FROM salary_structures ss
                                             LEFT JOIN users u ON u.id = ss.created_by
                                            WHERE ss.staff_id = ? ORDER BY ss.effective_date DESC, ss.id DESC', [$id]),
            'revisions'  => Database::all('SELECT r.*, u.full_name AS revised_by_name FROM salary_revisions r
                                             LEFT JOIN users u ON u.id = r.revised_by
                                            WHERE r.staff_id = ? ORDER BY r.created_at DESC, r.id DESC', [$id]),
        ]);
    }

    /** Create or revise a staff member's salary structure (always a new version). */
    public static function saveStructure(): never
    {
        Auth::requireSuperAdmin();
        $rules = ['staff_id' => 'required|int', 'effective_date' => 'required|date', 'change_reason' => 'nullable|max:255'];
        foreach (array_keys(self::EARNINGS + self::DEDUCTIONS) as $c) {
            $rules[$c] = 'nullable|money';
        }
        $rules['basic_salary'] = 'required|money|min_value:1';
        $d = Validator::make(Request::body(), $rules, ['basic_salary' => 'Basic salary']);
        foreach (array_keys(self::EARNINGS + self::DEDUCTIONS) as $c) {
            $d[$c] = Money::normalize($d[$c] ?? '0');
        }
        $gross = Money::sum(...array_map(static fn ($c) => $d[$c], array_keys(self::EARNINGS)));
        $ded = Money::sum(...array_map(static fn ($c) => $d[$c], array_keys(self::DEDUCTIONS)));
        $net = Money::sub($gross, $ded);
        if (Money::toPaise($net) < 0) {
            throw HttpException::validation(['other_deductions' => 'Total deductions cannot exceed the gross salary.']);
        }

        $id = Database::transaction(static function () use ($d, $gross, $net) {
            $staff = Database::one('SELECT id FROM staff WHERE id = ? FOR UPDATE', [$d['staff_id']])
                ?? throw HttpException::validation(['staff_id' => 'Select a valid staff member.']);
            $prev = Database::one('SELECT * FROM salary_structures WHERE staff_id = ? AND is_current = 1 FOR UPDATE', [$staff['id']]);
            if ($prev && $d['effective_date'] < $prev['effective_date']) {
                throw HttpException::validation(['effective_date' => 'Effective date cannot be earlier than the current structure (' . $prev['effective_date'] . ').']);
            }
            if ($prev) {
                Database::execute('UPDATE salary_structures SET is_current = NULL WHERE id = ?', [(int) $prev['id']]);
            }
            $row = ['staff_id' => (int) $staff['id'], 'effective_date' => $d['effective_date'], 'is_current' => 1, 'created_by' => Auth::id()];
            foreach (array_keys(self::EARNINGS + self::DEDUCTIONS) as $c) {
                $row[$c] = $d[$c];
            }
            $newId = Database::insert('salary_structures', $row);
            Database::insert('salary_revisions', [
                'staff_id' => (int) $staff['id'], 'previous_structure_id' => $prev ? (int) $prev['id'] : null,
                'new_structure_id' => $newId, 'previous_gross' => $prev['gross_salary'] ?? null,
                'previous_net' => $prev['net_salary'] ?? null, 'new_gross' => $gross, 'new_net' => $net,
                'effective_date' => $d['effective_date'],
                'change_reason' => $d['change_reason'] ?? ($prev ? 'Salary revision' : 'Initial salary structure'),
                'revised_by' => Auth::id(),
            ]);
            Audit::log($prev ? 'salary_structure.revise' : 'salary_structure.create', 'salary_structure', $newId, $prev, $row);
            return $newId;
        });
        Response::ok(['id' => $id], 'Salary structure saved and revision history updated.');
    }

    // ---------------------------------------------------------------- monthly payroll
    private static function month(): string
    {
        $m = Request::queryStr('month') ?? Request::str('month') ?? date('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            throw HttpException::validation(['month' => 'Month must be in YYYY-MM format.']);
        }
        return $m;
    }

    /** Payroll for one month: every eligible staff member, paid / pending / not generated. */
    public static function payroll(): never
    {
        Auth::requireSuperAdmin();
        $month = self::month();
        $rows = self::payrollRows($month);
        $summary = ['paid' => '0.00', 'pending' => '0.00', 'paid_count' => 0, 'pending_count' => 0, 'not_generated' => 0];
        foreach ($rows as $r) {
            if ($r['status'] === 'paid') {
                $summary['paid'] = Money::sum($summary['paid'], $r['net_salary']);
                $summary['paid_count']++;
            } elseif ($r['status'] === 'pending') {
                $summary['pending'] = Money::sum($summary['pending'], $r['net_salary']);
                $summary['pending_count']++;
            } else {
                $summary['not_generated']++;
            }
        }
        Response::ok(['month' => $month, 'data' => $rows, 'summary' => $summary]);
    }

    private static function payrollRows(string $month): array
    {
        $first = $month . '-01';
        return Database::all(
            "SELECT t.id AS staff_id, t.employee_id, t.full_name, t.designation, d.name AS department_name, t.employment_status,
                    sp.id AS payment_id, sp.slip_no, COALESCE(sp.status, 'not_generated') AS status, sp.paid_date, sp.payment_mode,
                    sp.transaction_ref, sp.remarks,
                    COALESCE(sp.gross_salary, ss.gross_salary) AS gross_salary,
                    COALESCE(sp.total_deductions, ss.total_deductions) AS total_deductions,
                    COALESCE(sp.net_salary, ss.net_salary) AS net_salary,
                    (ss.id IS NOT NULL) AS has_structure
               FROM staff t
          LEFT JOIN departments d ON d.id = t.department_id
          LEFT JOIN salary_payments sp ON sp.staff_id = t.id AND sp.pay_month = ?
          LEFT JOIN salary_structures ss ON ss.staff_id = t.id AND ss.is_current = 1
              WHERE sp.id IS NOT NULL OR (t.employment_status IN ('Active', 'On Leave') AND ss.id IS NOT NULL)
           ORDER BY t.full_name",
            [$first]
        );
    }

    /** Creates pending salary rows for all eligible staff not yet in this month's payroll. */
    public static function generate(): never
    {
        Auth::requireSuperAdmin();
        $month = self::month();
        $first = $month . '-01';
        if ($first > date('Y-m-01', strtotime('+1 month'))) {
            throw HttpException::validation(['month' => 'Payroll can only be generated up to next month.']);
        }
        $count = Database::transaction(static function () use ($first, $month) {
            // Use the structure in effect for the month: the current one when its effective date is on/before month end.
            $rows = Database::all(
                "SELECT ss.* FROM salary_structures ss JOIN staff t ON t.id = ss.staff_id
                  WHERE ss.is_current = 1 AND t.employment_status IN ('Active', 'On Leave')
                    AND ss.effective_date <= LAST_DAY(?)
                    AND NOT EXISTS (SELECT 1 FROM salary_payments sp WHERE sp.staff_id = ss.staff_id AND sp.pay_month = ?)",
                [$first, $first]
            );
            foreach ($rows as $s) {
                Database::insert('salary_payments', [
                    'staff_id' => (int) $s['staff_id'], 'salary_structure_id' => (int) $s['id'], 'pay_month' => $first,
                    'gross_salary' => $s['gross_salary'], 'total_deductions' => $s['total_deductions'],
                    'net_salary' => $s['net_salary'], 'status' => 'pending', 'created_by' => Auth::id(),
                ]);
            }
            Audit::log('payroll.generate', 'payroll', $month, null, ['staff_count' => count($rows)]);
            return count($rows);
        });
        Response::ok(['count' => $count], $count ? "Payroll generated for $count staff member(s)." : 'Payroll is already up to date for this month.');
    }

    public static function markPaid(int $id): never
    {
        Auth::requireSuperAdmin();
        $d = Validator::make(Request::body(), [
            'paid_date'       => 'required|date|before_today',
            'payment_mode'    => 'required|in:' . implode(',', self::MODES),
            'transaction_ref' => 'nullable|max:100',
            'remarks'         => 'nullable|max:255',
        ]);
        if ($d['payment_mode'] !== 'Cash' && $d['transaction_ref'] === null) {
            throw HttpException::validation(['transaction_ref' => 'Transaction reference is required for non-cash payments.']);
        }
        $slip = Database::transaction(static function () use ($id, $d) {
            $p = Database::one('SELECT * FROM salary_payments WHERE id = ? FOR UPDATE', [$id]) ?? throw HttpException::notFound('Salary record not found.');
            if ($p['status'] === 'paid') {
                throw new HttpException('This salary has already been marked as paid.', 409);
            }
            $slip = sprintf('SAL-%s-%04d', str_replace('-', '', substr($p['pay_month'], 0, 7)), (int) $p['staff_id']);
            $update = $d + ['status' => 'paid', 'slip_no' => $slip, 'paid_by' => Auth::id()];
            Database::update('salary_payments', $id, $update);
            Audit::log('salary_payment.paid', 'salary_payment', $id, ['status' => $p['status']], $update + ['net_salary' => $p['net_salary']]);
            return $slip;
        });
        Response::ok(['slip_no' => $slip], "Salary marked as paid. Slip $slip generated.");
    }

    /** Bulk mark all pending rows of a month as paid. */
    public static function payAll(): never
    {
        Auth::requireSuperAdmin();
        $month = self::month();
        $d = Validator::make(Request::body(), [
            'paid_date'       => 'required|date|before_today',
            'payment_mode'    => 'required|in:' . implode(',', self::MODES),
            'transaction_ref' => 'nullable|max:100',
        ]);
        $count = Database::transaction(static function () use ($month, $d) {
            $rows = Database::all("SELECT id, staff_id, pay_month FROM salary_payments WHERE pay_month = ? AND status = 'pending' FOR UPDATE", [$month . '-01']);
            foreach ($rows as $p) {
                $slip = sprintf('SAL-%s-%04d', str_replace('-', '', $month), (int) $p['staff_id']);
                Database::update('salary_payments', (int) $p['id'], $d + ['status' => 'paid', 'slip_no' => $slip, 'paid_by' => Auth::id()]);
            }
            Audit::log('payroll.pay_all', 'payroll', $month, null, $d + ['count' => count($rows)]);
            return count($rows);
        });
        Response::ok(['count' => $count], "$count salary payment(s) marked as paid.");
    }

    /** Remove a pending (unpaid) row, e.g. to regenerate after a revision. */
    public static function deletePending(int $id): never
    {
        Auth::requireSuperAdmin();
        $p = Database::one('SELECT * FROM salary_payments WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        if ($p['status'] !== 'pending') {
            throw new HttpException('Paid salaries cannot be removed.', 409);
        }
        Database::execute("DELETE FROM salary_payments WHERE id = ? AND status = 'pending'", [$id]);
        Audit::log('salary_payment.delete_pending', 'salary_payment', $id, $p);
        Response::ok([], 'Pending salary entry removed.');
    }

    public static function exportPayroll(): never
    {
        Auth::requireSuperAdmin();
        $month = self::month();
        Audit::log('payroll.export', 'payroll', $month);
        Csv::download("payroll_$month", [
            'employee_id' => 'Employee ID', 'full_name' => 'Name', 'designation' => 'Designation',
            'department_name' => 'Department', 'gross_salary' => 'Gross', 'total_deductions' => 'Deductions',
            'net_salary' => 'Net', 'status' => 'Status', 'paid_date' => 'Paid Date', 'payment_mode' => 'Mode',
            'transaction_ref' => 'Reference', 'slip_no' => 'Slip No',
        ], self::payrollRows($month));
    }
}
