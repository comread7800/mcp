<?php
declare(strict_types=1);
require __DIR__ . '/hub.php';
hub_require_login();
require __DIR__ . '/instagram-bootstrap.php';

$config = app_config();
$checks = hub_config_status();
$storageOk = is_dir((string)$config['storage_path']) ? is_writable((string)$config['storage_path']) : is_writable(__DIR__);
$mediaOk = is_dir((string)$config['media_path']) ? is_writable((string)$config['media_path']) : is_writable(__DIR__);
$runtime = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'cURL extension' => function_exists('curl_init'),
    'Fileinfo extension' => class_exists('finfo'),
    'GD image extension (recommended)' => extension_loaded('gd'),
    'Storage writable' => $storageOk,
    'Media writable' => $mediaOk,
];
$cronUrl = rtrim((string)$config['public_base_url'], '/') . '/cron.php?key=YOUR_CRON_SECRET';
$pageTitle = 'Setup · ' . $config['app_name'];
require __DIR__ . '/partials/header.php';
?>
<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Private config</div>
        <h2>config.php checks</h2>
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
        <p class="muted">GD is recommended for converting PNG/WEBP generated images into Instagram-safe JPEG. Existing JPEG media can still work without GD.</p>
    </div>
</section>

<section class="card">
    <div class="eyebrow">Deploy once</div>
    <h2>Single-folder setup order</h2>
    <ol class="setup-steps numbered">
        <li><strong>Upload this complete <code>social-scheduler</code> folder.</strong> Keep your existing private <code>config.php</code> and <code>storage/</code> if you are replacing the old folder.</li>
        <li><strong>Update config.php.</strong> Add the new Instagram/MCP keys shown in <code>config.example.php</code>. Never upload your real config.php to public GitHub.</li>
        <li><strong>Keep the website cron.</strong> It should call <code>cron.php</code> once per minute. Existing schedules remain in <code>storage/prompt-bridge.json</code>.</li>
        <li><strong>Work Trigger page.</strong> Keep one Gmail event trigger in ChatGPT Work. The website owns all schedule times.</li>
        <li><strong>Instagram page.</strong> Configure Meta App + OAuth and connect @webkitti.</li>
        <li><strong>MCP page.</strong> Connect this site's <code>mcp.php</code> endpoint to ChatGPT using the MCP API key.</li>
        <li><strong>Run a controlled direct test.</strong> Verify a real Instagram permalink comes back.</li>
        <li><strong>Only then cut over.</strong> Change <code>publisher_mode</code> from <code>metricool</code> to <code>instagram_mcp</code> and replace the Work instruction using the version shown on the Work Trigger page.</li>
    </ol>
</section>

<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Cron</div>
        <h2>Fallback cron URL pattern</h2>
        <p><code><?= e($cronUrl) ?></code></p>
        <p class="muted">Use your actual private cron secret. Do not share it in chat or screenshots.</p>
    </div>
    <div class="card">
        <div class="eyebrow">Current mode</div>
        <h2><?= $config['publisher_mode'] === 'instagram_mcp' ? 'Direct Instagram MCP' : 'Metricool' ?></h2>
        <p class="muted">This controls the execution rules inserted into every website-generated email job.</p>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
