<?php
declare(strict_types=1);
require __DIR__ . '/hub.php';
hub_require_login();
require __DIR__ . '/instagram-bootstrap.php';

$config = app_config();
$client = new InstagramClient();
$error = null;
$notice = hub_take_flash('notice');

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'oauth_connect') {
    csrf_verify($_GET['csrf'] ?? null);
    $state = bin2hex(random_bytes(24));
    app_start_session();
    $_SESSION['ig_oauth_state'] = $state;
    $_SESSION['ig_oauth_state_expires'] = time() + 600;
    header('Location: ' . $client->authorizationUrl($state), true, 302);
    exit;
}

if ($action === 'oauth_callback') {
    app_start_session();
    $oauthError = trim((string)($_GET['error_description'] ?? $_GET['error'] ?? ''));
    if ($oauthError !== '') {
        $_SESSION['hub_flash_error'] = 'Instagram authorization failed: ' . $oauthError;
        header('Location: instagram.php');
        exit;
    }

    $state = (string)($_GET['state'] ?? '');
    $expected = (string)($_SESSION['ig_oauth_state'] ?? '');
    $expires = (int)($_SESSION['ig_oauth_state_expires'] ?? 0);
    unset($_SESSION['ig_oauth_state'], $_SESSION['ig_oauth_state_expires']);

    if ($state === '' || $expected === '' || !hash_equals($expected, $state) || time() > $expires) {
        $_SESSION['hub_flash_error'] = 'Instagram OAuth state expired or did not match. Click Connect Instagram again.';
        header('Location: instagram.php');
        exit;
    }

    try {
        $code = trim((string)($_GET['code'] ?? ''));
        if ($code === '') throw new RuntimeException('Instagram did not return an authorization code.');
        $connected = $client->connectFromAuthorizationCode($code);
        $_SESSION['hub_flash_notice'] = 'Instagram connected: @' . ($connected['username'] ?: $connected['instagram_user_id']);
    } catch (Throwable $e) {
        $_SESSION['hub_flash_error'] = $e->getMessage();
    }
    header('Location: instagram.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf'] ?? null);

        if ($action === 'disconnect') {
            $client->disconnect();
            hub_set_flash('Instagram connection removed from this server.');
        }

        if ($action === 'direct_test') {
            if (($_POST['confirm_public'] ?? '') !== 'yes') {
                throw new RuntimeException('Confirm that this test will create a public Instagram post.');
            }
            $url = trim((string)($_POST['image_url'] ?? ''));
            $caption = trim((string)($_POST['caption'] ?? ''));
            if ($url === '') throw new RuntimeException('Public HTTPS image URL is required.');
            if ($caption === '') $caption = 'Direct Instagram MCP connectivity test.';
            $jobId = 'MANUAL-DIRECT-TEST-' . app_now()->format('Ymd-His');
            $result = $client->publishImage($jobId, $url, $caption, 'Direct Instagram MCP connectivity test image.');
            $link = (string)($result['permalink'] ?? '');
            hub_set_flash('Direct test published. Media ID: ' . ($result['media_id'] ?? 'unknown') . ($link ? ' · ' . $link : ''));
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = $client->connectionStatus();
$flashError = hub_take_flash('error');
$pageTitle = 'Instagram · ' . $config['app_name'];
require __DIR__ . '/partials/header.php';
?>
<?php if ($notice): ?><div class="alert success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert error"><?= e($flashError) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Direct publisher account</div>
        <h2>Instagram connection</h2>
        <?php if (!empty($status['connected']) && !empty($status['healthy'])): ?>
            <p class="statusline"><span class="status-badge ready">Connected</span><strong>@<?= e((string)($status['username'] ?? '')) ?></strong></p>
            <div class="meta-card"><span>Professional account ID</span><strong><?= e((string)($status['instagram_user_id'] ?? '')) ?></strong></div>
            <div class="meta-card"><span>Media count</span><strong><?= e((string)($status['media_count'] ?? '—')) ?></strong></div>
            <div class="meta-card"><span>Token expiry</span><strong><?= e((string)($status['expires_at'] ?? 'unknown')) ?></strong></div>
            <form method="post" style="margin-top:16px" onsubmit="return confirm('Remove the Instagram token from this server?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="disconnect">
                <button class="button danger-button" type="submit">Disconnect Instagram</button>
            </form>
        <?php else: ?>
            <p class="statusline"><span class="status-badge warn">Not ready</span></p>
            <?php if (!empty($status['error'])): ?><p class="muted"><?= e((string)$status['error']) ?></p><?php endif; ?>
            <a class="button primary" href="?action=oauth_connect&amp;csrf=<?= urlencode(csrf_token()) ?>">Connect Instagram</a>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="eyebrow">Meta App</div>
        <h2>OAuth settings</h2>
        <div class="meta-card"><span>App ID</span><strong><?= $config['instagram_app_id'] && $config['instagram_app_id'] !== 'CHANGE_ME' ? e(substr((string)$config['instagram_app_id'], 0, 5) . '…') : 'Not set' ?></strong></div>
        <div class="meta-card"><span>Graph API</span><strong><?= e((string)$config['graph_api_version']) ?></strong></div>
        <div class="meta-card"><span>Redirect URI</span><strong class="break"><?= e((string)$config['instagram_redirect_uri']) ?></strong></div>
        <p class="muted">Use Instagram API with Instagram Login and a Professional Business/Creator account.</p>
    </div>
</section>

<section class="card">
    <div class="eyebrow">Controlled live test</div>
    <h2>Publish one direct image test</h2>
    <p class="muted">Use this only after the account above is connected. This creates a real public Instagram post directly through Meta, without Metricool.</p>
    <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="direct_test">
        <label>Public HTTPS JPEG image URL
            <input type="url" name="image_url" placeholder="https://your-domain.com/test.jpg" required>
        </label>
        <label>Caption
            <textarea name="caption" rows="4">Direct Instagram MCP connectivity test — direct publishing is working.</textarea>
        </label>
        <label class="confirm-line"><input type="checkbox" name="confirm_public" value="yes" required> I understand this button creates a real public Instagram post.</label>
        <button class="button primary" type="submit" <?= empty($status['connected']) || empty($status['healthy']) ? 'disabled' : '' ?>>Publish direct test</button>
    </form>
</section>

<section class="card">
    <div class="eyebrow">One-time Meta setup</div>
    <h2>Required before Connect Instagram</h2>
    <ol class="setup-steps">
        <li>Create/use a Meta Business App in Meta for Developers.</li>
        <li>Enable <strong>Instagram API with Instagram Login</strong>.</li>
        <li>Add the exact redirect URI shown above.</li>
        <li>Enable/request <code>instagram_business_basic</code> and <code>instagram_business_content_publish</code>.</li>
        <li>Make sure @webkitti is a Business or Creator account and is allowed to test the app while it is in development mode.</li>
        <li>Come back here and click <strong>Connect Instagram</strong>.</li>
    </ol>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
