<?php
declare(strict_types=1);

final class InstagramMcpServer
{
    public const SERVER_NAME = 'webkitti-instagram-publisher';
    public const SERVER_VERSION = '1.0.0';

    public static function tools(): array
    {
        return [
            [
                'name' => 'instagram_connection_status',
                'description' => 'Check whether the Instagram Professional account is connected and whether the token/API connection is healthy.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            ],
            [
                'name' => 'instagram_recent_posts',
                'description' => 'Read recent Instagram posts so the agent can avoid obvious duplicate topics/posts before publishing.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'instagram_publishing_limit',
                'description' => 'Check the Instagram API publishing quota reported by Meta before publishing.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            ],
            [
                'name' => 'instagram_stage_media',
                'description' => 'Stage one image on this server and return a public HTTPS JPEG URL Meta can fetch. Provide exactly one of source_url or base64_data. PNG/WEBP are converted to JPEG. Use this before publishing if the source image is not already a durable public HTTPS JPEG.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'source_url' => ['type' => 'string', 'description' => 'Direct public HTTP/HTTPS image URL with no auth or redirects.'],
                        'base64_data' => ['type' => 'string', 'description' => 'Base64 image bytes or a data:...;base64,... value.'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'instagram_publish_image',
                'description' => 'Publish one image post directly to the connected Instagram Professional account. job_id is mandatory for idempotency and should be the unique website email subject/TRIGGERED_AT identity.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['job_id', 'image_url', 'caption'],
                    'properties' => [
                        'job_id' => ['type' => 'string', 'maxLength' => 220],
                        'image_url' => ['type' => 'string', 'format' => 'uri'],
                        'caption' => ['type' => 'string', 'maxLength' => 2200],
                        'alt_text' => ['type' => 'string', 'maxLength' => 1000],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'instagram_publish_carousel',
                'description' => 'Publish a 2-10 slide image carousel directly to the connected Instagram Professional account. Items are published in the supplied order. job_id is mandatory for duplicate protection.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['job_id', 'items', 'caption'],
                    'properties' => [
                        'job_id' => ['type' => 'string', 'maxLength' => 220],
                        'caption' => ['type' => 'string', 'maxLength' => 2200],
                        'items' => [
                            'type' => 'array',
                            'minItems' => 2,
                            'maxItems' => 10,
                            'items' => [
                                'type' => 'object',
                                'required' => ['url'],
                                'properties' => [
                                    'url' => ['type' => 'string', 'format' => 'uri'],
                                    'alt_text' => ['type' => 'string', 'maxLength' => 1000],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'instagram_publication_status',
                'description' => 'Return the stored direct-publishing result for a unique job_id. Use this before retrying a publish call.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['job_id'],
                    'properties' => ['job_id' => ['type' => 'string', 'maxLength' => 220]],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public static function call(string $name, array $args): array
    {
        $client = new InstagramClient();
        return match ($name) {
            'instagram_connection_status' => $client->connectionStatus(),
            'instagram_recent_posts' => ['posts' => $client->recentPosts((int)($args['limit'] ?? 10))],
            'instagram_publishing_limit' => $client->publishingLimit(),
            'instagram_stage_media' => (new MediaStager())->stage($args),
            'instagram_publish_image' => $client->publishImage(
                (string)($args['job_id'] ?? ''),
                (string)($args['image_url'] ?? ''),
                (string)($args['caption'] ?? ''),
                isset($args['alt_text']) ? (string)$args['alt_text'] : null
            ),
            'instagram_publish_carousel' => $client->publishCarousel(
                (string)($args['job_id'] ?? ''),
                (array)($args['items'] ?? []),
                (string)($args['caption'] ?? '')
            ),
            'instagram_publication_status' => $client->publicationStatus((string)($args['job_id'] ?? '')),
            default => throw new InvalidArgumentException('Unknown MCP tool: ' . $name),
        };
    }
}
