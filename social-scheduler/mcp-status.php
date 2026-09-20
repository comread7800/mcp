<?php
declare(strict_types=1);
require __DIR__ . '/hub.php';
hub_require_login();
require __DIR__ . '/instagram-bootstrap.php';

$config = app_config();
$status = (new InstagramClient())->connectionStatus();
$endpoint = rtrim((string)$config['public_base_url'], '/') . '/mcp.php';
$pageTitle = 'MCP · ' . $config['app_name'];
require __DIR__ . '/partials/header.php';
?>
<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Remote MCP endpoint</div>
        <h2>Direct Instagram Publisher</h2>
        <p><code><?= e($endpoint) ?></code></p>
        <p class="muted">Preferred authentication: <strong>Authorization: Bearer &lt;mcp_api_key&gt;</strong>.</p>
        <p class="muted">Fallback if a client cannot set headers: <code><?= e($endpoint) ?>?key=YOUR_MCP_API_KEY</code></p>
    </div>

    <div class="card">
        <div class="eyebrow">Connection</div>
        <h2><?= !empty($status['connected']) && !empty($status['healthy']) ? 'Instagram ready' : 'Instagram not ready' ?></h2>
        <?php if (!empty($status['connected']) && !empty($status['healthy'])): ?>
            <p><strong>@<?= e((string)($status['username'] ?? '')) ?></strong></p>
            <p class="muted">Professional account ID: <?= e((string)($status['instagram_user_id'] ?? '')) ?></p>
        <?php else: ?>
            <p class="muted"><?= e((string)($status['error'] ?? 'Connect Instagram first.')) ?></p>
            <a class="button primary" href="instagram.php">Open Instagram setup</a>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="eyebrow">Tools exposed to ChatGPT</div>
    <h2>MCP tool list</h2>
    <div class="tool-grid">
        <code>instagram_connection_status</code>
        <code>instagram_recent_posts</code>
        <code>instagram_publishing_limit</code>
        <code>instagram_stage_media</code>
        <code>instagram_publish_image</code>
        <code>instagram_publish_carousel</code>
        <code>instagram_publication_status</code>
    </div>
</section>

<section class="card">
    <div class="eyebrow">Cutover checklist</div>
    <h2>When to remove Metricool</h2>
    <ol class="setup-steps">
        <li>Instagram page shows the correct @webkitti Professional account as connected.</li>
        <li>This MCP endpoint is connected to ChatGPT.</li>
        <li><code>instagram_connection_status</code> succeeds.</li>
        <li>One controlled direct image/carousel test publishes successfully and returns a real Instagram permalink.</li>
        <li>Then change <code>publisher_mode</code> to <code>instagram_mcp</code> and replace the Work instruction with the Direct MCP version shown on the Work Trigger page.</li>
    </ol>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
