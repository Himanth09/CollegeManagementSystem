<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** User accounts & permission grants (Super Admin). */
final class UserController
{
    public static function index(): never
    {
        Auth::requirePermission('users.manage');
        $users = Database::all(
            'SELECT u.id, u.username, u.email, u.full_name, u.staff_id, u.is_active, u.last_login_at, u.created_at,
                    (u.password_hash <> \'\') AS has_password, r.id AS role_id, r.slug AS role, r.name AS role_name,
                    t.employee_id, t.full_name AS staff_name
               FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN staff t ON t.id = u.staff_id
           ORDER BY r.id, u.full_name'
        );
        $grants = [];
        foreach (Database::all('SELECT up.user_id, p.slug FROM user_permissions up JOIN permissions p ON p.id = up.permission_id') as $g) {
            $grants[$g['user_id']][] = $g['slug'];
        }
        foreach ($users as &$u) {
            $u['granted'] = $grants[$u['id']] ?? [];
        }
        Response::ok([
            'data'        => $users,
            'roles'       => Database::all('SELECT r.id, r.slug, r.name, r.description,
                                   (SELECT GROUP_CONCAT(p.slug) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = r.id) AS permissions
                                   FROM roles r ORDER BY r.id'),
            'permissions' => Database::all('SELECT id, slug, name, grantable FROM permissions ORDER BY id'),
            'staff'       => Database::all('SELECT t.id, t.employee_id, t.full_name, t.email,
                                   (SELECT u.id FROM users u WHERE u.staff_id = t.id) AS user_id
                                   FROM staff t ORDER BY t.full_name'),
        ]);
    }

    public static function save(int $id = 0): never
    {
        Auth::requirePermission('users.manage');
        $in = Request::body();
        $d = Validator::make($in, [
            'username'  => 'required|username',
            'email'     => 'required|email',
            'full_name' => 'required|max:150',
            'role_id'   => 'required|int',
            'staff_id'  => 'nullable|int',
            'is_active' => 'nullable|bool',
        ]);
        $password = (string) ($in['password'] ?? '');
        $d['is_active'] = in_array($d['is_active'], ['0', 'false'], true) ? 0 : 1;

        $role = Database::one('SELECT id, slug FROM roles WHERE id = ?', [$d['role_id']])
            ?? throw HttpException::validation(['role_id' => 'Select a valid role.']);
        if ($d['staff_id'] && !Database::value('SELECT id FROM staff WHERE id = ?', [$d['staff_id']])) {
            throw HttpException::validation(['staff_id' => 'Select a valid staff member.']);
        }
        if ($d['staff_id'] && Database::value('SELECT id FROM users WHERE staff_id = ? AND id <> ?', [$d['staff_id'], $id])) {
            throw HttpException::validation(['staff_id' => 'This staff member already has a user account.']);
        }
        if (Database::value('SELECT id FROM users WHERE username = ? AND id <> ?', [$d['username'], $id])) {
            throw HttpException::validation(['username' => 'Username is already taken.']);
        }
        if (Database::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$d['email'], $id])) {
            throw HttpException::validation(['email' => 'Email is already used by another account.']);
        }
        if (!$id || $password !== '') {
            if ($err = \App\Core\Validator::passwordError($password)) {
                throw HttpException::validation(['password' => $err]);
            }
        }

        // Only grantable permissions may be assigned individually.
        $requested = array_filter((array) ($in['permissions'] ?? []), 'is_string');
        $permIds = $requested ? Database::column(
            'SELECT id FROM permissions WHERE grantable = 1 AND slug IN (' . implode(',', array_fill(0, count($requested), '?')) . ')',
            array_values($requested)
        ) : [];

        $id = Database::transaction(static function () use ($id, $d, $password, $role, $permIds) {
            if ($id) {
                $old = Database::one('SELECT id, username, email, full_name, role_id, staff_id, is_active FROM users WHERE id = ? FOR UPDATE', [$id])
                    ?? throw HttpException::notFound('User not found.');
                if ($id === Auth::id() && (!$d['is_active'] || $role['slug'] !== Auth::SUPER_ADMIN)) {
                    throw new HttpException('You cannot deactivate your own account or remove your own Super Admin role.', 409);
                }
                $admins = (int) Database::value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'super_admin' AND u.is_active = 1 AND u.id <> ?", [$id]);
                $wasAdmin = (int) Database::value("SELECT COUNT(*) FROM roles WHERE id = ? AND slug = 'super_admin'", [(int) $old['role_id']]);
                if ($wasAdmin && $admins === 0 && ($role['slug'] !== Auth::SUPER_ADMIN || !$d['is_active'])) {
                    throw new HttpException('At least one active Super Admin account must remain.', 409);
                }
                $row = $d;
                if ($password !== '') {
                    $row['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    $row['password_changed_at'] = date('Y-m-d H:i:s');
                }
                Database::update('users', $id, $row);
                Audit::log('user.update', 'user', $id, ...Audit::diff($old, $d));
                if ($password !== '') {
                    Audit::log('user.password_set_by_admin', 'user', $id);
                }
            } else {
                $row = $d + ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => date('Y-m-d H:i:s')];
                $id = Database::insert('users', $row);
                Audit::log('user.create', 'user', $id, null, $d);
            }
            $oldGrants = Database::column('SELECT p.slug FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = ?', [$id]);
            Database::execute('DELETE FROM user_permissions WHERE user_id = ?', [$id]);
            if ($role['slug'] !== Auth::SUPER_ADMIN) {
                foreach ($permIds as $pid) {
                    Database::insert('user_permissions', ['user_id' => $id, 'permission_id' => (int) $pid, 'granted_by' => Auth::id()]);
                }
            }
            $newGrants = Database::column('SELECT p.slug FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = ?', [$id]);
            if ($oldGrants != $newGrants) {
                Audit::log('user.permissions', 'user', $id, ['granted' => $oldGrants], ['granted' => $newGrants]);
            }
            return $id;
        });
        Response::ok(['id' => $id], 'User account saved.');
    }
}
