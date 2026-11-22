<?php
declare(strict_types=1);

/**
 * Single-page application shell. All data is loaded from api/index.php,
 * which enforces authentication and authorization on every request.
 */
require __DIR__ . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Csrf;

if (is_dir(__DIR__ . '/install') && !is_file(STORAGE_PATH . '/install.lock')) {
    header('Location: install/');
    exit;
}

$v = '1.0.0';
$e = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$appName = (string) Config::get('app.name', 'College Management System');
$college = (string) Config::get('app.college_name', '');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; font-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $e(Csrf::token()) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($appName) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/app.css?v=<?= $v ?>">
<script src="assets/vendor/react.production.min.js"></script>
<script src="assets/vendor/react-dom.production.min.js"></script>
<script src="assets/vendor/htm.umd.js"></script>
<script src="assets/vendor/chart.umd.js" defer></script>
<script src="assets/js/boot.js?v=<?= $v ?>" data-college="<?= $e($college) ?>" data-name="<?= $e($appName) ?>" id="boot"></script>
<script type="module" src="assets/js/app.js?v=<?= $v ?>"></script>
</head>
<body>
<noscript><p style="padding:24px">This application requires JavaScript. Please enable it in your browser.</p></noscript>
<div id="app"></div>
</body>
</html>
