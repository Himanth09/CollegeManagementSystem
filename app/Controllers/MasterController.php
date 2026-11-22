<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** Departments, courses and academic years. */
final class MasterController
{
    /** Lookup lists used by dropdowns across the UI. */
    public static function meta(): never
    {
        Auth::requireLogin();
        Response::ok([
            'departments'    => Database::all('SELECT id, code, name, is_active FROM departments ORDER BY name'),
            'courses'        => Database::all('SELECT c.id, c.code, c.name, c.department_id, c.total_semesters, c.duration_years, c.is_active
                                                 FROM courses c ORDER BY c.name'),
            'academic_years' => Database::all('SELECT id, name, start_date, end_date, is_current FROM academic_years ORDER BY start_date DESC'),
            'designations'   => Database::column('SELECT DISTINCT designation FROM staff ORDER BY designation'),
        ]);
    }

    // ---------------------------------------------------------------- departments
    public static function departments(): never
    {
        Auth::requireLogin();
        Response::ok(['data' => Database::all(
            'SELECT d.*, (SELECT COUNT(*) FROM students s WHERE s.department_id = d.id) AS student_count,
                    (SELECT COUNT(*) FROM staff t WHERE t.department_id = d.id) AS staff_count,
                    (SELECT COUNT(*) FROM courses c WHERE c.department_id = d.id) AS course_count
               FROM departments d ORDER BY d.name'
        )]);
    }

    public static function saveDepartment(int $id = 0): never
    {
        Auth::requirePermission('masters.manage');
        $d = Validator::make(Request::body(), [
            'code'      => 'required|code|max:20',
            'name'      => 'required|max:120',
            'is_active' => 'nullable|bool',
        ]);
        $d['code'] = strtoupper($d['code']);
        $d['is_active'] = in_array($d['is_active'], ['0', 'false'], true) ? 0 : 1;
        if ($id) {
            $old = Database::one('SELECT * FROM departments WHERE id = ?', [$id]) ?? throw HttpException::notFound();
            Database::update('departments', $id, $d);
            Audit::log('department.update', 'department', $id, ...Audit::diff($old, $d));
        } else {
            $id = Database::insert('departments', $d);
            Audit::log('department.create', 'department', $id, null, $d);
        }
        Response::ok(['id' => $id], 'Department saved.');
    }

    public static function deleteDepartment(int $id): never
    {
        Auth::requirePermission('masters.manage');
        $old = Database::one('SELECT * FROM departments WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        Database::execute('DELETE FROM departments WHERE id = ?', [$id]); // FK RESTRICT protects linked rows
        Audit::log('department.delete', 'department', $id, $old);
        Response::ok([], 'Department deleted.');
    }

    // ---------------------------------------------------------------- courses
    public static function courses(): never
    {
        Auth::requireLogin();
        Response::ok(['data' => Database::all(
            'SELECT c.*, d.name AS department_name,
                    (SELECT COUNT(*) FROM students s WHERE s.course_id = c.id) AS student_count
               FROM courses c JOIN departments d ON d.id = c.department_id ORDER BY c.name'
        )]);
    }

    public static function saveCourse(int $id = 0): never
    {
        Auth::requirePermission('masters.manage');
        $d = Validator::make(Request::body(), [
            'department_id'   => 'required|int',
            'code'            => 'required|code|max:20',
            'name'            => 'required|max:150',
            'duration_years'  => 'required|int|min_value:1|max_value:8',
            'total_semesters' => 'required|int|min_value:1|max_value:16',
            'is_active'       => 'nullable|bool',
        ]);
        $d['code'] = strtoupper($d['code']);
        $d['is_active'] = in_array($d['is_active'], ['0', 'false'], true) ? 0 : 1;
        if (!Database::value('SELECT id FROM departments WHERE id = ?', [$d['department_id']])) {
            throw HttpException::validation(['department_id' => 'Select a valid department.']);
        }
        if ($id) {
            $old = Database::one('SELECT * FROM courses WHERE id = ?', [$id]) ?? throw HttpException::notFound();
            Database::update('courses', $id, $d);
            Audit::log('course.update', 'course', $id, ...Audit::diff($old, $d));
        } else {
            $id = Database::insert('courses', $d);
            Audit::log('course.create', 'course', $id, null, $d);
        }
        Response::ok(['id' => $id], 'Course saved.');
    }

    public static function deleteCourse(int $id): never
    {
        Auth::requirePermission('masters.manage');
        $old = Database::one('SELECT * FROM courses WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        Database::execute('DELETE FROM courses WHERE id = ?', [$id]);
        Audit::log('course.delete', 'course', $id, $old);
        Response::ok([], 'Course deleted.');
    }

    // ---------------------------------------------------------------- academic years
    public static function academicYears(): never
    {
        Auth::requireLogin();
        Response::ok(['data' => Database::all(
            'SELECT a.*, (SELECT COUNT(*) FROM students s WHERE s.academic_year_id = a.id) AS student_count
               FROM academic_years a ORDER BY a.start_date DESC'
        )]);
    }

    public static function saveAcademicYear(int $id = 0): never
    {
        Auth::requirePermission('masters.manage');
        $d = Validator::make(Request::body(), [
            'name'       => 'required|max:20',
            'start_date' => 'required|date',
            'end_date'   => 'required|date',
            'is_current' => 'nullable|bool',
        ]);
        if ($d['end_date'] <= $d['start_date']) {
            throw HttpException::validation(['end_date' => 'End date must be after the start date.']);
        }
        $d['is_current'] = in_array($d['is_current'], ['1', 'true'], true) ? 1 : 0;
        Database::transaction(static function () use (&$id, $d) {
            if ($d['is_current']) {
                Database::execute('UPDATE academic_years SET is_current = 0');
            }
            if ($id) {
                $old = Database::one('SELECT * FROM academic_years WHERE id = ?', [$id]) ?? throw HttpException::notFound();
                Database::update('academic_years', $id, $d);
                Audit::log('academic_year.update', 'academic_year', $id, ...Audit::diff($old, $d));
            } else {
                $id = Database::insert('academic_years', $d);
                Audit::log('academic_year.create', 'academic_year', $id, null, $d);
            }
        });
        Response::ok(['id' => $id], 'Academic year saved.');
    }

    public static function deleteAcademicYear(int $id): never
    {
        Auth::requirePermission('masters.manage');
        $old = Database::one('SELECT * FROM academic_years WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        Database::execute('DELETE FROM academic_years WHERE id = ?', [$id]);
        Audit::log('academic_year.delete', 'academic_year', $id, $old);
        Response::ok([], 'Academic year deleted.');
    }
}
