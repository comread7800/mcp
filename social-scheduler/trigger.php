<?php
declare(strict_types=1);
require __DIR__ . '/hub.php';
hub_require_login();

$error = null;
$notice = hub_take_flash('notice');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf'] ?? null);
        if (($_POST['action'] ?? '') === 'test_email') {
            $tag = app_config()['message_tag'];
            email_send('[' . $tag . '] Prompt Bridge TEST | ' . app_now()->format('Y-m-d H:i:s'), '[' . $tag . "]\nSOURCE: TEST\nPrompt Bridge connection test. Do not create or publish content.");
            add_log(null, null, 'sent', 'Email connection test handed to Gmail SMTP successfully.');
            hub_set_flash('Test Gmail message sent successfully.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$config = app_config();
$mode = $config['publisher_mode'];
$pageTitle = 'Work Trigger · ' . $config['app_name'];
require __DIR__ . '/partials/header.php';

if ($mode === 'instagram_mcp') {
$instruction = <<<TEXT
When a NEW Gmail message arrives from {$config['mail_from']} and its subject starts with [{$config['message_tag']}], process that exact newly arrived message as one unique automation job.

Use the full subject plus TRIGGERED_AT as the unique job identity. If the body contains SOURCE: TEST, stop and do nothing.

The website controls timing. Never create a ChatGPT time-based schedule.

Read the PROMPT section completely and execute it fully.

For every valid job:
1. Research and create all requested caption/media completely.
2. Check the connected Direct Instagram MCP with instagram_connection_status.
3. Check instagram_recent_posts and avoid obvious duplicate content, but do not skip a valid new job just because related topics were used recently.
4. If generated media is not already a durable public HTTPS JPEG URL, use instagram_stage_media for each slide.
5. For a carousel, call instagram_publish_carousel exactly once with all slides in the correct order.
6. Use the full email subject plus TRIGGERED_AT as job_id.
7. Before any retry, call instagram_publication_status with the same job_id.
8. Never use Metricool, Composio, or another publishing fallback.
9. If the MCP returns PUBLISHED/published, report media_id and permalink.
10. If it returns in-progress/pending, do not submit another copy.
11. If it returns failed/error, report the exact error and stop.
12. Never claim success unless the direct Instagram MCP confirms publication.

Keep the requested Hindi/Indian-audience carousel design, research quality, caption quality, date/source accuracy, and media rules from the email PROMPT.
TEXT;
} else {
$instruction = <<<TEXT
When a NEW Gmail message arrives from {$config['mail_from']} and its subject starts with [{$config['message_tag']}], process that exact newly arrived message as one unique automation job.

Use the full subject plus TRIGGERED_AT as the unique job identity. If the body contains SOURCE: TEST, stop and do nothing.

The website controls timing. Never create a ChatGPT time-based schedule.

Read the PROMPT section completely and execute it fully.

For every valid job:
1. Research and create all requested caption/media completely.
2. Check recent @webkitti Metricool posts and avoid obvious duplicate content, but do not skip a valid new job only because similar topics were used recently.
3. Publish ONLY through connected Metricool brand webkitti, brand ID 7005701.
4. Do not use Composio or another fallback publisher.
5. Create exactly one Instagram post/carousel with all media in order.
6. Use autoPublish true and isAiGenerated true when supported.
7. Publish as soon as content is ready. If Metricool requires a future timestamp, use the earliest valid time in the brand timezone.
8. If Metricool returns PENDING or PUBLISHING, do not submit another copy.
9. If Metricool returns ERROR or FAILED, report the exact error and stop.
10. Never claim success unless Metricool confirms publication.

Keep the requested Hindi/Indian-audience carousel design, research quality, caption quality, date/source accuracy, and media rules from the email PROMPT.
TEXT;
}
?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert success"><?= e($notice) ?></div><?php endif; ?>

<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Transport</div>
        <h2>Gmail event trigger</h2>
        <p>The website sends one unique email per job. ChatGPT Work reacts to the incoming Gmail event.</p>
        <div class="meta-card"><span>Sender</span><strong><?= e((string)$config['mail_from']) ?></strong></div>
        <div class="meta-card"><span>Tag</span><strong>[<?= e((string)$config['message_tag']) ?>]</strong></div>
        <div class="meta-card"><span>Publisher mode</span><strong><?= $mode === 'instagram_mcp' ? 'Direct Instagram MCP' : 'Metricool' ?></strong></div>
        <form method="post" style="margin-top:16px"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="test_email"><button class="button secondary" type="submit">Send Gmail TEST</button></form>
    </div>

    <div class="card">
        <div class="eyebrow">Important</div>
        <h2>One Work trigger only</h2>
        <p class="muted">Do not create 4 ChatGPT time schedules. Your website schedules control the clock. Work only reacts to matching incoming Gmail messages.</p>
        <p class="muted">Change <code>publisher_mode</code> in <code>config.php</code> only after the direct MCP has passed a real Instagram test.</p>
    </div>
</section>

<section class="card how-card">
    <div class="eyebrow">Copy into ChatGPT Work</div>
    <h2>Current Work trigger instruction</h2>
    <pre><?= e($instruction) ?></pre>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
