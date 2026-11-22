<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

final class AuthController
{
    /** Current session state + CSRF token; called by the SPA on load. */
    public static function me(): never
    {
        $user = Auth::publicUser();
        Response::ok([
            'authenticated' => $user !== null,
            'user'          => $user,
            'csrf'          => Csrf::token(),
            'app'           => [
                'name'         => Config::get('app.name'),
                'college_name' => Config::get('app.college_name'),
                'currency'     => Config::get('app.currency_symbol', '₹'),
            ],
        ]);
    }

    public static function login(): never
    {
        $login = mb_substr((string) Request::str('username'), 0, 150);
        $password = (string) Request::input('password', '');
        if ($login === '' || $password === '') {
            throw HttpException::validation(['username' => 'Enter your username and password.']);
        }
        $ip = Request::ip();
        $window = (int) Config::get('security.lockout_minutes', 15);
        $max = (int) Config::get('security.max_login_attempts', 5);
        $since = date('Y-m-d H:i:s', time() - $window * 60);

        // Per-account limit, plus a looser per-IP limit (many users can share one campus NAT address).
        $counts = Database::one(
            'SELECT COALESCE(SUM(username = ?), 0) AS by_user, COALESCE(SUM(ip_address = ?), 0) AS by_ip FROM login_attempts
              WHERE success = 0 AND attempted_at >= ? AND (username = ? OR ip_address = ?)',
            [$login, $ip, $since, $login, $ip]
        );
        if ((int) $counts['by_user'] >= $max || (int) $counts['by_ip'] >= $max * 5) {
            throw new HttpException("Too many failed sign-in attempts. Try again in $window minutes.", 429);
        }

        $user = Database::one(
            'SELECT id, password_hash, is_active FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$login, $login]
        );
        // Always run password_verify so response time does not reveal whether a user exists.
        $hash = ($user && $user['password_hash'] !== '') ? $user['password_hash'] : password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
        $valid = password_verify($password, $hash) && $user && (int) $user['is_active'] === 1;

        Database::insert('login_attempts', [
            'username' => $login, 'ip_address' => $ip, 'success' => $valid ? 1 : 0, 'attempted_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$valid) {
            Audit::log('auth.login_failed', 'user', $user['id'] ?? null, null, ['login' => $login]);
            throw new HttpException('Invalid username or password.', 401);
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', (int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        Database::update('users', (int) $user['id'], ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip]);
        Auth::login((int) $user['id']);
        Audit::log('auth.login', 'user', (int) $user['id']);
        // Housekeeping: drop attempt rows older than 30 days.
        Database::execute('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', strtotime('-30 days'))]);

        Response::ok(['user' => Auth::publicUser(), 'csrf' => Csrf::token()], 'Welcome back!');
    }

    public static function logout(): never
    {
        if (Auth::user()) {
            Audit::log('auth.logout', 'user', Auth::id());
        }
        Auth::logout();
        Session::start();
        Response::ok(['csrf' => Csrf::token()], 'You have been signed out.');
    }

    public static function forgot(): never
    {
        $email = (string) Request::str('email');
        $generic = 'If an active account exists for that email, a password reset link has been sent.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw HttpException::validation(['email' => 'Enter a valid email address.']);
        }
        $user = Database::one('SELECT id, email, full_name FROM users WHERE email = ? AND is_active = 1', [$email]);
        if ($user) {
            $recent = (int) Database::value(
                'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at >= ?',
                [(int) $user['id'], date('Y-m-d H:i:s', time() - 3600)]
            );
            if ($recent < 3) {
                $token = bin2hex(random_bytes(32));
                $minutes = (int) Config::get('security.reset_token_minutes', 30);
                Database::insert('password_resets', [
                    'user_id'    => (int) $user['id'],
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => date('Y-m-d H:i:s', time() + $minutes * 60),
                    'request_ip' => Request::ip(),
                ]);
                self::sendResetMail($user, $token, $minutes);
                Audit::log('auth.reset_requested', 'user', (int) $user['id']);
            }
        }
        Response::ok([], $generic);
    }

    private static function sendResetMail(array $user, string $token, int $minutes): void
    {
        $url = rtrim((string) Config::get('app.url'), '/') . '/#/reset-password?token=' . $token;
        $app = (string) Config::get('app.name');
        $from = (string) Config::get('mail.from_email');
        $fromName = (string) Config::get('mail.from_name', $app);
        $subject = "$app - Password reset";
        $body = "Hello {$user['full_name']},\r\n\r\n"
            . "We received a request to reset your password. Open the link below within $minutes minutes:\r\n\r\n"
            . "$url\r\n\r\n"
            . "If you did not request this, you can ignore this email; your password will not change.\r\n";
        $headers = [
            'From'         => sprintf('%s <%s>', str_replace(["\r", "\n", '<', '>'], '', $fromName), $from),
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Mailer'     => 'CMS',
        ];
        if (!@mail($user['email'], $subject, $body, $headers)) {
            error_log('Password reset mail could not be sent to user #' . $user['id']);
        }
    }

    public static function reset(): never
    {
        $token = (string) Request::str('token');
        $password = (string) Request::input('password', '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new HttpException('This reset link is invalid or has expired.', 400);
        }
        if ($err = Validator::passwordError($password)) {
            throw HttpException::validation(['password' => $err]);
        }
        Database::transaction(static function () use ($token, $password) {
            $row = Database::one(
                'SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE',
                [hash('sha256', $token)]
            );
            if (!$row) {
                throw new HttpException('This reset link is invalid or has expired.', 400);
            }
            $uid = (int) $row['user_id'];
            Database::execute(
                'UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $uid]
            );
            Database::execute('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$uid]);
            Database::execute('DELETE FROM login_attempts WHERE username IN (SELECT username FROM users WHERE id = ?)', [$uid]);
            Audit::log('auth.password_reset', 'user', $uid);
        });
        Response::ok([], 'Your password has been reset. You can now sign in.');
    }

    public static function changePassword(): never
    {
        $u = Auth::requireLogin();
        $current = (string) Request::input('current_password', '');
        $new = (string) Request::input('new_password', '');
        $hash = (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [(int) $u['id']]);
        if (!password_verify($current, $hash)) {
            throw HttpException::validation(['current_password' => 'Current password is incorrect.']);
        }
        if ($err = Validator::passwordError($new)) {
            throw HttpException::validation(['new_password' => $err]);
        }
        if (password_verify($new, $hash)) {
            throw HttpException::validation(['new_password' => 'New password must be different from the current one.']);
        }
        Database::execute('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), (int) $u['id']]);
        Auth::login((int) $u['id']); // fresh session id after credential change
        Audit::log('auth.password_changed', 'user', (int) $u['id']);
        Response::ok(['csrf' => Csrf::token()], 'Password changed successfully.');
    }
}
