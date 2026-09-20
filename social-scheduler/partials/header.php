<?php
declare(strict_types=1);
$pageTitle = $pageTitle ?? app_config()['app_name'];
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$nav = [
    'index.php' => 'Dashboard',
    'schedules.php' => 'Schedules',
    'trigger.php' => 'Work Trigger',
    'instagram.php' => 'Instagram',
    'mcp-status.php' => 'MCP',
    'logs.php' => 'Logs',
    'setup.php' => 'Setup',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="shell">
    <header class="hub-header">
        <div class="hub-brand">
            <div class="eyebrow">Website → Gmail → ChatGPT Work → Publisher</div>
            <a class="brand-link" href="index.php"><?= e((string)app_config()['app_name']) ?></a>
        </div>
        <div class="hub-header-actions">
            <span class="publisher-pill <?= app_config()['publisher_mode'] === 'instagram_mcp' ? 'direct' : 'metricool' ?>">
                <?= app_config()['publisher_mode'] === 'instagram_mcp' ? 'Direct Instagram MCP' : 'Metricool' ?>
            </span>
            <a class="button small ghost" href="?logout=1">Log out</a>
        </div>
    </header>
    <nav class="hub-nav" aria-label="Main">
        <?php foreach ($nav as $href => $label): ?>
            <a href="<?= e($href) ?>" class="<?= $currentPage === $href ? 'active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <main>
