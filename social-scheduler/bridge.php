<?php
declare(strict_types=1);

require __DIR__ . '/hub.php';
hub_require_login();
require __DIR__ . '/result-bootstrap.php';

$config = app_config();
$error = null;
$notice = hub_take_flash('notice');
$checkResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'activate') {
            $status = (new InstagramClient())->connectionStatus();
            if (empty($status['connected']) || empty($status['healthy'])) {
                throw new RuntimeException('Direct Instagram connection is not healthy yet. Open Instagram page first.');
            }

            $runtimeChecks = [
                'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
                'cURL extension' => function_exists('curl_init'),
                'OpenSSL/SSL streams' => extension_loaded('openssl') && in_array('ssl', stream_get_transports(), true),
                'Fileinfo extension' => class_exists('finfo'),
                'Mbstring extension' => extension_loaded('mbstring'),
                'GD image extension' => extension_loaded('gd'),
                'Storage writable' => is_dir((string)$config['storage_path']) && is_writable((string)$config['storage_path']),
                'Media writable' => is_dir((string)$config['media_path']) && is_writable((string)$config['media_path']),
                'Public HTTPS URL' => str_starts_with((string)$config['public_base_url'], 'https://'),
            ];
            $missing = array_keys(array_filter($runtimeChecks, static fn(bool $ok): bool => !$ok));
            if ($missing) {
                throw new RuntimeException('Server readiness failed: ' . implode(', ', $missing) . '. Open Setup and fix these checks first.');
            }

            (new ResultMailbox())->testConnection();
            set_publisher_mode('email_bridge');
            hub_set_flash('Final auto mode activated: Website → Gmail → ChatGPT Work → Gmail result → Direct Instagram.');
        }

        if ($action === 'metricool') {
            set_publisher_mode('metricool');
            hub_set_flash('Publisher switched back to Metricool.');
        }

        if ($action === 'test_mailbox') {
            $checkResult = ['mailbox' => (new ResultMailbox())->testConnection()];
        }

        if ($action === 'check_now') {
            if (publisher_mode() !== 'email_bridge') {
                throw new RuntimeException('Activate Final Auto Mode before processing result emails.');
            }
            @set_time_limit(240);
            $checkResult = (new ResultProcessor())->process((int)$config['result_max_messages_per_run']);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$mode = publisher_mode();
$igStatus = (new InstagramClient())->connectionStatus();
$pageTitle = 'Auto Bridge · ' . $config['app_name'];
require __DIR__ . '/partials/header.php';
?>
<?php if ($notice): ?><div class="alert success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Final automatic route</div>
        <h2><?= $mode === 'email_bridge' ? 'Auto Bridge is active' : 'Auto Bridge is not active' ?></h2>
        <div class="flowline">
            <span>Website</span><b>→</b><span>Gmail trigger</span><b>→</b><span>ChatGPT Work</span><b>→</b><span>Gmail result</span><b>→</b><span>Meta API</span><b>→</b><span>Instagram</span>
        </div>
        <p class="muted">Once active, the website cron sends scheduled jobs and also checks the result mailbox every minute. Final slide attachments are normalized to Instagram-safe 4:5 JPEGs before publishing.</p>
        <?php if ($mode !== 'email_bridge'): ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="activate">
                <button class="button primary" type="submit">Activate Final Auto Mode</button>
            </form>
        <?php else: ?>
            <span class="status-badge ready">Active</span>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="eyebrow">Readiness</div>
        <h2>Connection status</h2>
        <div class="meta-card"><span>Instagram</span><strong><?= !empty($igStatus['connected']) && !empty($igStatus['healthy']) ? '@' . e((string)($igStatus['username'] ?? 'connected')) . ' · ready' : 'Not ready' ?></strong></div>
        <div class="meta-card"><span>Result mailbox</span><strong><?= e((string)$config['imap_username']) ?></strong></div>
        <div class="meta-card"><span>Trusted result sender</span><strong><?= e((string)$config['result_email_from']) ?></strong></div>
        <div class="meta-card"><span>Result subject tag</span><strong>[<?= e((string)$config['result_subject_tag']) ?>]</strong></div>
        <div class="quick-actions">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="test_mailbox">
                <button class="button secondary" type="submit">Test mailbox login</button>
            </form>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="check_now">
                <button class="button ghost" type="submit">Check result inbox now</button>
            </form>
        </div>
    </div>
</section>

<?php if ($checkResult !== null): ?>
<section class="card how-card">
    <div class="eyebrow">Last manual check</div>
    <h2>Bridge response</h2>
    <pre><?= e(json_encode($checkResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') ?></pre>
</section>
<?php endif; ?>

<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Expected Work result</div>
        <h2>What ChatGPT sends back</h2>
        <p>Exactly one Gmail message to <strong><?= e((string)$config['result_email_to']) ?></strong> with subject beginning <code>[<?= e((string)$config['result_subject_tag']) ?>]</code>.</p>
        <p class="muted">The caption is between CAPTION_BEGIN / CAPTION_END. Final images are attached as slide-01.jpg, slide-02.jpg, etc. The website handles the Instagram API publish.</p>
        <a class="text-link" href="trigger.php">View exact Work instruction →</a>
    </div>

    <div class="card">
        <div class="eyebrow">Emergency fallback</div>
        <h2>Switch back without deleting anything</h2>
        <p class="muted">If the result mailbox is temporarily unavailable, you can switch publishing back to Metricool. Schedules, Instagram connection and stored state remain intact.</p>
        <?php if ($mode !== 'metricool'): ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="metricool">
            <button class="button ghost" type="submit">Switch to Metricool</button>
        </form>
        <?php else: ?><span class="status-badge warn">Metricool active</span><?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
