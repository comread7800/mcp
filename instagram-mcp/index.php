<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    ?>
    <!doctype html>
    <html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Instagram Publisher MCP</title>
    <style>body{font-family:system-ui;background:#f5f7fb;color:#172033;margin:0;padding:40px}.card{max-width:760px;margin:auto;background:#fff;border:1px solid #dde3ed;border-radius:18px;padding:28px;box-shadow:0 8px 30px #17203312}code{background:#f0f3f8;padding:2px 6px;border-radius:6px}</style>
    </head><body><div class="card"><h1>Setup required</h1><p>Copy <code>config.example.php</code> to <code>config.php</code>, fill in your Meta App values and secrets, then reload.</p></div></body></html>
    <?php
    exit;
}

require __DIR__ . '/bootstrap.php';
$config = igmcp_config();
igmcp_session_start();

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'oauth_connect') {
    if (!igmcp_admin_logged_in()) {
        header('Location: index.php');
        exit;
    }
    igmcp_verify_csrf($_GET['csrf'] ?? null);
    $state = bin2hex(random_bytes(24));
    $_SESSION['ig_oauth_state'] = $state;
    $_SESSION['ig_oauth_state_expires'] = time() + 600;
    header('Location: ' . (new InstagramClient())->authorizationUrl($state), true, 302);
    exit;
}

if ($action === 'oauth_callback') {
    if (!igmcp_admin_logged_in()) {
        http_response_code(403);
        exit('Admin session required. Log in to the dashboard and connect Instagram again.');
    }

    $oauthError = trim((string)($_GET['error_description'] ?? $_GET['error'] ?? ''));
    if ($oauthError !== '') {
        $_SESSION['flash_error'] = 'Instagram authorization failed: ' . $oauthError;
        header('Location: index.php');
        exit;
    }

    $state = (string)($_GET['state'] ?? '');
    $expected = (string)($_SESSION['ig_oauth_state'] ?? '');
    $expires = (int)($_SESSION['ig_oauth_state_expires'] ?? 0);
    unset($_SESSION['ig_oauth_state'], $_SESSION['ig_oauth_state_expires']);

    if ($state === '' || $expected === '' || !hash_equals($expected, $state) || time() > $expires) {
        $_SESSION['flash_error'] = 'Instagram OAuth state expired or did not match. Try Connect Instagram again.';
        header('Location: index.php');
        exit;
    }

    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') {
        $_SESSION['flash_error'] = 'Instagram did not return an authorization code.';
        header('Location: index.php');
        exit;
    }

    try {
        $connected = (new InstagramClient())->connectFromAuthorizationCode($code);
        $_SESSION['flash_notice'] = 'Instagram connected: @' . ($connected['username'] ?: $connected['instagram_user_id']);
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

if ($action === 'oauth_disconnect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!igmcp_admin_logged_in()) {
        header('Location: index.php');
        exit;
    }
    igmcp_verify_csrf($_POST['csrf'] ?? null);
    (new InstagramClient())->disconnect();
    $_SESSION['flash_notice'] = 'Instagram connection removed from this server.';
    header('Location: index.php');
    exit;
}

if ($action === 'health') {
    try {
        igmcp_json_response([
            'ok' => true,
            'server' => InstagramMcpServer::SERVER_NAME,
            'version' => InstagramMcpServer::SERVER_VERSION,
            'graph_api_version' => $config['graph_api_version'],
            'instagram' => (new InstagramClient())->connectionStatus(),
        ]);
    } catch (Throwable $e) {
        igmcp_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

$error = null;
$notice = $_SESSION['flash_notice'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_notice'], $_SESSION['flash_error']);

if (isset($_GET['logout'])) {
    igmcp_admin_logout();
    header('Location: index.php');
    exit;
}

if (!igmcp_admin_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (igmcp_admin_login((string)($_POST['password'] ?? ''))) {
        header('Location: index.php');
        exit;
    }
    $error = 'Wrong password, or admin_password is still the default value.';
}

$loggedIn = igmcp_admin_logged_in();
$status = $loggedIn ? (new InstagramClient())->connectionStatus() : null;
$base = rtrim((string)$config['public_base_url'], '/');
$mcpEndpoint = $base . '/mcp.php';
$redirectUri = (string)$config['instagram_redirect_uri'];
$setupWarnings = [];
if (!str_starts_with($base, 'https://')) $setupWarnings[] = 'public_base_url must use HTTPS.';
if ((string)$config['instagram_app_id'] === 'CHANGE_ME') $setupWarnings[] = 'Set instagram_app_id in config.php.';
if ((string)$config['instagram_app_secret'] === 'CHANGE_ME') $setupWarnings[] = 'Set instagram_app_secret in config.php.';
if (str_starts_with((string)$config['mcp_api_key'], 'CHANGE_')) $setupWarnings[] = 'Set a long random mcp_api_key in config.php.';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= igmcp_e((string)$config['app_name']) ?></title>
<style>
:root{color-scheme:light;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#111827}.shell{max-width:1080px;margin:0 auto;padding:32px 18px 60px}.top{display:flex;justify-content:space-between;gap:18px;align-items:center;margin-bottom:22px}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:#667085;font-weight:700}h1{font-size:34px;margin:5px 0 0}h2{margin:0 0 12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}.card{background:#fff;border:1px solid #e1e7ef;border-radius:18px;padding:22px;box-shadow:0 10px 30px rgba(17,24,39,.04);margin-bottom:16px}.button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:11px;padding:11px 15px;background:#111827;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.button.ghost{background:#eef2f7;color:#111827}.button.danger{background:#b42318}.muted{color:#667085}.ok{color:#067647;font-weight:800}.bad{color:#b42318;font-weight:800}.alert{padding:12px 14px;border-radius:10px;margin-bottom:16px}.alert.okay{background:#ecfdf3;color:#067647}.alert.err{background:#fef3f2;color:#b42318}code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}code{background:#f2f4f7;padding:3px 6px;border-radius:6px;word-break:break-all}pre{white-space:pre-wrap;background:#0b1220;color:#dbeafe;padding:16px;border-radius:12px;overflow:auto}.stack{display:grid;gap:12px}input{width:100%;padding:12px 13px;border:1px solid #d0d5dd;border-radius:10px;font:inherit}.list{margin:0;padding-left:19px;display:grid;gap:8px}.statusline{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.pill{display:inline-block;padding:5px 8px;border-radius:999px;background:#eef2ff;font-size:12px;font-weight:700;color:#3538cd}@media(max-width:640px){h1{font-size:28px}.top{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body><main class="shell">
<div class="top"><div><div class="eyebrow">Direct Instagram publishing · no Metricool</div><h1><?= igmcp_e((string)$config['app_name']) ?></h1></div><?php if($loggedIn): ?><a class="button ghost" href="?logout=1">Log out</a><?php endif; ?></div>

<?php if(!$loggedIn): ?>
<section class="card" style="max-width:520px"><h2>Dashboard login</h2><?php if($error): ?><div class="alert err"><?= igmcp_e($error) ?></div><?php endif; ?><form method="post" class="stack"><input type="hidden" name="action" value="login"><input type="password" name="password" required autofocus placeholder="Admin password"><button class="button" type="submit">Open dashboard</button></form></section>
<?php else: ?>
<?php if($notice): ?><div class="alert okay"><?= igmcp_e((string)$notice) ?></div><?php endif; ?>
<?php if($flashError): ?><div class="alert err"><?= igmcp_e((string)$flashError) ?></div><?php endif; ?>
<?php foreach($setupWarnings as $w): ?><div class="alert err"><?= igmcp_e($w) ?></div><?php endforeach; ?>

<section class="grid">
<div class="card"><div class="eyebrow">Instagram</div><h2>Account connection</h2>
<?php if(!empty($status['connected']) && empty($status['error'])): ?>
<p class="statusline"><span class="ok">Connected</span><span class="pill">@<?= igmcp_e((string)($status['username'] ?? '')) ?></span></p>
<p class="muted">Professional account ID: <code><?= igmcp_e((string)($status['instagram_user_id'] ?? '')) ?></code></p>
<p class="muted">Token expiry: <?= igmcp_e((string)($status['expires_at'] ?? 'unknown')) ?></p>
<form method="post" action="?action=oauth_disconnect"><input type="hidden" name="csrf" value="<?= igmcp_e(igmcp_csrf_token()) ?>"><button class="button danger" type="submit">Disconnect Instagram</button></form>
<?php else: ?>
<p class="bad"><?= !empty($status['connected']) ? 'Connection needs attention' : 'Not connected' ?></p>
<?php if(!empty($status['error'])): ?><p class="muted"><?= igmcp_e((string)$status['error']) ?></p><?php endif; ?>
<a class="button" href="?action=oauth_connect&amp;csrf=<?= urlencode(igmcp_csrf_token()) ?>">Connect Instagram</a>
<?php endif; ?>
</div>

<div class="card"><div class="eyebrow">MCP</div><h2>ChatGPT endpoint</h2><p>Use this remote MCP URL after deployment:</p><p><code><?= igmcp_e($mcpEndpoint) ?></code></p><p class="muted">Authentication: <strong>Bearer token</strong> using your <code>mcp_api_key</code>. If a client cannot send a Bearer header, <code>?key=...</code> is supported as a fallback but is less private because URLs can appear in logs.</p></div>
</section>

<section class="card"><div class="eyebrow">Meta App setup</div><h2>One-time configuration</h2><ol class="list"><li>Create/use a Meta Business App and enable <strong>Instagram API with Instagram Login</strong>.</li><li>Use a Business or Creator Instagram account.</li><li>Add this exact OAuth redirect URI in Meta: <code><?= igmcp_e($redirectUri) ?></code></li><li>Request scopes <code>instagram_business_basic</code> and <code>instagram_business_content_publish</code>.</li><li>Click <strong>Connect Instagram</strong> above and approve access.</li><li>Connect <code><?= igmcp_e($mcpEndpoint) ?></code> to ChatGPT as your remote MCP server.</li></ol></section>

<section class="card"><div class="eyebrow">Publishing tools</div><h2>What this MCP exposes</h2><pre>instagram_connection_status
instagram_recent_posts
instagram_publishing_limit
instagram_stage_media
instagram_publish_image
instagram_publish_carousel
instagram_publication_status</pre><p class="muted">Publishing calls require a unique <code>job_id</code>. Reusing the same job ID returns the existing/in-progress result instead of creating an obvious duplicate.</p></section>

<section class="card"><div class="eyebrow">Important</div><h2>Do not remove Metricool from the live Work trigger yet</h2><p>First deploy this folder, connect Instagram, connect the MCP to ChatGPT, and run one direct test post. After direct publishing is verified, change the Work trigger from Metricool to this MCP.</p></section>
<?php endif; ?>
</main></body></html>
