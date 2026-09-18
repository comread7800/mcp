<?php
declare(strict_types=1);

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('config.php is missing. Copy config.example.php to config.php and fill in your settings.');
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('config.php must return an array.');
    }

    $config = $loaded;
    $config['app_name'] = (string)($config['app_name'] ?? 'Prompt Bridge');
    $config['timezone'] = (string)($config['timezone'] ?? 'Asia/Kolkata');
    $config['admin_password'] = (string)($config['admin_password'] ?? '');
    $config['slack_webhook_url'] = (string)($config['slack_webhook_url'] ?? '');
    $config['cron_secret'] = (string)($config['cron_secret'] ?? '');
    $config['message_tag'] = trim((string)($config['message_tag'] ?? 'SOCIAL_AUTOMATION')) ?: 'SOCIAL_AUTOMATION';
    $config['storage_path'] = (string)($config['storage_path'] ?? (__DIR__ . '/storage'));

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO SQLite is not enabled on this PHP server.');
    }

    try {
        new DateTimeZone($config['timezone']);
    } catch (Throwable) {
        throw new RuntimeException('Invalid timezone in config.php: ' . $config['timezone']);
    }

    return $config;
}

function app_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $storage = rtrim($config['storage_path'], DIRECTORY_SEPARATOR);
    if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
        throw new RuntimeException('Unable to create storage directory.');
    }

    $dbPath = $storage . DIRECTORY_SEPARATOR . 'prompt-bridge.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            prompt TEXT NOT NULL,
            trigger_time TEXT NOT NULL,
            publish_time TEXT NOT NULL,
            weekdays TEXT NOT NULL DEFAULT "1,2,3,4,5,6,7",
            enabled INTEGER NOT NULL DEFAULT 1,
            last_run_key TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS run_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schedule_id INTEGER NULL,
            run_key TEXT NULL,
            status TEXT NOT NULL,
            message TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    return $pdo;
}

function app_now(): DateTimeImmutable
{
    $config = app_config();
    return new DateTimeImmutable('now', new DateTimeZone($config['timezone']));
}

function app_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('prompt_bridge_session');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }
}

function app_is_logged_in(): bool
{
    app_start_session();
    return !empty($_SESSION['logged_in']);
}

function app_login(string $password): bool
{
    app_start_session();
    $expected = app_config()['admin_password'];
    if ($expected === '' || $expected === 'CHANGE_ME_NOW') {
        return false;
    }

    if (!hash_equals($expected, $password)) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    return true;
}

function app_logout(): void
{
    app_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function csrf_token(): string
{
    app_start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_verify(?string $token): void
{
    if (!$token || !hash_equals(csrf_token(), $token)) {
        throw new RuntimeException('Invalid form token. Refresh the page and try again.');
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function valid_hhmm(string $value): bool
{
    return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
}

function app_excerpt(string $text, int $max = 180): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $max, '…', 'UTF-8');
    }
    if (strlen($text) <= $max) {
        return $text;
    }
    return substr($text, 0, max(1, $max - 3)) . '...';
}

function normalize_weekdays(array $input): string
{
    $days = [];
    foreach ($input as $value) {
        $day = (int)$value;
        if ($day >= 1 && $day <= 7) {
            $days[$day] = $day;
        }
    }
    if (!$days) {
        throw new RuntimeException('Select at least one day.');
    }
    ksort($days);
    return implode(',', $days);
}

function schedule_days(string $value): array
{
    $out = [];
    foreach (explode(',', $value) as $part) {
        $day = (int)$part;
        if ($day >= 1 && $day <= 7) {
            $out[] = $day;
        }
    }
    return array_values(array_unique($out));
}

function all_schedules(): array
{
    return app_db()->query('SELECT * FROM schedules ORDER BY enabled DESC, trigger_time ASC, id DESC')->fetchAll();
}

function get_schedule(int $id): ?array
{
    $stmt = app_db()->prepare('SELECT * FROM schedules WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function save_schedule(array $data, ?int $id = null): int
{
    $name = trim((string)($data['name'] ?? ''));
    $prompt = trim((string)($data['prompt'] ?? ''));
    $trigger = trim((string)($data['trigger_time'] ?? ''));
    $publish = trim((string)($data['publish_time'] ?? ''));
    $weekdays = normalize_weekdays((array)($data['weekdays'] ?? []));

    if ($name === '') {
        throw new RuntimeException('Schedule name is required.');
    }
    if ($prompt === '') {
        throw new RuntimeException('Prompt is required.');
    }
    if (!valid_hhmm($trigger) || !valid_hhmm($publish)) {
        throw new RuntimeException('Trigger and publish times must use HH:MM.');
    }

    $now = app_now()->format(DateTimeInterface::ATOM);
    $db = app_db();

    if ($id !== null) {
        $stmt = $db->prepare('UPDATE schedules SET name=?, prompt=?, trigger_time=?, publish_time=?, weekdays=?, updated_at=? WHERE id=?');
        $stmt->execute([$name, $prompt, $trigger, $publish, $weekdays, $now, $id]);
        return $id;
    }

    $stmt = $db->prepare('INSERT INTO schedules(name,prompt,trigger_time,publish_time,weekdays,enabled,created_at,updated_at) VALUES(?,?,?,?,?,1,?,?)');
    $stmt->execute([$name, $prompt, $trigger, $publish, $weekdays, $now, $now]);
    return (int)$db->lastInsertId();
}

function toggle_schedule(int $id): void
{
    $stmt = app_db()->prepare('UPDATE schedules SET enabled = CASE enabled WHEN 1 THEN 0 ELSE 1 END, updated_at=? WHERE id=?');
    $stmt->execute([app_now()->format(DateTimeInterface::ATOM), $id]);
}

function delete_schedule(int $id): void
{
    $db = app_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('DELETE FROM run_logs WHERE schedule_id=?');
        $stmt->execute([$id]);
        $stmt = $db->prepare('DELETE FROM schedules WHERE id=?');
        $stmt->execute([$id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function recent_logs(int $limit = 40): array
{
    $limit = max(1, min(100, $limit));
    $sql = 'SELECT l.*, s.name AS schedule_name FROM run_logs l LEFT JOIN schedules s ON s.id=l.schedule_id ORDER BY l.id DESC LIMIT ' . $limit;
    return app_db()->query($sql)->fetchAll();
}

function add_log(?int $scheduleId, ?string $runKey, string $status, string $message): void
{
    $stmt = app_db()->prepare('INSERT INTO run_logs(schedule_id,run_key,status,message,created_at) VALUES(?,?,?,?,?)');
    $stmt->execute([$scheduleId, $runKey, $status, $message, app_now()->format(DateTimeInterface::ATOM)]);
}

function publish_at_for(array $schedule, DateTimeImmutable $triggeredAt): DateTimeImmutable
{
    [$hour, $minute] = array_map('intval', explode(':', $schedule['publish_time']));
    $target = $triggeredAt->setTime($hour, $minute, 0);
    if ($target <= $triggeredAt) {
        $target = $target->modify('+1 day');
    }
    return $target;
}

function build_slack_message(array $schedule, DateTimeImmutable $triggeredAt, string $source): string
{
    $config = app_config();
    $publishAt = publish_at_for($schedule, $triggeredAt);
    $tag = $config['message_tag'];

    return '[' . $tag . "]\n"
        . 'SOURCE: ' . strtoupper($source) . "\n"
        . 'SCHEDULE_ID: ' . $schedule['id'] . "\n"
        . 'SCHEDULE_NAME: ' . $schedule['name'] . "\n"
        . 'TRIGGERED_AT: ' . $triggeredAt->format('Y-m-d H:i:s T') . "\n"
        . 'PUBLISH_AT: ' . $publishAt->format('Y-m-d H:i:s T') . "\n\n"
        . "PROMPT:\n" . trim($schedule['prompt']) . "\n\n"
        . "EXECUTION RULES:\n"
        . "1. Execute the prompt fully; research current information when the prompt requires it.\n"
        . "2. Prepare the final Instagram caption and the required visual/media.\n"
        . "3. Use the connected Metricool plugin to schedule the finished Instagram post for exactly PUBLISH_AT.\n"
        . "4. Do not create or use a ChatGPT time-based schedule for this task; this website already handled the trigger time.\n"
        . "5. Mark AI-generated Instagram content correctly when the Metricool tool exposes that option.\n"
        . "6. If scheduling succeeds, return the Metricool planner link/status. If a required input is genuinely missing, report the exact blocker instead of inventing it.";
}

function slack_send(string $text): array
{
    $url = trim(app_config()['slack_webhook_url']);
    if ($url === '' || str_contains($url, 'REPLACE/ME')) {
        throw new RuntimeException('Slack webhook is not configured in config.php.');
    }
    if (!str_starts_with($url, 'https://hooks.slack.com/')) {
        throw new RuntimeException('Slack webhook URL must use https://hooks.slack.com/.');
    }

    $payload = json_encode(['text' => $text], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('Slack request failed: ' . $error);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json; charset=utf-8\r\n",
                'content' => $payload,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            }
        }
        if ($body === false) {
            throw new RuntimeException('Slack request failed. Enable PHP cURL for clearer network errors.');
        }
    }

    if ($status < 200 || $status >= 300 || trim((string)$body) !== 'ok') {
        throw new RuntimeException('Slack returned HTTP ' . $status . ': ' . trim((string)$body));
    }

    return ['ok' => true, 'status' => $status, 'body' => trim((string)$body)];
}

function run_schedule(array $schedule, DateTimeImmutable $triggeredAt, string $source, ?string $runKey = null): array
{
    $message = build_slack_message($schedule, $triggeredAt, $source);
    try {
        $result = slack_send($message);
        add_log((int)$schedule['id'], $runKey, 'sent', 'Prompt sent to Slack successfully.');
        return ['ok' => true, 'message' => $message, 'slack' => $result];
    } catch (Throwable $e) {
        add_log((int)$schedule['id'], $runKey, 'failed', $e->getMessage());
        throw $e;
    }
}

function run_schedule_now(int $id): array
{
    $schedule = get_schedule($id);
    if (!$schedule) {
        throw new RuntimeException('Schedule not found.');
    }
    return run_schedule($schedule, app_now(), 'manual');
}

function process_due_schedules(): array
{
    $now = app_now();
    $currentTime = $now->format('H:i');
    $weekday = (int)$now->format('N');
    $runKey = $now->format('Y-m-d H:i');
    $results = [];

    $stmt = app_db()->query('SELECT * FROM schedules WHERE enabled=1 ORDER BY id ASC');
    foreach ($stmt->fetchAll() as $schedule) {
        if ($schedule['trigger_time'] !== $currentTime) {
            continue;
        }
        if (!in_array($weekday, schedule_days($schedule['weekdays']), true)) {
            continue;
        }
        if (($schedule['last_run_key'] ?? null) === $runKey) {
            continue;
        }

        $claim = app_db()->prepare('UPDATE schedules SET last_run_key=?, updated_at=? WHERE id=? AND (last_run_key IS NULL OR last_run_key<>?)');
        $claim->execute([$runKey, $now->format(DateTimeInterface::ATOM), $schedule['id'], $runKey]);
        if ($claim->rowCount() !== 1) {
            continue;
        }

        try {
            run_schedule($schedule, $now, 'scheduled', $runKey);
            $results[] = ['id' => (int)$schedule['id'], 'status' => 'sent'];
        } catch (Throwable $e) {
            $results[] = ['id' => (int)$schedule['id'], 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    return $results;
}
