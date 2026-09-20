<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$config = igmcp_config();

function mcp_error(mixed $id, int $code, string $message, int $httpStatus = 200, array $data = []): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => ['code' => $code, 'message' => $message] + ($data ? ['data' => $data] : []),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function mcp_server_meta(): array
{
    return [
        'io.modelcontextprotocol/serverInfo' => [
            'name' => InstagramMcpServer::SERVER_NAME,
            'version' => InstagramMcpServer::SERVER_VERSION,
        ],
    ];
}

function mcp_authorized(array $config): bool
{
    $expected = (string)($config['mcp_api_key'] ?? '');
    if ($expected === '' || str_starts_with($expected, 'CHANGE_')) {
        return false;
    }

    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m) && hash_equals($expected, trim($m[1]))) {
        return true;
    }

    $fallback = (string)($_GET['key'] ?? '');
    return $fallback !== '' && hash_equals($expected, $fallback);
}

function mcp_validate_origin(array $config): void
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return;
    }
    $allowed = array_map('strval', (array)($config['allowed_origins'] ?? []));
    if (!in_array($origin, $allowed, true)) {
        mcp_error(null, -32000, 'Forbidden Origin', 403);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['health'])) {
        if (!mcp_authorized($config)) {
            igmcp_json_response(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        igmcp_json_response([
            'ok' => true,
            'server' => InstagramMcpServer::SERVER_NAME,
            'version' => InstagramMcpServer::SERVER_VERSION,
        ]);
    }

    header('Allow: POST');
    igmcp_json_response(['ok' => false, 'error' => 'MCP endpoint accepts POST requests.'], 405);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    igmcp_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

mcp_validate_origin($config);
if (!mcp_authorized($config)) {
    header('WWW-Authenticate: Bearer realm="instagram-mcp"');
    mcp_error(null, -32001, 'Unauthorized', 401);
}

$maxBody = 26 * 1024 * 1024;
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxBody) {
    mcp_error(null, -32600, 'Request body too large.', 413);
}

$raw = file_get_contents('php://input', false, null, 0, $maxBody + 1);
if ($raw === false || strlen($raw) > $maxBody) {
    mcp_error(null, -32600, 'Unable to read request body.', 400);
}

$request = json_decode($raw, true);
if (!is_array($request) || ($request['jsonrpc'] ?? null) !== '2.0' || empty($request['method'])) {
    mcp_error($request['id'] ?? null, -32600, 'Invalid JSON-RPC request.', 400);
}

$id = $request['id'] ?? null;
$method = (string)$request['method'];
$params = is_array($request['params'] ?? null) ? $request['params'] : [];

$supported = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];
$headerVersion = trim((string)($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''));
$metaVersion = (string)($params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? '');

if ($headerVersion !== '' && !in_array($headerVersion, $supported, true)) {
    mcp_error($id, -32002, 'UnsupportedProtocolVersionError', 400, ['supported' => $supported]);
}
if ($headerVersion !== '' && $metaVersion !== '' && $headerVersion !== $metaVersion) {
    mcp_error($id, -32003, 'HeaderMismatch', 400);
}

if ($id === null && str_starts_with($method, 'notifications/')) {
    http_response_code(202);
    exit;
}

$result = null;
try {
    switch ($method) {
        case 'initialize':
            $requested = (string)($params['protocolVersion'] ?? '2025-03-26');
            $negotiated = in_array($requested, $supported, true) ? $requested : '2025-03-26';
            $result = [
                'protocolVersion' => $negotiated,
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => [
                    'name' => InstagramMcpServer::SERVER_NAME,
                    'version' => InstagramMcpServer::SERVER_VERSION,
                ],
                '_meta' => mcp_server_meta(),
            ];
            break;

        case 'ping':
            $result = ['_meta' => mcp_server_meta()];
            break;

        case 'tools/list':
            $result = ['tools' => InstagramMcpServer::tools(), '_meta' => mcp_server_meta()];
            break;

        case 'tools/call':
            $toolName = (string)($params['name'] ?? '');
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $data = InstagramMcpServer::call($toolName, $arguments);
            $result = [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode(
                        $data,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    ),
                ]],
                'structuredContent' => $data,
                'isError' => false,
                '_meta' => mcp_server_meta(),
            ];
            break;

        default:
            mcp_error($id, -32601, 'Method not found: ' . $method, $headerVersion === '2026-07-28' ? 404 : 200);
    }
} catch (Throwable $e) {
    $result = [
        'content' => [[
            'type' => 'text',
            'text' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]],
        'structuredContent' => ['error' => $e->getMessage()],
        'isError' => true,
        '_meta' => mcp_server_meta(),
    ];
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(
    ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
