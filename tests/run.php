<?php
declare(strict_types=1);

/**
 * Test runner.
 *
 *   php tests/run.php                 all tests (integration tests skip when no test DB is configured)
 *   php tests/run.php --unit          unit tests only
 *   php tests/run.php --filter=Money  only tests whose "suite > name" contains the text
 *   php tests/run.php -v              list every test, not just failures
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

$opts = getopt('v', ['unit', 'filter:']);
$verbose = isset($opts['v']);
$unitOnly = isset($opts['unit']);
$filter = isset($opts['filter']) ? (string) $opts['filter'] : null;

$files = array_merge(glob(__DIR__ . '/Unit/*Test.php') ?: [], $unitOnly ? [] : (glob(__DIR__ . '/Integration/*Test.php') ?: []));
sort($files);
foreach ($files as $f) {
    require $f;
}

$pdo = null;
$dbError = null;
if (!$unitOnly && test_db_config() !== null) {
    try {
        $pdo = App\Core\Database::pdo();
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$pass = $failCount = $skipCount = 0;
$failures = [];
$start = microtime(true);
$currentSuite = null;

foreach (TestKit::$tests as $t) {
    $label = ($t['suite'] ? $t['suite'] . ' > ' : '') . $t['name'];
    if ($filter !== null && stripos($label, $filter) === false) {
        continue;
    }
    if ($verbose && $t['suite'] !== $currentSuite) {
        $currentSuite = $t['suite'];
        echo "\n  " . $currentSuite . "\n";
    }
    reset_state();
    $status = 'pass';
    $detail = '';
    try {
        if ($t['db']) {
            if ($pdo === null) {
                skip($dbError ? "test DB unreachable: $dbError" : 'no test database configured');
            }
            $pdo->beginTransaction();
        }
        ($t['fn'])();
    } catch (SkipTest $e) {
        $status = 'skip';
        $detail = $e->getMessage();
    } catch (Throwable $e) {
        $status = 'fail';
        $where = $e instanceof AssertionFailed ? self_location($e) : $e::class . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        $detail = $e->getMessage() . "\n        ($where)";
    } finally {
        if ($t['db'] && $pdo !== null && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    if ($status === 'pass') {
        $pass++;
        $verbose ? print("    \e[32m✓\e[0m {$t['name']}\n") : print("\e[32m.\e[0m");
    } elseif ($status === 'skip') {
        $skipCount++;
        $verbose ? print("    \e[33m-\e[0m {$t['name']} (skipped: $detail)\n") : print("\e[33mS\e[0m");
    } else {
        $failCount++;
        $failures[] = "$label\n        $detail";
        $verbose ? print("    \e[31m✗\e[0m {$t['name']}\n") : print("\e[31mF\e[0m");
    }
}

/** File:line of the test that made the failing assertion. */
function self_location(Throwable $e): string
{
    foreach ($e->getTrace() as $frame) {
        $file = $frame['file'] ?? '';
        if ($file !== '' && !str_ends_with($file, 'TestKit.php')) {
            return basename($file) . ':' . ($frame['line'] ?? '?');
        }
    }
    return '?';
}

echo "\n\n";
foreach ($failures as $i => $f) {
    echo "\e[31m" . ($i + 1) . ") $f\e[0m\n\n";
}
$time = number_format(microtime(true) - $start, 2);
$summary = sprintf('%d passed, %d failed, %d skipped, %d assertions in %ss', $pass, $failCount, $skipCount, TestKit::$assertions, $time);
echo ($failCount ? "\e[31mFAILED\e[0m  " : "\e[32mOK\e[0m  ") . $summary . "\n";
if ($skipCount && $pdo === null && !$unitOnly) {
    echo "Integration tests skipped: " . ($dbError ?? 'configure tests/config.test.php or CMS_TEST_DB_* env vars') . "\n";
}

// Clean up the temporary storage folder used by upload tests.
if (is_dir(STORAGE_PATH)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STORAGE_PATH, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir(STORAGE_PATH);
}

exit($failCount ? 1 : 0);
