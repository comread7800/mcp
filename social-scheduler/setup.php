<?php
declare(strict_types=1);

require __DIR__ . '/hub.php';
hub_require_login();
require __DIR__ . '/result-bootstrap.php';

$config = app_config();
$checks = hub_config_status();
$storageOk = is_dir((string)$config['storage_path']) ? is_writable((string)$config['storage_path']) : is_writable(__DIR__);
$mediaOk = is_dir((string)$config['media_path']) ? is_writable((string)$config['media_path']) : is_writable(__DIR__);
$runtime = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'cURL extension' => function_exists('curl_init'),
    'OpenSSL/SSL streams' => extension_loaded('openssl') && in_array('ssl', stream_get_transports(), true),
    'Fileinfo extension' => class_exists('finfo'),
    'Mbstring extension' => extension_loaded('mbstring'),
    'GD image extension' => extension_loaded('gd'),
    'Storage writable' => $storageOk,
    'Media writable' => $mediaOk,
];
$cronUrl = rtrim((string)$config['public_base_url'], '/') . '/cron.php?key=YOUR_CRON_SECRET';
$mode = publisher_mode();
$igStatus = (new InstagramClient())->connectionStatus();
$pageTitle = 'Setup · ' . $config['app_name'];
require __DIR__ . '/partials/header.php';
?>
<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Private config</div>
        <h2>Configuration checks</h2>
        <ul class="check-list">
            <?php foreach ($checks as $label => $ok): ?><li class="<?= $ok ? 'ok' : 'bad' ?>"><span><?= $ok ? '✓' : '!' ?></span><?= e($label) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <div class="card">
        <div class="eyebrow">Server runtime</div>
        <h2>Hostinger checks</h2>
        <ul class="check-list">
            <?php foreach ($runtime as $label => $ok): ?><li class="<?= $ok ? 'ok' : 'bad' ?>"><span><?= $ok ? '✓' : '!' ?></span><?= e($label) ?></li><?php endforeach; ?>
        </ul>
        <p class="muted">GD is used to normalize result attachments to 1080x1350 when needed. Mbstring is required for safe Instagram caption/alt-text length handling. OpenSSL streams are used to read the Gmail result inbox over IMAP without requiring the PHP IMAP extension.</p>
    </div>
</section>

<section class="card">
    <div class="eyebrow">Final one-time setup</div>
    <h2>After this, daily posting is automatic</h2>
    <ol class="setup-steps numbered">
        <li><strong>Deploy this complete <code>social-scheduler</code> folder.</strong> Keep your existing private <code>config.php</code> and <code>storage/</code>.</li>
        <li><strong>Keep the current Gmail SMTP/App Password.</strong> By default the result reader reuses the same Gmail username and App Password over <code>imap.gmail.com:993</code>.</li>
        <li><strong>Keep the Hostinger cron running once per minute.</strong> The same <code>cron.php</code> now does two jobs: sends due website schedules and checks completed ChatGPT result emails.</li>
        <li><strong>Instagram must show connected/healthy.</strong> Your successful direct Meta API test already proves the publishing path.</li>
        <li><strong>Open Auto Bridge.</strong> Test the mailbox login, then click <em>Activate Final Auto Mode</em>.</li>
        <li><strong>Update the single ChatGPT Work Gmail-event instruction once.</strong> Copy the exact Email Bridge instruction from the Work Trigger page. Do not create ChatGPT clock schedules.</li>
        <li><strong>Run one full end-to-end test.</strong> Use Run now on a schedule. Work should send a <code>[<?= e((string)$config['result_subject_tag']) ?>]</code> email with final slide attachments. The next cron run publishes it directly to Instagram.</li>
    </ol>
</section>

<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Current final route</div>
        <h2><?= e(match($mode){'email_bridge'=>'Auto Email Bridge','instagram_mcp'=>'Direct MCP',default=>'Metricool'}) ?></h2>
        <div class="meta-card"><span>Instagram</span><strong><?= !empty($igStatus['connected']) && !empty($igStatus['healthy']) ? '@' . e((string)($igStatus['username'] ?? 'connected')) . ' · ready' : 'Not ready' ?></strong></div>
        <div class="meta-card"><span>Result inbox</span><strong><?= e((string)$config['imap_username']) ?></strong></div>
        <div class="meta-card"><span>Expected result sender</span><strong><?= e((string)$config['result_email_from']) ?></strong></div>
        <a class="button primary" href="bridge.php">Open Auto Bridge</a>
    </div>
    <div class="card">
        <div class="eyebrow">Cron</div>
        <h2>Fallback cron URL pattern</h2>
        <p><code><?= e($cronUrl) ?></code></p>
        <p class="muted">Use your actual private cron secret. Do not share it in chat or screenshots. PHP CLI cron is still preferable when Hostinger provides it.</p>
    </div>
</section>

<section class="card">
    <div class="eyebrow">What is no longer required</div>
    <h2>No Metricool and no ChatGPT custom MCP required in final mode</h2>
    <p class="muted">The final bridge uses Gmail only as the return transport for ChatGPT's completed caption and image attachments. Your website performs the actual Instagram publish with the Meta API connection already stored on the server.</p>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
