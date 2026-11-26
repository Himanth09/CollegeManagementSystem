<?php
declare(strict_types=1);

/*
 * Race-condition tests: several PHP processes send the same kind of request at
 * the same instant, each on its own MySQL connection. They prove that row
 * locks, the CHECK constraint and unique keys keep money and payroll correct
 * under concurrent use. Setup data is committed and removed afterwards, so
 * this suite does not run inside the runner's rollback transaction.
 */

use App\Core\Database;
use App\Core\FeeLedger;

const ADMIN = 1;
const ACCOUNTANT = 2;

/**
 * Starts every request in its own process, all released at the same moment.
 * @param list<array{uid:int, method?:string, route:string, body?:array}> $requests
 * @return list<array{status:int, body:array}>
 */
function race(array $requests): array
{
    $startAt = microtime(true) + 1.5 + 0.15 * count($requests);
    $procs = [];
    foreach ($requests as $req) {
        $payload = json_encode($req + ['method' => 'POST', 'body' => [], 'start_at' => $startAt]);
        $p = proc_open([PHP_BINARY, '-c', php_ini_loaded_file() ?: '', __DIR__ . '/../fixtures/concurrent_request.php', $payload],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $body = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($p);
        $status = (int) trim(substr(trim($err), -3));
        $out[] = ['status' => $status, 'body' => json_decode((string) $body, true) ?? ['raw' => $body . $err]];
    }
    return $out;
}

function statuses(array $results): array
{
    $c = array_count_values(array_column($results, 'status'));
    ksort($c);
    return $c;
}

function no_server_errors(array $results): void
{
    foreach ($results as $r) {
        if ($r['status'] >= 500 || $r['status'] === 0) {
            fail('a concurrent request failed with HTTP ' . $r['status'] . ': ' . json_encode($r['body']));
        }
    }
}

/** Creates a committed fee assignment for seeded student #1. */
function race_assignment(string $payable): int
{
    return Database::insert('student_fee_assignments', [
        'student_id' => 1, 'fee_structure_id' => null, 'semester' => 1, 'title' => 'RACE TEST ' . bin2hex(random_bytes(3)),
        'academic_year_id' => (int) Database::value('SELECT MIN(id) FROM academic_years'), 'tuition_fee' => $payable,
    ]);
}

function cleanup_assignments(array $ids): void
{
    foreach ($ids as $id) {
        Database::execute('DELETE FROM fee_payments WHERE assignment_id = ?', [$id]);
        Database::execute('DELETE FROM student_fee_assignments WHERE id = ?', [$id]);
    }
}

function paid(int $assignmentId): string
{
    return App\Core\Money::normalize((string) Database::value('SELECT amount_paid FROM student_fee_assignments WHERE id = ?', [$assignmentId]));
}

function pay(int $uid, int $assignmentId, string $amount, array $extra = []): array
{
    return ['uid' => $uid, 'route' => '/fees/payments', 'body' => $extra + [
        'assignment_id' => $assignmentId, 'amount' => $amount, 'payment_date' => date('Y-m-d'),
        'payment_mode' => 'Cash', 'confirm_duplicate' => '1',
    ]];
}

suite('Concurrency (parallel requests)', function () {
    $guard = function (): void {
        if (test_db_config() === null) {
            skip('no test database configured');
        }
        if (!function_exists('proc_open')) {
            skip('proc_open is disabled');
        }
    };

    it('8 simultaneous full payments: exactly one succeeds, no overpayment', function () use ($guard) {
        $guard();
        $a = race_assignment('10000.00');
        try {
            $res = race(array_map(fn ($i) => pay($i % 2 ? ADMIN : ACCOUNTANT, $a, '10000.00'), range(1, 8)));
            no_server_errors($res);
            eq([200 => 1, 422 => 7], statuses($res));
            eq('10000.00', paid($a));
            eq([], FeeLedger::reconcile());
        } finally {
            cleanup_assignments([$a]);
        }
    });

    it('8 simultaneous partial payments stop exactly at the balance', function () use ($guard) {
        $guard();
        $a = race_assignment('10000.00');
        try {
            $res = race(array_map(fn ($i) => pay($i % 2 ? ADMIN : ACCOUNTANT, $a, '3000.00'), range(1, 8)));
            no_server_errors($res);
            eq([200 => 3, 422 => 5], statuses($res));
            eq('9000.00', paid($a));
            eq(3, (int) Database::value("SELECT COUNT(*) FROM fee_payments WHERE assignment_id = ? AND status = 'confirmed'", [$a]));
            $receipts = Database::column('SELECT receipt_no FROM fee_payments WHERE assignment_id = ?', [$a]);
            eq(3, count(array_unique($receipts)), 'unique receipt numbers');
        } finally {
            cleanup_assignments([$a]);
        }
    });

    it('a brand-new transaction reference used 6 times at once is accepted only once', function () use ($guard) {
        $guard();
        $ids = array_map(fn () => race_assignment('5000.00'), range(1, 6));
        $ref = 'RACE-' . strtoupper(bin2hex(random_bytes(4)));
        try {
            $res = race(array_map(fn ($a) => ['uid' => ACCOUNTANT, 'route' => '/fees/payments', 'body' => [
                'assignment_id' => $a, 'amount' => '1000.00', 'payment_date' => date('Y-m-d'), 'payment_mode' => 'UPI', 'transaction_ref' => $ref,
            ]], $ids));
            no_server_errors($res);
            eq([200 => 1, 409 => 5], statuses($res));
            eq(1, (int) Database::value("SELECT COUNT(*) FROM fee_payments WHERE transaction_ref_norm = ? AND status = 'confirmed'", [$ref]));
        } finally {
            cleanup_assignments($ids);
        }
    });

    it('approving a pending payment races a new payment without overpaying', function () use ($guard) {
        $guard();
        $a = race_assignment('10000.00');
        try {
            $pending = Database::insert('fee_payments', [
                'student_id' => 1, 'assignment_id' => $a, 'payment_date' => date('Y-m-d'), 'amount' => '6000.00',
                'payment_mode' => 'UPI', 'transaction_ref' => 'PEND' . $a, 'transaction_ref_norm' => 'PEND' . $a, 'status' => 'pending_review',
            ]);
            $res = race([
                ['uid' => ADMIN, 'route' => "/fees/payments/$pending/review", 'body' => ['decision' => 'approve']],
                pay(ACCOUNTANT, $a, '6000.00'),
            ]);
            no_server_errors($res);
            eq(1, count(array_filter($res, fn ($r) => $r['status'] === 200)), 'exactly one wins: ' . json_encode(statuses($res)));
            eq('6000.00', paid($a));
            eq([], FeeLedger::reconcile());
        } finally {
            cleanup_assignments([$a]);
        }
    });

    it('cancelling the same payment 4 times at once restores the balance once', function () use ($guard) {
        $guard();
        $a = race_assignment('10000.00');
        try {
            $res = race([pay(ADMIN, $a, '4000.00')]);
            eq(200, $res[0]['status']);
            $pid = (int) $res[0]['body']['id'];
            $res = race(array_fill(0, 4, ['uid' => ADMIN, 'route' => "/fees/payments/$pid/cancel", 'body' => ['reason' => 'Race test cancel']]));
            no_server_errors($res);
            eq([200 => 1, 409 => 3], statuses($res));
            eq('0.00', paid($a));
            eq([], FeeLedger::reconcile());
        } finally {
            cleanup_assignments([$a]);
        }
    });

    it('generating the same payroll 4 times at once creates one entry per staff member', function () use ($guard) {
        $guard();
        $month = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));
        Database::execute("DELETE FROM salary_payments WHERE pay_month = ? AND status = 'pending'", [$month . '-01']);
        try {
            $res = race(array_fill(0, 4, ['uid' => ADMIN, 'route' => '/salary/payroll/generate', 'body' => ['month' => $month]]));
            no_server_errors($res);
            $created = array_sum(array_map(fn ($r) => (int) ($r['body']['count'] ?? 0), $res));
            $rows = (int) Database::value('SELECT COUNT(*) FROM salary_payments WHERE pay_month = ?', [$month . '-01']);
            $staff = (int) Database::value('SELECT COUNT(DISTINCT staff_id) FROM salary_payments WHERE pay_month = ?', [$month . '-01']);
            eq($rows, $staff, 'no duplicate salary rows');
            eq($rows, $created, 'responses add up to the rows created');
            ok($rows > 0);
        } finally {
            Database::execute("DELETE FROM salary_payments WHERE pay_month = ? AND status = 'pending'", [$month . '-01']);
        }
    });

    it('two simultaneous salary revisions leave exactly one current structure', function () use ($guard) {
        $guard();
        $staff = (int) Database::value('SELECT staff_id FROM salary_structures WHERE is_current = 1 ORDER BY staff_id LIMIT 1');
        $before = Database::column('SELECT id FROM salary_structures WHERE staff_id = ?', [$staff]);
        $current = Database::one('SELECT * FROM salary_structures WHERE staff_id = ? AND is_current = 1', [$staff]);
        try {
            $res = race(array_map(fn ($basic) => ['uid' => ADMIN, 'route' => '/salary/structures', 'body' => [
                'staff_id' => $staff, 'effective_date' => date('Y-m-d'), 'basic_salary' => $basic,
            ]], ['50000', '60000']));
            no_server_errors($res);
            eq(1, (int) Database::value('SELECT COUNT(*) FROM salary_structures WHERE staff_id = ? AND is_current = 1', [$staff]));
        } finally {
            $new = array_diff(Database::column('SELECT id FROM salary_structures WHERE staff_id = ?', [$staff]), $before);
            if ($new) {
                $in = implode(',', array_map('intval', $new));
                Database::execute("DELETE FROM salary_revisions WHERE new_structure_id IN ($in)");
                Database::execute("UPDATE salary_structures SET is_current = NULL WHERE id IN ($in)");
                Database::execute("DELETE FROM salary_structures WHERE id IN ($in)");
                Database::execute('UPDATE salary_structures SET is_current = 1 WHERE id = ?', [(int) $current['id']]);
            }
        }
    });
});
