<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function stopRequest(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

// The built-in PHP server defaults to local mode. Apache defaults to production.
// Never infer the environment from the browser's Host or forwarded headers.
$environment = getenv('DOCS_ENV') ?: (PHP_SAPI === 'cli-server' ? 'local' : 'production');
$isLoopback = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
$skipAuthentication = $environment === 'local' && $isLoopback;

if (!$skipAuthentication) {
    $authFile = getenv('DOCS_AUTH_FILE');
    $records = $authFile ? @file($authFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
    if ($records === false || $records === []) {
        stopRequest(503, 'Documentation authentication is not configured. See setup.md on the server.');
    }

    $username = $_SERVER['PHP_AUTH_USER'] ?? null;
    $password = $_SERVER['PHP_AUTH_PW'] ?? null;
    if ($username === null || $password === null) {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Basic\s+(.+)$/i', $authorization, $matches)) {
            $decoded = base64_decode($matches[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$username, $password] = explode(':', $decoded, 2);
            }
        }
    }

    $authenticated = false;
    foreach ($records as $record) {
        $parts = explode(':', $record, 2);
        if (count($parts) === 2 && $username !== null && $password !== null
            && hash_equals($parts[0], $username) && password_verify($password, $parts[1])) {
            $authenticated = true;
            break;
        }
    }
    if (!$authenticated) {
        header('WWW-Authenticate: Basic realm="Rose API Documentation", charset="UTF-8"');
        stopRequest(401, 'Authentication required.');
    }

}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    stopRequest(405, 'Method not allowed.');
}

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = PHP_SAPI === 'cli-server' ? '' : rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$relativePath = is_string($requestPath) ? substr($requestPath, strlen($base)) : '';
$files = [
    '/' => ['index.html', 'text/html; charset=utf-8'],
    '/index.html' => ['index.html', 'text/html; charset=utf-8'],
    '/openapi.json' => ['openapi.json', 'application/json; charset=utf-8'],
    '/itsupporthub.openapi.json' => ['itsupporthub.openapi.json', 'application/json; charset=utf-8'],
    '/esigned-sso-setup.html' => ['esigned-sso-setup.html', 'text/html; charset=utf-8'],
    '/esigned-sso-setup.md' => ['esigned-sso-setup.md', 'text/plain; charset=utf-8'],
];
if (!isset($files[$relativePath])) {
    stopRequest(404, 'Not found.');
}
[$filename, $contentType] = $files[$relativePath];
$contents = @file_get_contents(__DIR__ . '/' . $filename);
if ($contents === false) {
    stopRequest(500, 'Unable to read documentation.');
}
header('Content-Type: ' . $contentType);
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    echo $contents;
}
