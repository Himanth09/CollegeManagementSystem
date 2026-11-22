<?php
declare(strict_types=1);

/**
 * JSON API front controller.  Usage: api/index.php?r=/students/5
 * Authorization is enforced inside every controller method on the server.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\AuditController as Audit;
use App\Controllers\AuthController as AuthC;
use App\Controllers\BackupController as Backup;
use App\Controllers\DashboardController as Dashboard;
use App\Controllers\FeeController as Fee;
use App\Controllers\MasterController as Master;
use App\Controllers\PrintController as Prints;
use App\Controllers\ReportController as Report;
use App\Controllers\SalaryController as Salary;
use App\Controllers\StaffController as Staff;
use App\Controllers\StudentController as Student;
use App\Controllers\UserController as User;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

Response::securityHeaders();

if (is_file(BASE_PATH . '/install/index.php') && !is_file(STORAGE_PATH . '/install.lock')) {
    Response::error('The application is not installed yet. Open /install/ to complete setup.', 503, [], ['install_required' => true]);
}

$r = new Router();

// ---- auth
$r->get('/auth/me', [AuthC::class, 'me']);
$r->post('/auth/login', [AuthC::class, 'login']);
$r->post('/auth/logout', [AuthC::class, 'logout']);
$r->post('/auth/forgot', [AuthC::class, 'forgot']);
$r->post('/auth/reset', [AuthC::class, 'reset']);
$r->post('/auth/change-password', [AuthC::class, 'changePassword']);
$r->get('/profile', [Staff::class, 'profile']);

// ---- dashboard & lookups
$r->get('/dashboard', [Dashboard::class, 'index']);
$r->get('/meta', [Master::class, 'meta']);

// ---- masters
$r->get('/departments', [Master::class, 'departments']);
$r->post('/departments', [Master::class, 'saveDepartment']);
$r->post('/departments/{id}', [Master::class, 'saveDepartment']);
$r->post('/departments/{id}/delete', [Master::class, 'deleteDepartment']);
$r->get('/courses', [Master::class, 'courses']);
$r->post('/courses', [Master::class, 'saveCourse']);
$r->post('/courses/{id}', [Master::class, 'saveCourse']);
$r->post('/courses/{id}/delete', [Master::class, 'deleteCourse']);
$r->get('/academic-years', [Master::class, 'academicYears']);
$r->post('/academic-years', [Master::class, 'saveAcademicYear']);
$r->post('/academic-years/{id}', [Master::class, 'saveAcademicYear']);
$r->post('/academic-years/{id}/delete', [Master::class, 'deleteAcademicYear']);

// ---- students
$r->get('/students', [Student::class, 'index']);
$r->get('/students/export', [Student::class, 'export']);
$r->post('/students', [Student::class, 'store']);
$r->get('/students/{id}', [Student::class, 'show']);
$r->post('/students/{id}', [Student::class, 'update']);
$r->post('/students/{id}/delete', [Student::class, 'destroy']);
$r->get('/students/{id}/photo', [Student::class, 'photo']);

// ---- fees
$r->get('/fees/structures', [Fee::class, 'structures']);
$r->post('/fees/structures', [Fee::class, 'saveStructure']);
$r->post('/fees/structures/{id}', [Fee::class, 'saveStructure']);
$r->post('/fees/structures/{id}/delete', [Fee::class, 'deleteStructure']);
$r->post('/fees/assignments', [Fee::class, 'assign']);
$r->post('/fees/assignments/bulk', [Fee::class, 'bulkAssign']);
$r->post('/fees/assignments/{id}', [Fee::class, 'updateAssignment']);
$r->post('/fees/assignments/{id}/delete', [Fee::class, 'deleteAssignment']);
$r->get('/fees/payments', [Fee::class, 'payments']);
$r->get('/fees/payments/export', [Fee::class, 'exportPayments']);
$r->post('/fees/payments', [Fee::class, 'recordPayment']);
$r->get('/fees/payments/{id}', [Fee::class, 'showPayment']);
$r->post('/fees/payments/{id}/review', [Fee::class, 'reviewPayment']);
$r->post('/fees/payments/{id}/cancel', [Fee::class, 'cancelPayment']);
$r->get('/fees/dues', [Fee::class, 'dues']);
$r->get('/fees/dues/export', [Fee::class, 'exportDues']);

// ---- staff (no salary data)
$r->get('/staff', [Staff::class, 'index']);
$r->get('/staff/export', [Staff::class, 'export']);
$r->get('/staff/departments', [Staff::class, 'byDepartment']);
$r->post('/staff', [Staff::class, 'store']);
$r->get('/staff/{id}', [Staff::class, 'show']);
$r->post('/staff/{id}', [Staff::class, 'update']);
$r->post('/staff/{id}/delete', [Staff::class, 'destroy']);
$r->get('/staff/{id}/photo', [Staff::class, 'photo']);

// ---- salary (Super Admin only - enforced in every SalaryController method)
$r->get('/salary/structures', [Salary::class, 'structures']);
$r->post('/salary/structures', [Salary::class, 'saveStructure']);
$r->get('/salary/history/{id}', [Salary::class, 'history']);
$r->get('/salary/payroll', [Salary::class, 'payroll']);
$r->get('/salary/payroll/export', [Salary::class, 'exportPayroll']);
$r->post('/salary/payroll/generate', [Salary::class, 'generate']);
$r->post('/salary/payroll/pay-all', [Salary::class, 'payAll']);
$r->post('/salary/payments/{id}/pay', [Salary::class, 'markPaid']);
$r->post('/salary/payments/{id}/delete', [Salary::class, 'deletePending']);

// ---- printable documents
$r->get('/print/receipt/{id}', [Prints::class, 'receipt']);
$r->get('/print/salary-slip/{id}', [Prints::class, 'salarySlip']);

// ---- reports
$r->get('/reports/admissions', [Report::class, 'admissions']);
$r->get('/reports/fee-collection', [Report::class, 'feeCollection']);
$r->get('/reports/pending-dues', [Report::class, 'pendingDues']);
$r->get('/reports/overdue', [Report::class, 'overdue']);
$r->get('/reports/course-financial', [Report::class, 'courseFinancial']);
$r->get('/reports/semester-collection', [Report::class, 'semesterCollection']);
$r->get('/reports/staff', [Report::class, 'staff']);
$r->get('/reports/salary-expenditure', [Report::class, 'salaryExpenditure']);
$r->get('/reports/monthly-summary', [Report::class, 'monthlySummary']);

// ---- administration
$r->get('/users', [User::class, 'index']);
$r->post('/users', [User::class, 'save']);
$r->post('/users/{id}', [User::class, 'save']);
$r->get('/audit', [Audit::class, 'index']);
$r->get('/backups', [Backup::class, 'index']);
$r->post('/backups', [Backup::class, 'create']);
$r->get('/backups/download', [Backup::class, 'download']);
$r->post('/backups/delete', [Backup::class, 'delete']);

$route = $_GET['r'] ?? '/';
$r->dispatch(Request::method(), is_string($route) ? $route : '/');
