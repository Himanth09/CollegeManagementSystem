<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session authentication and server-side authorization.
 * The user record and permissions are re-read from the database on every
 * request, so deactivation or role changes take effect immediately.
 */
final class Auth
{
    public const SUPER_ADMIN = 'super_admin';
    public const ACCOUNTANT  = 'accountant';
    public const STAFF       = 'staff';

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $uid = $_SESSION['uid'] ?? null;
        if (!is_int($uid)) {
            return null;
        }
        $u = Database::one(
            'SELECT u.id, u.username, u.email, u.full_name, u.staff_id, u.is_active, u.last_login_at,
                    r.id AS role_id, r.slug AS role, r.name AS role_name
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.id = ?',
            [$uid]
        );
        if (!$u || !(int) $u['is_active']) {
            Session::destroy();
            return null;
        }
        $u['permissions'] = self::loadPermissions((int) $u['id'], (int) $u['role_id'], $u['role']);
        self::$user = $u;
        return $u;
    }

    private static function loadPermissions(int $userId, int $roleId, string $role): array
    {
        if ($role === self::SUPER_ADMIN) {
            return Database::column('SELECT slug FROM permissions ORDER BY id');
        }
        // Role permissions + individually granted ones. Non-grantable (admin-only)
        // permissions are filtered out for non-admins even if present in the DB.
        return Database::column(
            'SELECT p.slug FROM permissions p
              WHERE p.grantable = 1 AND (
                    p.id IN (SELECT permission_id FROM role_permissions WHERE role_id = ?)
                 OR p.id IN (SELECT permission_id FROM user_permissions WHERE user_id = ?))
              ORDER BY p.id',
            [$roleId, $userId]
        );
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function isSuperAdmin(): bool
    {
        return (self::user()['role'] ?? null) === self::SUPER_ADMIN;
    }

    public static function can(string $permission): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if ($u['role'] === self::SUPER_ADMIN) {
            return true;
        }
        return in_array($permission, $u['permissions'], true);
    }

    public static function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $p) {
            if (self::can($p)) {
                return true;
            }
        }
        return false;
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if (!$u) {
            $expired = !empty($_SESSION['expired']);
            throw new HttpException($expired ? 'Your session has expired. Please sign in again.' : 'Please sign in to continue.', 401);
        }
        return $u;
    }

    public static function requirePermission(string ...$anyOf): void
    {
        self::requireLogin();
        if (!self::canAny(...$anyOf)) {
            throw HttpException::forbidden();
        }
    }

    /** Salary data is gated on the role itself, never on grantable permissions. */
    public static function requireSuperAdmin(): void
    {
        self::requireLogin();
        if (!self::isSuperAdmin()) {
            throw HttpException::forbidden('This section is restricted to Super Admin users.');
        }
    }

    public static function login(int $userId): void
    {
        Session::regenerate(); // prevents session fixation
        unset($_SESSION['expired']);
        $_SESSION['uid'] = $userId;
        $_SESSION['login_at'] = time();
        Csrf::rotate();
        self::$loaded = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$loaded = true;
        self::$user = null;
    }

    /** Public shape of the current user for the frontend. */
    public static function publicUser(): ?array
    {
        $u = self::user();
        if (!$u) {
            return null;
        }
        return [
            'id'          => (int) $u['id'],
            'username'    => $u['username'],
            'email'       => $u['email'],
            'full_name'   => $u['full_name'],
            'staff_id'    => $u['staff_id'] !== null ? (int) $u['staff_id'] : null,
            'role'        => $u['role'],
            'role_name'   => $u['role_name'],
            'permissions' => $u['permissions'],
            'is_super_admin' => $u['role'] === self::SUPER_ADMIN,
        ];
    }
}
