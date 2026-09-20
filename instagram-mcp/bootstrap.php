<?php
declare(strict_types=1);

const IG_MCP_ROOT = __DIR__;

function igmcp_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $path = IG_MCP_ROOT . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Missing config.php. Copy config.example.php to config.php and fill it in.');
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('config.php must return an array.');
    }

    $defaults = [
        'app_name' => 'Instagram Publisher MCP',
        'timezone' => 'Asia/Kolkata',
        'allowed_origins' => [],
        'storage_path' => IG_MCP_ROOT . '/storage',
        'media_path' => IG_MCP_ROOT . '/media',
        'media_max_bytes' => 12 * 1024 * 1024,
        'graph_api_version' => 'v26.0',
        'instagram_scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
    ];

    $config = array_replace($defaults, $loaded);
    date_default_timezone_set((string)$config['timezone']);

    foreach (['storage_path', 'media_path'] as $key) {
        $dir = (string)$config[$key];
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create directory: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Directory is not writable: ' . $dir);
        }
    }

    return $config;
}

function igmcp_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function igmcp_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('igmcp_admin');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'use_strict_mode' => true,
        ]);
    }
}

function igmcp_admin_logged_in(): bool
{
    igmcp_session_start();
    return !empty($_SESSION['igmcp_admin_ok']);
}

function igmcp_admin_login(string $password): bool
{
    $config = igmcp_config();
    $expected = (string)($config['admin_password'] ?? '');
    if ($expected === '' || $expected === 'CHANGE_ME_NOW') {
        return false;
    }
    if (!hash_equals($expected, $password)) {
        return false;
    }
    igmcp_session_start();
    session_regenerate_id(true);
    $_SESSION['igmcp_admin_ok'] = true;
    return true;
}

function igmcp_admin_logout(): void
{
    igmcp_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function igmcp_csrf_token(): string
{
    igmcp_session_start();
    if (empty($_SESSION['igmcp_csrf'])) {
        $_SESSION['igmcp_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['igmcp_csrf'];
}

function igmcp_verify_csrf(?string $token): void
{
    if (!$token || !hash_equals(igmcp_csrf_token(), $token)) {
        throw new RuntimeException('Invalid CSRF token.');
    }
}

function igmcp_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function igmcp_public_base_url(): string
{
    $base = rtrim((string)(igmcp_config()['public_base_url'] ?? ''), '/');
    if (!preg_match('~^https://~i', $base)) {
        throw new RuntimeException('public_base_url must be a public HTTPS URL.');
    }
    return $base;
}

require_once IG_MCP_ROOT . '/lib/Store.php';
require_once IG_MCP_ROOT . '/lib/MediaStager.php';
require_once IG_MCP_ROOT . '/lib/InstagramClient.php';
require_once IG_MCP_ROOT . '/lib/McpServer.php';
