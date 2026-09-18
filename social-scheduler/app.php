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
    $config['mail_to'] = trim((string)($config['mail_to'] ?? ''));
    $config['mail_from'] = trim((string)($config['mail_from'] ?? ''));
    $config['mail_from_name'] = trim((string)($config['mail_from_name'] ?? 'Prompt Bridge')) ?: 'Prompt Bridge';
    $config['smtp_host'] = trim((string)($config['smtp_host'] ?? 'smtp.gmail.com')) ?: 'smtp.gmail.com';
    $config['smtp_port'] = (int)($config['smtp_port'] ?? 587);
    $config['smtp_username'] = trim((string)($config['smtp_username'] ?? ''));
    $config['smtp_app_password'] = preg_replace('/\s+/', '', (string)($config['smtp_app_password'] ?? '')) ?? '';
    $config['cron_secret'] = trim((string)($config['cron_secret'] ?? ''));
    $config['message_tag'] = trim((string)($config['message_tag'] ?? 'SOCIAL_AUTOMATION')) ?: 'SOCIAL_AUTOMATION';
    $config['storage_path'] = (string)($config['storage_path'] ?? (__DIR__ . '/storage'));

    try {
        new DateTimeZone($config['timezone']);
    } catch (Throwable) {
        throw new RuntimeException('Invalid timezone in config.php: ' . $config['timezone']);
    }

    return $config;
}

function storage_file(): string
{
    $storage = rtrim(app_config()['storage_path'], DIRECTORY_SEPARATOR);
    if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
        throw new RuntimeException('Unable to create storage directory.');
    }
    return $storage . DIRECTORY_SEPARATOR . 'prompt-bridge.json';
}

function empty_state(): array
{
    return [
        'nextScheduleId' => 1,
        'nextLogId' => 1,
        'schedules' => [],
        'logs' => [],
    ];
}

function with_state(callable $callback, bool $write = false): mixed
{
    $path = storage_file();
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open storage file.');
    }

    $lock = $write ? LOCK_EX : LOCK_SH;
    if (!flock($handle, $lock)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock storage file.');
    }

    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : empty_state();
        if (!is_array($state)) {
            throw new RuntimeException('Storage file is invalid JSON.');
        }
        $state = array_merge(empty_state(), $state);
        $state['schedules'] = is_array($state['schedules']) ? $state['schedules'] : [];
        $state['logs'] = is_array($state['logs']) ? $state['logs'] : [];

        $result = $callback($state);

        if ($write) {
            $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            rewind($handle);
            ftruncate($handle, 0);
            if (fwrite($handle, $encoded) === false) {
                throw new RuntimeException('Unable to write storage file.');
            }
            fflush($handle);
            @chmod($path, 0600);
        }

        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function app_db(): bool
{
    storage_file();
    return true;
}

function app_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(app_config()['timezone']));
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
    $schedules = with_state(fn(array $state) => $state['schedules']);
    usort($schedules, static function (array $a, array $b): int {
        $enabled = ((int)$b['enabled']) <=> ((int)$a['enabled']);
        if ($enabled !== 0) return $enabled;
        $time = strcmp((string)$a['trigger_time'], (string)$b['trigger_time']);
        if ($time !== 0) return $time;
        return ((int)$b['id']) <=> ((int)$a['id']);
    });
    return $schedules;
}

function get_schedule(int $id): ?array
{
    return with_state(static function (array $state) use ($id): ?array {
        foreach ($state['schedules'] as $schedule) {
            if ((int)$schedule['id'] === $id) return $schedule;
        }
        return null;
    });
}

function save_schedule(array $data, ?int $id = null): int
{
    $name = trim((string)($data['name'] ?? ''));
    $prompt = trim((string)($data['prompt'] ?? ''));
    $trigger = trim((string)($data['trigger_time'] ?? ''));
    $weekdays = normalize_weekdays((array)($data['weekdays'] ?? []));

    if ($name === '') throw new RuntimeException('Schedule name is required.');
    if ($prompt === '') throw new RuntimeException('Prompt is required.');
    if (!valid_hhmm($trigger)) throw new RuntimeException('Trigger time must use HH:MM.');

    $now = app_now()->format(DateTimeInterface::ATOM);

    return with_state(static function (array &$state) use ($id, $name, $prompt, $trigger, $weekdays, $now): int {
        if ($id !== null) {
            foreach ($state['schedules'] as &$schedule) {
                if ((int)$schedule['id'] === $id) {
                    $schedule['name'] = $name;
                    $schedule['prompt'] = $prompt;
                    $schedule['trigger_time'] = $trigger;
                    unset($schedule['publish_time']);
                    $schedule['weekdays'] = $weekdays;
                    $schedule['updated_at'] = $now;
                    return $id;
                }
            }
            throw new RuntimeException('Schedule not found.');
        }

        $newId = max(1, (int)$state['nextScheduleId']);
        $state['nextScheduleId'] = $newId + 1;
        $state['schedules'][] = [
            'id' => $newId,
            'name' => $name,
            'prompt' => $prompt,
            'trigger_time' => $trigger,
            'weekdays' => $weekdays,
            'enabled' => 1,
            'last_run_key' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        return $newId;
    }, true);
}

function toggle_schedule(int $id): void
{
    $now = app_now()->format(DateTimeInterface::ATOM);
    with_state(static function (array &$state) use ($id, $now): void {
        foreach ($state['schedules'] as &$schedule) {
            if ((int)$schedule['id'] === $id) {
                $schedule['enabled'] = (int)$schedule['enabled'] === 1 ? 0 : 1;
                $schedule['updated_at'] = $now;
                return;
            }
        }
        throw new RuntimeException('Schedule not found.');
    }, true);
}

function delete_schedule(int $id): void
{
    with_state(static function (array &$state) use ($id): void {
        $before = count($state['schedules']);
        $state['schedules'] = array_values(array_filter($state['schedules'], fn(array $s) => (int)$s['id'] !== $id));
        if (count($state['schedules']) === $before) throw new RuntimeException('Schedule not found.');
        $state['logs'] = array_values(array_filter($state['logs'], fn(array $l) => (int)($l['schedule_id'] ?? 0) !== $id));
    }, true);
}

function recent_logs(int $limit = 40): array
{
    $limit = max(1, min(100, $limit));
    return with_state(static function (array $state) use ($limit): array {
        $names = [];
        foreach ($state['schedules'] as $schedule) $names[(int)$schedule['id']] = $schedule['name'];
        $logs = array_reverse($state['logs']);
        $logs = array_slice($logs, 0, $limit);
        foreach ($logs as &$log) {
            $sid = (int)($log['schedule_id'] ?? 0);
            $log['schedule_name'] = $sid && isset($names[$sid]) ? $names[$sid] : 'System';
        }
        return $logs;
    });
}

function add_log(?int $scheduleId, ?string $runKey, string $status, string $message): void
{
    $createdAt = app_now()->format(DateTimeInterface::ATOM);
    with_state(static function (array &$state) use ($scheduleId, $runKey, $status, $message, $createdAt): void {
        $id = max(1, (int)$state['nextLogId']);
        $state['nextLogId'] = $id + 1;
        $state['logs'][] = [
            'id' => $id,
            'schedule_id' => $scheduleId,
            'run_key' => $runKey,
            'status' => $status,
            'message' => $message,
            'created_at' => $createdAt,
        ];
        if (count($state['logs']) > 250) {
            $state['logs'] = array_slice($state['logs'], -250);
        }
    }, true);
}

function build_email_job(array $schedule, DateTimeImmutable $triggeredAt, string $source): array
{
    $config = app_config();
    $tag = $config['message_tag'];

    $subject = '[' . $tag . '] ' . $schedule['name'];
    $body = '[' . $tag . "]\n"
        . 'SOURCE: ' . strtoupper($source) . "\n"
        . 'SCHEDULE_ID: ' . $schedule['id'] . "\n"
        . 'SCHEDULE_NAME: ' . $schedule['name'] . "\n"
        . 'TRIGGERED_AT: ' . $triggeredAt->format('Y-m-d H:i:s T') . "\n\n"
        . "PROMPT:\n" . trim($schedule['prompt']) . "\n\n"
        . "EXECUTION RULES:\n"
        . "1. Execute the prompt fully; research current information when the prompt requires it.\n"
        . "2. Prepare the final Instagram caption and the required visual/media.\n"
        . "3. Publish the finished Instagram post as soon as the content is ready using the connected Metricool plugin.\n"
        . "4. Do not create a ChatGPT time-based schedule and do not wait for a separate publish time; this website already decided when the job should start.\n"
        . "5. If Metricool only exposes scheduled publishing, use the earliest valid publication time available (immediate/next minute) with autoPublish enabled.\n"
        . "6. Mark AI-generated Instagram content correctly when the Metricool tool exposes that option.\n"
        . "7. If publication fails, report the exact Metricool/Instagram error instead of claiming it published.";

    return ['subject' => $subject, 'body' => $body];
}

function clean_mail_header(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

function smtp_subject(string $subject): string
{
    $clean = clean_mail_header($subject);
    return '=?UTF-8?B?' . base64_encode($clean) . '?=';
}

function email_send(string $subject, string $body): array
{
    $config = app_config();
    $to = clean_mail_header($config['mail_to']);
    $username = clean_mail_header($config['smtp_username']);
    $password = (string)$config['smtp_app_password'];
    $from = clean_mail_header($config['mail_from'] !== '' ? $config['mail_from'] : $username);
    $fromName = clean_mail_header($config['mail_from_name']);
    $host = clean_mail_header($config['smtp_host']);
    $port = (int)$config['smtp_port'];

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('mail_to is not a valid email address in config.php.');
    }
    if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('smtp_username must be your Gmail address in config.php.');
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('mail_from is not a valid email address in config.php.');
    }
    if ($password === '' || str_contains($password, 'CHANGE')) {
        throw new RuntimeException('smtp_app_password is missing. Use a Google App Password, not your normal Gmail password.');
    }
    if ($host === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('SMTP host/port are invalid in config.php.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for authenticated SMTP delivery.');
    }

    $domain = substr(strrchr($username, '@') ?: '@localhost', 1) ?: 'localhost';
    $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    $lines = [
        'Date: ' . date(DATE_RFC2822),
        'To: <' . $to . '>',
        'From: ' . $fromName . ' <' . $from . '>',
        'Reply-To: <' . $from . '>',
        'Subject: ' . smtp_subject($subject),
        'Message-ID: ' . $messageId,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Prompt-Bridge: 1',
        '',
        str_replace(["\r\n", "\r"], "\n", $body),
    ];
    $payload = str_replace("\n", "\r\n", implode("\n", $lines)) . "\r\n";
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Unable to create SMTP message stream.');
    }
    fwrite($stream, $payload);
    rewind($stream);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'smtp://' . $host . ':' . $port,
        CURLOPT_USERNAME => $username,
        CURLOPT_PASSWORD => $password,
        CURLOPT_USE_SSL => CURLUSESSL_ALL,
        CURLOPT_MAIL_FROM => '<' . $username . '>',
        CURLOPT_MAIL_RCPT => ['<' . $to . '>'],
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $stream,
        CURLOPT_INFILESIZE => strlen($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $result = curl_exec($ch);
    $error = curl_error($ch);
    $errorNo = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($stream);

    if ($result === false || $errorNo !== 0) {
        throw new RuntimeException('SMTP delivery failed: ' . ($error !== '' ? $error : 'unknown cURL error'));
    }
    if ($status >= 400) {
        throw new RuntimeException('SMTP server returned status ' . $status . '. Check Gmail address and App Password.');
    }

    return ['ok' => true, 'to' => $to, 'via' => $host . ':' . $port];
}

function run_schedule(array $schedule, DateTimeImmutable $triggeredAt, string $source, ?string $runKey = null): array
{
    $job = build_email_job($schedule, $triggeredAt, $source);
    try {
        $result = email_send($job['subject'], $job['body']);
        add_log((int)$schedule['id'], $runKey, 'sent', 'Prompt email sent through authenticated SMTP successfully.');
        return ['ok' => true, 'email' => $job, 'delivery' => $result];
    } catch (Throwable $e) {
        add_log((int)$schedule['id'], $runKey, 'failed', $e->getMessage());
        throw $e;
    }
}

function run_schedule_now(int $id): array
{
    $schedule = get_schedule($id);
    if (!$schedule) throw new RuntimeException('Schedule not found.');
    return run_schedule($schedule, app_now(), 'manual');
}

function process_due_schedules(): array
{
    $now = app_now();
    $weekday = (int)$now->format('N');
    $updatedAt = $now->format(DateTimeInterface::ATOM);
    $graceSeconds = 10 * 60;

    $due = with_state(static function (array &$state) use ($now, $weekday, $updatedAt, $graceSeconds): array {
        $claimed = [];
        foreach ($state['schedules'] as &$schedule) {
            if ((int)$schedule['enabled'] !== 1) continue;
            if (!in_array($weekday, schedule_days($schedule['weekdays']), true)) continue;

            [$hour, $minute] = array_map('intval', explode(':', (string)$schedule['trigger_time']));
            $scheduledAt = $now->setTime($hour, $minute, 0);
            $age = $now->getTimestamp() - $scheduledAt->getTimestamp();

            // Allow a short catch-up window so a cron that runs a little late does not miss the job.
            if ($age < 0 || $age > $graceSeconds) continue;

            $runKey = $scheduledAt->format('Y-m-d H:i');
            if (($schedule['last_run_key'] ?? null) === $runKey) continue;

            // Claim before delivery to prevent duplicate sends from overlapping cron invocations.
            $schedule['last_run_key'] = $runKey;
            $schedule['updated_at'] = $updatedAt;
            $claimed[] = [
                'schedule' => $schedule,
                'run_key' => $runKey,
                'scheduled_at' => $scheduledAt->format(DateTimeInterface::ATOM),
            ];
        }
        return $claimed;
    }, true);

    $results = [];
    foreach ($due as $item) {
        $schedule = $item['schedule'];
        $runKey = $item['run_key'];
        $scheduledAt = new DateTimeImmutable($item['scheduled_at']);

        try {
            run_schedule($schedule, $scheduledAt, 'scheduled', $runKey);
            $results[] = ['id' => (int)$schedule['id'], 'status' => 'sent'];
        } catch (Throwable $e) {
            // Release the claim so the next cron run inside the grace window can retry.
            with_state(static function (array &$state) use ($schedule, $runKey, $updatedAt): void {
                foreach ($state['schedules'] as &$stored) {
                    if ((int)$stored['id'] !== (int)$schedule['id']) continue;
                    if (($stored['last_run_key'] ?? null) === $runKey) {
                        $stored['last_run_key'] = null;
                        $stored['updated_at'] = $updatedAt;
                    }
                    break;
                }
            }, true);

            $results[] = ['id' => (int)$schedule['id'], 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }
    return $results;
}
