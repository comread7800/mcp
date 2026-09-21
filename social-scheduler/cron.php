<?php
declare(strict_types=1);

require __DIR__ . '/result-bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
@ignore_user_abort(true);
@set_time_limit(900);

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

    $mediaCleanup = ['deleted' => 0, 'kept' => 0, 'errors' => 0];
    try {
        $mediaCleanup = (new MediaStager())->cleanupExpired((int)$config['media_retention_seconds']);
        if ((int)($mediaCleanup['deleted'] ?? 0) > 0) {
            add_log(
                null,
                'MEDIA-CLEANUP',
                'sent',
                'Deleted ' . (int)$mediaCleanup['deleted'] . ' staged Instagram image(s) older than 24 hours.'
            );
        }
        if ((int)($mediaCleanup['errors'] ?? 0) > 0) {
            add_log(
                null,
                'MEDIA-CLEANUP',
                'failed',
                'Unable to delete ' . (int)$mediaCleanup['errors'] . ' expired staged image(s).'
            );
        }
    } catch (Throwable $cleanupError) {
        $mediaCleanup = ['deleted' => 0, 'kept' => 0, 'errors' => 1, 'error' => $cleanupError->getMessage()];
        add_log(null, 'MEDIA-CLEANUP', 'failed', 'Media cleanup error: ' . $cleanupError->getMessage());
    }

    $scheduleResults = process_due_schedules();

    $bridgeResult = ['enabled' => false, 'processed' => []];
    if (publisher_mode() === 'email_bridge') {
        try {
            $bridgeResult = (new ResultProcessor())->process((int)$config['result_max_messages_per_run']);
        } catch (Throwable $bridgeError) {
            add_log(null, 'RESULT-BRIDGE', 'failed', 'Result bridge fatal error: ' . $bridgeError->getMessage());
            $bridgeResult = [
                'enabled' => true,
                'processed' => [],
                'error' => $bridgeError->getMessage(),
            ];
        }
    }

    $bridgeFailed = isset($bridgeResult['error']);
    foreach ((array)($bridgeResult['processed'] ?? []) as $processed) {
        if (strtolower((string)($processed['status'] ?? '')) === 'failed') {
            $bridgeFailed = true;
            break;
        }
    }

    echo json_encode([
        'ok' => !$bridgeFailed,
        'checkedAt' => app_now()->format(DateTimeInterface::ATOM),
        'publisherMode' => publisher_mode(),
        'mediaCleanup' => $mediaCleanup,
        'scheduledJobs' => $scheduleResults,
        'resultBridge' => $bridgeResult,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
