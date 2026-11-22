<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\FeeLedger;
use App\Core\HttpException;
use App\Core\Money;
use App\Core\Response;

/** Printable HTML documents: fee receipts and salary slips. */
final class PrintController
{
    private static function render(string $view, array $vars): never
    {
        $vars['college'] = [
            'name'    => Config::get('app.college_name'),
            'address' => Config::get('app.college_address'),
            'phone'   => Config::get('app.college_phone'),
            'email'   => Config::get('app.college_email'),
        ];
        extract($vars, EXTR_SKIP);
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn ($v): string => htmlspecialchars(Money::format($v), ENT_QUOTES, 'UTF-8');
        $date = static fn ($v): string => $v ? date('d M Y', strtotime((string) $v)) : '-';
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; script-src 'unsafe-inline'");
        ob_start();
        require APP_PATH . '/Views/print/' . $view . '.php';
        Response::html((string) ob_get_clean());
    }

    public static function receipt(int $id): never
    {
        Auth::requirePermission('fees.view');
        $p = Database::one(
            'SELECT fp.*, s.admission_no, s.full_name AS student_name, s.father_name, s.current_semester, s.contact_number,
                    c.name AS course_name, d.name AS department_name, a.title AS assignment_title, a.total_payable,
                    ay.name AS academic_year, u.full_name AS recorded_by_name
               FROM fee_payments fp
               JOIN students s ON s.id = fp.student_id
               JOIN courses c ON c.id = s.course_id
               JOIN departments d ON d.id = s.department_id
               JOIN student_fee_assignments a ON a.id = fp.assignment_id
               JOIN academic_years ay ON ay.id = a.academic_year_id
          LEFT JOIN users u ON u.id = fp.recorded_by
              WHERE fp.id = ?',
            [$id]
        ) ?? throw HttpException::notFound('Payment not found.');
        if (!in_array($p['status'], ['confirmed', 'cancelled'], true)) {
            throw new HttpException('A receipt is available only for confirmed payments.', 409);
        }
        // Balance as of this receipt: confirmed payments up to and including this one.
        $paidToDate = (string) Database::value(
            "SELECT COALESCE(SUM(amount), 0) FROM fee_payments
              WHERE assignment_id = ? AND status = 'confirmed' AND (payment_date < ? OR (payment_date = ? AND id <= ?))",
            [(int) $p['assignment_id'], $p['payment_date'], $p['payment_date'], $id]
        );
        $p['paid_to_date'] = $paidToDate;
        $p['balance_after'] = Money::sub($p['total_payable'], $paidToDate);
        $p['amount_words'] = Money::inWords($p['amount']);
        Audit::log('fee_receipt.print', 'fee_payment', $id);
        self::render('receipt', ['p' => $p]);
    }

    public static function salarySlip(int $id): never
    {
        Auth::requireSuperAdmin();
        $sp = Database::one(
            'SELECT sp.*, t.employee_id, t.full_name, t.designation, t.date_of_joining, d.name AS department_name,
                    ss.basic_salary, ss.hra, ss.da, ss.ta, ss.other_allowances, ss.pf_deduction, ss.professional_tax, ss.other_deductions
               FROM salary_payments sp
               JOIN staff t ON t.id = sp.staff_id
               JOIN salary_structures ss ON ss.id = sp.salary_structure_id
          LEFT JOIN departments d ON d.id = t.department_id
              WHERE sp.id = ?',
            [$id]
        ) ?? throw HttpException::notFound('Salary record not found.');
        $sp['net_words'] = Money::inWords($sp['net_salary']);
        Audit::log('salary_slip.print', 'salary_payment', $id);
        self::render('salary_slip', [
            'sp' => $sp, 'earnings' => SalaryController::EARNINGS, 'deductions' => SalaryController::DEDUCTIONS,
        ]);
    }
}
