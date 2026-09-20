<?php
declare(strict_types=1);

require __DIR__ . '/hub.php';

$configError = null;
$error = null;
try {
    $config = app_config();
    app_db();
} catch (Throwable $e) {
    $configError = $e->getMessage();
    $config = ['app_name' => 'Webkitti Automation Hub'];
}

if ($configError === null) {
    hub_handle_logout();

    if (!app_is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
        if (app_login((string)($_POST['password'] ?? ''))) {
            header('Location: index.php');
            exit;
        }
        $error = 'Wrong password, or admin_password is still the default value.';
    }
}

$loggedIn = $configError === null && app_is_logged_in();

if (!$loggedIn):
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e((string)$config['app_name']) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="shell">
    <section class="card auth-card">
        <div class="eyebrow">One folder · complete automation control</div>
        <h2><?= e((string)$config['app_name']) ?></h2>
        <?php if ($configError): ?>
            <div class="alert error"><?= e($configError) ?></div>
            <p>Copy <code>config.example.php</code> to <code>config.php</code>, fill your private values, then reload.</p>
        <?php else: ?>
            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="stack">
                <input type="hidden" name="action" value="login">
                <label>Password
                    <input type="password" name="password" autocomplete="current-password" required autofocus>
                </label>
                <button class="button primary" type="submit">Open Automation Hub</button>
            </form>
        <?php endif; ?>
    </section>
</div>
</body></html>
<?php exit; endif;

$pageTitle = 'Dashboard · ' . app_config()['app_name'];
require __DIR__ . '/instagram-bootstrap.php';
$igStatus = (new InstagramClient())->connectionStatus();
$schedules = all_schedules();
$activeSchedules = count(array_filter($schedules, fn(array $s): bool => (int)$s['enabled'] === 1));
$lastCheck = hub_last_scheduler_check();
$checks = hub_config_status();
$readyCount = count(array_filter($checks));
$totalChecks = count($checks);
require __DIR__ . '/partials/header.php';
?>
<?php if ($notice = hub_take_flash('notice')): ?><div class="alert success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($err = hub_take_flash('error')): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<section class="grid stats hub-stats">
    <div class="card stat"><span>Active schedules</span><strong><?= $activeSchedules ?></strong><small>Website controls timing</small></div>
    <div class="card stat"><span>Scheduler heartbeat</span><strong><?= $lastCheck ? e((new DateTimeImmutable($lastCheck))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M, H:i')) : 'Not seen' ?></strong><small>IST</small></div>
    <div class="card stat"><span>Instagram</span><strong><?= !empty($igStatus['connected']) && !empty($igStatus['healthy']) ? '@' . e((string)($igStatus['username'] ?? 'connected')) : 'Not ready' ?></strong><small>Direct MCP account</small></div>
    <div class="card stat"><span>Publisher mode</span><strong><?= app_config()['publisher_mode'] === 'instagram_mcp' ? 'Direct MCP' : 'Metricool' ?></strong><small>Set in config.php</small></div>
</section>

<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Main flow</div>
        <h2>Automation pipeline</h2>
        <div class="flowline">
            <span>Website schedule</span><b>→</b><span>Gmail</span><b>→</b><span>ChatGPT Work</span><b>→</b>
            <span><?= app_config()['publisher_mode'] === 'instagram_mcp' ? 'Direct Instagram MCP' : 'Metricool' ?></span><b>→</b><span>Instagram</span>
        </div>
        <p class="muted">Use the menu above for schedules, Work trigger instructions, Instagram account connection, MCP endpoint, logs, and full setup checks.</p>
        <div class="quick-actions">
            <a class="button primary" href="schedules.php">Manage schedules</a>
            <a class="button secondary" href="setup.php">Open setup checklist</a>
        </div>
    </div>

    <div class="card">
        <div class="eyebrow">Setup health</div>
        <h2><?= $readyCount ?>/<?= $totalChecks ?> checks ready</h2>
        <div class="progress"><span style="width:<?= (int)round(($readyCount / max(1, $totalChecks)) * 100) ?>%"></span></div>
        <ul class="check-list compact">
            <?php foreach ($checks as $label => $ok): ?>
                <li class="<?= $ok ? 'ok' : 'bad' ?>"><span><?= $ok ? '✓' : '!' ?></span><?= e($label) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="grid dashboard-grid">
    <div class="card">
        <div class="eyebrow">Direct Instagram</div>
        <h2><?= !empty($igStatus['connected']) && !empty($igStatus['healthy']) ? 'Connected and healthy' : 'Connection not ready' ?></h2>
        <?php if (!empty($igStatus['connected']) && !empty($igStatus['healthy'])): ?>
            <p>Account: <strong>@<?= e((string)($igStatus['username'] ?? '')) ?></strong></p>
            <p class="muted">Token expiry: <?= e((string)($igStatus['expires_at'] ?? 'unknown')) ?></p>
        <?php else: ?>
            <p class="muted"><?= e((string)($igStatus['error'] ?? 'Open Instagram setup and connect your Professional account.')) ?></p>
        <?php endif; ?>
        <a class="text-link" href="instagram.php">Open Instagram setup →</a>
    </div>

    <div class="card">
        <div class="eyebrow">Safe cutover</div>
        <h2>Keep Metricool until direct test passes</h2>
        <p class="muted">Deploy this folder, connect Instagram, connect the MCP to ChatGPT, publish one controlled direct test, verify the final Instagram URL, then switch <code>publisher_mode</code> to <code>instagram_mcp</code> and update the Work trigger.</p>
        <a class="text-link" href="mcp-status.php">View MCP endpoint →</a>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
