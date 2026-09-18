<?php
return [
    'app_name' => 'Prompt Bridge',
    'timezone' => 'Asia/Kolkata',
    'admin_password' => 'CHANGE_ME_NOW',

    // Email inbox monitored by the ChatGPT Work Gmail event trigger.
    'mail_to' => 'your-gmail-address@example.com',

    // Use a mailbox on your own domain for better delivery.
    'mail_from' => 'automation@yourdomain.com',
    'mail_from_name' => 'Prompt Bridge',

    'cron_secret' => 'CHANGE_TO_A_LONG_RANDOM_SECRET',
    'message_tag' => 'SOCIAL_AUTOMATION',
    'storage_path' => __DIR__ . '/storage',
];
