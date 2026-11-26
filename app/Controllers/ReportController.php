<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\Database;
use App\Core\FeeLedger;
use App\Core\HttpException;
use App\Core\ListQuery;
use App\Core\Money;
use App\Core\Request;
use App\Core\Response;

/**
 * Reports. Each returns {title, columns, rows, totals}; append &format=csv to download.
 * Column types: text | money | date | int | percent.
 */
final class ReportController
{
    private const SCREEN_LIMIT = 2000;

    private static function output(string $slug, string $title, array $columns, array $rows, array $totals = []): never
    {
        if (Request::queryStr('format') === 'csv') {
            Audit::log('report.export', 'report', $slug, null, ['rows' => count($rows)]);
            $cols = [];
            foreach ($columns as $c) {
                $cols[$c['key']] = $c['label'];
            }
            if ($totals) {
                $rows[] = $totals + [$columns[0]['key'] => 'TOTAL'];
            }
            Csv::download($slug, $cols, $rows);
        }
        // Totals always cover every row; the on-screen table is capped to keep the browser responsive.
        $count = count($rows);
        $data = ['title' => $title, 'columns' => $columns, 'rows' => array_slice($rows, 0, self::SCREEN_LIMIT), 'totals' => (object) $totals, 'row_count' => $count];
        if ($count > self::SCREEN_LIMIT) {
            $data['truncated'] = true;
            $data['notice'] = 'Showing the first ' . number_format(self::SCREEN_LIMIT) . ' of ' . number_format($count) . ' rows. Totals include all rows; download the CSV for the full list.';
        }
        Response::ok($data);
    }

    private static function col(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    private static function sumCols(array $rows, array $keys): array
    {
        $t = [];
        foreach ($keys as $k) {
            $t[$k] = Money::sum(...array_column($rows, $k));
        }
        return $t;
    }

    private static function dateRange(): array
    {
        $from = Request::queryDate('from') ?? date('Y-m-01', strtotime(date('Y-m-01') . ' -11 months'));
        $to = Request::queryDate('to') ?? date('Y-m-d');
        if ($from > $to) {
            throw HttpException::validation(['from' => '"From" date must be before "To" date.']);
        }
        return [$from, $to];
    }

    // ---------------------------------------------------------------- student reports
    public static function admissions(): never
    {
        Auth::requirePermission('reports.students');
        [$from, $to] = self::dateRange();
        $q = new ListQuery();
        $q->add('s.admission_date BETWEEN ? AND ?', $from, $to);
        $q->when(Request::queryInt('course_id'), 's.course_id = ?');
        $q->when(Request::queryInt('department_id'), 's.department_id = ?');
        $q->when(Request::queryInt('academic_year_id'), 's.academic_year_id = ?');
        $rows = $q->all(
            's.admission_no, s.full_name, s.gender, c.name AS course_name, d.name AS department_name, s.current_semester,
             s.admission_date, ay.name AS academic_year, s.status, s.contact_number',
            'FROM students s JOIN courses c ON c.id = s.course_id JOIN departments d ON d.id = s.department_id
             JOIN academic_years ay ON ay.id = s.academic_year_id',
            'ORDER BY s.admission_date, s.admission_no'
        );
        self::output('admissions', "Student Admissions ($from to $to)", [
            self::col('admission_no', 'Admission No'), self::col('full_name', 'Name'), self::col('gender', 'Gender'),
            self::col('course_name', 'Course'), self::col('department_name', 'Department'),
            self::col('current_semester', 'Sem', 'int'), self::col('admission_date', 'Admitted', 'date'),
            self::col('academic_year', 'Academic Year'), self::col('status', 'Status'),
        ], $rows, ['admission_no' => 'TOTAL', 'full_name' => count($rows) . ' students']);
    }

    // ---------------------------------------------------------------- fee reports
    public static function feeCollection(): never
    {
        Auth::requirePermission('reports.fees');
        [$from, $to] = self::dateRange();
        $q = new ListQuery();
        $q->add("fp.status = 'confirmed'");
        $q->add('fp.payment_date BETWEEN ? AND ?', $from, $to);
        $q->when(Request::queryInt('course_id'), 's.course_id = ?');
        $mode = Request::queryStr('mode');
        if (in_array($mode, FeeController::MODES, true)) {
            $q->add('fp.payment_mode = ?', $mode);
        }
        $rows = $q->all(
            'fp.receipt_no, fp.payment_date, s.admission_no, s.full_name AS student_name, c.code AS course_code,
             a.semester, a.title, fp.payment_mode, fp.transaction_ref, fp.amount',
            'FROM fee_payments fp JOIN students s ON s.id = fp.student_id JOIN courses c ON c.id = s.course_id
             JOIN student_fee_assignments a ON a.id = fp.assignment_id',
            'ORDER BY fp.payment_date, fp.id'
        );
        self::output('fee_collection', "Fee Collection ($from to $to)", [
            self::col('receipt_no', 'Receipt'), self::col('payment_date', 'Date', 'date'), self::col('admission_no', 'Adm No'),
            self::col('student_name', 'Student'), self::col('course_code', 'Course'), self::col('semester', 'Sem', 'int'),
            self::col('title', 'Fee'), self::col('payment_mode', 'Mode'), self::col('transaction_ref', 'Reference'),
            self::col('amount', 'Amount', 'money'),
        ], $rows, self::sumCols($rows, ['amount']));
    }

    private static function dues(bool $overdueOnly): never
    {
        Auth::requirePermission('reports.fees');
        if ($overdueOnly) {
            $_GET['overdue'] = '1';
        }
        $rows = FeeController::duesFilters()->all(
            's.admission_no, s.full_name AS student_name, c.code AS course_code, ay.name AS academic_year, a.semester, a.title,
             a.due_date, GREATEST(DATEDIFF(CURDATE(), a.due_date), 0) AS days_overdue, a.total_payable,
             a.amount_paid, a.balance, s.parent_contact_number',
            FeeController::duesFrom(),
            $overdueOnly ? 'ORDER BY days_overdue DESC, a.balance DESC' : 'ORDER BY a.balance DESC'
        );
        self::output($overdueOnly ? 'overdue_fees' : 'pending_dues', $overdueOnly ? 'Overdue Fees' : 'Pending Dues', [
            self::col('admission_no', 'Adm No'), self::col('student_name', 'Student'), self::col('course_code', 'Course'),
            self::col('academic_year', 'AY'), self::col('semester', 'Sem', 'int'), self::col('title', 'Fee'),
            self::col('due_date', 'Due Date', 'date'), self::col('days_overdue', 'Days Overdue', 'int'),
            self::col('total_payable', 'Payable', 'money'), self::col('amount_paid', 'Paid', 'money'),
            self::col('balance', 'Outstanding', 'money'), self::col('parent_contact_number', 'Parent Contact'),
        ], $rows, self::sumCols($rows, ['total_payable', 'amount_paid', 'balance']));
    }

    public static function pendingDues(): never
    {
        self::dues(false);
    }

    public static function overdue(): never
    {
        self::dues(true);
    }

    /**
     * Fee totals per course (optionally per semester). Aggregates on the indexed
     * student course_id first and joins the small courses table afterwards,
     * which keeps the GROUP BY sort small on large datasets.
     */
    private static function financialRows(bool $bySemester): array
    {
        $params = [];
        $where = '';
        if ($ay = Request::queryInt('academic_year_id')) {
            $where = 'WHERE a.academic_year_id = ?';
            $params[] = $ay;
        }
        $sem = $bySemester ? ', a.semester' : '';
        $rows = Database::all(
            "SELECT c.code AS course_code, c.name AS course_name" . ($bySemester ? ', g.semester' : '') . ",
                    g.students, g.gross, g.concessions, g.total_payable, g.collected, g.outstanding
               FROM (SELECT s.course_id$sem, COUNT(DISTINCT a.student_id) AS students,
                            SUM(a.gross_amount) AS gross, SUM(a.scholarship_amount + a.discount_amount) AS concessions,
                            SUM(a.total_payable) AS total_payable, SUM(a.amount_paid) AS collected,
                            SUM(a.balance) AS outstanding
                       FROM student_fee_assignments a
                       JOIN students s ON s.id = a.student_id
                       $where
                   GROUP BY s.course_id$sem) g
               JOIN courses c ON c.id = g.course_id
           ORDER BY c.name" . ($bySemester ? ', g.semester' : ''),
            $params
        );
        foreach ($rows as &$r) {
            $payable = Money::toPaise($r['total_payable']);
            $r['collection_pct'] = $payable > 0 ? round(Money::toPaise($r['collected']) * 100 / $payable, 1) : 0;
        }
        return $rows;
    }

    private static function pct(array $t): array
    {
        $p = Money::toPaise($t['total_payable'] ?? '0');
        $t['collection_pct'] = $p > 0 ? round(Money::toPaise($t['collected']) * 100 / $p, 1) : 0;
        return $t;
    }

    public static function courseFinancial(): never
    {
        Auth::requirePermission('reports.fees');
        $rows = self::financialRows(false);
        self::output('course_financial', 'Course-wise Financial Report', [
            self::col('course_code', 'Code'), self::col('course_name', 'Course'), self::col('students', 'Students', 'int'),
            self::col('gross', 'Gross Fee', 'money'), self::col('concessions', 'Scholarship/Discount', 'money'),
            self::col('total_payable', 'Payable', 'money'), self::col('collected', 'Collected', 'money'),
            self::col('outstanding', 'Outstanding', 'money'), self::col('collection_pct', 'Collected %', 'percent'),
        ], $rows, self::pct(self::sumCols($rows, ['gross', 'concessions', 'total_payable', 'collected', 'outstanding'])));
    }

    public static function semesterCollection(): never
    {
        Auth::requirePermission('reports.fees');
        $rows = self::financialRows(true);
        self::output('semester_collection', 'Course & Semester-wise Collection', [
            self::col('course_code', 'Code'), self::col('course_name', 'Course'), self::col('semester', 'Semester', 'int'),
            self::col('students', 'Students', 'int'), self::col('total_payable', 'Payable', 'money'),
            self::col('collected', 'Collected', 'money'), self::col('outstanding', 'Outstanding', 'money'),
            self::col('collection_pct', 'Collected %', 'percent'),
        ], $rows, self::pct(self::sumCols($rows, ['total_payable', 'collected', 'outstanding'])));
    }

    // ---------------------------------------------------------------- staff
    public static function staff(): never
    {
        Auth::requirePermission('reports.staff');
        $q = new ListQuery();
        $q->when(Request::queryInt('department_id'), 't.department_id = ?');
        $status = Request::queryStr('status');
        if (in_array($status, StaffController::STATUSES, true)) {
            $q->add('t.employment_status = ?', $status);
        }
        $rows = $q->all(
            't.employee_id, t.full_name, t.designation, d.name AS department_name, t.qualification, t.date_of_joining,
             t.employment_status, t.contact_number, t.email',
            'FROM staff t LEFT JOIN departments d ON d.id = t.department_id',
            'ORDER BY d.name, t.full_name'
        );
        self::output('staff', 'Staff Information Report', [
            self::col('employee_id', 'Emp ID'), self::col('full_name', 'Name'), self::col('designation', 'Designation'),
            self::col('department_name', 'Department'), self::col('qualification', 'Qualification'),
            self::col('date_of_joining', 'Joined', 'date'), self::col('employment_status', 'Status'),
            self::col('contact_number', 'Contact'), self::col('email', 'Email'),
        ], $rows, ['employee_id' => 'TOTAL', 'full_name' => count($rows) . ' staff']);
    }

    // ---------------------------------------------------------------- salary (Super Admin only)
    private static function monthRange(): array
    {
        $re = '/^\d{4}-(0[1-9]|1[0-2])$/';
        $from = Request::queryStr('from_month');
        $to = Request::queryStr('to_month');
        $from = ($from && preg_match($re, $from)) ? $from : date('Y-m', strtotime(date('Y-m-01') . ' -11 months'));
        $to = ($to && preg_match($re, $to)) ? $to : date('Y-m');
        if ($from > $to) {
            throw HttpException::validation(['from_month' => '"From" month must be before "To" month.']);
        }
        return [$from, $to];
    }

    public static function salaryExpenditure(): never
    {
        Auth::requireSuperAdmin();
        [$from, $to] = self::monthRange();
        $rows = Database::all(
            "SELECT DATE_FORMAT(sp.pay_month, '%Y-%m') AS month, COUNT(*) AS staff_count,
                    SUM(sp.gross_salary) AS gross, SUM(sp.total_deductions) AS deductions, SUM(sp.net_salary) AS net,
                    SUM(CASE WHEN sp.status = 'paid' THEN sp.net_salary ELSE 0 END) AS paid,
                    SUM(CASE WHEN sp.status = 'pending' THEN sp.net_salary ELSE 0 END) AS pending
               FROM salary_payments sp
              WHERE sp.pay_month BETWEEN ? AND ?
           GROUP BY month ORDER BY month",
            [$from . '-01', $to . '-01']
        );
        self::output('salary_expenditure', "Salary Expenditure ($from to $to)", [
            self::col('month', 'Month'), self::col('staff_count', 'Staff', 'int'), self::col('gross', 'Gross', 'money'),
            self::col('deductions', 'Deductions', 'money'), self::col('net', 'Net Payroll', 'money'),
            self::col('paid', 'Paid', 'money'), self::col('pending', 'Pending', 'money'),
        ], $rows, self::sumCols($rows, ['gross', 'deductions', 'net', 'paid', 'pending']));
    }

    public static function monthlySummary(): never
    {
        Auth::requireSuperAdmin();
        [$from, $to] = self::monthRange();
        $map = [];
        for ($m = $from; $m <= $to; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
            $map[$m] = ['month' => $m, 'fee_collected' => '0.00', 'payments' => 0, 'salary_paid' => '0.00'];
        }
        foreach (Database::all(
            "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS m, SUM(amount) AS t, COUNT(*) AS n FROM fee_payments
              WHERE status = 'confirmed' AND payment_date BETWEEN ? AND LAST_DAY(?) GROUP BY m",
            [$from . '-01', $to . '-01']
        ) as $r) {
            if (isset($map[$r['m']])) {
                $map[$r['m']]['fee_collected'] = $r['t'];
                $map[$r['m']]['payments'] = (int) $r['n'];
            }
        }
        foreach (Database::all(
            "SELECT DATE_FORMAT(pay_month, '%Y-%m') AS m, SUM(net_salary) AS t FROM salary_payments
              WHERE status = 'paid' AND pay_month BETWEEN ? AND ? GROUP BY m",
            [$from . '-01', $to . '-01']
        ) as $r) {
            if (isset($map[$r['m']])) {
                $map[$r['m']]['salary_paid'] = $r['t'];
            }
        }
        $rows = array_values(array_map(static function ($r) {
            $r['net'] = Money::sub($r['fee_collected'], $r['salary_paid']);
            return $r;
        }, $map));
        $totals = self::sumCols($rows, ['fee_collected', 'salary_paid', 'net']);
        $totals['payments'] = array_sum(array_column($rows, 'payments'));
        self::output('monthly_financial_summary', "Monthly Financial Summary ($from to $to)", [
            self::col('month', 'Month'), self::col('payments', 'Fee Receipts', 'int'),
            self::col('fee_collected', 'Fee Collected', 'money'), self::col('salary_paid', 'Salary Paid', 'money'),
            self::col('net', 'Net (Fees - Salary)', 'money'),
        ], $rows, $totals);
    }
}
