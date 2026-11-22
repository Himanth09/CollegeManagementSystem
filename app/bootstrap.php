<?php
declare(strict_types=1);

/**
 * Application bootstrap: loads configuration, registers the autoloader,
 * configures error handling and the secure session.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('This application requires PHP 8.2 or newer. Current version: ' . PHP_VERSION);
}

$configFile = BASE_PATH . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing config/config.php. Copy config/config.sample.php to config/config.php and edit it.');
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Core\Config::load(require $configFile);

date_default_timezone_set((string) App\Core\Config::get('app.timezone', 'Asia/Kolkata'));
mb_internal_encoding('UTF-8');

$debug = (bool) App\Core\Config::get('app.debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

App\Core\Session::start();
