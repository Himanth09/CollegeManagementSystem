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
use App\Core\Validator;

final class FeeController
{
    public const COMPONENTS = [
        'admission_fee' => 'Admission Fee', 'tuition_fee' => 'Tuition Fee', 'examination_fee' => 'Examination Fee',
        'library_fee' => 'Library Fee', 'laboratory_fee' => 'Laboratory Fee', 'transport_fee' => 'Transport Fee',
        'hostel_fee' => 'Hostel Fee', 'misc_fee' => 'Miscellaneous Charges',
    ];
    public const MODES = ['Cash', 'UPI', 'Bank Transfer', 'Card'];

    private static function componentRules(string $base = 'nullable'): array
    {
        $rules = [];
        foreach (array_keys(self::COMPONENTS) as $c) {
            $rules[$c] = "$base|money";
        }
        return $rules;
    }

    // ================================================================ fee structures
    public static function structures(): never
    {
        Auth::requirePermission('fees.view', 'fees.collect');
        $q = new ListQuery();
        $q->when(Request::queryInt('course_id'), 'f.course_id = ?');
        $q->when(Request::queryInt('academic_year_id'), 'f.academic_year_id = ?');
        $q->when(Request::queryInt('semester'), 'f.semester = ?');
        if (Request::queryStr('active') === '1') {
            $q->add('f.is_active = 1');
        }
        $rows = $q->all(
            'f.*, c.name AS course_name, c.code AS course_code, ay.name AS academic_year,
             (SELECT COUNT(*) FROM student_fee_assignments a WHERE a.fee_structure_id = f.id) AS assigned_count',
            'FROM fee_structures f JOIN courses c ON c.id = f.course_id JOIN academic_years ay ON ay.id = f.academic_year_id',
            'ORDER BY ay.start_date DESC, c.name, f.semester'
        );
        Response::ok(['data' => $rows]);
    }

    public static function saveStructure(int $id = 0): never
    {
        Auth::requirePermission('fees.configure');
        $d = Validator::make(Request::body(), [
            'name'             => 'required|max:150',
            'course_id'        => 'required|int',
            'academic_year_id' => 'required|int',
            'semester'         => 'required|int|min_value:1|max_value:16',
            'due_date'         => 'nullable|date',
            'is_active'        => 'nullable|bool',
        ] + self::componentRules());
        foreach (array_keys(self::COMPONENTS) as $c) {
            $d[$c] = Money::normalize($d[$c] ?? '0');
        }
        $d['is_active'] = in_array($d['is_active'], ['0', 'false'], true) ? 0 : 1;
        $course = Database::one('SELECT total_semesters FROM courses WHERE id = ?', [$d['course_id']]);
        if (!$course) {
            throw HttpException::validation(['course_id' => 'Select a valid course.']);
        }
        if ($d['semester'] > (int) $course['total_semesters']) {
            throw HttpException::validation(['semester' => 'Semester exceeds the course duration.']);
        }
        if (!Database::value('SELECT id FROM academic_years WHERE id = ?', [$d['academic_year_id']])) {
            throw HttpException::validation(['academic_year_id' => 'Select a valid academic year.']);
        }
        if (Money::toPaise(Money::sum(...array_map(static fn ($c) => $d[$c], array_keys(self::COMPONENTS)))) <= 0) {
            throw HttpException::validation(['tuition_fee' => 'At least one fee component must be greater than zero.']);
        }
        if ($id) {
            $old = Database::one('SELECT * FROM fee_structures WHERE id = ?', [$id]) ?? throw HttpException::notFound();
            Database::update('fee_structures', $id, $d);
            Audit::log('fee_structure.update', 'fee_structure', $id, ...Audit::diff($old, $d));
            $msg = 'Fee structure updated. Existing student assignments keep their original amounts.';
        } else {
            $d['created_by'] = Auth::id();
            $id = Database::insert('fee_structures', $d);
            Audit::log('fee_structure.create', 'fee_structure', $id, null, $d);
            $msg = 'Fee structure created.';
        }
        Response::ok(['id' => $id], $msg);
    }

    public static function deleteStructure(int $id): never
    {
        Auth::requirePermission('fees.configure');
        $old = Database::one('SELECT * FROM fee_structures WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        if ((int) Database::value('SELECT COUNT(*) FROM student_fee_assignments WHERE fee_structure_id = ?', [$id])) {
            throw new HttpException('This structure is assigned to students. Deactivate it instead of deleting.', 409);
        }
        Database::execute('DELETE FROM fee_structures WHERE id = ?', [$id]);
        Audit::log('fee_structure.delete', 'fee_structure', $id, $old);
        Response::ok([], 'Fee structure deleted.');
    }

    // ================================================================ assignments
    /** Assign a fee structure (or a custom fee) to a single student. */
    public static function assign(): never
    {
        Auth::requirePermission('fees.collect', 'fees.configure');
        $canConfigure = Auth::can('fees.configure');
        $in = Request::body();
        $d = Validator::make($in, [
            'student_id'         => 'required|int',
            'fee_structure_id'   => 'nullable|int',
            'title'              => 'nullable|max:150',
            'academic_year_id'   => 'nullable|int',
            'semester'           => 'nullable|int|min_value:1|max_value:16',
            'scholarship_amount' => 'nullable|money',
            'discount_amount'    => 'nullable|money',
            'due_date'           => 'nullable|date',
            'remarks'            => 'nullable|max:255',
        ] + self::componentRules());

        $student = Database::one('SELECT id, full_name FROM students WHERE id = ?', [$d['student_id']])
            ?? throw HttpException::validation(['student_id' => 'Select a valid student.']);

        $row = ['student_id' => (int) $student['id'], 'remarks' => $d['remarks'], 'created_by' => Auth::id()];
        if ($d['fee_structure_id']) {
            $fs = Database::one('SELECT f.*, ay.name AS ay_name FROM fee_structures f JOIN academic_years ay ON ay.id = f.academic_year_id WHERE f.id = ?', [$d['fee_structure_id']])
                ?? throw HttpException::validation(['fee_structure_id' => 'Select a valid fee structure.']);
            if (Database::value('SELECT id FROM student_fee_assignments WHERE student_id = ? AND fee_structure_id = ?', [$student['id'], $fs['id']])) {
                throw HttpException::validation(['fee_structure_id' => 'This fee structure is already assigned to the student.']);
            }
            $row += [
                'fee_structure_id' => (int) $fs['id'],
                'academic_year_id' => (int) $fs['academic_year_id'],
                'semester'         => (int) $fs['semester'],
                'title'            => $d['title'] ?? "{$fs['name']} ({$fs['ay_name']})",
                'due_date'         => $d['due_date'] ?? $fs['due_date'],
            ];
            foreach (array_keys(self::COMPONENTS) as $c) {
                $row[$c] = $fs[$c];
            }
            // Optional services (transport / hostel) may be set by fee collectors;
            // every other component override requires fee configuration rights.
            foreach (array_keys(self::COMPONENTS) as $c) {
                if ($d[$c] === null) {
                    continue;
                }
                if (!$canConfigure && !in_array($c, ['transport_fee', 'hostel_fee'], true) && Money::cmp($d[$c], $fs[$c]) !== 0) {
                    throw HttpException::forbidden('Only administrators can change fee components other than transport and hostel.');
                }
                $row[$c] = $d[$c];
            }
        } else {
            if (!$canConfigure) {
                throw HttpException::forbidden('Only administrators can create custom fee demands. Choose a fee structure.');
            }
            $errors = [];
            if (!$d['title']) { $errors['title'] = 'Title is required for a custom fee.'; }
            if (!$d['academic_year_id']) { $errors['academic_year_id'] = 'Academic year is required.'; }
            if (!$d['semester']) { $errors['semester'] = 'Semester is required.'; }
            if ($errors) {
                throw HttpException::validation($errors);
            }
            $row += [
                'fee_structure_id' => null, 'title' => $d['title'], 'academic_year_id' => $d['academic_year_id'],
                'semester' => $d['semester'], 'due_date' => $d['due_date'],
            ];
            foreach (array_keys(self::COMPONENTS) as $c) {
                $row[$c] = $d[$c] ?? '0.00';
            }
        }

        $scholarship = $d['scholarship_amount'] ?? '0.00';
        $discount = $d['discount_amount'] ?? '0.00';
        if (!$canConfigure && (Money::toPaise($scholarship) > 0 || Money::toPaise($discount) > 0)) {
            throw HttpException::forbidden('Only administrators can grant scholarships or discounts.');
        }
        $row['scholarship_amount'] = $scholarship;
        $row['discount_amount'] = $discount;
        self::assertConcession($row);

        $id = Database::insert('student_fee_assignments', $row);
        Audit::log('fee_assignment.create', 'fee_assignment', $id, null, $row);
        Response::ok(['id' => $id], 'Fee assigned to ' . $student['full_name'] . '.');
    }

    private static function assertConcession(array $row): void
    {
        $gross = Money::sum(...array_map(static fn ($c) => $row[$c], array_keys(self::COMPONENTS)));
        if (Money::toPaise($gross) <= 0) {
            throw HttpException::validation(['tuition_fee' => 'Total fee must be greater than zero.']);
        }
        if (Money::cmp(Money::sum($row['scholarship_amount'], $row['discount_amount']), $gross) > 0) {
            throw HttpException::validation(['discount_amount' => 'Scholarship + discount cannot exceed the total fee of ' . Money::format($gross) . '.']);
        }
    }

    /** Admin-only: change components, scholarship or discount of an assignment. */
    public static function updateAssignment(int $id): never
    {
        Auth::requirePermission('fees.configure');
        $d = Validator::make(Request::body(), [
            'title'              => 'required|max:150',
            'scholarship_amount' => 'nullable|money',
            'discount_amount'    => 'nullable|money',
            'due_date'           => 'nullable|date',
            'remarks'            => 'nullable|max:255',
        ] + self::componentRules());
        Database::transaction(static function () use ($id, $d) {
            $a = FeeLedger::lockAssignment($id);
            $row = ['title' => $d['title'], 'due_date' => $d['due_date'], 'remarks' => $d['remarks']];
            foreach (array_keys(self::COMPONENTS) as $c) {
                $row[$c] = $d[$c] ?? $a[$c];
            }
            $row['scholarship_amount'] = $d['scholarship_amount'] ?? '0.00';
            $row['discount_amount'] = $d['discount_amount'] ?? '0.00';
            self::assertConcession($row);
            $newPayable = Money::sub(
                Money::sum(...array_map(static fn ($c) => $row[$c], array_keys(self::COMPONENTS))),
                Money::sum($row['scholarship_amount'], $row['discount_amount'])
            );
            if (Money::cmp($newPayable, $a['amount_paid']) < 0) {
                throw HttpException::validation(['discount_amount' =>
                    'New payable (' . Money::format($newPayable) . ') would be less than the amount already paid (' . Money::format($a['amount_paid']) . ').']);
            }
            Database::update('student_fee_assignments', $id, $row);
            Audit::log('fee_assignment.update', 'fee_assignment', $id, ...Audit::diff($a, $row));
        });
        Response::ok(['id' => $id], 'Fee assignment updated.');
    }

    public static function deleteAssignment(int $id): never
    {
        Auth::requirePermission('fees.configure');
        $a = Database::one('SELECT * FROM student_fee_assignments WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        if ((int) Database::value('SELECT COUNT(*) FROM fee_payments WHERE assignment_id = ?', [$id])) {
            throw new HttpException('Payments exist against this fee, so it cannot be deleted.', 409);
        }
        Database::execute('DELETE FROM student_fee_assignments WHERE id = ?', [$id]);
        Audit::log('fee_assignment.delete', 'fee_assignment', $id, $a);
        Response::ok([], 'Fee assignment removed.');
    }

    /** Assign a structure to every active student of its course + semester. */
    public static function bulkAssign(): never
    {
        Auth::requirePermission('fees.configure');
        $d = Validator::make(Request::body(), ['fee_structure_id' => 'required|int']);
        $fs = Database::one('SELECT f.*, ay.name AS ay_name FROM fee_structures f JOIN academic_years ay ON ay.id = f.academic_year_id WHERE f.id = ?', [$d['fee_structure_id']])
            ?? throw HttpException::notFound('Fee structure not found.');
        $count = Database::transaction(static function () use ($fs) {
            $students = Database::column(
                "SELECT s.id FROM students s
                  WHERE s.status = 'Active' AND s.course_id = ? AND s.current_semester = ? AND s.academic_year_id = ?
                    AND NOT EXISTS (SELECT 1 FROM student_fee_assignments a WHERE a.student_id = s.id AND a.fee_structure_id = ?)",
                [(int) $fs['course_id'], (int) $fs['semester'], (int) $fs['academic_year_id'], (int) $fs['id']]
            );
            foreach ($students as $sid) {
                $row = [
                    'student_id' => (int) $sid, 'fee_structure_id' => (int) $fs['id'],
                    'academic_year_id' => (int) $fs['academic_year_id'], 'semester' => (int) $fs['semester'],
                    'title' => "{$fs['name']} ({$fs['ay_name']})", 'due_date' => $fs['due_date'], 'created_by' => Auth::id(),
                ];
                foreach (array_keys(self::COMPONENTS) as $c) {
                    $row[$c] = $fs[$c];
                }
                Database::insert('student_fee_assignments', $row);
            }
            Audit::log('fee_assignment.bulk_create', 'fee_structure', (int) $fs['id'], null, ['students' => array_map('intval', $students)]);
            return count($students);
        });
        Response::ok(['count' => $count], $count ? "Fee structure assigned to $count student(s)." : 'All matching active students already have this fee.');
    }

    // ================================================================ payments
    private const PAY_SELECT = 'fp.id, fp.receipt_no, fp.payment_date, fp.amount, fp.payment_mode, fp.transaction_ref,
        fp.remarks, fp.status, fp.review_reason, fp.review_note, fp.reviewed_at, fp.created_at, fp.assignment_id,
        s.id AS student_id, s.admission_no, s.full_name AS student_name, c.name AS course_name, a.title AS assignment_title,
        a.semester, (SELECT u.full_name FROM users u WHERE u.id = fp.recorded_by) AS recorded_by_name,
        (SELECT rv.full_name FROM users rv WHERE rv.id = fp.reviewed_by) AS reviewed_by_name';
    private const PAY_FROM = 'FROM fee_payments fp
        JOIN students s ON s.id = fp.student_id
        JOIN courses c ON c.id = s.course_id
        JOIN student_fee_assignments a ON a.id = fp.assignment_id';
    // (User names are looked up per row: joining the tiny users table made MySQL pick a
    //  hash join that scans and sorts every payment before applying LIMIT.)
    private const PAY_SORT = [
        'payment_date' => 'fp.payment_date', 'amount' => 'fp.amount', 'student_name' => 's.full_name',
        'receipt_no' => 'fp.receipt_no', 'payment_mode' => 'fp.payment_mode', 'status' => 'fp.status', 'id' => 'fp.id',
    ];

    private static function paymentFilters(): ListQuery
    {
        $q = new ListQuery();
        if (($term = Request::queryStr('q')) !== null) {
            // Index-friendly search: exact receipt / reference, or a matching student.
            // (A substring LIKE across a joined payments table is a full scan at scale.)
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $q->add('(fp.receipt_no = ? OR fp.transaction_ref_norm = ? OR fp.student_id IN
                     (SELECT st.id FROM students st WHERE st.full_name LIKE ? OR st.admission_no LIKE ?))',
                strtoupper(trim($term)), FeeLedger::normalizeRef($term) ?? '', $like, $like);
        }
        $q->when(Request::queryDate('from'), 'fp.payment_date >= ?');
        $q->when(Request::queryDate('to'), 'fp.payment_date <= ?');
        $q->when(Request::queryInt('course_id'), 'fp.student_id IN (SELECT st.id FROM students st WHERE st.course_id = ?)');
        $q->when(Request::queryInt('student_id'), 'fp.student_id = ?');
        $mode = Request::queryStr('mode');
        if (in_array($mode, self::MODES, true)) {
            $q->add('fp.payment_mode = ?', $mode);
        }
        $status = Request::queryStr('status');
        if (in_array($status, ['confirmed', 'pending_review', 'rejected', 'cancelled'], true)) {
            $q->add('fp.status = ?', $status);
        }
        return $q;
    }

    public static function payments(): never
    {
        Auth::requirePermission('fees.view');
        $q = self::paymentFilters();
        // Filters only reference fp.*, so counts and totals skip the joins (every join is a required FK).
        $result = $q->paginate(self::PAY_SELECT, self::PAY_FROM, ListQuery::orderBy(self::PAY_SORT, 'id', 'desc'), 15, 'FROM fee_payments fp');
        $result['totals'] = Database::one(
            "SELECT COALESCE(SUM(CASE WHEN fp.status = 'confirmed' THEN fp.amount END), 0) AS confirmed_total,
                    COUNT(*) AS count FROM fee_payments fp" . $q->whereSql(),
            $q->params
        );
        $result['pending_review'] = (int) Database::value("SELECT COUNT(*) FROM fee_payments WHERE status = 'pending_review'");
        Response::ok($result);
    }

    public static function exportPayments(): never
    {
        Auth::requirePermission('fees.view');
        $rows = self::paymentFilters()->cursor(self::PAY_SELECT, self::PAY_FROM, ListQuery::orderBy(self::PAY_SORT, 'payment_date'));
        Audit::log('fee_payment.export', 'fee_payment', null, null, ['filters' => Csv::filters()]);
        Csv::download('fee_payments', [
            'receipt_no' => 'Receipt No', 'payment_date' => 'Payment Date', 'admission_no' => 'Admission No',
            'student_name' => 'Student', 'course_name' => 'Course', 'assignment_title' => 'Fee', 'amount' => 'Amount',
            'payment_mode' => 'Mode', 'transaction_ref' => 'Transaction Ref', 'status' => 'Status',
            'remarks' => 'Remarks', 'recorded_by_name' => 'Recorded By',
        ], $rows);
    }

    public static function showPayment(int $id): never
    {
        Auth::requirePermission('fees.view');
        $p = Database::one('SELECT ' . self::PAY_SELECT . ' ' . self::PAY_FROM . ' WHERE fp.id = ?', [$id])
            ?? throw HttpException::notFound('Payment not found.');
        Response::ok(['payment' => $p]);
    }

    public static function recordPayment(): never
    {
        Auth::requirePermission('fees.collect');
        $d = Validator::make(Request::body(), [
            'assignment_id'     => 'required|int',
            'amount'            => 'required|money|min_value:0.01',
            'payment_date'      => 'required|date|before_today',
            'payment_mode'      => 'required|in:' . implode(',', self::MODES),
            'transaction_ref'   => 'nullable|max:100',
            'remarks'           => 'nullable|max:255',
            'confirm_duplicate' => 'nullable|bool',
        ], ['assignment_id' => 'Fee', 'transaction_ref' => 'Transaction reference']);
        if ($d['payment_mode'] !== 'Cash' && $d['transaction_ref'] === null) {
            throw HttpException::validation(['transaction_ref' => 'Transaction reference is required for ' . $d['payment_mode'] . ' payments.']);
        }
        if ($d['payment_date'] < date('Y-m-d', strtotime('-2 years'))) {
            throw HttpException::validation(['payment_date' => 'Payment date is too far in the past.']);
        }
        $confirmDuplicate = in_array($d['confirm_duplicate'], ['1', 'true'], true);
        $isReviewer = Auth::can('fees.review');

        $result = Database::transaction(static function () use ($d, $confirmDuplicate, $isReviewer) {
            $a = FeeLedger::lockAssignment((int) $d['assignment_id']);
            if (Money::cmp($d['amount'], $a['balance']) > 0) {
                throw HttpException::validation(['amount' => 'Amount exceeds the outstanding balance of ' . Money::format($a['balance']) . '.']);
            }
            $norm = FeeLedger::normalizeRef($d['transaction_ref']);
            $status = 'confirmed';
            $reason = null;
            $reviewNote = null;

            if ($norm !== null) {
                // FOR UPDATE takes an index lock on this reference value, serialising concurrent attempts.
                $dup = Database::one(
                    "SELECT fp.id, fp.receipt_no, fp.amount, fp.payment_date, fp.status, s.full_name AS student_name
                       FROM fee_payments fp JOIN students s ON s.id = fp.student_id
                      WHERE fp.transaction_ref_norm = ? AND fp.status IN ('confirmed', 'pending_review')
                      ORDER BY fp.id LIMIT 1 FOR UPDATE",
                    [$norm]
                );
                if ($dup) {
                    if (!$confirmDuplicate) {
                        throw new HttpException(
                            'This transaction reference has already been used' .
                            ($dup['receipt_no'] ? " (receipt {$dup['receipt_no']})" : ' (awaiting review)') .
                            " for {$dup['student_name']}, " . Money::format($dup['amount']) . " on {$dup['payment_date']}.",
                            409,
                            [],
                            ['duplicate' => $dup, 'can_override' => $isReviewer]
                        );
                    }
                    $label = $dup['receipt_no'] ?: ('payment #' . $dup['id']);
                    if ($isReviewer) {
                        $reviewNote = "Duplicate reference (matches $label) approved by administrator at entry.";
                    } else {
                        $status = 'pending_review';
                        $reason = "Duplicate transaction reference (matches $label)";
                    }
                }
            } elseif (!$confirmDuplicate) {
                // Guard against accidental double submission of reference-less (cash) payments.
                $recent = Database::one(
                    "SELECT id, receipt_no FROM fee_payments
                      WHERE assignment_id = ? AND amount = ? AND payment_date = ? AND status = 'confirmed'
                        AND recorded_by = ? AND created_at >= (NOW() - INTERVAL 10 MINUTE) LIMIT 1",
                    [(int) $a['id'], $d['amount'], $d['payment_date'], Auth::id()]
                );
                if ($recent) {
                    throw new HttpException(
                        "An identical payment was recorded a few minutes ago (receipt {$recent['receipt_no']}). Submit again to confirm this is a separate payment.",
                        409, [], ['duplicate' => $recent, 'can_override' => true]
                    );
                }
            }

            $row = [
                'student_id' => (int) $a['student_id'], 'assignment_id' => (int) $a['id'],
                'payment_date' => $d['payment_date'], 'amount' => $d['amount'], 'payment_mode' => $d['payment_mode'],
                'transaction_ref' => $d['transaction_ref'], 'transaction_ref_norm' => $norm, 'remarks' => $d['remarks'],
                'status' => $status, 'review_reason' => $reason, 'review_note' => $reviewNote,
                'recorded_by' => Auth::id(),
            ];
            if ($reviewNote) {
                $row['reviewed_by'] = Auth::id();
                $row['reviewed_at'] = date('Y-m-d H:i:s');
            }
            $id = Database::insert('fee_payments', $row);
            $receipt = null;
            if ($status === 'confirmed') {
                $receipt = FeeLedger::receiptNo($id, $d['payment_date']);
                Database::execute('UPDATE fee_payments SET receipt_no = ? WHERE id = ?', [$receipt, $id]);
                FeeLedger::addPaid((int) $a['id'], $d['amount']);
            }
            Audit::log('fee_payment.create', 'fee_payment', $id, null, $row + ['receipt_no' => $receipt,
                'balance_before' => $a['balance'],
                'balance_after' => $status === 'confirmed' ? Money::sub($a['balance'], $d['amount']) : $a['balance']]);
            return ['id' => $id, 'receipt_no' => $receipt, 'status' => $status];
        });

        $msg = $result['status'] === 'confirmed'
            ? "Payment recorded. Receipt {$result['receipt_no']} generated."
            : 'Payment saved for administrator review because the transaction reference was already used. It will not count towards the balance until approved.';
        Response::ok($result, $msg);
    }

    /** Super Admin review of a payment flagged as duplicate. */
    public static function reviewPayment(int $id): never
    {
        Auth::requirePermission('fees.review');
        $d = Validator::make(Request::body(), [
            'decision' => 'required|in:approve,reject',
            'note'     => 'nullable|max:255',
        ]);
        $result = Database::transaction(static function () use ($id, $d) {
            $p = Database::one('SELECT * FROM fee_payments WHERE id = ? FOR UPDATE', [$id]) ?? throw HttpException::notFound('Payment not found.');
            if ($p['status'] !== 'pending_review') {
                throw new HttpException('This payment is not awaiting review.', 409);
            }
            $update = ['reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s'), 'review_note' => $d['note']];
            if ($d['decision'] === 'approve') {
                $a = FeeLedger::lockAssignment((int) $p['assignment_id']);
                if (Money::cmp($p['amount'], $a['balance']) > 0) {
                    throw new HttpException('Cannot approve: the amount exceeds the current outstanding balance of ' . Money::format($a['balance']) . '. Reject it instead.', 409);
                }
                $update['status'] = 'confirmed';
                $update['receipt_no'] = FeeLedger::receiptNo($id, $p['payment_date']);
                FeeLedger::addPaid((int) $p['assignment_id'], (string) $p['amount']);
            } else {
                $update['status'] = 'rejected';
            }
            Database::update('fee_payments', $id, $update);
            Audit::log('fee_payment.review_' . $d['decision'], 'fee_payment', $id, ['status' => $p['status']], $update);
            return $update;
        });
        Response::ok($result, $d['decision'] === 'approve' ? "Payment approved. Receipt {$result['receipt_no']} generated." : 'Payment rejected.');
    }

    /** Super Admin: void a confirmed payment (financial records are never deleted). */
    public static function cancelPayment(int $id): never
    {
        Auth::requirePermission('fees.review');
        $d = Validator::make(Request::body(), ['reason' => 'required|min:5|max:255']);
        Database::transaction(static function () use ($id, $d) {
            $p = Database::one('SELECT * FROM fee_payments WHERE id = ? FOR UPDATE', [$id]) ?? throw HttpException::notFound('Payment not found.');
            if ($p['status'] !== 'confirmed') {
                throw new HttpException('Only confirmed payments can be cancelled.', 409);
            }
            FeeLedger::lockAssignment((int) $p['assignment_id']);
            FeeLedger::addPaid((int) $p['assignment_id'], Money::sub('0', (string) $p['amount']));
            $update = ['status' => 'cancelled', 'review_note' => 'Cancelled: ' . $d['reason'],
                'reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s')];
            Database::update('fee_payments', $id, $update);
            Audit::log('fee_payment.cancel', 'fee_payment', $id, $p, $update);
        });
        Response::ok([], 'Payment cancelled. The amount has been added back to the outstanding balance.');
    }

    // ================================================================ dues
    private const DUES_SELECT = 'a.id AS assignment_id, a.title, a.semester, a.due_date, a.total_payable,
        a.amount_paid, a.balance,
        (a.due_date IS NOT NULL AND a.due_date < CURDATE()) AS is_overdue,
        GREATEST(DATEDIFF(CURDATE(), a.due_date), 0) AS days_overdue,
        s.id AS student_id, s.admission_no, s.full_name AS student_name, s.contact_number, s.parent_contact_number,
        s.status AS student_status, c.name AS course_name, d.name AS department_name, ay.name AS academic_year';
    private const DUES_SORT = [
        'balance' => 'a.balance', 'due_date' => 'a.due_date', 'student_name' => 's.full_name',
        'course' => 'c.name', 'days_overdue' => 'days_overdue', 'total_payable' => 'a.total_payable',
    ];

    public static function duesFrom(): string
    {
        return 'FROM student_fee_assignments a
            JOIN students s ON s.id = a.student_id
            JOIN courses c ON c.id = s.course_id
            JOIN departments d ON d.id = s.department_id
            JOIN academic_years ay ON ay.id = a.academic_year_id';
    }

    public static function duesFilters(): ListQuery
    {
        $q = new ListQuery();
        $q->add('a.balance > 0');
        $q->search(Request::queryStr('q'), ['s.full_name', 's.admission_no']);
        $q->when(Request::queryInt('course_id'), 's.course_id = ?');
        $q->when(Request::queryInt('department_id'), 's.department_id = ?');
        $q->when(Request::queryInt('academic_year_id'), 'a.academic_year_id = ?');
        $q->when(Request::queryInt('semester'), 'a.semester = ?');
        if (Request::queryStr('overdue') === '1') {
            $q->add('a.due_date IS NOT NULL AND a.due_date < CURDATE()');
        }
        return $q;
    }

    public static function dues(): never
    {
        Auth::requirePermission('fees.view');
        $q = self::duesFilters();
        $result = $q->paginate(self::DUES_SELECT, self::duesFrom(), ListQuery::orderBy(self::DUES_SORT, 'balance', 'desc'));
        $result['totals'] = Database::one(
            'SELECT COUNT(*) AS count, COUNT(DISTINCT a.student_id) AS students,
                    COALESCE(SUM(a.balance), 0) AS outstanding ' . self::duesFrom() . $q->whereSql(),
            $q->params
        );
        Response::ok($result);
    }

    public static function exportDues(): never
    {
        Auth::requirePermission('fees.view');
        $rows = self::duesFilters()->cursor(self::DUES_SELECT, self::duesFrom(), ListQuery::orderBy(self::DUES_SORT, 'balance', 'desc'));
        Csv::download(Request::queryStr('overdue') === '1' ? 'overdue_fees' : 'pending_dues', [
            'admission_no' => 'Admission No', 'student_name' => 'Student', 'course_name' => 'Course',
            'academic_year' => 'Academic Year', 'semester' => 'Semester', 'title' => 'Fee', 'total_payable' => 'Payable',
            'amount_paid' => 'Paid', 'balance' => 'Outstanding', 'due_date' => 'Due Date', 'days_overdue' => 'Days Overdue',
            'contact_number' => 'Contact', 'parent_contact_number' => 'Parent Contact',
        ], $rows);
    }
}
