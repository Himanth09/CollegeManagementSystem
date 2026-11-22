<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    public static function ok(array $data = [], string $message = ''): never
    {
        $payload = ['ok' => true] + $data;
        if ($message !== '') {
            $payload['message'] = $message;
        }
        self::json($payload);
    }

    public static function error(string $message, int $status = 400, array $errors = [], array $extra = []): never
    {
        self::json(['ok' => false, 'message' => $message, 'errors' => (object) $errors] + $extra, $status);
    }

    public static function html(string $html): never
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $html;
        exit;
    }
}
