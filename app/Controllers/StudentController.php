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
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Core\Validator;

final class StudentController
{
    private const SELECT = 's.id, s.admission_no, s.full_name, s.father_name, s.mother_name, s.date_of_birth, s.gender,
        s.contact_number, s.parent_contact_number, s.email, s.address, s.course_id, s.department_id, s.current_year,
        s.current_semester, s.admission_date, s.academic_year_id, s.status, s.created_at, s.updated_at,
        (s.photo IS NOT NULL) AS has_photo,
        c.name AS course_name, c.code AS course_code, d.name AS department_name, ay.name AS academic_year';

    private const FROM = 'FROM students s
        JOIN courses c ON c.id = s.course_id
        JOIN departments d ON d.id = s.department_id
        JOIN academic_years ay ON ay.id = s.academic_year_id';

    private const SORT = [
        'admission_no' => 's.admission_no', 'full_name' => 's.full_name', 'course' => 'c.name',
        'department' => 'd.name', 'semester' => 's.current_semester', 'status' => 's.status',
        'admission_date' => 's.admission_date', 'academic_year' => 'ay.start_date',
    ];

    private static function filters(): ListQuery
    {
        $q = new ListQuery();
        $q->search(Request::queryStr('q'), ['s.full_name', 's.admission_no', 'c.name', 'c.code', 'd.name', 's.contact_number', 's.email']);
        $q->when(Request::queryInt('academic_year_id'), 's.academic_year_id = ?');
        $q->when(Request::queryInt('semester'), 's.current_semester = ?');
        $q->when(Request::queryInt('department_id'), 's.department_id = ?');
        $q->when(Request::queryInt('course_id'), 's.course_id = ?');
        $status = Request::queryStr('status');
        if (in_array($status, ['Active', 'Inactive', 'Graduated'], true)) {
            $q->add('s.status = ?', $status);
        }
        return $q;
    }

    public static function index(): never
    {
        Auth::requirePermission('students.view');
        $result = self::filters()->paginate(self::SELECT, self::FROM, ListQuery::orderBy(self::SORT, 'full_name'));
        Response::ok($result);
    }

    public static function export(): never
    {
        Auth::requirePermission('students.view');
        $rows = self::filters()->all(self::SELECT, self::FROM, ListQuery::orderBy(self::SORT, 'admission_no'));
        Audit::log('student.export', 'student', null, null, ['rows' => count($rows)]);
        Csv::download('students', [
            'admission_no' => 'Admission No', 'full_name' => 'Full Name', 'father_name' => "Father's Name",
            'mother_name' => "Mother's Name", 'date_of_birth' => 'Date of Birth', 'gender' => 'Gender',
            'contact_number' => 'Contact', 'parent_contact_number' => 'Parent Contact', 'email' => 'Email',
            'address' => 'Address', 'course_name' => 'Course', 'department_name' => 'Department',
            'current_year' => 'Year', 'current_semester' => 'Semester', 'admission_date' => 'Admission Date',
            'academic_year' => 'Academic Year', 'status' => 'Status',
        ], $rows);
    }

    private static function find(int $id): array
    {
        return Database::one('SELECT ' . self::SELECT . ' ' . self::FROM . ' WHERE s.id = ?', [$id])
            ?? throw HttpException::notFound('Student not found.');
    }

    public static function show(int $id): never
    {
        Auth::requirePermission('students.view');
        $student = self::find($id);
        $data = ['student' => $student];
        if (Auth::can('fees.view')) {
            $data['fees'] = [
                'summary'     => FeeLedger::studentSummary($id),
                'assignments' => FeeLedger::assignmentsForStudent($id),
                'payments'    => Database::all(
                    'SELECT fp.id, fp.receipt_no, fp.payment_date, fp.amount, fp.payment_mode, fp.transaction_ref,
                            fp.remarks, fp.status, fp.assignment_id, a.title AS assignment_title, u.full_name AS recorded_by_name
                       FROM fee_payments fp
                       JOIN student_fee_assignments a ON a.id = fp.assignment_id
                  LEFT JOIN users u ON u.id = fp.recorded_by
                      WHERE fp.student_id = ?
                   ORDER BY fp.payment_date DESC, fp.id DESC',
                    [$id]
                ),
            ];
        }
        Response::ok($data);
    }

    public static function photo(int $id): never
    {
        Auth::requirePermission('students.view');
        $name = Database::value('SELECT photo FROM students WHERE id = ?', [$id]);
        Upload::stream('students', is_string($name) ? $name : null);
    }

    private static function validated(int $id = 0): array
    {
        $d = Validator::make(Request::body(), [
            'admission_no'          => 'nullable|code|max:30',
            'full_name'             => 'required|max:150',
            'father_name'           => 'nullable|max:150',
            'mother_name'           => 'nullable|max:150',
            'date_of_birth'         => 'nullable|date|before_today',
            'gender'                => 'required|in:Male,Female,Other',
            'contact_number'        => 'nullable|phone',
            'parent_contact_number' => 'nullable|phone',
            'email'                 => 'nullable|email',
            'address'               => 'nullable|max:500',
            'course_id'             => 'required|int',
            'current_year'          => 'nullable|int|min_value:1|max_value:8',
            'current_semester'      => 'required|int|min_value:1|max_value:16',
            'admission_date'        => 'required|date',
            'academic_year_id'      => 'required|int',
            'status'                => 'required|in:Active,Inactive,Graduated',
        ], [
            'current_semester' => 'Semester', 'current_year' => 'Year', 'course_id' => 'Course',
            'academic_year_id' => 'Academic year', 'date_of_birth' => 'Date of birth',
        ]);

        $course = Database::one('SELECT id, code, department_id, total_semesters, duration_years FROM courses WHERE id = ?', [$d['course_id']]);
        if (!$course) {
            throw HttpException::validation(['course_id' => 'Select a valid course.']);
        }
        if ($d['current_semester'] > (int) $course['total_semesters']) {
            throw HttpException::validation(['current_semester' => "This course has only {$course['total_semesters']} semesters."]);
        }
        if (!Database::value('SELECT id FROM academic_years WHERE id = ?', [$d['academic_year_id']])) {
            throw HttpException::validation(['academic_year_id' => 'Select a valid academic year.']);
        }
        if ($d['date_of_birth'] !== null && $d['date_of_birth'] >= $d['admission_date']) {
            throw HttpException::validation(['date_of_birth' => 'Date of birth must be before the admission date.']);
        }
        // Department always follows the course, keeping the data consistent.
        $d['department_id'] = (int) $course['department_id'];
        $d['current_year'] ??= (int) ceil($d['current_semester'] / 2);

        if ($d['admission_no'] === null) {
            if ($id) {
                unset($d['admission_no']);
            } else {
                $d['admission_no'] = self::nextAdmissionNo($course['code'], $d['admission_date']);
            }
        } else {
            $d['admission_no'] = strtoupper($d['admission_no']);
            $dupe = Database::value('SELECT id FROM students WHERE admission_no = ? AND id <> ?', [$d['admission_no'], $id]);
            if ($dupe) {
                throw HttpException::validation(['admission_no' => 'This admission number is already in use.']);
            }
        }
        return $d;
    }

    private static function nextAdmissionNo(string $courseCode, string $admissionDate): string
    {
        $prefix = 'ADM' . substr($admissionDate, 2, 2) . preg_replace('/[^A-Z0-9]/', '', strtoupper($courseCode));
        $n = (int) Database::value('SELECT COUNT(*) FROM students WHERE admission_no LIKE ?', [$prefix . '%']) + 1;
        do {
            $candidate = $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $n++;
        } while (Database::value('SELECT id FROM students WHERE admission_no = ?', [$candidate]));
        return $candidate;
    }

    public static function store(): never
    {
        Auth::requirePermission('students.manage');
        $d = self::validated();
        $photo = Upload::photo('photo', 'students');
        $d['photo'] = $photo;
        $d['created_by'] = Auth::id();
        try {
            $id = Database::insert('students', $d);
        } catch (\Throwable $e) {
            Upload::delete('students', $photo);
            throw $e;
        }
        Audit::log('student.create', 'student', $id, null, $d);
        Response::ok(['id' => $id], 'Student added successfully.');
    }

    public static function update(int $id): never
    {
        Auth::requirePermission('students.manage');
        $old = Database::one('SELECT * FROM students WHERE id = ?', [$id]) ?? throw HttpException::notFound('Student not found.');
        $d = self::validated($id);
        $photo = Upload::photo('photo', 'students');
        if ($photo) {
            $d['photo'] = $photo;
        } elseif (Request::input('remove_photo') === '1') {
            $d['photo'] = null;
        }
        Database::update('students', $id, $d);
        if (array_key_exists('photo', $d) && $old['photo'] && $old['photo'] !== $d['photo']) {
            Upload::delete('students', $old['photo']);
        }
        Audit::log('student.update', 'student', $id, ...Audit::diff($old, $d));
        Response::ok(['id' => $id], 'Student details updated.');
    }

    public static function destroy(int $id): never
    {
        Auth::requirePermission('students.delete');
        $old = Database::one('SELECT * FROM students WHERE id = ?', [$id]) ?? throw HttpException::notFound('Student not found.');
        $hasFees = (int) Database::value('SELECT COUNT(*) FROM student_fee_assignments WHERE student_id = ?', [$id]);
        if ($hasFees) {
            throw new HttpException('This student has fee records and cannot be deleted. Mark the student as Inactive instead.', 409);
        }
        Database::execute('DELETE FROM students WHERE id = ?', [$id]);
        Upload::delete('students', $old['photo']);
        Audit::log('student.delete', 'student', $id, $old);
        Response::ok([], 'Student deleted.');
    }
}
