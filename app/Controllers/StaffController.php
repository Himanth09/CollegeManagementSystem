<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csv;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\ListQuery;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Core\Validator;

/** Staff directory. Contains NO salary data - see SalaryController (Super Admin only). */
final class StaffController
{
    public const STATUSES = ['Active', 'On Leave', 'Resigned', 'Retired', 'Terminated'];

    private const SELECT = 't.id, t.employee_id, t.full_name, t.designation, t.department_id, t.contact_number, t.email,
        t.address, t.date_of_joining, t.employment_status, t.qualification, t.created_at, t.updated_at,
        (t.photo IS NOT NULL) AS has_photo, d.name AS department_name,
        (SELECT u.username FROM users u WHERE u.staff_id = t.id) AS username';
    private const FROM = 'FROM staff t LEFT JOIN departments d ON d.id = t.department_id';
    private const SORT = [
        'employee_id' => 't.employee_id', 'full_name' => 't.full_name', 'designation' => 't.designation',
        'department' => 'd.name', 'date_of_joining' => 't.date_of_joining', 'status' => 't.employment_status',
    ];

    private static function filters(): ListQuery
    {
        $q = new ListQuery();
        $q->search(Request::queryStr('q'), ['t.full_name', 't.employee_id', 't.designation', 't.email', 't.contact_number', 'd.name']);
        $q->when(Request::queryInt('department_id'), 't.department_id = ?');
        $q->when(Request::queryStr('designation'), 't.designation = ?');
        $status = Request::queryStr('status');
        if (in_array($status, self::STATUSES, true)) {
            $q->add('t.employment_status = ?', $status);
        }
        return $q;
    }

    public static function index(): never
    {
        Auth::requirePermission('staff.view');
        Response::ok(self::filters()->paginate(self::SELECT, self::FROM, ListQuery::orderBy(self::SORT, 'full_name')));
    }

    /** Department-wise headcount summary. */
    public static function byDepartment(): never
    {
        Auth::requirePermission('staff.view');
        Response::ok(['data' => Database::all(
            "SELECT d.id, d.name, COUNT(t.id) AS total,
                    SUM(t.employment_status = 'Active') AS active
               FROM departments d LEFT JOIN staff t ON t.department_id = d.id
           GROUP BY d.id, d.name ORDER BY d.name"
        )]);
    }

    public static function export(): never
    {
        Auth::requirePermission('staff.view');
        $rows = self::filters()->all(self::SELECT, self::FROM, ListQuery::orderBy(self::SORT, 'employee_id'));
        Audit::log('staff.export', 'staff', null, null, ['rows' => count($rows)]);
        Csv::download('staff', [
            'employee_id' => 'Employee ID', 'full_name' => 'Full Name', 'designation' => 'Designation',
            'department_name' => 'Department', 'contact_number' => 'Contact', 'email' => 'Email', 'address' => 'Address',
            'date_of_joining' => 'Date of Joining', 'employment_status' => 'Status', 'qualification' => 'Qualification',
        ], $rows);
    }

    private static function find(int $id): array
    {
        return Database::one('SELECT ' . self::SELECT . ' ' . self::FROM . ' WHERE t.id = ?', [$id])
            ?? throw HttpException::notFound('Staff member not found.');
    }

    private static function isOwnRecord(int $staffId): bool
    {
        $u = Auth::user();
        return $u && $u['staff_id'] !== null && (int) $u['staff_id'] === $staffId;
    }

    public static function show(int $id): never
    {
        Auth::requireLogin();
        if (!Auth::can('staff.view') && !self::isOwnRecord($id)) {
            throw HttpException::forbidden();
        }
        Response::ok(['staff' => self::find($id)]);
    }

    public static function photo(int $id): never
    {
        Auth::requireLogin();
        if (!Auth::can('staff.view') && !self::isOwnRecord($id)) {
            throw HttpException::forbidden();
        }
        $name = Database::value('SELECT photo FROM staff WHERE id = ?', [$id]);
        Upload::stream('staff', is_string($name) ? $name : null);
    }

    /** Logged-in user's own profile (any role). */
    public static function profile(): never
    {
        $u = Auth::requireLogin();
        $staff = $u['staff_id'] !== null ? self::find((int) $u['staff_id']) : null;
        Response::ok(['user' => Auth::publicUser(), 'staff' => $staff]);
    }

    private static function validated(int $id = 0): array
    {
        $d = Validator::make(Request::body(), [
            'employee_id'       => 'required|code|max:30',
            'full_name'         => 'required|max:150',
            'designation'       => 'required|max:100',
            'department_id'     => 'nullable|int',
            'contact_number'    => 'nullable|phone',
            'email'             => 'nullable|email',
            'address'           => 'nullable|max:500',
            'date_of_joining'   => 'nullable|date',
            'employment_status' => 'required|in:' . implode(',', self::STATUSES),
            'qualification'     => 'nullable|max:255',
        ], ['employee_id' => 'Employee ID', 'employment_status' => 'Employment status']);
        $d['employee_id'] = strtoupper($d['employee_id']);
        if ($d['department_id'] && !Database::value('SELECT id FROM departments WHERE id = ?', [$d['department_id']])) {
            throw HttpException::validation(['department_id' => 'Select a valid department.']);
        }
        if (Database::value('SELECT id FROM staff WHERE employee_id = ? AND id <> ?', [$d['employee_id'], $id])) {
            throw HttpException::validation(['employee_id' => 'This employee ID is already in use.']);
        }
        return $d;
    }

    public static function store(): never
    {
        Auth::requirePermission('staff.manage');
        $d = self::validated();
        $d['photo'] = Upload::photo('photo', 'staff');
        try {
            $id = Database::insert('staff', $d);
        } catch (\Throwable $e) {
            Upload::delete('staff', $d['photo']);
            throw $e;
        }
        Audit::log('staff.create', 'staff', $id, null, $d);
        Response::ok(['id' => $id], 'Staff member added.');
    }

    public static function update(int $id): never
    {
        Auth::requirePermission('staff.manage');
        $old = Database::one('SELECT * FROM staff WHERE id = ?', [$id]) ?? throw HttpException::notFound('Staff member not found.');
        $d = self::validated($id);
        $photo = Upload::photo('photo', 'staff');
        if ($photo) {
            $d['photo'] = $photo;
        } elseif (Request::input('remove_photo') === '1') {
            $d['photo'] = null;
        }
        Database::update('staff', $id, $d);
        if (array_key_exists('photo', $d) && $old['photo'] && $old['photo'] !== $d['photo']) {
            Upload::delete('staff', $old['photo']);
        }
        Audit::log('staff.update', 'staff', $id, ...Audit::diff($old, $d));
        Response::ok(['id' => $id], 'Staff details updated.');
    }

    public static function destroy(int $id): never
    {
        Auth::requireSuperAdmin();
        $old = Database::one('SELECT * FROM staff WHERE id = ?', [$id]) ?? throw HttpException::notFound('Staff member not found.');
        if ((int) Database::value('SELECT COUNT(*) FROM salary_structures WHERE staff_id = ?', [$id])) {
            throw new HttpException('This staff member has salary records and cannot be deleted. Change the employment status instead.', 409);
        }
        Database::execute('DELETE FROM staff WHERE id = ?', [$id]);
        Upload::delete('staff', $old['photo']);
        Audit::log('staff.delete', 'staff', $id, $old);
        Response::ok([], 'Staff member deleted.');
    }
}
