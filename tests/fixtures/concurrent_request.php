<?php
declare(strict_types=1);

/**
 * Child process for ConcurrencyTest: performs ONE API call as a given user on
 * its own MySQL connection, starting at an agreed moment so that several
 * children hit the database at the same time (the PHP built-in web server is
 * single-threaded, so real concurrency needs separate processes).
 *
 * argv[1] = JSON {uid, method, route, body, start_at}
 * stdout  = JSON response body; stderr = HTTP status.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';
restore_error_handler();
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

use App\Controllers\FeeController as Fee;
use App\Controllers\SalaryController as Salary;
use App\Core\Router;

$in = json_decode($argv[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
$token = bin2hex(random_bytes(32));
$_SESSION = ['uid' => (int) $in['uid'], 'csrf_token' => $token];
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
$_SERVER['REQUEST_METHOD'] = $in['method'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_POST = $in['body'] ?? [];

register_shutdown_function(static function () {
    fwrite(STDERR, (string) http_response_code());
});

$r = new Router();
$r->post('/fees/payments', [Fee::class, 'recordPayment']);
$r->post('/fees/payments/{id}/review', [Fee::class, 'reviewPayment']);
$r->post('/fees/payments/{id}/cancel', [Fee::class, 'cancelPayment']);
$r->post('/salary/payroll/generate', [Salary::class, 'generate']);
$r->post('/salary/structures', [Salary::class, 'saveStructure']);

App\Core\Database::pdo(); // connect before the barrier so only the request itself races
while (microtime(true) < (float) $in['start_at']) {
    usleep(500);
}
$r->dispatch($in['method'], $in['route']);
