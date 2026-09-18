<?php
declare(strict_types=1);
require __DIR__ . '/app.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $config = app_config();
    app_db();

    if (PHP_SAPI !== 'cli') {
        $provided = trim((string)($_GET['key'] ?? ''));
        if ($config['cron_secret'] === '' || $config['cron_secret'] === 'CHANGE_TO_A_LONG_RANDOM_SECRET') {
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => 'cron_secret is not configured']);
            exit;
        }
        if (!hash_equals($config['cron_secret'], $provided)) {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'error' => 'unauthorized',
                'hint' => 'Use the exact cron_secret from config.php. For URL cron, use only URL-safe characters A-Z a-z 0-9 _ - in the secret, with no spaces.'
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $results = process_due_schedules();
    echo json_encode([
        'ok' => true,
        'checkedAt' => app_now()->format(DateTimeInterface::ATOM),
        'processed' => $results,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
