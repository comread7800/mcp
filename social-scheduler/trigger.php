<?php
declare(strict_types=1);

require __DIR__ . '/hub.php';
hub_require_login();

$error = null;
$notice = hub_take_flash('notice');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf'] ?? null);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'test_email') {
            $tag = app_config()['message_tag'];
            email_send(
                '[' . $tag . '] Prompt Bridge TEST | ' . app_now()->format('Y-m-d H:i:s'),
                '[' . $tag . "]\nSOURCE: TEST\nPrompt Bridge connection test. Do not create or publish content."
            );
            add_log(null, null, 'sent', 'Email connection test handed to Gmail SMTP successfully.');
            hub_set_flash('Test Gmail message sent successfully.');
        }
        if ($action === 'save_instruction') {
            set_work_trigger_instruction((string)($_POST['instruction'] ?? ''));
            add_log(null, 'TRIGGER-INSTRUCTION', 'sent', 'Global trigger instruction updated from the dashboard.');
            hub_set_flash('Trigger instruction updated. Future jobs will use the new creative direction.');
        }
        if ($action === 'reset_instruction') {
            reset_work_trigger_instruction();
            add_log(null, 'TRIGGER-INSTRUCTION', 'sent', 'Global trigger instruction reset to the built-in WebKitti creator-studio style.');
            hub_set_flash('Trigger instruction reset to the WebKitti premium creator-studio default.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$config = app_config();
$mode = publisher_mode();
$globalInstruction = work_trigger_instruction();
$instructionUpdatedAt = work_trigger_instruction_updated_at();
$editingInstruction = isset($_GET['edit_instruction']) && $_GET['edit_instruction'] === '1';
$pageTitle = 'Work Trigger · ' . $config['app_name'];
require __DIR__ . '/partials/header.php';

$common = <<<TEXT
When a NEW Gmail message arrives from {$config['mail_from']} and its subject starts with [{$config['message_tag']}], process that exact newly arrived message as one unique automation job.

Use the full subject plus TRIGGERED_AT as the unique job identity. If the body contains SOURCE: TEST, stop and do nothing.

The website controls timing. Never create a ChatGPT time-based schedule.

Read the PROMPT section completely and execute it fully. Then read the GLOBAL TRIGGER INSTRUCTION section and apply it to the creative/media output. GLOBAL TRIGGER INSTRUCTION overrides conflicting visual/design/media directions inside PROMPT. The FINAL completion route below overrides any conflicting publishing instruction inside PROMPT.

Research current information from official/reputable sources when requested. Reject rumors, unsupported claims, fake statistics, stale recycled stories, and obvious duplicates. Do not abandon a valid new job merely because a related topic was used recently; choose different fresh stories and expand the research window up to 48 hours if necessary.

For carousel jobs, create the complete final caption and every final slide before the completion step. Keep the requested Hindi/Indian-audience writing, date/source accuracy, exact 1080x1350 portrait design when possible, strong first slide, mobile-readable story slides, original realistic topic visuals, consistent WebKitti branding, and final CTA. Retry one failed media-generation step once. Unsupported carousel music/audio must never block completion.
TEXT;

if ($mode === 'email_bridge') {
    $instruction = $common . <<<TEXT


FINAL COMPLETION ROUTE — EMAIL BRIDGE:
1. Do NOT publish through Metricool, MCP, Composio, Instagram directly, or any other publisher.
2. After the caption and all final slides are complete, send exactly ONE Gmail result email to {$config['result_email_to']}.
3. The result subject MUST be:
[{$config['result_subject_tag']}] <the exact full original trigger subject>

4. The plain-text body MUST contain:
[{$config['result_subject_tag']}]
JOB_KEY_BEGIN
<the exact full original trigger subject>
JOB_KEY_END
SCHEDULE_ID: <copy SCHEDULE_ID from the trigger email>
TRIGGERED_AT: <copy TRIGGERED_AT from the trigger email>
SLIDE_COUNT: <number of attached final slides>
CAPTION_BEGIN
<complete final Instagram caption>
CAPTION_END

5. Attach ONLY the final Instagram images. Name them slide-01.jpg, slide-02.jpg, slide-03.jpg and so on, in exact posting order. Do not attach drafts, source screenshots, references, PDFs, ZIP files, or duplicate versions.
6. Use exact JPEG 1080x1350 (4:5) whenever possible. Keep the highest practical quality; do not intentionally downscale or heavily compress. The website preserves valid 4:5 JPEGs without another re-encode before Meta publishing.
7. Sending the [{$config['result_subject_tag']}] Gmail result is the required completion step. If Gmail send fails, report the exact Gmail error and do not claim completion.
8. Once the result email is sent successfully, stop. The website cron will read the result mailbox and publish directly through the Meta Instagram API.
TEXT;
} elseif ($mode === 'instagram_mcp') {
    $instruction = $common . <<<TEXT


FINAL COMPLETION ROUTE — DIRECT MCP:
1. Publish ONLY through the connected direct Instagram MCP.
2. Check instagram_connection_status and instagram_recent_posts.
3. Use the full subject plus TRIGGERED_AT as job_id.
4. Stage media if needed, then call instagram_publish_carousel exactly once.
5. Verify status/media_id/permalink. Never claim success unless the MCP confirms publication.
TEXT;
} else {
    $instruction = $common . <<<TEXT


FINAL COMPLETION ROUTE — METRICOOL:
1. Publish ONLY through connected Metricool brand webkitti, brand ID 7005701.
2. Do not use Composio or another fallback publisher.
3. Create exactly one Instagram post/carousel with all media in order.
4. Use autoPublish true and isAiGenerated true when supported.
5. Publish as soon as possible; if a future timestamp is required, use the earliest valid time.
6. If Metricool reports PENDING/PUBLISHING, do not submit another copy. If ERROR/FAILED, report the exact error.
7. Never claim success unless Metricool confirms publication.
TEXT;
}
?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert success"><?= e($notice) ?></div><?php endif; ?>

<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Transport</div>
        <h2>Gmail event trigger</h2>
        <p>The website sends one unique email per scheduled job. ChatGPT Work reacts to the incoming Gmail event.</p>
        <div class="meta-card"><span>Trigger sender</span><strong><?= e((string)$config['mail_from']) ?></strong></div>
        <div class="meta-card"><span>Trigger tag</span><strong>[<?= e((string)$config['message_tag']) ?>]</strong></div>
        <div class="meta-card"><span>Publisher mode</span><strong><?= e(match($mode){'email_bridge'=>'Auto Email Bridge','instagram_mcp'=>'Direct Instagram MCP',default=>'Metricool'}) ?></strong></div>
        <?php if ($mode === 'email_bridge'): ?>
            <div class="meta-card"><span>Result email</span><strong><?= e((string)$config['result_email_to']) ?></strong></div>
            <div class="meta-card"><span>Result tag</span><strong>[<?= e((string)$config['result_subject_tag']) ?>]</strong></div>
        <?php endif; ?>
        <form method="post" style="margin-top:16px">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="test_email">
            <button class="button secondary" type="submit">Send Gmail TEST</button>
        </form>
    </div>

    <div class="card">
        <div class="eyebrow">Important</div>
        <h2>One Work trigger only</h2>
        <p class="muted">Do not create separate ChatGPT clock schedules. Your website owns the 08:00 / 12:30 / 17:30 / 21:30 timing; Work only reacts to matching incoming Gmail jobs.</p>
        <?php if ($mode === 'email_bridge'): ?>
            <p class="muted">The final Gmail result goes back to the website mailbox. Cron reads the attachments and the website publishes through the already-tested Meta Instagram API.</p>
            <a class="text-link" href="bridge.php">Open Auto Bridge status →</a>
        <?php endif; ?>
    </div>
</section>

<section class="card how-card">
    <div class="section-title">
        <div>
            <div class="eyebrow">Creative control</div>
            <h2>Global Trigger Instruction</h2>
        </div>
        <?php if (!$editingInstruction): ?>
            <a class="button primary" href="trigger.php?edit_instruction=1">Change Trigger Instruction</a>
        <?php else: ?>
            <a class="button ghost" href="trigger.php">Cancel</a>
        <?php endif; ?>
    </div>
    <p class="muted">This creative direction is inserted into every future website trigger. It controls the visual/media style while the protected Email Bridge → Meta API completion route stays unchanged.</p>
    <?php if ($instructionUpdatedAt): ?>
        <p class="muted">Last changed: <?= e((new DateTimeImmutable($instructionUpdatedAt))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y, H:i')) ?> IST</p>
    <?php endif; ?>

    <?php if ($editingInstruction): ?>
        <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_instruction">
            <label>Instruction used for future posts
                <textarea name="instruction" rows="28" required><?= e($globalInstruction) ?></textarea>
            </label>
            <div class="quick-actions">
                <button class="button primary" type="submit">Save Trigger Instruction</button>
            </div>
        </form>
        <form method="post" style="margin-top:12px" onsubmit="return confirm('Reset to the built-in WebKitti premium creator-studio instruction?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="reset_instruction">
            <button class="button secondary" type="submit">Reset to WebKitti Default</button>
        </form>
    <?php else: ?>
        <pre><?= e($globalInstruction) ?></pre>
    <?php endif; ?>
</section>

<section class="card how-card">
    <div class="eyebrow">Copy into ChatGPT Work</div>
    <h2>Current Work trigger instruction</h2>
    <p class="muted">This event instruction reads the editable GLOBAL TRIGGER INSTRUCTION from each incoming job, so you do not need to recreate the ChatGPT Work trigger after every visual-style change.</p>
    <pre><?= e($instruction) ?></pre>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
