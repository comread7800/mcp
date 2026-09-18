<?php
return [
    'app_name' => 'Prompt Bridge',
    'timezone' => 'Asia/Kolkata',
    'admin_password' => 'CHANGE_ME_NOW',

    // Gmail inbox monitored by the ChatGPT Work event trigger.
    'mail_to' => 'your-personal-gmail@gmail.com',

    // Authenticated Gmail SMTP sender.
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_username' => 'your-personal-gmail@gmail.com',
    // Use a Google App Password (16 characters), not your normal Gmail password.
    'smtp_app_password' => 'CHANGE_ME_APP_PASSWORD',

    // Usually keep mail_from the same as smtp_username.
    'mail_from' => 'your-personal-gmail@gmail.com',
    'mail_from_name' => 'Prompt Bridge',

    'cron_secret' => 'CHANGE_TO_A_LONG_RANDOM_SECRET',
    'message_tag' => 'SOCIAL_AUTOMATION',
    'storage_path' => __DIR__ . '/storage',
];
