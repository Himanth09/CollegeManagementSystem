<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Validator;

/**
 * One-time web installer.
 *  1. Checks server requirements and the database connection (config/config.php)
 *  2. Imports database/schema.sql (+ optional seed.sql) if tables are missing
 *  3. Initializes the Super Admin account (and optional demo-user passwords)
 *  4. Writes storage/install.lock - the installer refuses to run again.
 * Delete the /install folder after a successful installation.
 */

$base = dirname(__DIR__);
$lock = $base . '/storage/install.lock';

function h(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function page(string $title, string $body): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . h($title) . ' - CMS Installer</title><style>'
        . 'body{font-family:"Segoe UI",system-ui,Arial,sans-serif;background:#f1f5f9;color:#0f172a;margin:0;padding:32px 16px}'
        . '.box{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px;box-shadow:0 10px 30px rgba(15,23,42,.08)}'
        . 'h1{margin:0 0 4px;color:#1e3a8a;font-size:22px}h2{font-size:16px;margin:24px 0 10px;color:#1e3a8a}p{color:#475569}'
        . 'table{width:100%;border-collapse:collapse;font-size:14px}td{padding:7px 8px;border-bottom:1px solid #e2e8f0}'
        . '.ok{color:#16a34a;font-weight:600}.bad{color:#dc2626;font-weight:600}'
        . 'label{display:block;font-weight:600;font-size:13px;margin:12px 0 4px}input[type=text],input[type=email],input[type=password]{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}'
        . '.check{display:flex;gap:8px;align-items:center;font-weight:400;margin-top:12px}'
        . 'button,.btn{display:inline-block;margin-top:20px;background:#1d4ed8;color:#fff;border:0;border-radius:8px;padding:11px 22px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}'
        . '.alert{padding:12px 14px;border-radius:8px;margin:14px 0}.err{background:#fee2e2;color:#991b1b}.info{background:#eff6ff;color:#1e3a8a}.warn{background:#fef3c7;color:#92400e}'
        . 'code{background:#f1f5f9;padding:1px 6px;border-radius:4px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}@media(max-width:600px){.grid{grid-template-columns:1fr}}'
        . '</style></head><body><div class="box"><h1>College Management System</h1><p>Installation wizard</p>' . $body . '</div></body></html>';
    exit;
}

if (is_file($lock)) {
    http_response_code(403);
    page('Already installed', '<div class="alert warn">The application is already installed. For security, <strong>delete the <code>install/</code> folder</strong> from your server.</div><a class="btn" href="../">Open the application</a>');
}

// ------------------------------------------------------------------ requirements
$checks = [
    'PHP 8.2 or newer (current ' . PHP_VERSION . ')' => PHP_VERSION_ID >= 80200,
    'PDO MySQL extension'                            => extension_loaded('pdo_mysql'),
    'mbstring extension'                             => extension_loaded('mbstring'),
    'fileinfo extension (secure uploads)'            => extension_loaded('fileinfo'),
    'JSON extension'                                 => extension_loaded('json'),
    'config/config.php exists'                       => is_file($base . '/config/config.php'),
    'storage/ is writable'                           => is_writable($base . '/storage'),
    'storage/uploads/ is writable'                   => is_writable($base . '/storage/uploads'),
    'storage/backups/ is writable'                   => is_writable($base . '/storage/backups'),
];
$reqOk = !in_array(false, $checks, true);
$reqHtml = '<h2>1. Server requirements</h2><table>';
foreach ($checks as $label => $ok) {
    $reqHtml .= '<tr><td>' . h($label) . '</td><td class="' . ($ok ? 'ok">OK' : 'bad">Missing') . '</td></tr>';
}
$reqHtml .= '</table>';
if (!$reqOk) {
    page('Requirements', $reqHtml . '<div class="alert err">Fix the items marked "Missing" and reload this page. Copy <code>config/config.sample.php</code> to <code>config/config.php</code> and enter your database details. Folder permissions can be set to 755 in File Manager.</div>');
}

require $base . '/app/bootstrap.php';


// ------------------------------------------------------------------ database
try {
    $pdo = Database::pdo();
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
} catch (Throwable $e) {
    page('Database', $reqHtml . '<h2>2. Database connection</h2><div class="alert err">Could not connect to the database: '
        . h($e->getMessage()) . '</div><p>Check the <code>db</code> section of <code>config/config.php</code> (host is usually <code>localhost</code>; on Hostinger the database and user names start with your account prefix, e.g. <code>u123456789_cms</code>).</p>');
}
$isMaria = stripos($version, 'mariadb') !== false;
$numeric = preg_replace('/[^0-9.].*$/', '', $version);
$versionOk = $isMaria ? version_compare($numeric, '10.4', '>=') : version_compare($numeric, '8.0.16', '>=');
$tables = Database::column('SHOW TABLES');
$installed = in_array('users', $tables, true) && in_array('fee_payments', $tables, true);
$hasSeedUsers = $installed && (int) Database::value('SELECT COUNT(*) FROM users') > 0;

$dbHtml = '<h2>2. Database</h2><table><tr><td>Connected to <code>' . h(Config::get('db.name')) . '</code></td><td class="ok">OK</td></tr>'
    . '<tr><td>Server version ' . h($version) . ' (MySQL 8.0.16+ or MariaDB 10.4+ required)</td><td class="' . ($versionOk ? 'ok">OK' : 'bad">Too old') . '</td></tr>'
    . '<tr><td>Application tables</td><td>' . ($installed ? '<span class="ok">Found (imported via phpMyAdmin)</span>' : 'Not yet created - will be imported') . '</td></tr></table>';
if (!$versionOk) {
    page('Database', $reqHtml . $dbHtml . '<div class="alert err">Your database server is too old. In hPanel / cPanel choose a MySQL 8 database.</div>');
}

/** Splits a SQL file into statements, respecting quotes and comments. */
function split_sql(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\') {
                $buf .= $sql[++$i] ?? '';
            } elseif ($c === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
        }
        if ($c === ';') {
            if (trim($buf) !== '') {
                $out[] = trim($buf);
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}

// ------------------------------------------------------------------ handle submit
$errors = [];
$values = ['username' => 'admin', 'email' => '', 'full_name' => 'System Administrator', 'seed' => '1'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = array_merge($values, array_map(static fn ($v) => is_string($v) ? trim($v) : '', $_POST));
    $values['seed'] = isset($_POST['seed']) ? '1' : '0';
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        $errors[] = 'Security token expired. Please submit the form again.';
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $values['username'])) {
        $errors[] = 'Username must be 3-60 letters, numbers, dot, dash or underscore.';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid admin email address.';
    }
    if ($values['full_name'] === '' || mb_strlen($values['full_name']) > 150) {
        $errors[] = 'Enter the administrator\'s full name.';
    }
    $pw = (string) ($_POST['password'] ?? '');
    if ($err = Validator::passwordError($pw)) {
        $errors[] = 'Admin password: ' . $err;
    } elseif ($pw !== ($_POST['password_confirm'] ?? '')) {
        $errors[] = 'Admin passwords do not match.';
    }
    $demoPw = (string) ($_POST['demo_password'] ?? '');
    if ($demoPw !== '' && ($err = Validator::passwordError($demoPw))) {
        $errors[] = 'Demo user password: ' . $err;
    }

    if (!$errors) {
        try {
            if (!$installed) {
                foreach (split_sql((string) file_get_contents($base . '/database/schema.sql')) as $stmt) {
                    $pdo->exec($stmt);
                }
                if ($values['seed'] === '1') {
                    foreach (split_sql((string) file_get_contents($base . '/database/seed.sql')) as $stmt) {
                        $pdo->exec($stmt);
                    }
                } else {
                    // Minimum reference data: roles + permissions.
                    foreach (split_sql((string) file_get_contents($base . '/database/seed.sql')) as $stmt) {
                        if (preg_match('/^INSERT INTO (roles|permissions|role_permissions)\b/i', $stmt) || str_starts_with($stmt, 'SET ')) {
                            $pdo->exec($stmt);
                        }
                    }
                }
            }
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            $adminRole = (int) Database::value("SELECT id FROM roles WHERE slug = 'super_admin'");
            $existing = Database::one('SELECT id FROM users WHERE username = ? OR email = ? OR id = 1 ORDER BY (username = ?) DESC LIMIT 1',
                [$values['username'], $values['email'], $values['username']]);
            if ($existing && (int) Database::value('SELECT role_id FROM users WHERE id = ?', [(int) $existing['id']]) === $adminRole) {
                Database::execute('UPDATE users SET username = ?, email = ?, full_name = ?, password_hash = ?, is_active = 1, password_changed_at = NOW() WHERE id = ?',
                    [$values['username'], $values['email'], $values['full_name'], $hash, (int) $existing['id']]);
                $adminId = (int) $existing['id'];
            } else {
                $adminId = Database::insert('users', ['role_id' => $adminRole, 'username' => $values['username'], 'email' => $values['email'],
                    'full_name' => $values['full_name'], 'password_hash' => $hash, 'password_changed_at' => date('Y-m-d H:i:s')]);
            }
            $demoSet = 0;
            if ($demoPw !== '') {
                $demoSet = Database::execute("UPDATE users SET password_hash = ? WHERE password_hash = '' AND id <> ?", [password_hash($demoPw, PASSWORD_DEFAULT), $adminId]);
            }
            Database::insert('audit_logs', ['user_id' => $adminId, 'action' => 'system.install', 'entity_type' => 'system',
                'new_values' => json_encode(['admin' => $values['username'], 'seed' => !$installed && $values['seed'] === '1']), 'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
            $pdo->commit();
            file_put_contents($lock, 'Installed ' . date('c') . "\n");

            $demo = '';
            if ($demoSet > 0) {
                $demo = '<div class="alert info">Demo accounts activated with the demo password: <code>accountant</code> (Accountant) and <code>staff</code> (Staff). Change or deactivate them before going live.</div>';
            }
            page('Installed', '<div class="alert info"><strong>Installation complete.</strong> Sign in as <code>' . h($values['username']) . '</code>.</div>' . $demo
                . '<div class="alert warn"><strong>Important:</strong> delete the <code>install/</code> folder from your server now.</div>'
                . '<a class="btn" href="../">Open the application</a>');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}

// ------------------------------------------------------------------ form
$err = $errors ? '<div class="alert err">' . implode('<br>', array_map('h', $errors)) . '</div>' : '';
$seedOpt = $installed
    ? '<div class="alert info">Tables already exist, so nothing will be imported. Only the admin account will be initialized.</div>'
    : '<label class="check"><input type="checkbox" name="seed" value="1"' . ($values['seed'] === '1' ? ' checked' : '') . '> Load sample data (20 students, 12 staff, fees, payroll) - recommended for evaluation</label>';
$demoOpt = (!$installed || $hasSeedUsers)
    ? '<h2>4. Demo users (optional)</h2><p>If sample data is loaded, set one password for the demo <code>accountant</code> and <code>staff</code> accounts. Leave blank to keep them disabled.</p>'
      . '<label>Demo user password</label><input type="password" name="demo_password" autocomplete="new-password">'
    : '';
page('Install', $reqHtml . $dbHtml . $err . '<form method="post" autocomplete="off">'
    . '<input type="hidden" name="_csrf" value="' . h(Csrf::token()) . '">'
    . '<h2>3. Super Admin account</h2>' . $seedOpt
    . '<div class="grid"><div><label>Username</label><input type="text" name="username" required value="' . h($values['username']) . '"></div>'
    . '<div><label>Email</label><input type="email" name="email" required value="' . h($values['email']) . '"></div></div>'
    . '<label>Full name</label><input type="text" name="full_name" required value="' . h($values['full_name']) . '">'
    . '<div class="grid"><div><label>Password</label><input type="password" name="password" required autocomplete="new-password"></div>'
    . '<div><label>Confirm password</label><input type="password" name="password_confirm" required autocomplete="new-password"></div></div>'
    . '<p style="font-size:13px">At least 8 characters with upper &amp; lower case letters, a number and a symbol.</p>'
    . $demoOpt
    . '<button type="submit">Install now</button></form>');
