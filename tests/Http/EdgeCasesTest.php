<?php
declare(strict_types=1);

/*
 * Edge cases through the real API: boundaries, odd input, Unicode, XSS,
 * malformed requests and referential integrity.
 */

suite('HTTP: edge cases', function () {
    it('pagination clamps out-of-range and junk values', function () {
        $c = ApiClient::role('accountant');
        $total = $c->get('/students')['meta']['total'];
        $r = $c->get('/students', ['page' => 999999]);
        status(200, $c, $r);
        eq($r['meta']['pages'], $r['meta']['page'], 'clamped to last page');
        ok(count($r['data']) > 0, 'last page is not empty');
        eq(100, $c->get('/students', ['per_page' => 100000])['meta']['per_page'], 'per_page capped');
        eq(5, $c->get('/students', ['per_page' => 0])['meta']['per_page'], 'per_page minimum');
        eq(1, $c->get('/students', ['page' => -3])['meta']['page']);
        eq(1, $c->get('/students', ['page' => 'abc'])['meta']['page']);
        eq($total, $c->get('/students', ['sort' => 'password_hash', 'dir' => 'sideways'])['meta']['total'], 'unknown sort ignored');
    });

    it('treats % and _ in search as literal characters', function () {
        $c = ApiClient::role('accountant');
        eq(0, $c->get('/students', ['q' => '%'])['meta']['total']);
        eq(0, $c->get('/students', ['q' => '_'])['meta']['total']);
        eq(0, $c->get('/fees/payments', ['q' => '%'])['meta']['total']);
    });

    it('stores, searches and exports Unicode names', function () {
        $c = ApiClient::role('super_admin');
        $tamil = 'அருண் குமார் ' . run_id();
        $hindi = 'राहुल शर्मा ' . run_id();
        new_student($c, ['full_name' => $tamil, 'gender' => 'Male']);
        new_student($c, ['full_name' => $hindi, 'gender' => 'Male']);
        $r = $c->get('/students', ['q' => 'அருண்']);
        ok(in_array($tamil, array_column($r['data'], 'full_name'), true), 'Tamil search');
        $r = $c->get('/students', ['q' => 'राहुल']);
        ok(in_array($hindi, array_column($r['data'], 'full_name'), true), 'Hindi search');
        $csv = $c->getRaw('/students/export', ['q' => run_id()]);
        ok(str_starts_with($csv, "\xEF\xBB\xBF"), 'UTF-8 BOM for Excel');
        contains($tamil, $csv);
        contains($hindi, $csv);
    });

    it('enforces field length limits in characters', function () {
        $c = ApiClient::role('super_admin');
        $base = ['gender' => 'Male', 'course_id' => 1, 'current_semester' => 1, 'admission_date' => '2026-07-01', 'academic_year_id' => 3, 'status' => 'Active'];
        $r = $c->post('/students', $base + ['full_name' => str_repeat('அ', 151)]);
        status(422, $c, $r, '151 chars');
        $r = $c->post('/students', $base + ['full_name' => str_repeat('அ', 150)]);
        status(200, $c, $r, '150 multibyte chars fit');
    });

    it('escapes HTML in printed receipts (no stored XSS)', function () {
        $admin = ApiClient::role('super_admin');
        $sid = new_student($admin, ['full_name' => '<script>alert(1)</script>' . run_id(), 'father_name' => '"><img src=x onerror=alert(2)>']);
        $fs = $admin->post('/fees/structures', ['name' => 'XSS Fee ' . run_id(), 'course_id' => 1, 'academic_year_id' => 3, 'semester' => 2, 'tuition_fee' => '1000']);
        status(200, $admin, $fs);
        $a = $admin->post('/fees/assignments', ['student_id' => $sid, 'fee_structure_id' => $fs['id']]);
        $p = $admin->post('/fees/payments', ['assignment_id' => $a['id'], 'amount' => '100', 'payment_date' => date('Y-m-d'), 'payment_mode' => 'Cash',
            'remarks' => '<b onmouseover=alert(3)>x</b>']);
        status(200, $admin, $p);
        $html = $admin->getRaw('/print/receipt/' . $p['id']);
        no(str_contains($html, '<script>alert(1)</script>'), 'script tag must be escaped');
        no(str_contains($html, '<img src=x'), 'attribute injection must be escaped');
        contains('&lt;script&gt;', $html);
        Flow::$v['xss_payment'] = (int) $p['id'];
        Flow::$v['xss_assignment'] = (int) $a['id'];
        Flow::$v['xss_student'] = $sid;
    });

    it('only confirmed or cancelled payments have a printable receipt', function () {
        $acct = ApiClient::role('accountant');
        $ref = 'EDGE' . run_id();
        $aid = Flow::need('xss_assignment');
        status(200, $acct, $acct->post('/fees/payments', ['assignment_id' => $aid, 'amount' => '10', 'payment_date' => date('Y-m-d'), 'payment_mode' => 'UPI', 'transaction_ref' => $ref]));
        $r = $acct->post('/fees/payments', ['assignment_id' => $aid, 'amount' => '10', 'payment_date' => date('Y-m-d'), 'payment_mode' => 'UPI', 'transaction_ref' => $ref, 'confirm_duplicate' => '1']);
        eq('pending_review', $r['status']);
        $acct->getRaw('/print/receipt/' . $r['id']);
        ok($acct->status >= 400 && $acct->status < 500, "pending payment receipt refused (HTTP {$acct->status})");
    });

    it('accepts payment dates up to 2 years back, not earlier', function () {
        $c = ApiClient::role('super_admin');
        $aid = Flow::need('xss_assignment');
        $body = ['assignment_id' => $aid, 'amount' => '1', 'payment_mode' => 'Cash', 'confirm_duplicate' => '1'];
        $r = $c->post('/fees/payments', $body + ['payment_date' => date('Y-m-d', strtotime('-2 years -1 day'))]);
        status(422, $c, $r, 'older than 2 years');
        status(200, $c, $c->post('/fees/payments', $body + ['payment_date' => date('Y-m-d', strtotime('-2 years'))]), 'exactly 2 years');
        status(422, $c, $c->post('/fees/payments', $body + ['payment_date' => '2026-02-30']), 'impossible date');
    });

    it('a concession that would make the student overpaid is refused', function () {
        $c = ApiClient::role('super_admin');
        $aid = Flow::need('xss_assignment');
        // Payable 1000, paid 111 so far: a 950 discount would leave payable below paid.
        $r = $c->post("/fees/assignments/$aid", ['title' => 'XSS Fee', 'tuition_fee' => '1000', 'discount_amount' => '950']);
        status(422, $c, $r);
        status(200, $c, $c->post("/fees/assignments/$aid", ['title' => 'XSS Fee', 'tuition_fee' => '1000', 'discount_amount' => '889']), 'down to exactly paid');
        $s = $c->get('/students/' . Flow::need('xss_student'))['fees']['assignments'][0];
        eq('0.00', App\Core\Money::normalize((string) $s['balance']));
    });

    it('editing a fee structure does not change amounts already assigned', function () {
        $c = ApiClient::role('super_admin');
        $fs = $c->post('/fees/structures', ['name' => 'Freeze ' . run_id(), 'course_id' => 1, 'academic_year_id' => 3, 'semester' => 3, 'tuition_fee' => '5000']);
        $sid = new_student($c, ['full_name' => 'Freeze ' . run_id()]);
        $a = $c->post('/fees/assignments', ['student_id' => $sid, 'fee_structure_id' => $fs['id']]);
        status(200, $c, $c->post('/fees/structures/' . $fs['id'], ['name' => 'Freeze ' . run_id(), 'course_id' => 1, 'academic_year_id' => 3, 'semester' => 3, 'tuition_fee' => '9000']));
        eq('5000.00', balance_of($c, $sid, (int) $a['id']));
    });

    it('rejects amounts beyond the money column range without a server error', function () {
        $c = ApiClient::role('super_admin');
        $r = $c->post('/fees/structures', ['name' => 'Huge ' . run_id(), 'course_id' => 1, 'academic_year_id' => 3, 'semester' => 4,
            'tuition_fee' => '9999999999', 'examination_fee' => '9999999999']);
        ok($c->status === 422, "sum beyond DECIMAL(12,2) must be a validation error, got HTTP {$c->status}: " . ($r['message'] ?? ''));
    });

    it('records in use cannot be deleted', function () {
        $c = ApiClient::role('super_admin');
        status(409, $c, $c->post('/courses/1/delete'), 'course with students');
        status(409, $c, $c->post('/departments/1/delete'), 'department with courses');
        status(409, $c, $c->post('/academic-years/3/delete'), 'academic year in use');
    });

    it('changing a student course moves the department too', function () {
        $c = ApiClient::role('super_admin');
        $sid = new_student($c, ['full_name' => 'Mover ' . run_id()]);
        $s = $c->get("/students/$sid")['student'];
        $r = $c->post("/students/$sid", array_merge($s, ['course_id' => 3]));
        status(200, $c, $r);
        $s = $c->get("/students/$sid")['student'];
        eq(3, (int) $s['course_id']);
        eq(3, (int) $s['department_id'], 'BTME belongs to Mechanical');
    });

    it('report date ranges are validated', function () {
        $c = ApiClient::role('accountant');
        status(422, $c, $c->get('/reports/fee-collection', ['from' => '2026-12-01', 'to' => '2026-01-01']));
        status(200, $c, $c->get('/reports/fee-collection', ['from' => 'garbage', 'to' => '2026-13-45']), 'junk dates fall back to defaults');
    });

    it('malformed or oversized request bodies are validation errors, not crashes', function () {
        $c = ApiClient::role('accountant');
        foreach (['{"amount": ', str_repeat('[', 5000), json_encode(['note' => str_repeat('x', 2_100_000)])] as $i => $raw) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'header' => implode("\r\n", [
                'Content-Type: application/json', 'X-CSRF-Token: ' . $c->csrf, 'Cookie: ' . $c->cookieHeader(),
            ]), 'content' => $raw]]);
            $body = file_get_contents(TestKit::$http['url'] . '/api/index.php?r=/fees/payments', false, $ctx);
            preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m);
            eq(422, (int) $m[1], "body #$i: " . substr((string) $body, 0, 120));
        }
    });

    it('a new session id is issued at login (no session fixation)', function () {
        $c = ApiClient::anonymous();
        $before = $c->cookieHeader();
        [$u, $p] = TestKit::$http['users']['accountant'];
        $c->post('/auth/login', ['username' => $u, 'password' => $p]);
        ok($before !== $c->cookieHeader() && $c->cookieHeader() !== '', 'session cookie changed');
        $fix = new ApiClient(TestKit::$http['url']);
        $fix->setCookieHeader($before);
        status(401, $fix, $fix->get('/students'), 'pre-login session id is not authenticated');
    });

    it('the same user can be signed in from two browsers', function () {
        $a = ApiClient::role('accountant');
        $b = ApiClient::role('accountant');
        status(200, $a, $a->get('/students'));
        status(200, $b, $b->get('/students'));
        $a->post('/auth/logout');
        status(200, $b, $b->get('/students'), 'logging out one does not end the other');
    });
}, http: true);
