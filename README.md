# Prompt Bridge

A clean single-user scheduler for this flow:

~~~text
Your website schedule
        ↓
Slack channel message
        ↓
ChatGPT Work event trigger
        ↓
AI creates/researches the post + media
        ↓
Connected Metricool plugin
        ↓
Instagram scheduled/published by Metricool
~~~

This project does **not** use ChatGPT's time scheduler and does **not** call the OpenAI API. Your own hosting cron decides when the prompt is sent.

## Features

- Password-protected dashboard
- Daily/weekly prompt schedules
- Separate trigger time and Metricool publish time
- Slack Incoming Webhook delivery
- Stable [SOCIAL_AUTOMATION] message tag for ChatGPT Work
- Run now, edit, pause/enable and delete
- SQLite delivery log
- No Instagram API credentials
- No OpenAI API key

## Requirements

- PHP 8.1+
- PDO SQLite
- PHP cURL recommended
- HTTPS hosting
- Slack Incoming Webhook
- ChatGPT Work Slack event trigger for the same channel
- Metricool connected to ChatGPT and Instagram connected inside Metricool

## 1. Upload

Upload the repository to a dedicated folder or subdomain on your hosting.

Example:

~~~text
/home/USER/domains/automation.example.com/public_html/
~~~

## 2. Configure

Copy:

~~~bash
cp config.example.php config.php
~~~

Edit config.php:

~~~php
return [
    'app_name' => 'My Social Scheduler',
    'timezone' => 'Asia/Kolkata',
    'admin_password' => 'use-a-long-unique-password',
    'slack_webhook_url' => 'https://hooks.slack.com/services/...',
    'cron_secret' => 'another-long-random-secret',
    'message_tag' => 'SOCIAL_AUTOMATION',
    'storage_path' => __DIR__ . '/storage',
];
~~~

config.php is ignored by Git and must never be committed.

## 3. Slack

Create or reuse a Slack app, enable Incoming Webhooks, and point one webhook at a private automation channel such as:

~~~text
#chatgpt-social-queue
~~~

Paste that webhook URL into config.php.

Log in to Prompt Bridge and press **Test Slack**. A test message should arrive in the channel.

## 4. ChatGPT Work setup

Create one Slack event-triggered Work task for the same channel. The website controls **time**; the Work task only reacts to new Slack messages.

Use an instruction equivalent to:

~~~text
When a new Slack message starts with [SOCIAL_AUTOMATION], execute the PROMPT completely.
Research current information when the prompt requires it.
Create the required Instagram caption and visual/media.
Then use my connected Metricool plugin to schedule the finished Instagram post for PUBLISH_AT.
Do not create a ChatGPT time-based schedule.
Ignore messages with SOURCE: TEST.
If Metricool succeeds, return the planner link/status.
If a required input is genuinely missing, report the exact blocker.
~~~

Metricool remains the publishing layer. Prompt Bridge never needs your Instagram password or token.

## 5. Your own cron

### Preferred: PHP CLI cron

Run every minute:

~~~cron
* * * * * /usr/bin/php /home/USER/domains/automation.example.com/public_html/cron.php >/dev/null 2>&1
~~~

Use the exact PHP path and site path from your host.

### Alternative: HTTP cron

If your host only supports URL cron jobs:

~~~text
https://automation.example.com/cron.php?key=YOUR_CRON_SECRET
~~~

Run it every minute and keep the secret private.

## Publish-time behavior

Example:

- Trigger: 08:00
- Publish in Metricool: 10:00

At 08:00 Prompt Bridge sends the Slack job. ChatGPT Work then creates the post and schedules it in Metricool for 10:00.

If publish time is earlier than or equal to trigger time, Prompt Bridge uses the **next day**. Example: trigger 20:00, publish 09:00 means tomorrow at 09:00.

## Security

- Keep config.php private.
- Use a dedicated Slack channel/webhook.
- Use a strong dashboard password and cron secret.
- Use HTTPS.
- .htaccess blocks direct access to application/config files and storage on Apache-compatible hosting.
- If your host ignores .htaccess, move storage_path outside public_html and deny web access to config.php.

## Files

- index.php — dashboard
- app.php — database, schedule logic and Slack delivery
- cron.php — your minute-based runner
- health.php — health endpoint
- config.example.php — safe configuration template
- assets/app.css — responsive UI
- storage/ — SQLite data, ignored by Git
