<?php
declare(strict_types=1);

/**
 * Child process used by RouterTest: Router::dispatch() always ends the
 * request with exit, so each scenario runs in its own PHP process.
 * Input: argv[1] = JSON {method, path, session?, server?}
 * Output: the JSON response body; the HTTP status is printed on stderr.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__, 2));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', sys_get_temp_dir());
spl_autoload_register(static function (string $class): void {
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (str_starts_with($class, 'App\\') && is_file($file)) {
        require $file;
    }
});

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Router;

final class FakeIntegrityError extends PDOException
{
    public function __construct(string $msg)
    {
        parent::__construct($msg);
        $this->code = '23000';
    }
}

$in = json_decode($argv[1] ?? '{}', true);
Config::load(['app' => ['debug' => (bool) ($in['debug'] ?? false)]]);
$_SESSION = $in['session'] ?? [];
$_SERVER = ($in['server'] ?? []) + $_SERVER;
// Router logs 500s with error_log(); in CLI that goes to stderr, where the status is reported.
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

register_shutdown_function(static function () {
    fwrite(STDERR, (string) http_response_code());
});

$r = new Router();
$r->get('/items', static fn () => Response::ok(['list' => true]));
$r->get('/items/{id}', static fn (int $id) => Response::ok(['id' => $id]));
$r->get('/students/{sid}/fees/{fid}', static fn (int $sid, int $fid) => Response::ok(['sid' => $sid, 'fid' => $fid]));
$r->post('/items', static fn () => Response::ok(['created' => true]));
$r->get('/silent', static function () {
});
$r->get('/forbidden', static fn () => throw HttpException::forbidden());
$r->get('/invalid', static fn () => throw HttpException::validation(['name' => 'Name is required.']));
$r->get('/boom', static fn () => throw new RuntimeException('secret internal detail'));
$r->get('/check', static fn () => throw new PDOException('SQLSTATE[HY000]: Check constraint \'chk_sfa_concession\' is violated.'));
$r->get('/dup', static fn () => throw new FakeIntegrityError('SQLSTATE[23000]: Duplicate entry \'X\' for key \'uq\''));
$r->get('/fk', static fn () => throw new FakeIntegrityError('SQLSTATE[23000]: Cannot delete or update a parent row'));

$r->dispatch((string) ($in['method'] ?? 'GET'), (string) ($in['path'] ?? '/'));
