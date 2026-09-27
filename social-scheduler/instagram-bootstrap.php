<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

function igmcp_config(): array
{
    return app_config();
}

function igmcp_e(string $value): string
{
    return e($value);
}

function igmcp_public_base_url(): string
{
    $base = rtrim((string)(app_config()['public_base_url'] ?? ''), '/');
    if (!preg_match('~^https://~i', $base)) {
        throw new RuntimeException('public_base_url must be a public HTTPS URL in config.php.');
    }
    return $base;
}

function igmcp_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

require_once __DIR__ . '/lib/Store.php';
require_once __DIR__ . '/lib/MediaStager.php';
require_once __DIR__ . '/lib/InstagramClient.php';
require_once __DIR__ . '/lib/McpServer.php';
