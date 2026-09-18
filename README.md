# Prompt Bridge

A single-user scheduler for this flow:

~~~text
Your website schedule
        ↓
Email to your monitored inbox
        ↓
ChatGPT Work Gmail event trigger
        ↓
AI creates/researches the post + media
        ↓
Connected Metricool plugin
        ↓
Instagram scheduled/published by Metricool
~~~

This project does **not** use ChatGPT's time-based scheduler and does **not** call the OpenAI API. Your own hosting cron decides when the prompt email is sent.

## Features

- Password-protected dashboard
- Daily/weekly prompt schedules
- Separate trigger time and Metricool publish time
- Authenticated Gmail SMTP delivery
- Stable [SOCIAL_AUTOMATION] subject/body tag for ChatGPT Work
- Run now, edit, pause/enable and delete
- JSON delivery log with file locking
- No Instagram API credentials
- No OpenAI API key

## Requirements

- PHP 8.1+
- HTTPS hosting
- PHP cURL enabled by the host
- Gmail with 2-Step Verification and a Google App Password
- One inbox that ChatGPT Work can monitor through Gmail
- ChatGPT Work Gmail event trigger
- Metricool connected to ChatGPT
- Instagram connected inside Metricool

## 1. Deploy

Deploy the repository so this folder is reachable:

~~~text
/social-scheduler/
~~~

Folder layout:

~~~text
social-scheduler/
├── index.php
├── app.php
├── cron.php
├── health.php
├── config.example.php
├── .htaccess
├── assets/
│   └── app.css
└── storage/
    └── .htaccess
~~~

## 2. Configure

On the server copy:

~~~bash
cp social-scheduler/config.example.php social-scheduler/config.php
~~~

Edit social-scheduler/config.php:

~~~php
<?php
return [
    'app_name' => 'My Social Scheduler',
    'timezone' => 'Asia/Kolkata',
    'admin_password' => 'use-a-long-unique-password',

    'mail_to' => 'YOUR_GMAIL_ADDRESS',

    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_username' => 'YOUR_GMAIL_ADDRESS',
    'smtp_app_password' => 'YOUR_GOOGLE_APP_PASSWORD',

    'mail_from' => 'YOUR_GMAIL_ADDRESS',
    'mail_from_name' => 'Prompt Bridge',

    'cron_secret' => 'another-long-random-secret',
    'message_tag' => 'SOCIAL_AUTOMATION',
    'storage_path' => __DIR__ . '/storage',
];
~~~

Use a Google App Password in smtp_app_password, not your normal Gmail password. For the simplest setup, keep smtp_username, mail_from, and mail_to on the same Gmail account that is connected to ChatGPT Work.

config.php is ignored by Git and must never be committed.

## 3. Test email delivery

Open the scheduler dashboard and press **Test Email**. The website sends it through smtp.gmail.com using your authenticated Gmail account.

The inbox configured in mail_to should receive an email with a subject similar to:

~~~text
[SOCIAL_AUTOMATION] Prompt Bridge TEST
~~~

Its body contains:

~~~text
[SOCIAL_AUTOMATION]
SOURCE: TEST
...
~~~

The ChatGPT Work trigger must ignore SOURCE: TEST.

## 4. ChatGPT Work Gmail trigger

Connect the Gmail account that receives mail_to to ChatGPT.

Create one event-triggered Work task for new Gmail messages matching the automation tag.

Suggested instruction:

~~~text
When a new Gmail message has a subject starting with [SOCIAL_AUTOMATION]:

If the email body contains SOURCE: TEST, ignore it and take no action.

Read PUBLISH_AT and PROMPT from the email body.
Execute the PROMPT completely.
Research current information when required.
Create the complete Instagram caption and required visual/media.
Then use my connected Metricool plugin to schedule the finished Instagram post for exactly PUBLISH_AT.

Do not create or use a ChatGPT time-based schedule. The website already controls the trigger time.

If Metricool succeeds, return the planner link/status.
If a genuinely required input is missing, report the exact blocker instead of inventing it.
~~~

## 5. Your own cron

Preferred PHP CLI cron, once per minute:

~~~cron
* * * * * /usr/bin/php /home/USER/domains/YOUR_DOMAIN/public_html/social-scheduler/cron.php >/dev/null 2>&1
~~~

Use the exact PHP binary and site path from your host.

If your host only supports URL cron jobs:

~~~text
https://YOUR_DOMAIN/social-scheduler/cron.php?key=YOUR_CRON_SECRET
~~~

Run it every minute and keep the secret private.

## Publish-time behavior

Example:

- Trigger: 08:00
- Publish in Metricool: 10:00

At 08:00 Prompt Bridge sends the structured email. ChatGPT Work reacts to that incoming email, creates the post, and schedules it in Metricool for 10:00.

If publish time is earlier than or equal to trigger time, Prompt Bridge uses the next day. Example: trigger 20:00, publish 09:00 means tomorrow at 09:00.

## Security

- Keep config.php private.
- Use a strong dashboard password and cron secret.
- Use HTTPS.
- Do not publish mailbox credentials in Git.
- .htaccess blocks direct access to application/config files and storage on Apache-compatible hosting.
- If your host ignores .htaccess, move storage_path outside public_html and deny web access to config.php.
