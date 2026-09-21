<?php
declare(strict_types=1);

final class InstagramClient
{
    private array $config;
    private IgStore $store;

    public function __construct(?array $config = null, ?IgStore $store = null)
    {
        $this->config = $config ?? igmcp_config();
        $this->store = $store ?? new IgStore((string)$this->config['storage_path']);
    }

    public function authorizationUrl(string $state): string
    {
        $params = [
            'client_id' => (string)$this->config['instagram_app_id'],
            'redirect_uri' => (string)$this->config['instagram_redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(',', (array)$this->config['instagram_scopes']),
            'state' => $state,
            'force_reauth' => 'true',
        ];
        return 'https://www.instagram.com/oauth/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function connectFromAuthorizationCode(string $code): array
    {
        $short = $this->oauthCodeExchange($code);
        $long = $this->exchangeLongLivedToken((string)$short['access_token']);
        $token = (string)$long['access_token'];
        $profile = $this->requestAbsolute('GET', $this->graphBase() . '/me', [
            'fields' => 'user_id,username',
        ], $token);

        $userId = (string)($profile['user_id'] ?? $profile['id'] ?? '');
        if ($userId === '') {
            throw new RuntimeException('Instagram profile response did not include a professional account ID.');
        }

        $now = time();
        $expiresIn = (int)($long['expires_in'] ?? 5184000);
        $connection = [
            'instagram_user_id' => $userId,
            'username' => (string)($profile['username'] ?? ''),
            'access_token' => $token,
            'issued_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + $expiresIn),
            'connected_at' => gmdate('c', $now),
            'token_source' => 'oauth',
        ];
        $this->store->saveConnection($connection);
        $this->store->log('instagram_connected', ['instagram_user_id' => $userId, 'username' => $connection['username']]);
        return $this->safeConnection($connection);
    }

    public function connectFromAccessToken(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('Instagram access token is required.');
        }

        $profile = $this->request('GET', '/me', [
            'fields' => 'user_id,username',
        ], $token);

        $userId = (string)($profile['user_id'] ?? $profile['id'] ?? '');
        if ($userId === '') {
            throw new RuntimeException('Instagram token is valid but /me did not return a professional account ID.');
        }

        $now = time();
        $connection = [
            'instagram_user_id' => $userId,
            'username' => (string)($profile['username'] ?? ''),
            'access_token' => $token,
            'issued_at' => gmdate('c', $now),
            // App Dashboard tokens do not always expose expiry metadata here.
            // Track a 60-day refresh window so the server can refresh proactively.
            'expires_at' => gmdate('c', $now + 5184000),
            'expires_at_estimated' => true,
            'connected_at' => gmdate('c', $now),
            'token_source' => 'manual_dashboard_token',
        ];

        $this->store->saveConnection($connection);
        $this->store->log('instagram_connected_manual', [
            'instagram_user_id' => $userId,
            'username' => $connection['username'],
        ]);

        return $this->safeConnection($connection);
    }

    public function disconnect(): void
    {
        $connection = $this->store->connection();
        $this->store->clearConnection();
        $this->store->log('instagram_disconnected', ['username' => (string)($connection['username'] ?? '')]);
    }

    public function connectionStatus(): array
    {
        $connection = $this->store->connection();
        if (!$connection) {
            return ['connected' => false];
        }

        try {
            $connection = $this->ensureFreshToken($connection);
            $profile = $this->request('GET', '/me', [
                'fields' => 'user_id,username',
            ], (string)$connection['access_token']);

            $resolvedUserId = (string)($profile['user_id'] ?? $profile['id'] ?? $connection['instagram_user_id'] ?? '');
            if ($resolvedUserId === '') {
                throw new RuntimeException('Instagram profile check did not return a professional account ID.');
            }

            // Keep stored identity in sync with Meta's current /me response.
            if ($resolvedUserId !== (string)($connection['instagram_user_id'] ?? '')
                || (string)($profile['username'] ?? '') !== (string)($connection['username'] ?? '')) {
                $connection['instagram_user_id'] = $resolvedUserId;
                $connection['username'] = (string)($profile['username'] ?? $connection['username'] ?? '');
                $this->store->saveConnection($connection);
            }

            // A basic /me lookup proves identity access only. Verify that the token
            // can also reach the content-publishing endpoint so the dashboard does
            // not report "healthy" for a token that cannot publish.
            $this->request('GET', '/' . rawurlencode($resolvedUserId) . '/content_publishing_limit', [
                'fields' => 'quota_usage,config',
            ], (string)$connection['access_token']);

            return [
                'connected' => true,
                'healthy' => true,
                'publish_ready' => true,
                'instagram_user_id' => $resolvedUserId,
                'username' => (string)($profile['username'] ?? $connection['username'] ?? ''),
                'expires_at' => $connection['expires_at'] ?? null,
                'token_source' => $connection['token_source'] ?? 'oauth',
                'graph_api_version' => (string)$this->config['graph_api_version'],
            ];
        } catch (Throwable $e) {
            return [
                'connected' => true,
                'healthy' => false,
                'instagram_user_id' => (string)($connection['instagram_user_id'] ?? ''),
                'username' => (string)($connection['username'] ?? ''),
                'expires_at' => $connection['expires_at'] ?? null,
                'token_source' => $connection['token_source'] ?? 'oauth',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function recentPosts(int $limit = 10): array
    {
        $limit = max(1, min(25, $limit));
        [$connection, $token] = $this->connectionAndToken();
        $data = $this->request('GET', '/' . rawurlencode((string)$connection['instagram_user_id']) . '/media', [
            'fields' => 'id,caption,media_type,permalink,timestamp,thumbnail_url',
            'limit' => $limit,
        ], $token);
        return (array)($data['data'] ?? []);
    }

    public function publishingLimit(): array
    {
        [$connection, $token] = $this->connectionAndToken();
        return $this->request('GET', '/' . rawurlencode((string)$connection['instagram_user_id']) . '/content_publishing_limit', [
            'fields' => 'quota_usage,config',
        ], $token);
    }

    public function publishImage(string $jobId, string $imageUrl, string $caption, ?string $altText = null): array
    {
        $jobId = $this->validateJobId($jobId);
        if ($existing = $this->idempotentResult($jobId)) {
            return $existing;
        }

        $this->assertPublicHttpsMediaUrl($imageUrl);
        $this->validateCaption($caption);
        $now = gmdate('c');
        $this->store->savePublication($jobId, [
            'job_id' => $jobId,
            'status' => 'in_progress',
            'type' => 'image',
            'attempt_started_at' => $now,
            'updated_at' => $now,
        ]);
        $this->store->log('instagram_publish_started', ['job_id' => $jobId, 'type' => 'image']);

        try {
            [$connection, $token] = $this->connectionAndToken();
            $params = ['image_url' => $imageUrl, 'caption' => $caption];
            if ($altText !== null && trim($altText) !== '') {
                $params['alt_text'] = mb_substr(trim($altText), 0, 1000);
            }
            $container = $this->createContainer($connection, $token, $params);
            $this->waitForContainer($container, $token, 45);
            $result = $this->publishContainer($connection, $token, $container);
            return $this->finalizePublication($jobId, 'image', $result, $token);
        } catch (Throwable $e) {
            $this->saveFailure($jobId, 'image', $e);
            throw $e;
        }
    }

    public function publishCarousel(string $jobId, array $items, string $caption): array
    {
        $jobId = $this->validateJobId($jobId);
        if ($existing = $this->idempotentResult($jobId)) {
            return $existing;
        }
        if (count($items) < 2 || count($items) > 10) {
            throw new InvalidArgumentException('Instagram API carousels require 2 to 10 items.');
        }
        $this->validateCaption($caption);
        foreach ($items as $i => $item) {
            if (!is_array($item) || empty($item['url'])) {
                throw new InvalidArgumentException('Carousel item ' . ($i + 1) . ' must include url.');
            }
            $this->assertPublicHttpsMediaUrl((string)$item['url']);
        }

        $now = gmdate('c');
        $this->store->savePublication($jobId, [
            'job_id' => $jobId,
            'status' => 'in_progress',
            'type' => 'carousel',
            'slide_count' => count($items),
            'attempt_started_at' => $now,
            'updated_at' => $now,
        ]);
        $this->store->log('instagram_publish_started', [
            'job_id' => $jobId,
            'type' => 'carousel',
            'slide_count' => count($items),
        ]);
        try {
            [$connection, $token] = $this->connectionAndToken();
            $children = [];
            $positions = [];
            $total = count($items);

            // Create every child first so Meta can process the whole carousel in
            // parallel. Waiting up to 60 seconds after each child made 10-slide
            // carousels capable of exceeding the PHP/hosting runtime limit.
            foreach ($items as $index => $item) {
                $position = $index + 1;
                $this->store->log('instagram_carousel_child_creating', [
                    'job_id' => $jobId,
                    'position' => $position,
                    'total' => $total,
                ]);
                $params = [
                    'image_url' => (string)$item['url'],
                    'is_carousel_item' => 'true',
                ];
                if (!empty($item['alt_text'])) {
                    $params['alt_text'] = mb_substr(trim((string)$item['alt_text']), 0, 1000);
                }
                $child = $this->createContainer($connection, $token, $params);
                $children[] = $child;
                $positions[$child] = $position;

                $this->store->log('instagram_carousel_child_created', [
                    'job_id' => $jobId,
                    'position' => $position,
                    'total' => $total,
                    'container_id' => $child,
                ]);
            }

            $childStatuses = $this->waitForContainers($children, $token, 120);
            foreach ($children as $child) {
                $childStatus = $childStatuses[$child] ?? [];
                $this->store->log('instagram_carousel_child_ready', [
                    'job_id' => $jobId,
                    'position' => (int)($positions[$child] ?? 0),
                    'container_id' => $child,
                    'status_code' => (string)($childStatus['status_code'] ?? ''),
                ]);
            }

            $this->store->log('instagram_carousel_parent_creating', [
                'job_id' => $jobId,
                'children' => $children,
            ]);
            $parent = $this->createContainer($connection, $token, [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $children),
                'caption' => $caption,
            ]);
            $parentStatus = $this->waitForContainer($parent, $token, 120);
            $this->store->log('instagram_carousel_parent_ready', [
                'job_id' => $jobId,
                'container_id' => $parent,
                'status_code' => (string)($parentStatus['status_code'] ?? ''),
            ]);
            $result = $this->publishContainer($connection, $token, $parent);
            $result['child_container_ids'] = $children;
            $result['parent_container_id'] = $parent;
            return $this->finalizePublication($jobId, 'carousel', $result, $token);
        } catch (Throwable $e) {
            $this->saveFailure($jobId, 'carousel', $e);
            throw $e;
        }
    }

    public function publicationStatus(string $jobId): array
    {
        $jobId = $this->validateJobId($jobId);
        return $this->store->publication($jobId) ?? ['job_id' => $jobId, 'status' => 'not_found'];
    }

    private function oauthCodeExchange(string $code): array
    {
        return $this->requestAbsolute('POST', 'https://api.instagram.com/oauth/access_token', [
            'client_id' => (string)$this->config['instagram_app_id'],
            'client_secret' => (string)$this->config['instagram_app_secret'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => (string)$this->config['instagram_redirect_uri'],
            'code' => $code,
        ], null);
    }

    private function exchangeLongLivedToken(string $shortToken): array
    {
        return $this->requestAbsolute('GET', 'https://graph.instagram.com/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => (string)$this->config['instagram_app_secret'],
            'access_token' => $shortToken,
        ], null);
    }

    private function refreshLongLivedToken(string $token): array
    {
        return $this->requestAbsolute('GET', 'https://graph.instagram.com/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $token,
        ], null);
    }

    private function connectionAndToken(): array
    {
        $connection = $this->store->connection();
        if (!$connection || empty($connection['access_token']) || empty($connection['instagram_user_id'])) {
            throw new RuntimeException('Instagram is not connected. Open the dashboard and connect a Professional account first.');
        }
        $connection = $this->ensureFreshToken($connection);
        return [$connection, (string)$connection['access_token']];
    }

    private function ensureFreshToken(array $connection): array
    {
        $expiresAt = !empty($connection['expires_at']) ? strtotime((string)$connection['expires_at']) : false;
        $issuedAt = !empty($connection['issued_at']) ? strtotime((string)$connection['issued_at']) : false;

        if (!$expiresAt
            && ($connection['token_source'] ?? '') === 'manual_dashboard_token'
            && $issuedAt) {
            $expiresAt = $issuedAt + 5184000;
            $connection['expires_at'] = gmdate('c', $expiresAt);
            $connection['expires_at_estimated'] = true;
            $this->store->saveConnection($connection);
        }

        if ($expiresAt && $expiresAt - time() < 7 * 86400 && (!$issuedAt || time() - $issuedAt >= 86400)) {
            $refreshed = $this->refreshLongLivedToken((string)$connection['access_token']);
            if (!empty($refreshed['access_token'])) {
                $now = time();
                $connection['access_token'] = (string)$refreshed['access_token'];
                $connection['issued_at'] = gmdate('c', $now);
                $connection['expires_at'] = gmdate('c', $now + (int)($refreshed['expires_in'] ?? 5184000));
                $this->store->saveConnection($connection);
                $this->store->log('instagram_token_refreshed', ['username' => (string)($connection['username'] ?? '')]);
            }
        }
        return $connection;
    }

    private function createContainer(array $connection, string $token, array $params): string
    {
        $data = $this->request('POST', '/' . rawurlencode((string)$connection['instagram_user_id']) . '/media', $params, $token);
        $id = (string)($data['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Instagram did not return a media container ID.');
        }
        return $id;
    }

    private function waitForContainer(string $containerId, string $token, int $timeoutSeconds): array
    {
        $deadline = time() + $timeoutSeconds;
        $last = [];
        do {
            $last = $this->request('GET', '/' . rawurlencode($containerId), ['fields' => 'status_code,status'], $token);
            $status = strtoupper((string)($last['status_code'] ?? ''));
            if (in_array($status, ['FINISHED', 'PUBLISHED'], true)) {
                return $last;
            }
            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException('Instagram container ' . $containerId . ' failed: ' . (string)($last['status'] ?? $status));
            }
            usleep(1500000);
        } while (time() < $deadline);

        throw new RuntimeException('Instagram container ' . $containerId . ' did not finish within ' . $timeoutSeconds . ' seconds. Last status: ' . (string)($last['status_code'] ?? 'unknown'));
    }

    private function waitForContainers(array $containerIds, string $token, int $timeoutSeconds): array
    {
        $containerIds = array_values(array_unique(array_filter(array_map('strval', $containerIds))));
        if ($containerIds === []) {
            return [];
        }

        $deadline = time() + $timeoutSeconds;
        $pending = array_fill_keys($containerIds, true);
        $lastStatuses = [];

        do {
            foreach (array_keys($pending) as $containerId) {
                $last = $this->request(
                    'GET',
                    '/' . rawurlencode($containerId),
                    ['fields' => 'status_code,status'],
                    $token
                );
                $lastStatuses[$containerId] = $last;
                $status = strtoupper((string)($last['status_code'] ?? ''));

                if (in_array($status, ['FINISHED', 'PUBLISHED'], true)) {
                    unset($pending[$containerId]);
                    continue;
                }

                if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                    throw new RuntimeException(
                        'Instagram container ' . $containerId . ' failed: '
                        . (string)($last['status'] ?? $status)
                    );
                }
            }

            if ($pending === []) {
                return $lastStatuses;
            }

            usleep(1500000);
        } while (time() < $deadline);

        $unfinished = [];
        foreach (array_keys($pending) as $containerId) {
            $unfinished[] = $containerId . '='
                . (string)($lastStatuses[$containerId]['status_code'] ?? 'unknown');
        }

        throw new RuntimeException(
            'Instagram carousel children did not finish within '
            . $timeoutSeconds . ' seconds. Pending: ' . implode(', ', $unfinished)
        );
    }

    private function publishContainer(array $connection, string $token, string $containerId): array
    {
        $data = $this->request('POST', '/' . rawurlencode((string)$connection['instagram_user_id']) . '/media_publish', [
            'creation_id' => $containerId,
        ], $token);
        if (empty($data['id'])) {
            throw new RuntimeException('Instagram did not return a published media ID.');
        }
        return ['media_id' => (string)$data['id'], 'container_id' => $containerId];
    }

    private function finalizePublication(string $jobId, string $type, array $result, string $token): array
    {
        $mediaId = (string)$result['media_id'];

        // Persist the confirmed media ID immediately after /media_publish succeeds.
        // This closes the duplicate-post window if the optional permalink lookup or
        // PHP process dies after Meta has already published the post.
        $final = [
            'job_id' => $jobId,
            'status' => 'published',
            'type' => $type,
            'media_id' => $mediaId,
            'permalink' => null,
            'timestamp' => null,
            'updated_at' => gmdate('c'),
        ] + $result;
        $this->store->savePublication($jobId, $final);
        $this->store->log('instagram_publish_confirmed', [
            'job_id' => $jobId,
            'type' => $type,
            'media_id' => $mediaId,
        ]);

        try {
            $details = $this->request('GET', '/' . rawurlencode($mediaId), [
                'fields' => 'id,permalink,timestamp,media_type',
            ], $token);
            $final['permalink'] = $details['permalink'] ?? null;
            $final['timestamp'] = $details['timestamp'] ?? null;
            $final['updated_at'] = gmdate('c');
            $this->store->savePublication($jobId, $final);
        } catch (Throwable $e) {
            // Publication already succeeded; permalink lookup is best-effort.
            $this->store->log('instagram_permalink_lookup_failed', [
                'job_id' => $jobId,
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->store->log('instagram_published', [
            'job_id' => $jobId,
            'type' => $type,
            'media_id' => $mediaId,
            'permalink' => $final['permalink'],
        ]);
        return $final;
    }

    private function saveFailure(string $jobId, string $type, Throwable $e): void
    {
        $failure = [
            'job_id' => $jobId,
            'status' => 'failed',
            'type' => $type,
            'error' => $e->getMessage(),
            'updated_at' => gmdate('c'),
        ];
        $this->store->savePublication($jobId, $failure);
        $this->store->log('instagram_publish_failed', $failure);
    }

    private function idempotentResult(string $jobId): ?array
    {
        $existing = $this->store->publication($jobId);
        if (!$existing) {
            return null;
        }

        $status = strtolower((string)($existing['status'] ?? ''));
        if ($status === 'published') {
            $existing['idempotent_replay'] = true;
            return $existing;
        }

        if ($status === 'in_progress') {
            $updatedAt = strtotime((string)($existing['updated_at'] ?? ''));
            $age = $updatedAt ? max(0, time() - $updatedAt) : PHP_INT_MAX;

            // A fresh in-progress record can be another live request. Defer rather
            // than creating duplicate containers. If it is stale, recover it.
            if ($age < 180) {
                $existing['idempotent_replay'] = true;
                $existing['retry_after_seconds'] = 180 - $age;
                return $existing;
            }

            $this->store->log('instagram_recover_stale_in_progress', [
                'job_id' => $jobId,
                'age_seconds' => $age,
            ]);
        }

        return null;
    }

    private function validateJobId(string $jobId): string
    {
        $jobId = trim($jobId);
        if ($jobId === '' || strlen($jobId) > 220) {
            throw new InvalidArgumentException('job_id is required and must be 220 characters or fewer.');
        }
        return $jobId;
    }

    private function validateCaption(string $caption): void
    {
        if (mb_strlen($caption) > 2200) {
            throw new InvalidArgumentException('Instagram caption must be 2200 characters or fewer.');
        }
    }

    private function assertPublicHttpsMediaUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('Instagram media URL must be a public HTTPS URL.');
        }
    }

    private function graphBase(): string
    {
        return 'https://graph.instagram.com/' . rawurlencode((string)$this->config['graph_api_version']);
    }

    private function request(string $method, string $path, array $params, string $token): array
    {
        return $this->requestAbsolute($method, $this->graphBase() . $path, $params, $token);
    }

    private function requestAbsolute(string $method, string $url, array $params, ?string $bearerToken): array
    {
        $method = strtoupper($method);
        $headers = ['Accept: application/json', 'User-Agent: InstagramPublisherMCP/1.0'];
        if ($bearerToken) {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }

        if ($method === 'GET' && $params) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Instagram API network error: ' . $curlError);
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Instagram API returned invalid JSON (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300 || isset($data['error'])) {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];
            $parts = [(string)($error['message'] ?? ('HTTP ' . $status)), 'http=' . $status];
            if (isset($error['type'])) {
                $parts[] = 'type=' . $error['type'];
            }
            if (isset($error['code'])) {
                $parts[] = 'code=' . $error['code'];
            }
            if (isset($error['error_subcode'])) {
                $parts[] = 'subcode=' . $error['error_subcode'];
            }
            if (isset($error['fbtrace_id'])) {
                $parts[] = 'fbtrace_id=' . $error['fbtrace_id'];
            }
            throw new RuntimeException('Instagram API error: ' . implode(' | ', $parts));
        }
        return $data;
    }

    private function safeConnection(array $connection): array
    {
        unset($connection['access_token']);
        $connection['connected'] = true;
        return $connection;
    }
}
