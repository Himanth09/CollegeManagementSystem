<?php
declare(strict_types=1);

/**
 * Minimal HTTP client for black-box API tests: keeps its own session cookie
 * and CSRF token, like one browser tab. Uses PHP streams (no cURL needed).
 */
final class ApiClient
{
    private array $cookies = [];
    public ?string $csrf = null;
    public int $status = 0;
    public array $headers = [];
    public string $raw = '';

    public function __construct(private string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Creates a client and signs in. */
    public static function as(string $username, string $password): self
    {
        $c = new self(TestKit::$http['url']);
        $c->bootstrap();
        $r = $c->post('/auth/login', ['username' => $username, 'password' => $password]);
        if ($c->status !== 200) {
            fail("login as $username failed: HTTP {$c->status} " . ($r['message'] ?? $c->raw));
        }
        return $c;
    }

    public static function role(string $role): self
    {
        [$u, $p] = TestKit::$http['users'][$role] ?? fail("no credentials configured for role '$role'");
        return self::as($u, $p);
    }

    public static function anonymous(): self
    {
        $c = new self(TestKit::$http['url']);
        $c->bootstrap();
        return $c;
    }

    /** Loads the session + CSRF token exactly like the SPA does on page load. */
    public function bootstrap(): array
    {
        return $this->get('/auth/me');
    }

    public function get(string $route, array $query = []): array
    {
        return $this->request('GET', $route, $query);
    }

    public function post(string $route, array $body = [], bool $withCsrf = true): array
    {
        return $this->request('POST', $route, [], $body, $withCsrf);
    }

    /** Raw GET for non-JSON responses (CSV downloads, printable HTML). */
    public function getRaw(string $route, array $query = []): string
    {
        $this->request('GET', $route, $query, null, false, false);
        return $this->raw;
    }

    public function cookieHeader(): string
    {
        return implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
    }

    public function setCookieHeader(string $header): void
    {
        $this->cookies = [];
        foreach (array_filter(explode('; ', $header)) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $this->cookies[$k] = $v;
        }
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    private function request(string $method, string $route, array $query = [], ?array $body = null, bool $withCsrf = true, bool $json = true): array
    {
        $url = $this->baseUrl . '/api/index.php?' . http_build_query(['r' => $route] + $query);
        $headers = ['Accept: application/json'];
        if ($this->cookies) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        $content = null;
        if ($method === 'POST') {
            $content = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            if ($withCsrf && $this->csrf) {
                $headers[] = 'X-CSRF-Token: ' . $this->csrf;
            }
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
            'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0,
        ]]);
        set_error_handler(static fn () => true);
        $raw = file_get_contents($url, false, $ctx);
        restore_error_handler();
        if ($raw === false) {
            fail("cannot reach $url - is the test server running?");
        }
        $this->raw = $raw;
        $this->parseHeaders($http_response_header ?? []);
        if (!$json) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            fail("$method $route returned non-JSON (HTTP {$this->status}): " . substr($raw, 0, 300));
        }
        if (isset($data['csrf']) && is_string($data['csrf'])) {
            $this->csrf = $data['csrf'];
        }
        return $data;
    }

    private function parseHeaders(array $lines): void
    {
        $this->headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $this->status = (int) $m[1];
                $this->headers = [];
                continue;
            }
            [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
            $k = strtolower(trim($k));
            $this->headers[$k] = trim($v);
            if ($k === 'set-cookie' && preg_match('/^([^=;]+)=([^;]*)/', trim($v), $c)) {
                if ($c[2] === '' || $c[2] === 'deleted' || stripos($v, 'expires=Thu, 01 Jan 1970') !== false || preg_match('/max-age=0/i', $v)) {
                    unset($this->cookies[$c[1]]);
                } else {
                    $this->cookies[$c[1]] = $c[2];
                }
            }
        }
    }
}

/** Asserts the last response status, showing the API message on mismatch. */
function status(int $expected, ApiClient $c, array $response = [], string $msg = ''): void
{
    TestKit::$assertions++;
    if ($c->status !== $expected) {
        fail(($msg ? "$msg: " : '') . "expected HTTP $expected, got {$c->status}: " . json_encode($response['message'] ?? substr($c->raw, 0, 200)) .
            (!empty($response['errors']) ? ' ' . json_encode($response['errors']) : ''));
    }
}

/** Unique suffix so the HTTP suite can be re-run against the same database. */
function run_id(): string
{
    static $id = null;
    return $id ??= strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}
