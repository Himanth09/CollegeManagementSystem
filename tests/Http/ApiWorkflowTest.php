<?php
declare(strict_types=1);

/*
 * End-to-end integration tests: real HTTP requests through api/index.php,
 * the router, controllers, sessions, CSRF and MySQL, exactly as the browser
 * uses them. Data IS committed, so point the test server at a disposable
 * database loaded with schema.sql + seed.sql (never a live site).
 *
 * Tests inside a suite run in order and share state through Flow.
 */

final class Flow
{
    public static array $v = [];

    public static function need(string $key): mixed
    {
        return self::$v[$key] ?? skip("depends on an earlier step that did not complete ($key)");
    }
}

/** Balance of one fee assignment as reported by the student profile API. */
function balance_of(ApiClient $c, int $studentId, int $assignmentId): string
{
    $r = $c->get("/students/$studentId");
    foreach ($r['fees']['assignments'] ?? [] as $a) {
        if ((int) $a['id'] === $assignmentId) {
            return App\Core\Money::normalize((string) $a['balance']);
        }
    }
    fail("assignment $assignmentId not found on student $studentId");
}

function new_student(ApiClient $c, array $over = []): int
{
    $r = $c->post('/students', $over + [
        'full_name' => 'IT Student ' . run_id(), 'gender' => 'Female', 'course_id' => 1, 'current_year' => 1,
        'current_semester' => 1, 'admission_date' => '2026-07-01', 'academic_year_id' => 3, 'status' => 'Active',
        'email' => 'it.' . strtolower(run_id()) . '@example.com', 'contact_number' => '9876512345',
    ]);
    status(200, $c, $r, 'create student');
    return (int) $r['id'];
}

// ---------------------------------------------------------------------------------------------
suite('HTTP: authentication and session', function () {
    it('anonymous visitor gets a CSRF token but is not signed in', function () {
        $c = ApiClient::anonymous();
        $r = $c->get('/auth/me');
        status(200, $c, $r);
        no($r['authenticated']);
        ok(is_string($r['csrf']) && strlen($r['csrf']) === 64);
        eq('nosniff', $c->header('X-Content-Type-Options'));
        contains('application/json', (string) $c->header('Content-Type'));
    });

    it('protected endpoints require sign-in', function () {
        $c = ApiClient::anonymous();
        foreach (['/students', '/dashboard', '/fees/payments', '/salary/payroll', '/users', '/staff/1'] as $route) {
            $r = $c->get($route);
            status(401, $c, $r, $route);
        }
    });

    it('wrong password and unknown user get the same generic message', function () {
        $c = ApiClient::anonymous();
        $a = $c->post('/auth/login', ['username' => 'admin', 'password' => 'Wrong@12345']);
        status(401, $c, $a);
        $b = $c->post('/auth/login', ['username' => 'nobody_' . run_id(), 'password' => 'Wrong@12345']);
        status(401, $c, $b);
        eq($a['message'], $b['message'], 'no user enumeration');
    });

    it('login without the CSRF token is refused', function () {
        $c = ApiClient::anonymous();
        $r = $c->post('/auth/login', TestKit::$http['users']['super_admin'] ? ['username' => TestKit::$http['users']['super_admin'][0], 'password' => TestKit::$http['users']['super_admin'][1]] : [], false);
        status(419, $c, $r);
    });

    it('login rotates the CSRF token and the old one stops working', function () {
        $c = ApiClient::anonymous();
        $before = $c->csrf;
        [$u, $p] = TestKit::$http['users']['super_admin'];
        $r = $c->post('/auth/login', ['username' => $u, 'password' => $p]);
        status(200, $c, $r);
        eq('super_admin', $r['user']['role']);
        ok($c->csrf !== $before, 'token rotated');
        $c->csrf = $before;
        $r = $c->post('/auth/logout');
        status(419, $c, $r, 'pre-login token');
    });

    it('logout ends the session', function () {
        $c = ApiClient::role('accountant');
        status(200, $c, $c->get('/students'));
        $r = $c->post('/auth/logout');
        status(200, $c, $r);
        status(401, $c, $c->get('/students'), 'after logout');
        no($c->get('/auth/me')['authenticated']);
    });

    it('locks an account after 5 failed attempts', function () {
        $c = ApiClient::anonymous();
        $user = 'locktest_' . run_id();
        for ($i = 1; $i <= 5; $i++) {
            status(401, $c, $c->post('/auth/login', ['username' => $user, 'password' => 'Wrong@1234']), "attempt $i");
        }
        $r = $c->post('/auth/login', ['username' => $user, 'password' => 'Wrong@1234']);
        status(429, $c, $r, 'attempt 6');
        contains('Too many', $r['message']);
    });

    it('changes the password with current-password check and strength rules', function () {
        $c = ApiClient::role('staff');
        [, $pw] = TestKit::$http['users']['staff'];
        $r = $c->post('/auth/change-password', ['current_password' => 'Bad@12345', 'new_password' => 'New@123456']);
        status(422, $c, $r);
        ok(isset($r['errors']['current_password']));
        $r = $c->post('/auth/change-password', ['current_password' => $pw, 'new_password' => 'weak']);
        status(422, $c, $r);
        ok(isset($r['errors']['new_password']));
        $r = $c->post('/auth/change-password', ['current_password' => $pw, 'new_password' => $pw]);
        status(422, $c, $r, 'same password');
        // Change and change back so later runs keep working.
        $tmp = 'Tmp@' . run_id() . 'x';
        status(200, $c, $c->post('/auth/change-password', ['current_password' => $pw, 'new_password' => $tmp]));
        status(200, $c, $c->get('/auth/me'), 'session survives password change');
        status(200, $c, $c->post('/auth/change-password', ['current_password' => $tmp, 'new_password' => $pw]));
    });

    it('password reset request never reveals whether an email exists', function () {
        $c = ApiClient::anonymous();
        $a = $c->post('/auth/forgot', ['email' => 'nobody-' . strtolower(run_id()) . '@example.com']);
        status(200, $c, $a);
        status(422, $c, $c->post('/auth/forgot', ['email' => 'not-an-email']));
        $r = $c->post('/auth/reset', ['token' => str_repeat('a', 64), 'password' => 'Valid@12345']);
        status(400, $c, $r, 'unknown reset token');
    });
}, http: true);

// ---------------------------------------------------------------------------------------------
suite('HTTP: role-based access control', function () {
    it('accountant is blocked from salary, users, audit, backups and fee configuration', function () {
        $c = ApiClient::role('accountant');
        foreach (['/salary/structures', '/salary/payroll', '/salary/history/1', '/print/salary-slip/1', '/users', '/audit', '/backups',
                     '/reports/salary-expenditure'] as $route) {
            status(403, $c, $c->get($route), $route);
        }
        status(403, $c, $c->post('/fees/structures', ['name' => 'x']), 'create fee structure');
        status(403, $c, $c->post('/users', ['username' => 'x']), 'create user');
        status(403, $c, $c->post('/salary/payroll/generate', []), 'generate payroll');
        status(403, $c, $c->post('/departments', ['code' => 'X', 'name' => 'X']), 'masters');
        status(403, $c, $c->post('/backups'), 'backup');
    });

    it('accountant can work with students, fees and fee reports', function () {
        $c = ApiClient::role('accountant');
        foreach (['/students', '/students/1', '/fees/payments', '/fees/dues', '/fees/structures', '/reports/fee-collection', '/reports/pending-dues', '/dashboard'] as $route) {
            status(200, $c, $c->get($route), $route);
        }
    });

    it('staff user sees only what was granted', function () {
        $c = ApiClient::role('staff');
        status(200, $c, $c->get('/students'), 'granted students.view');
        $r = $c->get('/students/1');
        status(200, $c, $r);
        no(array_key_exists('fees', $r), 'no fee data without fees.view');
        foreach (['/fees/payments', '/fees/dues', '/staff', '/salary/payroll', '/reports/fee-collection', '/users'] as $route) {
            status(403, $c, $c->get($route), $route);
        }
        status(403, $c, $c->post('/students', ['full_name' => 'x']), 'cannot add students');
        status(200, $c, $c->get('/profile'), 'own profile');
    });

    it('dashboard only includes blocks the role may see', function () {
        $admin = ApiClient::role('super_admin')->get('/dashboard');
        $acct = ApiClient::role('accountant')->get('/dashboard');
        $staff = ApiClient::role('staff')->get('/dashboard');
        ok(array_key_exists('monthly_salary_expense', $admin['cards']), 'admin sees salary expense');
        no(array_key_exists('monthly_salary_expense', $acct['cards']), 'accountant must not see salary');
        ok(array_key_exists('total_fee_collection', $acct['cards']));
        no(array_key_exists('total_fee_collection', $staff['cards']), 'staff must not see fee totals');
        eq('accountant', $acct['role']);
    });

    it('rejects unknown routes, wrong methods and non-numeric ids', function () {
        $c = ApiClient::role('super_admin');
        status(404, $c, $c->get('/does-not-exist'));
        status(405, $c, $c->get('/fees/payments/1/review'));
        status(404, $c, $c->get('/students/1 OR 1=1'));
        status(404, $c, $c->get('/students/99999999'));
    });
}, http: true);

// ---------------------------------------------------------------------------------------------
suite('HTTP: student lifecycle', function () {
    it('validates required fields and course rules', function () {
        $c = ApiClient::role('super_admin');
        $r = $c->post('/students', []);
        status(422, $c, $r);
        foreach (['full_name', 'gender', 'course_id', 'current_semester', 'admission_date', 'academic_year_id', 'status'] as $f) {
            ok(isset($r['errors'][$f]), "error for $f");
        }
        $r = $c->post('/students', ['full_name' => 'X', 'gender' => 'Male', 'course_id' => 1, 'current_semester' => 9,
            'admission_date' => '2026-07-01', 'academic_year_id' => 3, 'status' => 'Active']);
        status(422, $c, $r);
        ok(isset($r['errors']['current_semester']), 'semester beyond course length');
        $r = $c->post('/students', ['full_name' => 'X', 'gender' => 'Male', 'course_id' => 99999, 'current_semester' => 1,
            'admission_date' => '2026-07-01', 'academic_year_id' => 3, 'status' => 'Active']);
        status(422, $c, $r);
        ok(isset($r['errors']['course_id']));
    });

    it('creates a student, derives the department and generates an admission number', function () {
        $c = ApiClient::role('super_admin');
        $id = new_student($c, ['full_name' => 'Lifecycle ' . run_id(), 'father_name' => 'Parent ' . run_id()]);
        $s = $c->get("/students/$id")['student'];
        eq('Lifecycle ' . run_id(), $s['full_name']);
        eq(1, (int) $s['department_id'], 'department follows the course');
        ok($s['admission_no'] !== null && $s['admission_no'] !== '', 'admission number generated');
        Flow::$v['student'] = $id;
        Flow::$v['admission_no'] = $s['admission_no'];
    });

    it('rejects a duplicate admission number', function () {
        $c = ApiClient::role('super_admin');
        $r = $c->post('/students', ['admission_no' => Flow::need('admission_no'), 'full_name' => 'Dup', 'gender' => 'Male', 'course_id' => 1,
            'current_semester' => 1, 'admission_date' => '2026-07-01', 'academic_year_id' => 3, 'status' => 'Active']);
        ok(in_array($c->status, [409, 422], true), "expected 409/422, got {$c->status}");
    });

    it('finds the student by search and filters', function () {
        $c = ApiClient::role('accountant');
        $r = $c->get('/students', ['q' => Flow::need('admission_no')]);
        eq(1, $r['meta']['total']);
        eq(Flow::$v['student'], (int) $r['data'][0]['id']);
        $r = $c->get('/students', ['q' => 'Lifecycle ' . run_id(), 'course_id' => 2]);
        eq(0, $r['meta']['total'], 'course filter excludes it');
        $r = $c->get('/students', ['per_page' => 5, 'page' => 1, 'sort' => 'full_name', 'dir' => 'desc']);
        eq(5, count($r['data']));
        eq(5, $r['meta']['per_page']);
    });

    it('updates student details', function () {
        $c = ApiClient::role('super_admin');
        $id = Flow::need('student');
        $s = $c->get("/students/$id")['student'];
        $r = $c->post("/students/$id", array_merge($s, ['status' => 'Inactive', 'current_semester' => 2, 'address' => 'Updated address']));
        status(200, $c, $r);
        $s = $c->get("/students/$id")['student'];
        eq('Inactive', $s['status']);
        eq(2, (int) $s['current_semester']);
        eq('Updated address', $s['address']);
    });

    it('exports CSV with formula injection neutralised', function () {
        $c = ApiClient::role('super_admin');
        new_student($c, ['full_name' => '=HYPERLINK("http://evil")' . run_id()]);
        $csv = $c->getRaw('/students/export', ['q' => run_id()]);
        status(200, $c);
        contains('text/csv', (string) $c->header('Content-Type'));
        contains('attachment', (string) $c->header('Content-Disposition'));
        contains('Admission No', $csv);
        contains('"\'=HYPERLINK(', $csv, 'formula cell is prefixed with an apostrophe');
    });

    it('only Super Admin can delete, and only students without fee records', function () {
        $admin = ApiClient::role('super_admin');
        $id = Flow::need('student');
        status(403, $acct = ApiClient::role('accountant'), $acct->post("/students/$id/delete"));
        status(200, $admin, $admin->post("/students/$id/delete"));
        status(404, $admin, $admin->get("/students/$id"));
        status(409, $admin, $admin->post('/students/1/delete'), 'seeded student 1 has fees');
    });
}, http: true);

// ---------------------------------------------------------------------------------------------
suite('HTTP: fee collection workflow', function () {
    it('Super Admin creates a fee structure (43,000)', function () {
        $c = ApiClient::role('super_admin');
        $r = $c->post('/fees/structures', ['name' => 'IT Fee ' . run_id(), 'course_id' => 1, 'academic_year_id' => 3, 'semester' => 1,
            'tuition_fee' => '40000', 'examination_fee' => '2000', 'library_fee' => '1000', 'due_date' => '2026-12-31', 'is_active' => '1']);
        status(200, $c, $r);
        Flow::$v['structure'] = (int) $r['id'];
        status(422, $c, $c->post('/fees/structures', ['name' => 'Zero', 'course_id' => 1, 'academic_year_id' => 3, 'semester' => 1]), 'all-zero structure');
        status(422, $c, $c->post('/fees/structures', ['name' => 'Bad sem', 'course_id' => 1, 'academic_year_id' => 3, 'semester' => 12, 'tuition_fee' => '1']));
    });

    it('accountant assigns it; concessions are admin-only; no double assignment', function () {
        $admin = ApiClient::role('super_admin');
        $acct = ApiClient::role('accountant');
        $sid = new_student($admin, ['full_name' => 'Fee Flow ' . run_id()]);
        Flow::$v['fee_student'] = $sid;
        $fs = Flow::need('structure');
        status(403, $acct, $acct->post('/fees/assignments', ['student_id' => $sid, 'fee_structure_id' => $fs, 'scholarship_amount' => '1000']), 'scholarship by accountant');
        $r = $acct->post('/fees/assignments', ['student_id' => $sid, 'fee_structure_id' => $fs]);
        status(200, $acct, $r);
        Flow::$v['assignment'] = (int) $r['id'];
        eq('43000.00', balance_of($acct, $sid, Flow::$v['assignment']));
        $r = $acct->post('/fees/assignments', ['student_id' => $sid, 'fee_structure_id' => $fs]);
        status(422, $acct, $r, 'already assigned');
    });

    it('records a partial cash payment with receipt and updates the balance', function () {
        $c = ApiClient::role('accountant');
        $aid = Flow::need('assignment');
        $r = $c->post('/fees/payments', ['assignment_id' => $aid, 'amount' => '10000', 'payment_date' => date('Y-m-d'), 'payment_mode' => 'Cash']);
        status(200, $c, $r);
        eq('confirmed', $r['status']);
        ok((bool) preg_match('/^RCP-\d{4}-\d{6,}$/', (string) $r['receipt_no']), 'receipt number format');
        Flow::$v['cash_payment'] = (int) $r['id'];
        eq('33000.00', balance_of($c, Flow::$v['fee_student'], $aid));
    });

    it('catches an accidental double submission of the same cash payment', function () {
        $c = ApiClient::role('accountant');
        $aid = Flow::need('assignment');
        $body = ['assignment_id' => $aid, 'amount' => '10000', 'payment_date' => date('Y-m-d'), 'payment_mode' => 'Cash'];
        $r = $c->post('/fees/payments', $body);
        status(409, $c, $r);
        ok($r['can_override'] === true);
        eq('33000.00', balance_of($c, Flow::$v['fee_student'], $aid), 'nothing recorded');
        $r = $c->post('/fees/payments', $body + ['confirm_duplicate' => '1']);
        status(200, $c, $r, 'confirmed as a separate payment');
        eq('23000.00', balance_of($c, Flow::$v['fee_student'], $aid));
    });

    it('validates amount, mode, reference and date', function () {
        $c = ApiClient::role('accountant');
        $aid = Flow::need('assignment');
        $base = ['assignment_id' => $aid, 'payment_date' => date('Y-m-d'), 'payment_mode' => 'Cash'];
        $r = $c->post('/fees/payments', ['amount' => '23000.01'] + $base);
        status(422, $c, $r, 'overpayment');
        ok(isset($r['errors']['amount']));
        status(422, $c, $c->post('/fees/payments', ['amount' => '0'] + $base), 'zero');
        status(422, $c, $c->post('/fees/payments', ['amount' => '10.555'] + $base), '3 decimals');
        $r = $c->post('/fees/payments', ['amount' => '100', 'payment_mode' => 'UPI'] + $base);
        status(422, $c, $r, 'UPI without reference');
        ok(isset($r['errors']['transaction_ref']));
        status(422, $c, $c->post('/fees/payments', ['amount' => '100', 'payment_mode' => 'Cheque'] + $base), 'unknown mode');
        status(422, $c, $c->post('/fees/payments', ['amount' => '100', 'payment_date' => date('Y-m-d', strtotime('+1 day'))] + $base), 'future date');
        status(404, $c, $c->post('/fees/payments', ['amount' => '100', 'assignment_id' => 99999999] + $base), 'unknown assignment');
    });

    it('records a UPI payment with a transaction reference', function () {
        $c = ApiClient::role('accountant');
        $r = $c->post('/fees/payments', ['assignment_id' => Flow::need('assignment'), 'amount' => '5000', 'payment_date' => date('Y-m-d'),
            'payment_mode' => 'UPI', 'transaction_ref' => 'upi it ' . run_id()]);
        status(200, $c, $r);
        eq('18000.00', balance_of($c, Flow::$v['fee_student'], Flow::$v['assignment']));
    });

    it('blocks a reused reference, even with different case and spaces', function () {
        $c = ApiClient::role('accountant');
        $r = $c->post('/fees/payments', ['assignment_id' => Flow::need('assignment'), 'amount' => '1000', 'payment_date' => date('Y-m-d'),
            'payment_mode' => 'UPI', 'transaction_ref' => 'UPIIT' . run_id()]);
        status(409, $c, $r);
        contains('already been used', $r['message']);
        no($r['can_override'], 'accountant cannot override');
    });

    it('accountant can only send a duplicate to the review queue (no effect on balance)', function () {
        $c = ApiClient::role('accountant');
        $r = $c->post('/fees/payments', ['assignment_id' => Flow::need('assignment'), 'amount' => '1000', 'payment_date' => date('Y-m-d'),
            'payment_mode' => 'UPI', 'transaction_ref' => 'UPIIT' . run_id(), 'confirm_duplicate' => '1']);
        status(200, $c, $r);
        eq('pending_review', $r['status']);
        eq(null, $r['receipt_no']);
        Flow::$v['pending'] = (int) $r['id'];
        eq('18000.00', balance_of($c, Flow::$v['fee_student'], Flow::$v['assignment']));
        status(403, $c, $c->post('/fees/payments/' . $r['id'] . '/review', ['decision' => 'approve']), 'accountant cannot review');
        $list = ApiClient::role('super_admin')->get('/fees/payments', ['status' => 'pending_review', 'q' => 'UPIIT' . run_id()]);
        eq(1, $list['meta']['total'], 'shows in the pending review queue');
    });

    it('Super Admin approves the pending payment, which then counts', function () {
        $c = ApiClient::role('super_admin');
        $id = Flow::need('pending');
        $r = $c->post("/fees/payments/$id/review", ['decision' => 'approve', 'note' => 'Verified with bank statement']);
        status(200, $c, $r);
        eq('confirmed', $r['status']);
        ok(str_starts_with((string) $r['receipt_no'], 'RCP-'));
        eq('17000.00', balance_of($c, Flow::$v['fee_student'], Flow::$v['assignment']));
        status(409, $c, $c->post("/fees/payments/$id/review", ['decision' => 'reject']), 'already reviewed');
    });

    it('Super Admin can reject a pending duplicate', function () {
        $acct = ApiClient::role('accountant');
        $r = $acct->post('/fees/payments', ['assignment_id' => Flow::need('assignment'), 'amount' => '500', 'payment_date' => date('Y-m-d'),
            'payment_mode' => 'UPI', 'transaction_ref' => 'UPIIT' . run_id(), 'confirm_duplicate' => '1']);
        eq('pending_review', $r['status']);
        $admin = ApiClient::role('super_admin');
        $r = $admin->post('/fees/payments/' . $r['id'] . '/review', ['decision' => 'reject', 'note' => 'Duplicate entry']);
        status(200, $admin, $r);
        eq('rejected', $r['status']);
        eq('17000.00', balance_of($admin, Flow::$v['fee_student'], Flow::$v['assignment']));
    });

    it('cancelling a payment needs a reason and restores the balance', function () {
        $c = ApiClient::role('super_admin');
        $id = Flow::need('cash_payment');
        status(403, $a = ApiClient::role('accountant'), $a->post("/fees/payments/$id/cancel", ['reason' => 'Entered twice']));
        status(422, $c, $c->post("/fees/payments/$id/cancel", ['reason' => 'x']), 'reason too short');
        status(200, $c, $c->post("/fees/payments/$id/cancel", ['reason' => 'Cheque bounced']));
        eq('27000.00', balance_of($c, Flow::$v['fee_student'], Flow::$v['assignment']));
        status(409, $c, $c->post("/fees/payments/$id/cancel", ['reason' => 'Again please']), 'already cancelled');
    });

    it('prints a receipt with the amount in words', function () {
        $c = ApiClient::role('accountant');
        $p = $c->get('/fees/payments', ['student_id' => Flow::need('fee_student'), 'status' => 'confirmed']);
        ok($p['meta']['total'] >= 2);
        $pay = $p['data'][0];
        $html = $c->getRaw('/print/receipt/' . $pay['id']);
        status(200, $c);
        contains('text/html', (string) $c->header('Content-Type'));
        contains((string) $pay['receipt_no'], $html);
        contains('Rupees', $html);
        contains('Only', $html);
        $s = ApiClient::role('staff');
        $s->getRaw('/print/receipt/' . $pay['id']);
        status(403, $s);
    });

    it('a fee with payments cannot be deleted', function () {
        $c = ApiClient::role('super_admin');
        status(409, $c, $c->post('/fees/assignments/' . Flow::need('assignment') . '/delete'));
        status(409, $c, $c->post('/fees/structures/' . Flow::need('structure') . '/delete'), 'structure in use');
    });

    it('shows the student in dues, then clears the balance exactly', function () {
        $c = ApiClient::role('accountant');
        $sid = Flow::need('fee_student');
        $d = $c->get('/fees/dues', ['q' => 'Fee Flow ' . run_id()]);
        eq(1, $d['meta']['total']);
        eq(0, App\Core\Money::cmp((string) $d['data'][0]['balance'], '27000'));
        $r = $c->post('/fees/payments', ['assignment_id' => Flow::$v['assignment'], 'amount' => '27000.00', 'payment_date' => date('Y-m-d'), 'payment_mode' => 'Card',
            'transaction_ref' => 'CARD' . run_id()]);
        status(200, $c, $r);
        eq('0.00', balance_of($c, $sid, Flow::$v['assignment']));
        status(422, $c, $c->post('/fees/payments', ['assignment_id' => Flow::$v['assignment'], 'amount' => '0.01', 'payment_date' => date('Y-m-d'), 'payment_mode' => 'Cash']), 'fully paid');
        eq(0, $c->get('/fees/dues', ['q' => 'Fee Flow ' . run_id()])['meta']['total'], 'no longer in dues');
        $sum = $c->get("/students/$sid")['fees']['summary'];
        eq(0, App\Core\Money::cmp((string) $sum['total_paid'], '43000'));
        eq('0.00', $sum['outstanding']);
    });

    it('exports payments and dues as CSV', function () {
        $c = ApiClient::role('accountant');
        $csv = $c->getRaw('/fees/payments/export', ['q' => 'CARD' . run_id()]);
        status(200, $c);
        contains('CARD' . run_id(), $csv);
        $c->getRaw('/fees/dues/export');
        status(200, $c);
        contains('text/csv', (string) $c->header('Content-Type'));
    });
}, http: true);

// ---------------------------------------------------------------------------------------------
suite('HTTP: salary and payroll (Super Admin only)', function () {
    it('creates a staff member without exposing salary in staff endpoints', function () {
        $c = ApiClient::role('super_admin');
        $r = $c->post('/staff', ['employee_id' => 'IT' . run_id(), 'full_name' => 'Payroll Tester ' . run_id(), 'designation' => 'Lecturer',
            'department_id' => 1, 'employment_status' => 'Active', 'date_of_joining' => '2026-06-01', 'email' => 'pt.' . strtolower(run_id()) . '@example.com']);
        status(200, $c, $r);
        Flow::$v['staff'] = (int) $r['id'];
        $c->post('/staff', ['employee_id' => 'IT' . run_id(), 'full_name' => 'Dup', 'designation' => 'X', 'employment_status' => 'Active']);
        ok(in_array($c->status, [409, 422], true), "duplicate employee id: expected 409/422, got {$c->status}");
    });

    it('rejects deductions larger than gross salary', function () {
        $c = ApiClient::role('super_admin');
        $r = $c->post('/salary/structures', ['staff_id' => Flow::need('staff'), 'effective_date' => '2026-06-01',
            'basic_salary' => '10000', 'pf_deduction' => '12000']);
        status(422, $c, $r);
    });

    it('saves a salary structure and computes gross / net', function () {
        $c = ApiClient::role('super_admin');
        $sid = Flow::need('staff');
        $r = $c->post('/salary/structures', ['staff_id' => $sid, 'effective_date' => '2026-06-01', 'basic_salary' => '30000',
            'hra' => '12000', 'da' => '3000', 'pf_deduction' => '1800', 'professional_tax' => '200']);
        status(200, $c, $r);
        $h = $c->get("/salary/history/$sid");
        eq(1, count($h['structures']));
        eq(0, App\Core\Money::cmp((string) $h['structures'][0]['gross_salary'], '45000'));
        eq(0, App\Core\Money::cmp((string) $h['structures'][0]['net_salary'], '43000'));
    });

    it('revises salary with full history; backdating is refused', function () {
        $c = ApiClient::role('super_admin');
        $sid = Flow::need('staff');
        status(422, $c, $c->post('/salary/structures', ['staff_id' => $sid, 'effective_date' => '2026-01-01', 'basic_salary' => '31000']), 'backdated');
        $r = $c->post('/salary/structures', ['staff_id' => $sid, 'effective_date' => '2026-10-01', 'basic_salary' => '33000',
            'hra' => '12000', 'da' => '3000', 'pf_deduction' => '1800', 'professional_tax' => '200', 'change_reason' => 'Annual increment']);
        status(200, $c, $r);
        $h = $c->get("/salary/history/$sid");
        eq(2, count($h['structures']));
        eq(2, count($h['revisions']));
        eq('Annual increment', $h['revisions'][0]['change_reason']);
        eq(0, App\Core\Money::cmp((string) $h['revisions'][0]['previous_net'], '43000'));
        eq(0, App\Core\Money::cmp((string) $h['revisions'][0]['new_net'], '46000'));
        $current = array_values(array_filter($h['structures'], fn ($s) => (int) $s['is_current'] === 1));
        eq(1, count($current), 'exactly one current structure');
    });

    it('generates monthly payroll once', function () {
        $c = ApiClient::role('super_admin');
        $month = date('Y-m');
        $r = $c->post('/salary/payroll/generate', ['month' => $month]);
        status(200, $c, $r);
        ok($r['count'] >= 1);
        eq(0, $c->post('/salary/payroll/generate', ['month' => $month])['count'], 'second run adds nothing');
        status(422, $c, $c->post('/salary/payroll/generate', ['month' => '2026-13']));
        status(422, $c, $c->post('/salary/payroll/generate', ['month' => date('Y-m', strtotime('+3 months'))]), 'too far ahead');
        $p = $c->get('/salary/payroll', ['month' => $month]);
        $row = array_values(array_filter($p['data'], fn ($x) => (int) $x['staff_id'] === Flow::need('staff')));
        eq(1, count($row));
        eq('pending', $row[0]['status']);
        eq(0, App\Core\Money::cmp((string) $row[0]['net_salary'], '46000'), 'uses current structure');
        Flow::$v['salary_payment'] = (int) $row[0]['payment_id'];
    });

    it('marks salary paid and prints the slip', function () {
        $c = ApiClient::role('super_admin');
        $id = Flow::need('salary_payment');
        status(422, $c, $c->post("/salary/payments/$id/pay", ['paid_date' => date('Y-m-d'), 'payment_mode' => 'Bank Transfer']), 'missing ref');
        $r = $c->post("/salary/payments/$id/pay", ['paid_date' => date('Y-m-d'), 'payment_mode' => 'Bank Transfer', 'transaction_ref' => 'NEFT' . run_id()]);
        status(200, $c, $r);
        ok(is_string($r['slip_no']) && $r['slip_no'] !== '');
        $c->post("/salary/payments/$id/pay", ['paid_date' => date('Y-m-d'), 'payment_mode' => 'Cash']);
        ok(in_array($c->status, [409, 422], true), "pay twice: expected 409/422, got {$c->status}");
        $html = $c->getRaw("/print/salary-slip/$id");
        status(200, $c);
        contains($r['slip_no'], $html);
        contains('Rupees', $html);
        $a = ApiClient::role('accountant');
        $a->getRaw("/print/salary-slip/$id");
        status(403, $a, [], 'accountant cannot print salary slips');
    });

    it('exports the payroll CSV', function () {
        $c = ApiClient::role('super_admin');
        $csv = $c->getRaw('/salary/payroll/export', ['month' => date('Y-m')]);
        status(200, $c);
        contains('IT' . run_id(), $csv);
    });

    it('staff profile API never contains salary figures', function () {
        $c = ApiClient::role('super_admin');
        $raw = json_encode($c->get('/staff/' . Flow::need('staff')));
        foreach (['basic_salary', 'net_salary', 'gross_salary', '46000'] as $needle) {
            no(str_contains($raw, $needle), "staff endpoint leaks $needle");
        }
    });
}, http: true);

// ---------------------------------------------------------------------------------------------
suite('HTTP: reports, audit, users and backups', function () {
    it('every report returns rows and downloads as CSV for the Super Admin', function () {
        $c = ApiClient::role('super_admin');
        foreach (['admissions', 'fee-collection', 'pending-dues', 'overdue', 'course-financial', 'semester-collection', 'staff',
                     'salary-expenditure', 'monthly-summary'] as $rep) {
            $r = $c->get("/reports/$rep");
            status(200, $c, $r, $rep);
            ok(isset($r['title'], $r['columns'], $r['rows']), "$rep has title/columns/rows");
            $c->getRaw("/reports/$rep", ['format' => 'csv']);
            status(200, $c, [], "$rep csv");
            contains('text/csv', (string) $c->header('Content-Type'), "$rep csv");
        }
    });

    it('fee collection report matches recorded payments', function () {
        $c = ApiClient::role('accountant');
        $today = date('Y-m-d');
        $r = $c->get('/reports/fee-collection', ['from' => $today, 'to' => $today]);
        status(200, $c, $r);
        $sum = App\Core\Money::sum(...array_map(fn ($row) => (string) ($row['amount'] ?? '0'), $r['rows']));
        $p = $c->get('/fees/payments', ['from' => $today, 'to' => $today, 'status' => 'confirmed', 'per_page' => 100]);
        $sum2 = App\Core\Money::sum(...array_map(fn ($row) => (string) $row['amount'], $p['data']));
        if ($p['meta']['total'] <= 100) {
            eq($sum2, $sum, 'report total equals the confirmed payments list');
        }
    });

    it('audit log records every financial action of this run', function () {
        $c = ApiClient::role('super_admin');
        $r = $c->get('/audit', ['per_page' => 100]);
        status(200, $c, $r);
        $actions = array_column($r['data'], 'action');
        foreach (['fee_payment.create', 'fee_payment.review_approve', 'fee_payment.review_reject', 'fee_payment.cancel',
                     'fee_structure.create', 'salary_structure.revise', 'salary_payment.pay', 'student.create', 'student.delete'] as $a) {
            if ($a === 'salary_payment.pay' && !in_array($a, $actions, true)) {
                ok((bool) array_filter($actions, fn ($x) => str_starts_with($x, 'salary') || str_starts_with($x, 'payroll')), 'salary actions audited');
                continue;
            }
            ok(in_array($a, $actions, true), "audit has $a");
        }
    });

    it('creates users with only grantable permissions', function () {
        $c = ApiClient::role('super_admin');
        $roles = $c->get('/users')['roles'] ?? [];
        $staffRole = array_values(array_filter($roles, fn ($r) => $r['slug'] === 'staff'))[0]['id'] ?? 3;
        $u = 'it_' . strtolower(run_id());
        $base = ['username' => $u, 'email' => "$u@example.com", 'full_name' => 'IT User', 'role_id' => $staffRole, 'is_active' => '1'];
        $r = $c->post('/users', $base + ['password' => 'weak']);
        status(422, $c, $r, 'weak password');
        $r = $c->post('/users', $base + ['password' => 'Strong@123', 'permissions' => ['staff.view', 'salary.manage', 'users.manage']]);
        status(200, $c, $r);
        status(422, $c, $c->post('/users', $base + ['password' => 'Strong@123']), 'duplicate username');
        $n = ApiClient::as($u, 'Strong@123');
        $me = $n->get('/auth/me')['user'];
        ok(in_array('staff.view', $me['permissions'], true), 'grantable permission applied');
        no(in_array('salary.manage', $me['permissions'], true), 'salary cannot be granted');
        no(in_array('users.manage', $me['permissions'], true), 'user admin cannot be granted');
        status(200, $n, $n->get('/staff'));
        status(403, $n, $n->get('/salary/payroll'));
        status(403, $n, $n->get('/users'));
        Flow::$v['new_user'] = (int) $r['id'];
        Flow::$v['new_user_base'] = $base;
    });

    it('deactivating a user locks them out immediately', function () {
        $c = ApiClient::role('super_admin');
        $id = Flow::need('new_user');
        $n = ApiClient::as(Flow::$v['new_user_base']['username'], 'Strong@123');
        status(200, $c, $c->post("/users/$id", ['is_active' => '0'] + Flow::$v['new_user_base']));
        status(401, $n, $n->get('/staff'), 'existing session ends');
        $x = ApiClient::anonymous();
        status(401, $x, $x->post('/auth/login', ['username' => Flow::$v['new_user_base']['username'], 'password' => 'Strong@123']));
    });

    it('Super Admin cannot deactivate or demote their own account', function () {
        $c = ApiClient::role('super_admin');
        $me = $c->get('/auth/me')['user'];
        $users = $c->get('/users')['data'];
        $row = array_values(array_filter($users, fn ($u) => (int) $u['id'] === $me['id']))[0];
        $r = $c->post('/users/' . $me['id'], ['username' => $row['username'], 'email' => $row['email'], 'full_name' => $row['full_name'],
            'role_id' => $row['role_id'], 'is_active' => '0']);
        status(409, $c, $r);
    });

    it('creates, lists, downloads and deletes a backup', function () {
        $c = ApiClient::role('super_admin');
        $r = $c->post('/backups');
        status(200, $c, $r);
        $name = $r['name'];
        ok((bool) preg_match('/^cms_backup_\d{8}_\d{6}_[a-f0-9]{8}\.sql(\.gz)?$/', $name));
        ok(in_array($name, array_column($c->get('/backups')['data'], 'name'), true), 'listed');
        $bytes = $c->getRaw('/backups/download', ['name' => $name]);
        status(200, $c);
        $sql = str_ends_with($name, '.gz') ? gzdecode($bytes) : $bytes;
        contains('CREATE TABLE', (string) $sql);
        contains('INSERT INTO', (string) $sql);
        $c->getRaw('/backups/download', ['name' => '../config/config.php']);
        status(404, $c, [], 'path traversal');
        status(200, $c, $c->post('/backups/delete', ['name' => $name]));
        no(in_array($name, array_column($c->get('/backups')['data'], 'name'), true), 'deleted');
    });
}, http: true);
