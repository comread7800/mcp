<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $config = app_config();
    app_db();
    $lastSchedulerCheck = with_state(fn(array $state) => $state['meta']['last_scheduler_check_at'] ?? null);
    echo json_encode([
        'ok' => true,
        'service' => 'prompt-bridge',
        'timezone' => 'Asia/Kolkata',
        'timezoneLabel' => 'Mumbai / IST',
        'lastSchedulerCheckAt' => $lastSchedulerCheck,
        'emailConfigured' => filter_var($config['mail_to'], FILTER_VALIDATE_EMAIL) !== false
            && filter_var($config['smtp_username'], FILTER_VALIDATE_EMAIL) !== false
            && $config['smtp_app_password'] !== '',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
