<?php
declare(strict_types=1);
require __DIR__ . '/hub.php';
hub_require_login();
$pageTitle = 'Logs · ' . app_config()['app_name'];
require __DIR__ . '/partials/header.php';
?>
<section class="card">
    <div class="eyebrow">Website delivery log</div>
    <h2>Recent automation and publishing runs</h2>
    <p class="muted">This log shows Gmail trigger delivery plus Email Bridge / Meta Instagram publishing progress and exact final errors.</p>
    <?php $logs = recent_logs(100); ?>
    <?php if (!$logs): ?><div class="empty">No runs yet.</div>
    <?php else: ?><div class="log-list">
        <?php foreach ($logs as $log): ?>
        <div class="log-row">
            <span class="status-dot <?= e((string)$log['status']) ?>"></span>
            <div><strong><?= e((string)($log['schedule_name'] ?? 'System')) ?></strong><p><?= e((string)$log['message']) ?></p><small class="muted"><?= e((string)($log['run_key'] ?? 'manual')) ?></small></div>
            <time><?= e((new DateTimeImmutable((string)$log['created_at']))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M, H:i:s')) ?> IST</time>
        </div>
        <?php endforeach; ?>
    </div><?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
