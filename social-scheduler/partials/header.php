<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? app_config()['app_name'];
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$config = app_config();

$nav = [
    'index.php' => ['label' => 'Dashboard', 'short' => 'Home'],
    'schedules.php' => ['label' => 'Schedules', 'short' => 'Schedules'],
    'trigger.php' => ['label' => 'Work Trigger', 'short' => 'Trigger'],
    'instagram.php' => ['label' => 'Instagram', 'short' => 'Instagram'],
    'bridge.php' => ['label' => 'Auto Bridge', 'short' => 'Bridge'],
    'mcp-status.php' => ['label' => 'MCP', 'short' => 'MCP'],
    'logs.php' => ['label' => 'Logs', 'short' => 'Logs'],
    'setup.php' => ['label' => 'Setup', 'short' => 'Setup'],
];

$pageMeta = [
    'index.php' => ['Dashboard', 'System overview, publisher status and setup health'],
    'schedules.php' => ['Schedules', 'Create, edit and manually run website-controlled jobs'],
    'trigger.php' => ['Work Trigger', 'Gmail event bridge and ChatGPT Work instructions'],
    'instagram.php' => ['Instagram', 'Connect and test the direct Instagram publisher'],
    'bridge.php' => ['Auto Bridge', 'ChatGPT Work result mailbox and automatic Instagram publishing'],
    'mcp-status.php' => ['MCP', 'Legacy remote MCP endpoint and tool status'],
    'logs.php' => ['Logs', 'Recent website-to-Gmail trigger activity'],
    'setup.php' => ['Setup', 'Configuration and server readiness checklist'],
];

[$currentTitle, $currentDescription] = $pageMeta[$currentPage] ?? ['Automation Hub', 'Webkitti social publishing control panel'];
$mode = publisher_mode();
$isDirect = in_array($mode, ['instagram_mcp', 'email_bridge'], true);
$publisherLabel = match ($mode) {
    'email_bridge' => 'Auto Email Bridge',
    'instagram_mcp' => 'Direct Instagram MCP',
    default => 'Metricool Publisher',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#ffffff">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="assets/app.css?v=20260920-3">
</head>
<body>
<div class="app-shell">
    <header class="app-header">
        <div class="header-main">
            <a class="brand" href="index.php" aria-label="Open dashboard">
                <span class="brand-mark">W</span>
                <span class="brand-copy">
                    <strong><?= e((string)$config['app_name']) ?></strong>
                    <small>Social Automation Control</small>
                </span>
            </a>

            <div class="header-actions">
                <span class="publisher-status <?= $isDirect ? 'is-direct' : 'is-metricool' ?>">
                    <span class="status-pulse"></span>
                    <?= e($publisherLabel) ?>
                </span>
                <a class="logout-link" href="?logout=1">Log out</a>
            </div>
        </div>

        <nav class="primary-nav" aria-label="Main navigation">
            <?php foreach ($nav as $href => $item): ?>
                <a href="<?= e($href) ?>" class="nav-link <?= $currentPage === $href ? 'active' : '' ?>">
                    <span class="nav-label"><?= e($item['label']) ?></span>
                    <span class="nav-short"><?= e($item['short']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </header>

    <main class="page-shell">
        <section class="page-head">
            <div>
                <div class="eyebrow">Webkitti Automation</div>
                <h1><?= e($currentTitle) ?></h1>
                <p><?= e($currentDescription) ?></p>
            </div>
            <div class="flow-chip" title="Current automation route">
                <span>Website</span><b>→</b><span>Gmail</span><b>→</b><span>Work</span><b>→</b><span><?= $mode === 'email_bridge' ? 'Email Bridge' : ($mode === 'instagram_mcp' ? 'MCP' : 'Metricool') ?></span>
            </div>
        </section>
