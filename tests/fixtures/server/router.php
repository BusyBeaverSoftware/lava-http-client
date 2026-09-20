<?php

declare(strict_types=1);

/*
 * The fixture server behind lavaphp/http-client's live tests.
 *
 * One route per thing the client has to get right, so each behaviour is a
 * plain GET or POST against a real socket rather than a mock agreeing with the
 * code it is testing. `php -S` is single-process, so a slow route blocks the
 * next request — /slow sleeps one second for that reason, and the test that
 * uses it sets a timeout far below that.
 *
 * This file is run by the PHP development server, not by PHPUnit: it must not
 * use the pack's classes, and it must not assume the autoloader exists.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** @param array<string, mixed> $data */
$json = static function (int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR);
};

$send = static function (int $status, string $body, string $type = 'text/plain'): void {
    http_response_code($status);
    header('Content-Type: ' . $type);
    echo $body;
};

switch (true) {
    case $path === '/ok':
        $send(200, 'ok');
        return;

    case $path === '/json':
        $json(200, ['hello' => 'world', 'n' => 1]);
        return;

    case $path === '/scalar':
        $send(200, '"just a string"', 'application/json');
        return;

    case $path === '/not-json':
        $send(200, "<html><body>nope</body></html>", 'text/html');
        return;

    // Echoes back what actually arrived, which is the only way to prove from
    // outside that a header or a body was sent at all.
    case $path === '/echo':
        $json(200, [
            'method' => $method,
            'body' => (string) file_get_contents('php://input'),
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
        return;

    // A response that lies about its length. curl sees the connection close
    // before the body is complete and reports errno 18, which is a *transport*
    // failure — no response arrived — and therefore the one failure the pack
    // retries. This is how the retry rule is tested over a real socket instead
    // of against a fake: the counter below is the attempt count.
    case $path === '/drop':
        $id = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_GET['id'] ?? 'default'));
        $file = sys_get_temp_dir() . '/lava-http-fixture-drop-' . $id;
        $count = is_file($file) ? (int) file_get_contents($file) : 0;
        file_put_contents($file, (string) ($count + 1));

        header('Content-Length: 100');
        echo 'short';
        return;

    // A body of an exact size, for the response ceiling. `?bytes=` is capped
    // so a typo in a test cannot ask the fixture server for a gigabyte.
    case $path === '/big':
        $bytes = min(4_000_000, max(1, (int) ($_GET['bytes'] ?? 1024)));
        http_response_code(200);
        header('Content-Type: text/plain');
        header('Content-Length: ' . $bytes);
        echo str_repeat('a', $bytes);
        return;

    // The same body with no Content-Length, which is the case CURLOPT_MAXFILESIZE
    // cannot see: curl only knows how big it was when it is already too late.
    case $path === '/big-unknown':
        $bytes = min(4_000_000, max(1, (int) ($_GET['bytes'] ?? 1024)));
        http_response_code(200);
        header('Content-Type: text/plain');
        for ($sent = 0; $sent < $bytes; $sent += 8192) {
            echo str_repeat('a', (int) min(8192, $bytes - $sent));
            flush();
        }
        return;

    case $path === '/slow':
        usleep(1_000_000);
        $send(200, 'slow');
        return;

    case $path === '/redirect':
        http_response_code(302);
        header('Location: /ok');
        return;

    case preg_match('#^/status/(\d+)$#', $path, $matched) === 1:
        $send((int) $matched[1], 'status ' . $matched[1]);
        return;

    // No response on the FIRST attempt and a complete one on the second — which
    // is exactly the boundary the pack retries on, and the counter is per `id`
    // so two tests never share one. A counter file rather than memory because
    // the server is a separate process from the test.
    //
    // It truncates rather than erroring, and that is the fixture's whole point.
    // A route that recovered after a 500 would model a rule the pack
    // deliberately does not have: a status is a RESULT that comes back to the
    // caller, and the retry boundary is "no response arrived" — retrying a 5xx
    // without honouring `Retry-After` and without jitter is a request the
    // caller's rate limiter pays for twice. See HttpClient's docblock.
    case $path === '/flaky':
        $id = (string) preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_GET['id'] ?? 'default'));
        $file = sys_get_temp_dir() . '/lava-http-fixture-flaky-' . $id;
        $seen = is_file($file) ? (int) file_get_contents($file) : 0;
        file_put_contents($file, (string) ($seen + 1));

        if ($seen === 0) {
            // Promises 100 bytes and sends 5: curl sees the connection close
            // before the body is complete and reports errno 18.
            header('Content-Length: 100');
            echo 'short';
            return;
        }

        $send(200, 'ok after ' . ($seen + 1) . ' attempts');
        return;
}

$send(404, 'no such fixture path: ' . $path);
