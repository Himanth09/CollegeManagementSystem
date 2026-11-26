<?php
declare(strict_types=1);

/** Runs one request through tests/fixtures/router_harness.php and returns [status, body]. */
function route(string $method, string $path, array $extra = []): array
{
    $input = json_encode(['method' => $method, 'path' => $path] + $extra);
    $cmd = [PHP_BINARY, __DIR__ . '/../fixtures/router_harness.php', $input];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        fail('could not start PHP child process');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $body = json_decode((string) $out, true);
    if (!is_array($body)) {
        fail("harness returned non-JSON output: $out $err");
    }
    return [(int) trim((string) $err), $body];
}

suite('Router', function () {
    $csrf = str_repeat('c', 64);

    it('matches static and numeric parameter routes', function () {
        [$s, $b] = route('GET', '/items');
        eq(200, $s);
        eq(['ok' => true, 'list' => true], $b);
        [$s, $b] = route('GET', '/items/42/');
        eq(200, $s);
        eq(42, $b['id'], 'trailing slash ignored, id passed as int');
        [, $b] = route('GET', 'students/7/fees/9');
        eq(['ok' => true, 'sid' => 7, 'fid' => 9], $b);
    });

    it('only matches digits for {id}', function () {
        eq(404, route('GET', '/items/abc')[0]);
        eq(404, route('GET', '/items/1 OR 1=1')[0]);
        eq(404, route('GET', '/items/-1')[0]);
    });

    it('returns 404 for unknown endpoints and 405 for wrong methods', function () {
        [$s, $b] = route('GET', '/nope');
        eq(404, $s);
        eq(false, $b['ok']);
        eq(405, route('POST', '/items/5')[0]);
    });

    it('requires a valid CSRF token on every POST', function () use ($csrf) {
        eq(419, route('POST', '/items')[0], 'no token');
        eq(419, route('POST', '/items', ['session' => ['csrf_token' => $csrf], 'server' => ['HTTP_X_CSRF_TOKEN' => 'wrong']])[0]);
        [$s, $b] = route('POST', '/items', ['session' => ['csrf_token' => $csrf], 'server' => ['HTTP_X_CSRF_TOKEN' => $csrf]]);
        eq(200, $s);
        eq(true, $b['created']);
    });

    it('sends ok:true when a handler returns without responding', function () {
        eq([200, ['ok' => true]], route('GET', '/silent'));
    });

    it('maps HttpExceptions to JSON errors', function () {
        eq(403, route('GET', '/forbidden')[0]);
        [$s, $b] = route('GET', '/invalid');
        eq(422, $s);
        eq(['name' => 'Name is required.'], $b['errors']);
    });

    it('hides internal error details unless debug is on', function () {
        [$s, $b] = route('GET', '/boom');
        eq(500, $s);
        eq('An unexpected error occurred.', $b['message']);
        eq('secret internal detail', route('GET', '/boom', ['debug' => true])[1]['message']);
    });

    it('translates database constraint errors into friendly messages', function () {
        [$s, $b] = route('GET', '/check');
        eq(422, $s);
        contains('business rule', $b['message']);
        [$s, $b] = route('GET', '/dup');
        eq(409, $s);
        contains('already exists', $b['message']);
        [$s, $b] = route('GET', '/range');
        eq(422, $s, 'out-of-range value');
        contains('larger than', $b['message']);
        [$s, $b] = route('GET', '/fk');
        eq(409, $s);
        contains('linked to other records', $b['message']);
    });
});
