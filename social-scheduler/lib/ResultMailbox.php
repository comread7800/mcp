<?php
declare(strict_types=1);

final class ResultMailbox
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? app_config();
    }

    public function testConnection(): array
    {
        $stream = $this->connect();
        try {
            $this->command($stream, 'LOGIN ' . $this->quote((string)$this->config['imap_username']) . ' ' . $this->quote((string)$this->config['imap_app_password']));
            $select = $this->command($stream, 'SELECT ' . $this->quote((string)$this->config['imap_mailbox']));
            return [
                'ok' => true,
                'host' => (string)$this->config['imap_host'],
                'mailbox' => (string)$this->config['imap_mailbox'],
                'selected' => stripos($select, ' OK ') !== false,
            ];
        } finally {
            try { $this->command($stream, 'LOGOUT', false); } catch (Throwable) {}
            fclose($stream);
        }
    }

    public function fetchReadyMessages(int $limit = 3): array
    {
        $limit = max(1, min(5, $limit));
        $stream = $this->connect();
        try {
            $this->command($stream, 'LOGIN ' . $this->quote((string)$this->config['imap_username']) . ' ' . $this->quote((string)$this->config['imap_app_password']));
            $this->command($stream, 'SELECT ' . $this->quote((string)$this->config['imap_mailbox']));
            $tag = (string)$this->config['result_subject_tag'];
            $response = $this->command($stream, 'UID SEARCH SUBJECT ' . $this->quote('[' . $tag . ']'));
            $uids = $this->parseSearchUids($response);
            if (!$uids) return [];

            rsort($uids, SORT_NUMERIC);
            $uids = array_slice($uids, 0, $limit);
            $messages = [];
            foreach (array_reverse($uids) as $uid) {
                $raw = $this->fetchUid($stream, $uid);
                if ($raw === '') continue;
                $messages[] = ['uid' => (int)$uid, 'raw' => $raw];
            }
            return $messages;
        } finally {
            try { $this->command($stream, 'LOGOUT', false); } catch (Throwable) {}
            fclose($stream);
        }
    }

    public function markSeen(int $uid): void
    {
        $stream = $this->connect();
        try {
            $this->command($stream, 'LOGIN ' . $this->quote((string)$this->config['imap_username']) . ' ' . $this->quote((string)$this->config['imap_app_password']));
            $this->command($stream, 'SELECT ' . $this->quote((string)$this->config['imap_mailbox']));
            $this->command($stream, 'UID STORE ' . $uid . ' +FLAGS (\\Seen)');
        } finally {
            try { $this->command($stream, 'LOGOUT', false); } catch (Throwable) {}
            fclose($stream);
        }
    }

    private function connect()
    {
        $host = trim((string)$this->config['imap_host']);
        $port = (int)$this->config['imap_port'];
        $user = trim((string)$this->config['imap_username']);
        $pass = preg_replace('/\s+/', '', (string)$this->config['imap_app_password']) ?? '';
        if ($host === '' || $port < 1 || !filter_var($user, FILTER_VALIDATE_EMAIL) || $pass === '') {
            throw new RuntimeException('Result mailbox IMAP settings are incomplete.');
        }

        $errno = 0; $errstr = '';
        $stream = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]])
        );
        if (!$stream) {
            throw new RuntimeException('Unable to connect to IMAP mailbox: ' . ($errstr ?: ('error ' . $errno)));
        }
        stream_set_timeout($stream, 30);
        $greeting = fgets($stream);
        if ($greeting === false || stripos($greeting, '* OK') !== 0) {
            fclose($stream);
            throw new RuntimeException('IMAP server did not return an OK greeting.');
        }
        return $stream;
    }

    private function command($stream, string $command, bool $throwOnNo = true): string
    {
        static $counter = 0;
        $counter++;
        $tag = 'A' . str_pad((string)$counter, 4, '0', STR_PAD_LEFT);
        if (fwrite($stream, $tag . ' ' . $command . "\r\n") === false) {
            throw new RuntimeException('Unable to write to IMAP server.');
        }

        $out = '';
        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) break;
            $out .= $line;
            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', $line, $m)) {
                $status = strtoupper($m[1]);
                if ($status !== 'OK' && $throwOnNo) {
                    throw new RuntimeException('IMAP command failed: ' . trim($line));
                }
                break;
            }
        }
        return $out;
    }

    private function fetchUid($stream, int $uid): string
    {
        static $counter = 5000;
        $counter++;
        $tag = 'F' . $counter;
        if (fwrite($stream, $tag . ' UID FETCH ' . $uid . " (BODY.PEEK[])\r\n") === false) {
            throw new RuntimeException('Unable to request IMAP message.');
        }

        $raw = '';
        $max = (int)$this->config['result_email_max_bytes'];
        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) break;

            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $bytes = (int)$m[1];
                if ($bytes > $max) {
                    throw new RuntimeException('Result email exceeds configured size limit.');
                }
                $raw = $this->readExact($stream, $bytes);
                continue;
            }

            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', $line, $m)) {
                if (strtoupper($m[1]) !== 'OK') {
                    throw new RuntimeException('IMAP fetch failed: ' . trim($line));
                }
                break;
            }
        }

        return $raw;
    }

    private function readExact($stream, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes && !feof($stream)) {
            $chunk = fread($stream, min(65536, $bytes - strlen($data)));
            if ($chunk === false || $chunk === '') break;
            $data .= $chunk;
        }
        if (strlen($data) !== $bytes) {
            throw new RuntimeException('IMAP message download was incomplete.');
        }
        return $data;
    }

    private function parseSearchUids(string $response): array
    {
        if (!preg_match('/^\* SEARCH(?:\s+([0-9 ]+))?/mi', $response, $m)) return [];
        $raw = trim((string)($m[1] ?? ''));
        if ($raw === '') return [];
        return array_values(array_filter(array_map('intval', preg_split('/\s+/', $raw) ?: [])));
    }

    private function quote(string $value): string
    {
        return '"' . addcslashes($value, "\\\"") . '"';
    }
}
