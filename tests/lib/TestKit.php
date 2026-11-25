<?php
declare(strict_types=1);

/**
 * Tiny, dependency-free test kit (no Composer / PHPUnit needed, so the
 * suite runs anywhere PHP runs, including shared-hosting SSH shells).
 *
 *   suite('Money', function () {
 *       it('adds without float errors', fn () => eq('0.30', Money::sum('0.1', '0.2')));
 *   });
 */

final class TestKit
{
    /** @var list<array{suite:string, name:string, fn:callable, db:bool}> */
    public static array $tests = [];
    public static string $suite = '';
    public static bool $suiteNeedsDb = false;
    public static int $assertions = 0;
}

final class AssertionFailed extends RuntimeException
{
}

final class SkipTest extends RuntimeException
{
}

/** Registers a group of tests. Pass $db = true when the tests need the test database. */
function suite(string $name, callable $define, bool $db = false): void
{
    TestKit::$suite = $name;
    TestKit::$suiteNeedsDb = $db;
    $define();
    TestKit::$suite = '';
    TestKit::$suiteNeedsDb = false;
}

function it(string $name, callable $fn): void
{
    TestKit::$tests[] = ['suite' => TestKit::$suite, 'name' => $name, 'fn' => $fn, 'db' => TestKit::$suiteNeedsDb];
}

function skip(string $reason): never
{
    throw new SkipTest($reason);
}

function fail(string $message): never
{
    throw new AssertionFailed($message);
}

function export(mixed $v): string
{
    return is_string($v) ? '"' . addcslashes($v, "\0..\37\"") . '"' : var_export($v, true);
}

/** Strict equality (===). */
function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    TestKit::$assertions++;
    if ($expected !== $actual) {
        fail(($msg ? "$msg: " : '') . 'expected ' . export($expected) . ', got ' . export($actual));
    }
}

function ok(mixed $cond, string $msg = 'expected condition to be true'): void
{
    TestKit::$assertions++;
    if ($cond !== true) {
        fail($msg . ' (got ' . export($cond) . ')');
    }
}

function no(mixed $cond, string $msg = 'expected condition to be false'): void
{
    TestKit::$assertions++;
    if ($cond !== false) {
        fail($msg . ' (got ' . export($cond) . ')');
    }
}

function contains(string $needle, string $haystack, string $msg = ''): void
{
    TestKit::$assertions++;
    if (!str_contains($haystack, $needle)) {
        fail(($msg ? "$msg: " : '') . export($haystack) . ' does not contain ' . export($needle));
    }
}

/**
 * Asserts that $fn throws $class. Returns the exception for further checks.
 * @template T of Throwable
 * @param class-string<T> $class
 * @return T
 */
function throws(string $class, callable $fn, string $msg = ''): Throwable
{
    TestKit::$assertions++;
    try {
        $fn();
    } catch (AssertionFailed $e) {
        throw $e;
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        fail(($msg ? "$msg: " : '') . "expected $class, got " . $e::class . ': ' . $e->getMessage());
    }
    fail(($msg ? "$msg: " : '') . "expected $class to be thrown, nothing was thrown");
}

/** Asserts an App HttpException with the given status (and optionally an error key). */
function throwsHttp(int $status, callable $fn, ?string $errorField = null): App\Core\HttpException
{
    $e = throws(App\Core\HttpException::class, $fn);
    eq($status, $e->status, 'HTTP status');
    if ($errorField !== null) {
        ok(array_key_exists($errorField, $e->errors), "expected validation error for '$errorField', got: " . json_encode($e->errors));
    }
    return $e;
}

/** Sets a private static property (used to reset per-request caches between tests). */
function setStatic(string $class, string $prop, mixed $value): void
{
    $r = new ReflectionProperty($class, $prop);
    $r->setValue(null, $value);
}

/** Calls a private static method. */
function callPrivate(string $class, string $method, mixed ...$args): mixed
{
    $r = new ReflectionMethod($class, $method);
    return $r->invoke(null, ...$args);
}
