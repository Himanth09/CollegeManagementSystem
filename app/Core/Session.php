<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Hardened PHP session: HttpOnly + SameSite cookies, Secure on HTTPS,
 * strict mode, idle timeout and periodic ID rotation.
 */
final class Session
{
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_name('CMSSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::cookiePath(),
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $timeout = (int) Config::get('app.session_timeout', 1800);
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $timeout) {
            self::destroy();
            session_start();
            $_SESSION['expired'] = true;
        }
        $_SESSION['last_activity'] = $now;

        // Rotate the session id every 15 minutes to limit fixation / hijack windows.
        if (!isset($_SESSION['created_at'])) {
            $_SESSION['created_at'] = $now;
        } elseif ($now - (int) $_SESSION['created_at'] > 900) {
            session_regenerate_id(true);
            $_SESSION['created_at'] = $now;
        }
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    /** Root path keeps the cookie valid whether the app lives at / or in a sub-folder. */
    private static function cookiePath(): string
    {
        return '/';
    }
}
