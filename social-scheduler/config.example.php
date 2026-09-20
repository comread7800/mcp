<?php
declare(strict_types=1);

return [
    'app_name' => 'Webkitti Automation Hub',
    'timezone' => 'Asia/Kolkata',
    'admin_password' => 'CHANGE_ME_NOW',

    // Website -> Gmail -> ChatGPT Work bridge.
    'mail_to' => 'your-personal-gmail@gmail.com',
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_username' => 'your-personal-gmail@gmail.com',
    'smtp_app_password' => 'CHANGE_ME_APP_PASSWORD',
    'mail_from' => 'your-personal-gmail@gmail.com',
    'mail_from_name' => 'Prompt Bridge',
    'cron_secret' => 'CHANGE_TO_A_LONG_RANDOM_SECRET',
    'message_tag' => 'SOCIAL_AUTOMATION',

    // Keep "metricool" until direct Instagram MCP has passed a real test.
    // Then change to "instagram_mcp".
    'publisher_mode' => 'metricool',

    // Public HTTPS URL where this ONE social-scheduler folder is deployed.
    // Example: https://iwebry.com/social-scheduler
    'public_base_url' => 'https://example.com/social-scheduler',

    // Direct Instagram MCP / Meta app settings.
    'instagram_app_id' => 'CHANGE_ME',
    'instagram_app_secret' => 'CHANGE_ME',
    'instagram_redirect_uri' => 'https://example.com/social-scheduler/instagram.php?action=oauth_callback',
    'instagram_scopes' => [
        'instagram_business_basic',
        'instagram_business_content_publish',
    ],
    'graph_api_version' => 'v26.0',
    'mcp_api_key' => 'CHANGE_TO_ANOTHER_LONG_RANDOM_SECRET',
    'allowed_origins' => [],

    // Local data/media.
    'storage_path' => __DIR__ . '/storage',
    'media_path' => __DIR__ . '/media',
    'media_max_bytes' => 12 * 1024 * 1024,
];
