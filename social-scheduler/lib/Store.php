<?php
declare(strict_types=1);

final class IgStore
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? (string)igmcp_config()['storage_path'], '/');
    }

    public function connection(): ?array
    {
        $data = $this->read('instagram-connection.json', []);
        return $data ?: null;
    }

    public function saveConnection(array $data): void
    {
        $this->write('instagram-connection.json', $data);
    }

    public function clearConnection(): void
    {
        $path = $this->path('instagram-connection.json');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function publication(string $jobId): ?array
    {
        $all = $this->read('publications.json', []);
        return isset($all[$jobId]) && is_array($all[$jobId]) ? $all[$jobId] : null;
    }

    public function savePublication(string $jobId, array $data): void
    {
        $this->mutate('publications.json', function (array $all) use ($jobId, $data): array {
            $all[$jobId] = $data;
            if (count($all) > 500) {
                uasort($all, fn(array $a, array $b): int => strcmp((string)($a['updated_at'] ?? ''), (string)($b['updated_at'] ?? '')));
                $all = array_slice($all, -500, null, true);
            }
            return $all;
        });
    }

    public function log(string $event, array $context = []): void
    {
        $safe = $context;
        foreach (['access_token', 'token', 'app_secret'] as $secretKey) {
            unset($safe[$secretKey]);
        }
        $line = json_encode([
            'time' => gmdate('c'),
            'event' => $event,
            'context' => $safe,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        @file_put_contents($this->path('events.log'), $line, FILE_APPEND | LOCK_EX);
    }

    private function path(string $name): string
    {
        return $this->dir . '/' . $name;
    }

    private function read(string $name, array $default): array
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function write(string $name, array $data): void
    {
        $path = $this->path($name);
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write storage file.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to finalize storage file.');
        }
    }

    private function mutate(string $name, callable $fn): void
    {
        $lockPath = $this->path($name . '.lock');
        $lock = @fopen($lockPath, 'c+');
        if (!$lock) {
            throw new RuntimeException('Unable to open storage lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock storage.');
            }
            $current = $this->read($name, []);
            $next = $fn($current);
            if (!is_array($next)) {
                throw new RuntimeException('Storage mutation returned invalid data.');
            }
            $this->write($name, $next);
            flock($lock, LOCK_UN);
        } finally {
            fclose($lock);
        }
    }
}
