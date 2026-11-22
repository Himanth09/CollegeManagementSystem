<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?array $body = null;

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /** Merged JSON / form body. */
    public static function body(): array
    {
        if (self::$body === null) {
            $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if (str_contains($type, 'application/json')) {
                $raw = file_get_contents('php://input', false, null, 0, 2_000_000) ?: '';
                $data = json_decode($raw, true);
                self::$body = is_array($data) ? $data : [];
            } else {
                self::$body = $_POST;
            }
        }
        return self::$body;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $body = self::body();
        return array_key_exists($key, $body) ? $body[$key] : $default;
    }

    /** Trimmed string input or null when empty. */
    public static function str(string $key): ?string
    {
        $v = self::input($key);
        if ($v === null || is_array($v)) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public static function queryInt(string $key, ?int $default = null): ?int
    {
        $v = $_GET[$key] ?? null;
        if ($v === null || $v === '' || !is_scalar($v) || !preg_match('/^\d+$/', (string) $v)) {
            return $default;
        }
        return (int) $v;
    }

    public static function queryStr(string $key): ?string
    {
        $v = $_GET[$key] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, 150);
    }

    public static function queryDate(string $key): ?string
    {
        $v = self::queryStr($key);
        return ($v !== null && Validator::isDate($v)) ? $v : null;
    }

    public static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
