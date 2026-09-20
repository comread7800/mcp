<?php
declare(strict_types=1);

return [
    'app_name' => 'Webkitti Automation Hub',
    'timezone' => 'Asia/Kolkata',
    'admin_password' => 'CHANGE_ME_NOW',

    // Website -> Gmail -> ChatGPT Work trigger bridge.
    'mail_to' => 'your-chatgpt-connected-gmail@gmail.com',
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_username' => 'your-prompt-bridge-gmail@gmail.com',
    'smtp_app_password' => 'CHANGE_ME_APP_PASSWORD',
    'mail_from' => 'your-prompt-bridge-gmail@gmail.com',
    'mail_from_name' => 'Prompt Bridge',
    'cron_secret' => 'CHANGE_TO_A_LONG_RANDOM_SECRET',
    'message_tag' => 'SOCIAL_AUTOMATION',

    // Final recommended mode:
    // website -> Gmail trigger -> ChatGPT Work -> Gmail result -> website -> Meta Instagram API.
    // "metricool" and "instagram_mcp" remain available as fallbacks.
    'publisher_mode' => 'email_bridge',

    // ChatGPT Work sends final caption + slide attachments back to this mailbox.
    // Usually this is the same Gmail used by smtp_username so the same App Password can read it.
    'result_subject_tag' => 'SOCIAL_READY',
    'result_email_to' => 'your-prompt-bridge-gmail@gmail.com',
    'result_email_from' => 'your-chatgpt-connected-gmail@gmail.com',

    // Result mailbox reader. Leave username/password empty to reuse smtp_username/smtp_app_password.
    'imap_host' => 'imap.gmail.com',
    'imap_port' => 993,
    'imap_username' => '',
    'imap_app_password' => '',
    'imap_mailbox' => 'INBOX',
    'result_email_max_bytes' => 41943040,
    'result_max_messages_per_run' => 2,

    // Public HTTPS URL where this ONE social-scheduler folder is deployed.
    'public_base_url' => 'https://example.com/social-scheduler',

    // Meta Instagram API with Instagram Login.
    'instagram_app_id' => 'CHANGE_ME',
    'instagram_app_secret' => 'CHANGE_ME',
    'instagram_redirect_uri' => 'https://example.com/social-scheduler/instagram.php?action=oauth_callback',
    'instagram_scopes' => [
        'instagram_business_basic',
        'instagram_business_content_publish',
    ],
    'graph_api_version' => 'v26.0',

    // Legacy/custom MCP settings. Not required by the final email-bridge flow.
    'mcp_api_key' => 'CHANGE_TO_ANOTHER_LONG_RANDOM_SECRET',
    'allowed_origins' => [],

    // Local private state and temporary public media.
    'storage_path' => __DIR__ . '/storage',
    'media_path' => __DIR__ . '/media',
    'media_max_bytes' => 12 * 1024 * 1024,
];
