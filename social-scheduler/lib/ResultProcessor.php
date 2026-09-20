<?php
declare(strict_types=1);

final class ResultProcessor
{
    private array $config;
    private ResultMailbox $mailbox;
    private InstagramClient $instagram;
    private MediaStager $stager;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? app_config();
        $this->mailbox = new ResultMailbox($this->config);
        $this->instagram = new InstagramClient();
        $this->stager = new MediaStager();
    }

    public function process(int $limit = 2): array
    {
        if (publisher_mode() !== 'email_bridge') {
            return ['enabled' => false, 'reason' => 'publisher_mode is not email_bridge', 'processed' => []];
        }

        $lockPath = rtrim((string)$this->config['storage_path'], '/') . '/result-bridge.lock';
        $lock = @fopen($lockPath, 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return ['enabled' => true, 'locked' => true, 'processed' => []];
        }

        try {
            $messages = $this->mailbox->fetchReadyMessages($limit);
            $results = [];
            foreach ($messages as $message) {
                $uid = (int)$message['uid'];
                try {
                    $parsed = $this->parseMime((string)$message['raw']);
                    $this->assertTrustedResult($parsed);
                    $jobKey = $this->extractJobKey($parsed);
                    $messageId = trim((string)($parsed['headers']['message-id'] ?? ('imap-uid-' . $uid)));
                    $dedupeKey = hash('sha256', $messageId . '|' . $jobKey);

                    if ($this->alreadyProcessed($dedupeKey)) {
                        $this->mailbox->markSeen($uid);
                        $results[] = ['uid' => $uid, 'status' => 'duplicate_ignored', 'job_key' => $jobKey];
                        continue;
                    }

                    $caption = $this->extractCaption((string)$parsed['text']);
                    $attachments = $this->orderedImages((array)$parsed['attachments']);
                    if (!$attachments) {
                        throw new RuntimeException('SOCIAL_READY email has no image attachments.');
                    }
                    if (count($attachments) > 10) {
                        throw new RuntimeException('Instagram carousel supports at most 10 images.');
                    }

                    $items = [];
                        foreach ($attachments as $attachment) {
                            $stage = $this->stager->stage([
                                'base64_data' => base64_encode((string)$attachment['bytes']),
                                'fit_4_5' => true,
                            ]);
                            $items[] = [
                                'url' => $stage['public_url'],
                                'alt_text' => 'Webkitti Instagram carousel slide',
                            ];
                        }

                        $publishJobId = 'MAIL-' . substr(hash('sha256', $jobKey), 0, 48);
                        add_log(
                            null,
                            'RESULT-UID:' . $uid,
                            'pending',
                            'Prepared ' . count($items) . ' slide(s); handing the job to Meta Instagram API.'
                        );

                        $publish = count($items) === 1
                            ? $this->instagram->publishImage($publishJobId, $items[0]['url'], $caption, $items[0]['alt_text'])
                            : $this->instagram->publishCarousel($publishJobId, $items, $caption);

                        $status = strtolower((string)($publish['status'] ?? ''));
                        if ($status === 'in_progress') {
                            add_log(
                                null,
                                'RESULT-UID:' . $uid,
                                'pending',
                                'Instagram job is still in progress; leaving the result email unread for a later retry.'
                            );
                            $results[] = [
                                'uid' => $uid,
                                'status' => 'deferred',
                                'job_key' => $jobKey,
                                'retry_after_seconds' => $publish['retry_after_seconds'] ?? null,
                            ];
                            continue;
                        }
                        if ($status !== 'published') {
                            throw new RuntimeException('Instagram did not confirm publication. Status: ' . ($status ?: 'unknown'));
                        }

                        $this->rememberProcessed($dedupeKey, $uid, [
                            'message_id' => $messageId,
                            'job_key' => $jobKey,
                            'media_id' => $publish['media_id'] ?? null,
                            'permalink' => $publish['permalink'] ?? null,
                            'published_at' => app_now()->format(DateTimeInterface::ATOM),
                        ]);
                        $this->mailbox->markSeen($uid);
                        add_log(
                            null,
                            'RESULT:' . substr($dedupeKey, 0, 12),
                            'sent',
                            'Direct Instagram publish completed. Temporary staged media will be deleted automatically after 24 hours. ' . ((string)($publish['permalink'] ?? ''))
                        );
                        $results[] = [
                            'uid' => $uid,
                            'status' => 'published',
                            'job_key' => $jobKey,
                            'media_id' => $publish['media_id'] ?? null,
                            'permalink' => $publish['permalink'] ?? null,
                            'slides' => count($items),
                        ];
                    // Staged media is intentionally retained for Meta for 24 hours.
                    // cron.php removes expired temporary images automatically.
                } catch (Throwable $e) {
                    $attempts = $this->incrementFailure($uid, $e->getMessage());
                    add_log(null, 'RESULT-UID:' . $uid, 'failed', 'Result bridge attempt ' . $attempts . ': ' . $e->getMessage());
                    if ($attempts >= 5) {
                        add_log(
                            null,
                            'RESULT-UID:' . $uid,
                            'failed',
                            'Result email reached the 5-attempt safety limit and was marked seen. Last error: ' . $e->getMessage()
                        );
                        try { $this->mailbox->markSeen($uid); } catch (Throwable) {}
                    }
                    $results[] = ['uid' => $uid, 'status' => 'failed', 'attempts' => $attempts, 'error' => $e->getMessage()];
                }
            }
            return ['enabled' => true, 'processed' => $results];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function assertTrustedResult(array $parsed): void
    {
        $subject = (string)($parsed['headers']['subject'] ?? '');
        $from = strtolower((string)($parsed['from_email'] ?? ''));
        $expectedFrom = strtolower(trim((string)$this->config['result_email_from']));
        $tag = '[' . (string)$this->config['result_subject_tag'] . ']';

        if (!str_starts_with($subject, $tag)) {
            throw new RuntimeException('Result email subject tag is invalid.');
        }
        if ($expectedFrom !== '' && $from !== $expectedFrom) {
            throw new RuntimeException('Result email sender is not trusted: ' . $from);
        }
    }

    private function extractJobKey(array $parsed): string
    {
        $text = (string)$parsed['text'];
        if (preg_match('/JOB_KEY_BEGIN\s*\R(.*?)\RJOB_KEY_END/s', $text, $m)) {
            $key = trim($m[1]);
            if ($key !== '') return $key;
        }

        $subject = (string)($parsed['headers']['subject'] ?? '');
        $prefix = '[' . (string)$this->config['result_subject_tag'] . ']';
        $key = trim(str_starts_with($subject, $prefix) ? substr($subject, strlen($prefix)) : $subject);
        if ($key === '') throw new RuntimeException('SOCIAL_READY email is missing JOB_KEY.');
        return $key;
    }

    private function extractCaption(string $text): string
    {
        if (!preg_match('/CAPTION_BEGIN\s*\R(.*?)\RCAPTION_END/s', $text, $m)) {
            throw new RuntimeException('SOCIAL_READY email is missing CAPTION_BEGIN/CAPTION_END markers.');
        }
        $caption = trim($m[1]);
        if ($caption === '') throw new RuntimeException('Instagram caption is empty.');
        if (mb_strlen($caption) > 2200) $caption = mb_substr($caption, 0, 2197) . '...';
        return $caption;
    }

    private function orderedImages(array $attachments): array
    {
        $images = array_values(array_filter($attachments, static function(array $a): bool {
            return str_starts_with(strtolower((string)($a['mime'] ?? '')), 'image/')
                && (string)($a['bytes'] ?? '') !== '';
        }));
        $allNumbered = $images !== [] && count(array_filter($images, static fn(array $a): bool =>
            (bool)preg_match('/^slide[-_ ]?\d+/i', (string)($a['filename'] ?? ''))
        )) === count($images);
        if ($allNumbered) {
            usort($images, static fn(array $a, array $b): int => strnatcasecmp((string)$a['filename'], (string)$b['filename']));
        }
        return $images;
    }

    private function alreadyProcessed(string $key): bool
    {
        return with_state(static fn(array $state): bool => isset($state['meta']['processed_result_emails'][$key]));
    }

    private function rememberProcessed(string $key, int $uid, array $data): void
    {
        with_state(static function(array &$state) use ($key, $uid, $data): void {
            $state['meta']['processed_result_emails'] = is_array($state['meta']['processed_result_emails'] ?? null)
                ? $state['meta']['processed_result_emails'] : [];
            $state['meta']['processed_result_emails'][$key] = $data;
            if (count($state['meta']['processed_result_emails']) > 200) {
                $state['meta']['processed_result_emails'] = array_slice($state['meta']['processed_result_emails'], -200, null, true);
            }

            // Clear only this message's retry counter. Do not erase failures for other pending jobs.
            if (is_array($state['meta']['result_failures'] ?? null)) {
                unset($state['meta']['result_failures'][(string)$uid]);
                if ($state['meta']['result_failures'] === []) {
                    unset($state['meta']['result_failures']);
                }
            }
        }, true);
    }

    private function incrementFailure(int $uid, string $error): int
    {
        return with_state(static function(array &$state) use ($uid, $error): int {
            $state['meta']['result_failures'] = is_array($state['meta']['result_failures'] ?? null)
                ? $state['meta']['result_failures'] : [];
            $key = (string)$uid;
            $count = (int)($state['meta']['result_failures'][$key]['attempts'] ?? 0) + 1;
            $state['meta']['result_failures'][$key] = [
                'attempts' => $count,
                'error' => $error,
                'updated_at' => app_now()->format(DateTimeInterface::ATOM),
            ];
            return $count;
        }, true);
    }

    private function parseMime(string $raw): array
    {
        [$headerText, $body] = $this->splitHeaderBody($raw);
        $headers = $this->parseHeaders($headerText);
        $subject = $this->decodeHeader((string)($headers['subject'] ?? ''));
        $headers['subject'] = $subject;

        $fromHeader = $this->decodeHeader((string)($headers['from'] ?? ''));
        $fromEmail = '';
        if (preg_match('/<([^>]+)>/', $fromHeader, $m)) $fromEmail = trim($m[1]);
        elseif (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $fromHeader, $m)) $fromEmail = $m[0];

        $textParts = [];
        $attachments = [];
        $this->walkMime($headers, $body, $textParts, $attachments);

        return [
            'headers' => $headers,
            'from_email' => $fromEmail,
            'text' => trim(implode("\n", $textParts)),
            'attachments' => $attachments,
        ];
    }

    private function walkMime(array $headers, string $body, array &$texts, array &$attachments): void
    {
        $contentType = (string)($headers['content-type'] ?? 'text/plain');
        $mime = strtolower(trim(strtok($contentType, ';') ?: 'text/plain'));
        $boundary = $this->headerParam($contentType, 'boundary');

        if (str_starts_with($mime, 'multipart/') && $boundary !== '') {
            $parts = preg_split('/\\R--' . preg_quote($boundary, '/') . '(?:--)?\\s*(?:\\R|$)/', "\n" . $body) ?: [];
            foreach ($parts as $part) {
                $part = trim($part, "\r\n");
                if ($part === '' || $part === '--') continue;
                [$h, $b] = $this->splitHeaderBody($part);
                $this->walkMime($this->parseHeaders($h), $b, $texts, $attachments);
            }
            return;
        }

        $encoding = strtolower(trim((string)($headers['content-transfer-encoding'] ?? '')));
        $decoded = $body;
        if ($encoding === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
            if ($decoded === false) $decoded = '';
        } elseif ($encoding === 'quoted-printable') {
            $decoded = quoted_printable_decode($body);
        }

        $disposition = (string)($headers['content-disposition'] ?? '');
        $filename = $this->headerParam($disposition, 'filename');
        if ($filename === '') $filename = $this->headerParam($contentType, 'name');
        $filename = $this->decodeHeader($filename);

        if ($filename !== '' || str_starts_with($mime, 'image/')) {
            if (str_starts_with($mime, 'image/')) {
                $attachments[] = ['filename' => $filename !== '' ? $filename : ('slide-' . (count($attachments) + 1) . '.jpg'), 'mime' => $mime, 'bytes' => (string)$decoded];
            }
            return;
        }

        if ($mime === 'text/plain') {
            $texts[] = (string)$decoded;
        }
    }

    private function splitHeaderBody(string $raw): array
    {
        $pos = strpos($raw, "\r\n\r\n");
        $sep = 4;
        if ($pos === false) { $pos = strpos($raw, "\n\n"); $sep = 2; }
        if ($pos === false) return [$raw, ''];
        return [substr($raw, 0, $pos), substr($raw, $pos + $sep)];
    }

    private function parseHeaders(string $text): array
    {
        $text = preg_replace("/\r?\n[ \t]+/", ' ', $text) ?? $text;
        $headers = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $p = strpos($line, ':');
            if ($p === false) continue;
            $name = strtolower(trim(substr($line, 0, $p)));
            $value = trim(substr($line, $p + 1));
            if (!isset($headers[$name])) $headers[$name] = $value;
        }
        return $headers;
    }

    private function headerParam(string $header, string $name): string
    {
        if (preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '\*?\s*=\s*(?:"([^"]*)"|([^;\r\n]+))/i', $header, $m)) {
            $value = trim((string)($m[1] !== '' ? $m[1] : $m[2]));
            if (str_contains($header, $name . '*=')) {
                $value = preg_replace("/^[^']*'[^']*'/", '', $value) ?? $value;
                $value = rawurldecode($value);
            }
            return $value;
        }
        return '';
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '') return '';
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') return $decoded;
        }
        return $value;
    }
}
