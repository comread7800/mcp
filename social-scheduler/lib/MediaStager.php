<?php
declare(strict_types=1);

final class MediaStager
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? igmcp_config();
    }

    public function stage(array $args): array
    {
        $sourceUrl = trim((string)($args['source_url'] ?? ''));
        $base64 = trim((string)($args['base64_data'] ?? ''));
        if (($sourceUrl === '') === ($base64 === '')) {
            throw new InvalidArgumentException('Provide exactly one of source_url or base64_data.');
        }

        $bytes = $sourceUrl !== '' ? $this->download($sourceUrl) : $this->decodeBase64($base64);
        $max = (int)$this->config['media_max_bytes'];
        if (strlen($bytes) > $max) {
            throw new RuntimeException('Media exceeds staging limit of ' . $max . ' bytes.');
        }

        $mime = $this->detectMime($bytes);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Only JPEG, PNG, and WEBP images are supported by this staging helper.');
        }

        $fit45 = !empty($args['fit_4_5']);
        [$jpeg, $width, $height] = $this->toInstagramJpeg($bytes, $mime, $fit45);
        if (strlen($jpeg) > 8 * 1024 * 1024) {
            throw new RuntimeException('Converted JPEG is over Instagram\'s 8 MB image limit.');
        }

        $ratio = $width / max(1, $height);
        if ($ratio < 0.8 - 0.001 || $ratio > 1.91 + 0.001) {
            throw new RuntimeException(sprintf('Image aspect ratio %.3f is outside Instagram feed range 4:5 to 1.91:1.', $ratio));
        }

        $name = bin2hex(random_bytes(16)) . '.jpg';
        $path = rtrim((string)$this->config['media_path'], '/') . '/' . $name;
        if (@file_put_contents($path, $jpeg, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write staged media.');
        }
        @chmod($path, 0644);

        return [
            'public_url' => igmcp_public_base_url() . '/media/' . rawurlencode($name),
            'mime_type' => 'image/jpeg',
            'width' => $width,
            'height' => $height,
            'bytes' => strlen($jpeg),
            'sha256' => hash('sha256', $jpeg),
        ];
    }

    public function deleteStagedUrl(string $url): void
    {
        $base = rtrim(igmcp_public_base_url(), '/') . '/media/';
        if (!str_starts_with($url, $base)) {
            return;
        }
        $name = basename((string)parse_url($url, PHP_URL_PATH));
        if ($name === '' || !preg_match('/^[a-f0-9]{32}\.jpg$/', $name)) {
            return;
        }
        $path = rtrim((string)$this->config['media_path'], '/') . '/' . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function decodeBase64(string $value): string
    {
        if (preg_match('~^data:[^;]+;base64,(.+)$~s', $value, $m)) {
            $value = $m[1];
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('base64_data is not valid base64.');
        }
        return $decoded;
    }

    private function download(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            throw new InvalidArgumentException('source_url must be an HTTP or HTTPS URL.');
        }
        $this->assertPublicHost((string)$parts['host']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'InstagramPublisherMCP/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $data = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($data === false) {
            throw new RuntimeException('Unable to download source media: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('source_url returned HTTP ' . $status . '. Use a direct public media URL without redirects/authentication.');
        }
        return (string)$data;
    }

    private function assertPublicHost(string $host): void
    {
        if (strcasecmp($host, 'localhost') === 0) {
            throw new RuntimeException('Local/private media URLs are not allowed.');
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!$records) {
            throw new RuntimeException('Unable to resolve source_url host.');
        }
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!$ip || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('source_url resolves to a private/reserved address, which is blocked.');
            }
        }
    }

    private function detectMime(string $bytes): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return (string)$finfo->buffer($bytes);
    }

    private function toInstagramJpeg(string $bytes, string $mime, bool $fit45 = false): array
    {
        if (!extension_loaded('gd')) {
            if ($mime !== 'image/jpeg') {
                throw new RuntimeException('PHP GD extension is required to convert PNG/WEBP to JPEG.');
            }
            $info = @getimagesizefromstring($bytes);
            if (!$info) {
                throw new RuntimeException('Invalid JPEG image.');
            }
            return [$bytes, (int)$info[0], (int)$info[1]];
        }

        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            throw new RuntimeException('Unable to decode image.');
        }
        $width = imagesx($src);
        $height = imagesy($src);
        if ($width < 320) {
            imagedestroy($src);
            throw new RuntimeException('Instagram image width must be at least 320 px.');
        }

        if ($fit45) {
            $targetWidth = 1080;
            $targetHeight = 1350;
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);

            $scale = min($targetWidth / $width, $targetHeight / $height);
            $newWidth = max(1, (int)round($width * $scale));
            $newHeight = max(1, (int)round($height * $scale));
            $x = (int)floor(($targetWidth - $newWidth) / 2);
            $y = (int)floor(($targetHeight - $newHeight) / 2);

            imagecopyresampled($canvas, $src, $x, $y, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($src);
            $src = $canvas;
            $width = $targetWidth;
            $height = $targetHeight;
        } elseif ($width > 1440) {
            $newWidth = 1440;
            $newHeight = (int)round($height * ($newWidth / $width));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            $white = imagecolorallocate($resized, 255, 255, 255);
            imagefill($resized, 0, 0, $white);
            imagecopyresampled($resized, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($src);
            $src = $resized;
            $width = $newWidth;
            $height = $newHeight;
        } else {
            $flattened = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($flattened, 255, 255, 255);
            imagefill($flattened, 0, 0, $white);
            imagecopy($flattened, $src, 0, 0, 0, 0, $width, $height);
            imagedestroy($src);
            $src = $flattened;
        }

        ob_start();
        imagejpeg($src, null, 90);
        $jpeg = (string)ob_get_clean();
        imagedestroy($src);
        if ($jpeg === '') {
            throw new RuntimeException('Unable to encode JPEG.');
        }
        return [$jpeg, $width, $height];
    }
}
