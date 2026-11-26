<?php
declare(strict_types=1);

/*
 * Integration tests against a real MySQL test database loaded with
 * database/schema.sql + database/seed.sql. Each test runs inside a
 * transaction that the runner rolls back, so the data is left unchanged.
 */

use App\Core\Auth;
use App\Core\Database;
use App\Core\FeeLedger;

/** Creates a fee assignment for seeded student #1: gross 60,000, concessions 6,000 => payable 54,000. */
function make_assignment(array $over = []): int
{
    return Database::insert('student_fee_assignments', $over + [
        'student_id' => 1,
        'fee_structure_id' => null,
        'academic_year_id' => (int) Database::value('SELECT MIN(id) FROM academic_years'),
        'semester' => 1,
        'title' => 'UNIT TEST FEE',
        'tuition_fee' => '50000.00',
        'examination_fee' => '7000.50',
        'library_fee' => '2999.50',
        'scholarship_amount' => '5000.00',
        'discount_amount' => '1000.00',
    ]);
}

/** Inserts a payment the way the app does: a confirmed one also updates the running total. */
function make_payment(int $assignmentId, string $amount, string $status = 'confirmed', ?string $ref = null): int
{
    if ($status === 'confirmed') {
        FeeLedger::lockAssignment($assignmentId);
        FeeLedger::addPaid($assignmentId, $amount);
    }
    return Database::insert('fee_payments', [
        'student_id' => 1, 'assignment_id' => $assignmentId, 'payment_date' => date('Y-m-d'), 'amount' => $amount,
        'payment_mode' => $ref ? 'UPI' : 'Cash', 'transaction_ref' => $ref, 'transaction_ref_norm' => FeeLedger::normalizeRef($ref),
        'status' => $status,
    ]);
}

/** Simulates a signed-in user for Auth (no real session needed). */
function login_as(mixed $uid): void
{
    $_SESSION = ['uid' => $uid];
    setStatic(Auth::class, 'user', null);
    setStatic(Auth::class, 'loaded', false);
}

suite('Database', function () {
    it('binds ints, strings and nulls with native prepared statements', function () {
        eq(5, Database::value('SELECT ? + 2', [3]));
        eq("O'Brien; DROP TABLE x", Database::value('SELECT ?', ["O'Brien; DROP TABLE x"]));
        eq(null, Database::value('SELECT ?', [null]));
        eq(1, Database::value('SELECT :a = :b', ['a' => 'x', 'b' => 'x']) ? 1 : 0);
    });

    it('insert, update, one, column and execute work together', function () {
        $id = make_assignment();
        ok($id > 0);
        eq(1, Database::update('student_fee_assignments', $id, ['remarks' => 'updated']));
        eq('updated', Database::one('SELECT remarks FROM student_fee_assignments WHERE id = ?', [$id])['remarks']);
        eq([$id], array_map('intval', Database::column('SELECT id FROM student_fee_assignments WHERE id = ?', [$id])));
        eq(null, Database::one('SELECT * FROM student_fee_assignments WHERE id = 0'));
        eq(0, Database::update('student_fee_assignments', $id, []));
    });

    it('nested transaction callbacks join the outer transaction', function () {
        $id = Database::transaction(fn () => make_assignment());
        ok(Database::pdo()->inTransaction(), 'outer (runner) transaction still open');
        ok(Database::one('SELECT id FROM student_fee_assignments WHERE id = ?', [$id]) !== null);
    });

    it('rolls back on exceptions in a top-level transaction', function () {
        $pdo = Database::pdo();
        $pdo->rollBack(); // leave the runner's transaction to test a real top-level one
        $before = (int) Database::value('SELECT COUNT(*) FROM student_fee_assignments');
        throws(RuntimeException::class, fn () => Database::transaction(function () {
            make_assignment();
            throw new RuntimeException('abort');
        }));
        eq($before, (int) Database::value('SELECT COUNT(*) FROM student_fee_assignments'));
        no($pdo->inTransaction());
        $pdo->beginTransaction();
    });

    it('computes gross and payable as generated columns', function () {
        $a = Database::one('SELECT gross_amount, total_payable FROM student_fee_assignments WHERE id = ?', [make_assignment()]);
        eq('60000.00', (string) $a['gross_amount']);
        eq('54000.00', (string) $a['total_payable']);
    });

    it('rejects concessions larger than the fee (CHECK constraint)', function () {
        throws(PDOException::class, fn () => make_assignment(['scholarship_amount' => '70000.00']));
    });

    it('rejects negative fee components and zero payments', function () {
        throws(PDOException::class, fn () => make_assignment(['tuition_fee' => '-1.00']));
        $id = make_assignment();
        throws(PDOException::class, fn () => make_payment($id, '0.00'));
    });
}, db: true);

suite('FeeLedger (balances)', function () {
    it('counts only confirmed payments toward the amount paid', function () {
        $id = make_assignment();
        make_payment($id, '10000.00');
        make_payment($id, '2500.50', 'confirmed', 'UPI-UT-1');
        make_payment($id, '9999.00', 'pending_review', 'UPI-UT-1');
        make_payment($id, '5000.00', 'rejected');
        make_payment($id, '3000.00', 'cancelled');

        $a = FeeLedger::lockAssignment($id);
        eq('12500.50', $a['amount_paid']);
        eq('41499.50', $a['balance']);
    });

    it('reports zero paid and full balance for a new assignment', function () {
        $a = FeeLedger::lockAssignment(make_assignment());
        eq('0.00', $a['amount_paid']);
        eq('54000.00', $a['balance']);
    });

    it('throws 404 for a missing assignment', function () {
        throwsHttp(404, fn () => FeeLedger::lockAssignment(999999999));
    });

    it('lists assignments with paid, balance and pending-review count', function () {
        $id = make_assignment();
        make_payment($id, '4000.00');
        make_payment($id, '100.00', 'pending_review', 'DUP-1');
        make_payment($id, '100.00', 'pending_review', 'DUP-2');
        $rows = array_values(array_filter(FeeLedger::assignmentsForStudent(1), fn ($r) => (int) $r['id'] === $id));
        eq(1, count($rows));
        eq(0, Money_cmp($rows[0]['amount_paid'], '4000'));
        eq(0, Money_cmp($rows[0]['balance'], '50000'));
        eq(2, (int) $rows[0]['pending_review_count']);
    });

    it('student summary outstanding = total payable - confirmed paid', function () {
        $before = FeeLedger::studentSummary(1);
        $id = make_assignment();
        make_payment($id, '1500.25');
        make_payment($id, '700.00', 'pending_review', 'X1');
        $after = FeeLedger::studentSummary(1);
        eq(0, Money_cmp(App\Core\Money::sub($after['total_payable'], $before['total_payable']), '54000'));
        eq(0, Money_cmp(App\Core\Money::sub($after['total_paid'], $before['total_paid']), '1500.25'));
        eq(App\Core\Money::sub($after['total_payable'], $after['total_paid']), $after['outstanding']);
    });

    it('running totals always match the payment history', function () {
        $id = make_assignment();
        make_payment($id, '1000.00');
        make_payment($id, '500.00', 'pending_review', 'R1');
        eq([], FeeLedger::reconcile());
        // A payment changed without going through the ledger is detected.
        Database::execute("UPDATE fee_payments SET status = 'cancelled' WHERE assignment_id = ? AND status = 'confirmed'", [$id]);
        eq([$id], array_map(fn ($r) => (int) $r['id'], FeeLedger::reconcile()));
    });

    it('the database itself refuses an overpaid or negative running total', function () {
        $id = make_assignment();
        throws(PDOException::class, fn () => FeeLedger::addPaid($id, '54000.01'));
        FeeLedger::addPaid($id, '54000.00');
        eq('0.00', FeeLedger::lockAssignment($id)['balance']);
        throws(PDOException::class, fn () => FeeLedger::addPaid($id, '-54000.01'));
    });

    it('a concession that would push payable below the amount paid is refused', function () {
        $id = make_assignment();
        make_payment($id, '50000.00');
        throws(PDOException::class, fn () => Database::update('student_fee_assignments', $id, ['discount_amount' => '6000.00']));
    });

    it('balance is a stored column that follows payable and paid', function () {
        $id = make_assignment();
        make_payment($id, '4000.00');
        eq('50000.00', (string) Database::value('SELECT balance FROM student_fee_assignments WHERE id = ?', [$id]));
        Database::update('student_fee_assignments', $id, ['library_fee' => '0.00']);
        eq('47000.50', (string) Database::value('SELECT balance FROM student_fee_assignments WHERE id = ?', [$id]));
    });

    it('addPaid refuses to run outside a transaction', function () {
        $pdo = Database::pdo();
        $pdo->rollBack();
        try {
            throws(LogicException::class, fn () => FeeLedger::addPaid(1, '1.00'));
        } finally {
            $pdo->beginTransaction();
        }
    });

    it('seed data totals match the documented figures', function () {
        $r = Database::one('SELECT SUM(total_payable) AS payable FROM student_fee_assignments');
        $paid = Database::value("SELECT SUM(amount) FROM fee_payments WHERE status = 'confirmed'");
        eq(0, Money_cmp($r['payable'], '1721500'), 'total payable');
        eq(0, Money_cmp($paid, '1431000'), 'total confirmed paid');
    });

    it('seed running totals are consistent and no assignment is overpaid', function () {
        eq([], FeeLedger::reconcile());
        eq(0, (int) Database::value('SELECT COUNT(*) FROM student_fee_assignments WHERE balance < 0'));
        eq(0, Money_cmp(Database::value('SELECT SUM(amount_paid) FROM student_fee_assignments'), '1431000'));
    });
}, db: true);

function Money_cmp(mixed $a, mixed $b): int
{
    return App\Core\Money::cmp(is_scalar($a) ? (string) $a : null, is_scalar($b) ? (string) $b : null);
}

suite('Auth (role-based access)', function () {
    it('treats a missing or non-integer session uid as signed out', function () {
        eq(null, Auth::user());
        throwsHttp(401, fn () => Auth::requireLogin());
        login_as('1');
        eq(null, Auth::user(), 'string uid is rejected');
        no(Auth::can('dashboard.view'));
    });

    it('reports an expired session distinctly', function () {
        $_SESSION = ['expired' => true];
        contains('expired', throwsHttp(401, fn () => Auth::requireLogin())->getMessage());
    });

    it('super admin has every permission and passes the salary gate', function () {
        login_as(1);
        ok(Auth::isSuperAdmin());
        ok(Auth::can('salary.manage'));
        ok(Auth::can('anything.at.all'));
        Auth::requireSuperAdmin();
        eq((int) Database::value('SELECT COUNT(*) FROM permissions'), count(Auth::user()['permissions']));
    });

    it('accountant can handle fees but not salary or admin areas', function () {
        login_as(2);
        no(Auth::isSuperAdmin());
        ok(Auth::can('fees.collect'));
        ok(Auth::canAny('salary.manage', 'fees.view'));
        no(Auth::can('salary.manage'));
        no(Auth::can('users.manage'));
        no(Auth::can('fees.review'));
        throwsHttp(403, fn () => Auth::requireSuperAdmin());
        throwsHttp(403, fn () => Auth::requirePermission('backup.manage', 'audit.view'));
        Auth::requirePermission('backup.manage', 'fees.view');
    });

    it('staff gets individually granted permissions only', function () {
        login_as(3);
        ok(Auth::can('students.view'), 'granted via user_permissions in seed');
        no(Auth::can('students.manage'));
        no(Auth::can('fees.collect'));
    });

    it('admin-only permissions cannot be granted to others, even by editing the DB', function () {
        $salary = (int) Database::value("SELECT id FROM permissions WHERE slug = 'salary.manage'");
        $review = (int) Database::value("SELECT id FROM permissions WHERE slug = 'fees.review'");
        Database::insert('user_permissions', ['user_id' => 3, 'permission_id' => $salary, 'granted_by' => 1]);
        Database::insert('role_permissions', ['role_id' => (int) Database::value('SELECT role_id FROM users WHERE id = 2'), 'permission_id' => $review]);
        login_as(3);
        no(Auth::can('salary.manage'));
        throwsHttp(403, fn () => Auth::requireSuperAdmin());
        login_as(2);
        no(Auth::can('fees.review'));
    });

    it('a role change takes effect on the next request', function () {
        login_as(2);
        no(Auth::isSuperAdmin());
        Database::execute("UPDATE users SET role_id = (SELECT id FROM roles WHERE slug = 'super_admin') WHERE id = 2");
        login_as(2); // new request: user is re-read from the database
        ok(Auth::isSuperAdmin());
    });

    it('exposes a safe public user shape without the password hash', function () {
        login_as(2);
        $u = Auth::publicUser();
        eq(['id', 'username', 'email', 'full_name', 'staff_id', 'role', 'role_name', 'permissions', 'is_super_admin'], array_keys($u));
        eq(2, $u['id']);
        eq('accountant', $u['role']);
        no($u['is_super_admin']);
        ok(is_int($u['staff_id']));
    });
}, db: true);
