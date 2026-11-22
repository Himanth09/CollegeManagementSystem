<?php
declare(strict_types=1);

namespace App\Core;

use PDOException;
use Throwable;

/**
 * Tiny router. Routes are addressed as  api/index.php?r=/students/5
 * so no URL rewriting is required on shared hosting. Only GET and POST are
 * used because some hosts' firewalls block PUT/DELETE.
 */
final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function dispatch(string $method, string $path): never
    {
        try {
            $path = '/' . trim($path, '/');
            $allowed = false;
            foreach ($this->routes as [$m, $pattern, $handler]) {
                $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
                if (!preg_match($regex, $path, $match)) {
                    continue;
                }
                $allowed = true;
                if ($m !== $method) {
                    continue;
                }
                if ($method === 'POST') {
                    Csrf::verifyRequest();
                }
                $params = array_map('intval', array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY));
                $handler(...$params);
                Response::ok();
            }
            throw $allowed ? new HttpException('Method not allowed.', 405) : HttpException::notFound('Unknown API endpoint.');
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->status, $e->errors, $e->extra);
        } catch (PDOException $e) {
            error_log('[DB] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $msg = $e->getMessage();
            if (stripos($msg, 'check constraint') !== false || stripos($msg, 'CONSTRAINT') !== false && stripos($msg, 'failed') !== false) {
                Response::error('The values entered violate a business rule (for example, a discount larger than the fee).', 422);
            }
            // 23000 = integrity constraint (duplicate key / foreign-key restriction)
            if ($e->getCode() === '23000') {
                Response::error(str_contains($msg, 'Duplicate entry')
                    ? 'A record with the same unique value already exists.'
                    : 'This record is linked to other records and cannot be changed or removed.', 409);
            }
            Response::error(Config::get('app.debug') ? $e->getMessage() : 'A database error occurred.', 500);
        } catch (Throwable $e) {
            error_log('[APP] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            Response::error(Config::get('app.debug') ? $e->getMessage() : 'An unexpected error occurred.', 500);
        }
    }
}
