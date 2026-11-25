<?php
declare(strict_types=1);

/**
 * Test bootstrap. Unlike app/bootstrap.php it does not start a session or
 * read config/config.php: tests use their own configuration so they can
 * never touch the live database by accident.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', sys_get_temp_dir() . '/cms_test_storage_' . getmypid());

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/lib/TestKit.php';
require __DIR__ . '/lib/ApiClient.php';

/** tests/config.test.php may return the DB settings directly, or ['db' => [...], 'http' => [...]]. */
function test_settings(): array
{
    static $s = null;
    if ($s === null) {
        $file = __DIR__ . '/config.test.php';
        $raw = is_file($file) ? require $file : [];
        $s = isset($raw['host']) || isset($raw['name']) ? ['db' => $raw] : $raw;
    }
    return $s;
}

/**
 * Test database settings, in order of preference:
 *   1. tests/config.test.php (git-ignored), 'db' => ['host'=>..., 'port'=>..., 'name'=>..., 'user'=>..., 'pass'=>...]
 *   2. environment variables CMS_TEST_DB_HOST / _PORT / _NAME / _USER / _PASS
 * Integration tests are skipped when neither is present.
 * Use a DEDICATED test database loaded with schema.sql + seed.sql: every test
 * runs in a transaction that is rolled back, but never point this at live data.
 */
function test_db_config(): ?array
{
    if (!empty(test_settings()['db'])) {
        return test_settings()['db'];
    }
    if (getenv('CMS_TEST_DB_NAME')) {
        return [
            'host' => getenv('CMS_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('CMS_TEST_DB_PORT') ?: 3306),
            'name' => getenv('CMS_TEST_DB_NAME'),
            'user' => getenv('CMS_TEST_DB_USER') ?: 'root',
            'pass' => getenv('CMS_TEST_DB_PASS') ?: '',
            'charset' => 'utf8mb4',
        ];
    }
    return null;
}

/**
 * End-to-end HTTP settings: tests/config.test.php 'http' => ['url' => 'http://127.0.0.1:8098',
 * 'users' => ['super_admin' => ['admin', 'pw'], 'accountant' => [...], 'staff' => [...]]],
 * or CMS_TEST_URL plus CMS_TEST_ADMIN / CMS_TEST_ACCOUNTANT / CMS_TEST_STAFF as "user:password".
 */
function test_http_config(): ?array
{
    if (!empty(test_settings()['http']['url'])) {
        return test_settings()['http'];
    }
    if (getenv('CMS_TEST_URL')) {
        $users = [];
        foreach (['super_admin' => 'CMS_TEST_ADMIN', 'accountant' => 'CMS_TEST_ACCOUNTANT', 'staff' => 'CMS_TEST_STAFF'] as $role => $env) {
            if (getenv($env)) {
                $users[$role] = explode(':', (string) getenv($env), 2);
            }
        }
        return ['url' => getenv('CMS_TEST_URL'), 'users' => $users];
    }
    return null;
}

function test_config(): array
{
    return [
        'app' => [
            'name' => 'CMS Test', 'college_name' => 'Test College', 'url' => 'https://test.local',
            'timezone' => 'Asia/Kolkata', 'currency_symbol' => '₹', 'debug' => false, 'session_timeout' => 1800,
        ],
        'db' => test_db_config() ?? [],
        'mail' => ['from_email' => 'no-reply@test.local', 'from_name' => 'CMS Test'],
        'security' => ['max_login_attempts' => 5, 'lockout_minutes' => 15, 'password_min_length' => 8, 'reset_token_minutes' => 30],
        'uploads' => ['max_photo_bytes' => 2 * 1024 * 1024],
    ];
}

/** Restores clean global state before each test. */
function reset_state(): void
{
    App\Core\Config::load(test_config());
    $_SESSION = [];
    $_GET = [];
    $_POST = [];
    $_FILES = [];
    $_SERVER = array_intersect_key($_SERVER, array_flip(['argv', 'argc', 'PHP_SELF', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'PATH', 'SystemRoot', 'TEMP', 'TMP']));
    setStatic(App\Core\Request::class, 'body', null);
    setStatic(App\Core\Auth::class, 'user', null);
    setStatic(App\Core\Auth::class, 'loaded', false);
}

date_default_timezone_set('Asia/Kolkata');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');
App\Core\Config::load(test_config());

// Convert warnings/notices into failures so sloppy code is caught.
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new ErrorException($str, 0, $no, $file, $line);
});
