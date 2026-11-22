<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Pure-PHP database backup (no mysqldump / exec needed, so it works on
 * shared hosting). Files are kept in storage/backups, outside web access.
 */
final class BackupController
{
    private const NAME_RE = '/^cms_backup_\d{8}_\d{6}_[a-f0-9]{8}\.sql(\.gz)?$/';

    private static function dir(): string
    {
        $dir = STORAGE_PATH . '/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    public static function index(): never
    {
        Auth::requirePermission('backup.manage');
        $files = [];
        foreach (glob(self::dir() . '/cms_backup_*') ?: [] as $f) {
            if (preg_match(self::NAME_RE, basename($f))) {
                $files[] = ['name' => basename($f), 'size' => filesize($f), 'created_at' => date('Y-m-d H:i:s', filemtime($f))];
            }
        }
        usort($files, static fn ($a, $b) => strcmp($b['name'], $a['name']));
        Response::ok(['data' => $files]);
    }

    public static function create(): never
    {
        Auth::requirePermission('backup.manage');
        @set_time_limit(300);
        $gz = function_exists('gzopen');
        $name = 'cms_backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql' . ($gz ? '.gz' : '');
        $path = self::dir() . '/' . $name;
        $fh = $gz ? gzopen($path, 'wb6') : fopen($path, 'wb');
        if (!$fh) {
            throw new HttpException('Could not write the backup file. Check that storage/backups is writable.', 500);
        }
        $write = $gz ? static fn (string $s) => gzwrite($fh, $s) : static fn (string $s) => fwrite($fh, $s);
        $pdo = Database::pdo();

        $write("-- College Management System database backup\n-- Created: " . date('c') . "\n\n");
        $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = Database::column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        foreach ($tables as $table) {
            $create = Database::one('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
            $write("DROP TABLE IF EXISTS `$table`;\n" . $create['Create Table'] . ";\n\n");
            // Generated (computed) columns must not appear in INSERT statements.
            $cols = Database::column(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%'
               ORDER BY ORDINAL_POSITION",
                [$table]
            );
            $colList = '`' . implode('`, `', $cols) . '`';
            $offset = 0;
            while (true) {
                $rows = Database::all("SELECT $colList FROM `$table` LIMIT 500 OFFSET $offset");
                if (!$rows) {
                    break;
                }
                $values = [];
                foreach ($rows as $r) {
                    $values[] = '(' . implode(', ', array_map(
                        static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                        array_values($r)
                    )) . ')';
                }
                $write("INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $values) . ";\n");
                $offset += 500;
            }
            $write("\n");
        }
        $write("SET FOREIGN_KEY_CHECKS = 1;\n");
        $gz ? gzclose($fh) : fclose($fh);
        @chmod($path, 0640);
        Audit::log('backup.create', 'backup', $name, null, ['size' => filesize($path), 'tables' => count($tables)]);
        Response::ok(['name' => $name], 'Backup created successfully.');
    }

    public static function download(): never
    {
        Auth::requirePermission('backup.manage');
        $name = (string) Request::queryStr('name');
        if (!preg_match(self::NAME_RE, $name) || !is_file(self::dir() . '/' . $name)) {
            throw HttpException::notFound('Backup not found.');
        }
        $path = self::dir() . '/' . $name;
        Audit::log('backup.download', 'backup', $name);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    public static function delete(): never
    {
        Auth::requirePermission('backup.manage');
        $name = (string) Request::str('name');
        if (!preg_match(self::NAME_RE, $name) || !is_file(self::dir() . '/' . $name)) {
            throw HttpException::notFound('Backup not found.');
        }
        unlink(self::dir() . '/' . $name);
        Audit::log('backup.delete', 'backup', $name);
        Response::ok([], 'Backup deleted.');
    }
}
