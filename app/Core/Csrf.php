<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Synchronizer-token CSRF protection. The token lives in the session and
 * must be echoed back in the X-CSRF-Token header (or _csrf field) on every
 * state-changing request.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function rotate(): string
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    public static function verify(?string $token): bool
    {
        $expected = $_SESSION['csrf_token'] ?? '';
        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }

    public static function verifyRequest(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null);
        if (!self::verify(is_string($token) ? $token : null)) {
            throw new HttpException('Your session security token is invalid or expired. Please reload the page.', 419);
        }
    }
}
