<?php
declare(strict_types=1);

return [
    'app_name' => 'Instagram Publisher MCP',
    'timezone' => 'Asia/Kolkata',

    // Dashboard password. Change before deployment.
    'admin_password' => 'CHANGE_ME_NOW',

    // Public HTTPS base URL where this folder is deployed, no trailing slash.
    // Example: https://example.com/instagram-mcp
    'public_base_url' => 'https://example.com/instagram-mcp',

    // Meta App -> Instagram API with Instagram Login.
    'instagram_app_id' => 'CHANGE_ME',
    'instagram_app_secret' => 'CHANGE_ME',
    'instagram_redirect_uri' => 'https://example.com/instagram-mcp/index.php?action=oauth_callback',
    'instagram_scopes' => [
        'instagram_business_basic',
        'instagram_business_content_publish',
    ],

    // Latest Graph API version at the time this project was built (Sep 2026).
    'graph_api_version' => 'v26.0',

    // Secret used by ChatGPT/another MCP client to call mcp/server.php.
    // Prefer Authorization: Bearer <token>. Query-string ?key=... is supported only as a fallback.
    'mcp_api_key' => 'CHANGE_TO_A_LONG_RANDOM_SECRET',

    // Optional Origin allowlist for clients that send an Origin header.
    // Server-side MCP clients usually send no Origin. Add exact origins only if needed.
    'allowed_origins' => [],

    'storage_path' => __DIR__ . '/storage',
    'media_path' => __DIR__ . '/media',
    'media_max_bytes' => 12 * 1024 * 1024,
];
