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
    // Force all scheduler calculations to India Standard Time (Mumbai/Delhi/Kolkata).
    // IANA's canonical timezone identifier for India is Asia/Kolkata.
    $config['timezone'] = 'Asia/Kolkata';
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
    $config['publisher_mode'] = strtolower(trim((string)($config['publisher_mode'] ?? 'metricool')));
    if (!in_array($config['publisher_mode'], ['metricool', 'instagram_mcp', 'email_bridge'], true)) {
        $config['publisher_mode'] = 'metricool';
    }
    $config['public_base_url'] = rtrim((string)($config['public_base_url'] ?? ''), '/');
    $config['instagram_app_id'] = trim((string)($config['instagram_app_id'] ?? ''));
    $config['instagram_app_secret'] = (string)($config['instagram_app_secret'] ?? '');
    $config['instagram_redirect_uri'] = trim((string)($config['instagram_redirect_uri'] ?? ''));
    $config['instagram_scopes'] = is_array($config['instagram_scopes'] ?? null) ? $config['instagram_scopes'] : ['instagram_business_basic', 'instagram_business_content_publish'];
    $config['graph_api_version'] = trim((string)($config['graph_api_version'] ?? 'v26.0')) ?: 'v26.0';
    $config['mcp_api_key'] = trim((string)($config['mcp_api_key'] ?? ''));
    $config['allowed_origins'] = is_array($config['allowed_origins'] ?? null) ? $config['allowed_origins'] : [];
    $config['media_path'] = (string)($config['media_path'] ?? (__DIR__ . '/media'));
    $config['media_max_bytes'] = (int)($config['media_max_bytes'] ?? (12 * 1024 * 1024));
    $config['result_subject_tag'] = trim((string)($config['result_subject_tag'] ?? 'SOCIAL_READY')) ?: 'SOCIAL_READY';
    $config['result_email_to'] = trim((string)($config['result_email_to'] ?? '')) ?: $config['smtp_username'];
    $config['result_email_from'] = trim((string)($config['result_email_from'] ?? '')) ?: $config['mail_to'];
    $config['imap_host'] = trim((string)($config['imap_host'] ?? 'imap.gmail.com')) ?: 'imap.gmail.com';
    $config['imap_port'] = (int)($config['imap_port'] ?? 993);
    $config['imap_username'] = trim((string)($config['imap_username'] ?? $config['smtp_username']));
    $config['imap_app_password'] = preg_replace('/\s+/', '', (string)($config['imap_app_password'] ?? $config['smtp_app_password'])) ?? '';
    $config['imap_mailbox'] = trim((string)($config['imap_mailbox'] ?? 'INBOX')) ?: 'INBOX';
    $config['result_email_max_bytes'] = (int)($config['result_email_max_bytes'] ?? (40 * 1024 * 1024));
    $config['result_max_messages_per_run'] = max(1, min(5, (int)($config['result_max_messages_per_run'] ?? 2)));

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
        'meta' => [
            'last_scheduler_check_at' => null,
        ],
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
        $state['meta'] = is_array($state['meta'] ?? null) ? $state['meta'] : ['last_scheduler_check_at' => null];

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

function publisher_mode(): string
{
    $configured = (string)app_config()['publisher_mode'];
    try {
        $override = with_state(static fn(array $state): ?string => isset($state['meta']['publisher_mode_override'])
            ? (string)$state['meta']['publisher_mode_override'] : null);
        if (in_array($override, ['metricool', 'instagram_mcp', 'email_bridge'], true)) {
            return $override;
        }
    } catch (Throwable) {
        // Fall back to config.php during first-run/storage errors.
    }
    return $configured;
}

function set_publisher_mode(string $mode): void
{
    if (!in_array($mode, ['metricool', 'instagram_mcp', 'email_bridge'], true)) {
        throw new InvalidArgumentException('Invalid publisher mode.');
    }
    with_state(static function(array &$state) use ($mode): void {
        $state['meta']['publisher_mode_override'] = $mode;
        $state['meta']['publisher_mode_changed_at'] = app_now()->format(DateTimeInterface::ATOM);
    }, true);
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
    $mode = publisher_mode();

    $subject = '[' . $tag . '] '
        . $schedule['name']
        . ' | '
        . $triggeredAt->format('Y-m-d H:i:s')
        . ' | JOB-' . $schedule['id'];

    if ($mode === 'email_bridge') {
        $publisherRules = "3. DO NOT publish through Metricool, MCP, Composio, Instagram directly, or any other publisher.\n"
            . "4. When every final slide and the final caption are complete, send exactly ONE Gmail result email to: " . $config['result_email_to'] . "\n"
            . "5. Result email subject MUST be: [" . $config['result_subject_tag'] . "] " . $subject . "\n"
            . "6. Result email body MUST use this exact plain-text envelope:\n"
            . "[" . $config['result_subject_tag'] . "]\n"
            . "JOB_KEY_BEGIN\n" . $subject . "\nJOB_KEY_END\n"
            . "SCHEDULE_ID: " . $schedule['id'] . "\n"
            . "TRIGGERED_AT: " . $triggeredAt->format('Y-m-d H:i:s T') . "\n"
            . "SLIDE_COUNT: <number of final attached slides>\n"
            . "CAPTION_BEGIN\n<the complete final Instagram caption>\nCAPTION_END\n"
            . "7. Attach ONLY the final carousel images to that Gmail result email. Name them slide-01.jpg, slide-02.jpg, and so on in exact Instagram order. Use 1080x1350 (4:5) whenever possible. Do not attach drafts, references, source screenshots, ZIPs, PDFs, or duplicate versions.\n"
            . "8. Gmail delivery of the [" . $config['result_subject_tag'] . "] result email is the required completion step. If sending the result email fails, report the exact Gmail error and do not claim completion.\n"
            . "9. After the result email is sent successfully, stop. The website cron will read that mailbox and publish the attachments directly through Meta Instagram API.\n";
    } elseif ($mode === 'instagram_mcp') {
        $publisherRules = "3. Publish ONLY through the connected direct Instagram MCP for this site. Do not use Metricool or Composio.\n"
          . "4. Use the full email subject plus TRIGGERED_AT as the unique job_id. Check instagram_publication_status before retrying.\n"
          . "5. Check instagram_connection_status and instagram_recent_posts before publishing.\n"
          . "6. Stage media with instagram_stage_media when a durable public HTTPS JPEG URL is not already available.\n"
          . "7. For a carousel, call instagram_publish_carousel exactly once with slides in the correct order.\n"
          . "8. After publishing, verify the returned status/media_id/permalink. If the MCP returns an error, report the exact error and stop.\n"
          . "9. Never claim success unless the direct Instagram MCP confirms publication.\n";
    } else {
        $publisherRules = "3. Publish ONLY through the connected Metricool plugin. Do not use Composio or another fallback publisher.\n"
          . "4. Publish as soon as the content is ready. If Metricool requires a future timestamp, use the earliest valid future time with autoPublish enabled.\n"
          . "5. Mark AI-generated Instagram content correctly when the tool exposes that option.\n"
          . "6. Every valid non-test job must end with exactly one Metricool post attempt and a verified Metricool status; never stop silently after research or media creation.\n"
          . "7. If Metricool reports PENDING/PUBLISHING, do not submit another copy. If it reports ERROR/FAILED, report the exact error and stop.\n"
          . "8. Never claim success unless Metricool confirms publication.\n";
    }

    $body = '[' . $tag . "]\n"
        . 'SOURCE: ' . strtoupper($source) . "\n"
        . 'SCHEDULE_ID: ' . $schedule['id'] . "\n"
        . 'SCHEDULE_NAME: ' . $schedule['name'] . "\n"
        . 'TRIGGERED_AT: ' . $triggeredAt->format('Y-m-d H:i:s T') . "\n"
        . 'PUBLISHER_MODE: ' . strtoupper($mode) . "\n"
        . 'RESULT_EMAIL_TO: ' . $config['result_email_to'] . "\n"
        . 'RESULT_SUBJECT_TAG: [' . $config['result_subject_tag'] . "]\n\n"
        . "PROMPT:\n" . trim($schedule['prompt']) . "\n\n"
        . "EXECUTION RULES:\n"
        . "1. Execute the prompt fully; research current information when the prompt requires it.\n"
        . "2. Prepare the final Instagram caption and every required visual/media asset.\n"
        . $publisherRules
        . "10. Do not create a ChatGPT time-based schedule; this website already decided when the job starts.\n"
        . "11. Do not skip the whole job because similar topics were recently used; choose different fresh stories and expand research up to 48 hours if needed.\n"
        . "12. If one media-generation or upload step fails, retry that failed step once. Unsupported carousel music/audio must never block the job.";

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

    $due = with_state(static function (array &$state) use ($now, $weekday, $updatedAt): array {
        $state['meta']['last_scheduler_check_at'] = $updatedAt;
        $claimed = [];

        foreach ($state['schedules'] as &$schedule) {
            if ((int)$schedule['enabled'] !== 1) continue;
            if (!in_array($weekday, schedule_days($schedule['weekdays']), true)) continue;

            [$hour, $minute] = array_map('intval', explode(':', (string)$schedule['trigger_time']));
            $scheduledAt = $now->setTime($hour, $minute, 0);

            // Never run before the selected Mumbai/IST time.
            if ($now < $scheduledAt) continue;

            // One successful attempt per schedule/date. If cron starts late, catch up the same day.
            $runKey = $scheduledAt->format('Y-m-d H:i');
            if (($schedule['last_run_key'] ?? null) === $runKey) continue;

            // Claim before delivery so overlapping cron calls cannot send duplicates.
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
            $results[] = [
                'id' => (int)$schedule['id'],
                'status' => 'sent',
                'scheduledAt' => $scheduledAt->format(DateTimeInterface::ATOM),
                'sentAt' => app_now()->format(DateTimeInterface::ATOM),
            ];
        } catch (Throwable $e) {
            // Release the claim after SMTP failure so the next cron tick can retry.
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

            $results[] = [
                'id' => (int)$schedule['id'],
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    return $results;
}
