<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Csv;
use App\Core\FeeLedger;
use App\Core\HttpException;
use App\Core\ListQuery;
use App\Core\Request;
use App\Core\Upload;

suite('FeeLedger (pure helpers)', function () {
    it('normalizes transaction references for duplicate detection', function () {
        eq('UPI123', FeeLedger::normalizeRef(' upi 123 '));
        eq('UPI123', FeeLedger::normalizeRef('UPI123'));
        eq('ABCD', FeeLedger::normalizeRef("ab\tc\nd"));
        eq(null, FeeLedger::normalizeRef(null));
        eq(null, FeeLedger::normalizeRef('   '));
        eq(100, strlen((string) FeeLedger::normalizeRef(str_repeat('a', 150))));
    });

    it('builds receipt numbers from year and id', function () {
        eq('RCP-2026-000042', FeeLedger::receiptNo(42, '2026-07-01'));
        eq('RCP-2025-1234567', FeeLedger::receiptNo(1234567, '2025-12-31'));
    });
});

suite('Csrf', function () {
    it('creates one stable 64-char token per session', function () {
        $t = Csrf::token();
        ok((bool) preg_match('/^[a-f0-9]{64}$/', $t), 'token is 64 hex chars');
        eq($t, Csrf::token());
    });

    it('verifies only the exact token', function () {
        $t = Csrf::token();
        ok(Csrf::verify($t));
        no(Csrf::verify(strtoupper($t)));
        no(Csrf::verify(''));
        no(Csrf::verify(null));
    });

    it('never accepts an empty token when the session has none', function () {
        $_SESSION = [];
        no(Csrf::verify(''));
    });

    it('rotation invalidates the previous token', function () {
        $old = Csrf::token();
        $new = Csrf::rotate();
        ok($old !== $new);
        no(Csrf::verify($old));
        ok(Csrf::verify($new));
    });

    it('checks the X-CSRF-Token header or _csrf field on requests', function () {
        $t = Csrf::token();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $t;
        Csrf::verifyRequest();
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $_POST['_csrf'] = $t;
        Csrf::verifyRequest();
        $_POST = [];
        throwsHttp(419, fn () => Csrf::verifyRequest());
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'forged';
        throwsHttp(419, fn () => Csrf::verifyRequest());
    });
});

suite('Config', function () {
    it('reads nested values with dot notation', function () {
        Config::load(['db' => ['host' => 'h', 'port' => 3306], 'flag' => null]);
        eq('h', Config::get('db.host'));
        eq(3306, Config::get('db.port'));
        eq(['host' => 'h', 'port' => 3306], Config::get('db'));
    });

    it('falls back to the default only when a key is missing', function () {
        Config::load(['a' => ['b' => null], 's' => 'str']);
        eq('x', Config::get('missing', 'x'));
        eq('x', Config::get('a.c', 'x'));
        eq(null, Config::get('a.b', 'x'), 'explicit null is kept');
        eq('x', Config::get('s.deeper', 'x'), 'cannot descend into a scalar');
    });
});

suite('HttpException', function () {
    it('provides typed factories', function () {
        eq(403, HttpException::forbidden()->status);
        eq(404, HttpException::notFound()->status);
        $v = HttpException::validation(['f' => 'bad']);
        eq(422, $v->status);
        eq(['f' => 'bad'], $v->errors);
        eq(409, (new HttpException('dup', 409, [], ['duplicate' => true]))->status);
    });
});

suite('Csv injection protection', function () {
    $safe = fn (mixed $v) => callPrivate(Csv::class, 'safe', $v);

    it('prefixes formula-like cells with an apostrophe', function () use ($safe) {
        eq("'=SUM(A1:A9)", $safe('=SUM(A1:A9)'));
        eq("'+91 98765", $safe('+91 98765'));
        eq("'@cmd", $safe('@cmd'));
        eq("'-2+3", $safe('-2+3'));
        eq("'\tx", $safe("\tx"));
        eq("'-", $safe('-'));
    });

    it('leaves plain text and negative numbers untouched', function () use ($safe) {
        eq('Asha Rao', $safe('Asha Rao'));
        eq('-5', $safe('-5'));
        eq('-1250.50', $safe('-1250.50'));
        eq('12', $safe(12));
        eq('', $safe(null));
    });
});

suite('Request', function () {
    it('parses query integers strictly', function () {
        $_GET = ['a' => '12', 'b' => '-1', 'c' => '1e3', 'd' => ['x'], 'e' => ''];
        eq(12, Request::queryInt('a'));
        eq(7, Request::queryInt('b', 7));
        eq(null, Request::queryInt('c'));
        eq(null, Request::queryInt('d'));
        eq(3, Request::queryInt('e', 3));
        eq(null, Request::queryInt('missing'));
    });

    it('trims and caps query strings, rejecting arrays', function () {
        $_GET = ['q' => '  ravi  ', 'blank' => '   ', 'arr' => ['x'], 'long' => str_repeat('z', 300)];
        eq('ravi', Request::queryStr('q'));
        eq(null, Request::queryStr('blank'));
        eq(null, Request::queryStr('arr'));
        eq(150, mb_strlen((string) Request::queryStr('long')));
    });

    it('accepts only valid query dates', function () {
        $_GET = ['from' => '2026-01-31', 'to' => '2026-02-31', 'x' => "2026-01-01' OR 1=1"];
        eq('2026-01-31', Request::queryDate('from'));
        eq(null, Request::queryDate('to'));
        eq(null, Request::queryDate('x'));
    });

    it('reads form bodies and trims string input', function () {
        $_POST = ['name' => '  Ravi ', 'empty' => '  ', 'list' => ['a'], 'zero' => '0'];
        eq('Ravi', Request::str('name'));
        eq(null, Request::str('empty'));
        eq(null, Request::str('list'));
        eq('0', Request::str('zero'), '"0" is a real value');
        eq('dflt', Request::input('missing', 'dflt'));
    });

    it('limits IP and user-agent length', function () {
        eq('0.0.0.0', Request::ip());
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        eq('203.0.113.9', Request::ip());
        $_SERVER['HTTP_USER_AGENT'] = str_repeat('u', 500);
        eq(255, mb_strlen(Request::userAgent()));
    });
});

suite('ListQuery', function () {
    it('adds conditional filters only for non-empty values', function () {
        $q = (new ListQuery())->when(null, 's.status = ?')->when('', 's.course_id = ?')->when(3, 's.semester = ?')
            ->when('x', '(a = ? OR b = ?)', 2);
        eq(' WHERE s.semester = ? AND (a = ? OR b = ?)', $q->whereSql());
        eq([3, 'x', 'x'], $q->params);
    });

    it('produces no WHERE clause when there are no filters', function () {
        eq('', (new ListQuery())->search(null, ['name'])->search('', ['name'])->whereSql());
    });

    it('escapes LIKE wildcards in search terms', function () {
        $q = (new ListQuery())->search('50%_off\\', ['s.first_name', 's.last_name']);
        eq(' WHERE (s.first_name LIKE ? OR s.last_name LIKE ?)', $q->whereSql());
        eq(['%50\%\_off\\\\%', '%50\%\_off\\\\%'], $q->params);
    });

    it('sorts only by whitelisted columns', function () {
        $map = ['name' => 's.first_name', 'id' => 's.id'];
        $_GET = ['sort' => 'name', 'dir' => 'DESC'];
        eq(' ORDER BY s.first_name DESC', ListQuery::orderBy($map, 'id'));
        $_GET = ['sort' => 'id; DROP TABLE students', 'dir' => 'desc'];
        eq(' ORDER BY s.id ASC', ListQuery::orderBy($map, 'id'), 'unknown sort falls back to default');
        $_GET = ['sort' => 'name', 'dir' => 'sideways'];
        eq(' ORDER BY s.first_name ASC', ListQuery::orderBy($map, 'id'));
        $_GET = [];
        eq(' ORDER BY s.id DESC', ListQuery::orderBy($map, 'id', 'desc'));
    });
});

suite('Upload validation', function () {
    it('returns null when no photo was sent', function () {
        eq(null, Upload::photo('photo', 'students'));
        $_FILES['photo'] = ['error' => UPLOAD_ERR_NO_FILE];
        eq(null, Upload::photo('photo', 'students'));
    });

    it('rejects multiple files, oversize and failed uploads', function () {
        $_FILES['photo'] = ['error' => [0, 0]];
        throwsHttp(422, fn () => Upload::photo('photo', 'students'), 'photo');
        $_FILES['photo'] = ['error' => UPLOAD_ERR_INI_SIZE];
        eq('Photo is too large.', throwsHttp(422, fn () => Upload::photo('photo', 'students'))->errors['photo']);
        $_FILES['photo'] = ['error' => UPLOAD_ERR_PARTIAL];
        contains('failed', throwsHttp(422, fn () => Upload::photo('photo', 'students'))->errors['photo']);
        $_FILES['photo'] = ['error' => UPLOAD_ERR_OK, 'size' => 3 * 1024 * 1024, 'tmp_name' => __FILE__];
        contains('smaller than 2 MB', throwsHttp(422, fn () => Upload::photo('photo', 'students'))->errors['photo']);
        $_FILES['photo'] = ['error' => UPLOAD_ERR_OK, 'size' => 0, 'tmp_name' => __FILE__];
        throwsHttp(422, fn () => Upload::photo('photo', 'students'), 'photo');
    });

    it('rejects files that did not come from an HTTP upload', function () {
        $_FILES['photo'] = ['error' => UPLOAD_ERR_OK, 'size' => 1000, 'tmp_name' => __FILE__];
        eq('Invalid upload.', throwsHttp(422, fn () => Upload::photo('photo', 'students'))->errors['photo']);
    });

    it('allows only known upload folders', function () {
        throwsHttp(400, fn () => Upload::dir('../config'));
        ok(is_dir(Upload::dir('staff')));
    });

    it('deletes only files with generated names (no path traversal)', function () {
        $dir = Upload::dir('students');
        $good = str_repeat('a', 32) . '.jpg';
        file_put_contents("$dir/$good", 'x');
        file_put_contents("$dir/keep.php", 'x');
        Upload::delete('students', '../students/keep.php');
        Upload::delete('students', 'keep.php');
        ok(is_file("$dir/keep.php"), 'non-matching name must not be deleted');
        Upload::delete('students', $good);
        no(is_file("$dir/$good"), 'generated name is deleted');
        Upload::delete('students', null);
    });

    it('refuses to stream unsafe photo names', function () {
        throwsHttp(404, fn () => Upload::stream('students', '../../config/config.php'));
        throwsHttp(404, fn () => Upload::stream('students', str_repeat('b', 32) . '.png'));
    });
});
