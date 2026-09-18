<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $config = app_config();
    app_db();
    echo json_encode([
        'ok' => true,
        'service' => 'prompt-bridge',
        'timezone' => $config['timezone'],
        'slackConfigured' => $config['slack_webhook_url'] !== '' && !str_contains($config['slack_webhook_url'], 'REPLACE/ME'),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
