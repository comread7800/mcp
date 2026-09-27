<?php
declare(strict_types=1);
require __DIR__ . '/hub.php';
hub_require_login();

$error = null;
$notice = hub_take_flash('notice');
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf'] ?? null);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save') {
            $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
            save_schedule($_POST, $id);
            hub_set_flash($id ? 'Schedule updated.' : 'Schedule created.');
        }
        if ($action === 'toggle') {
            toggle_schedule((int)($_POST['id'] ?? 0));
            hub_set_flash('Schedule status changed.');
        }
        if ($action === 'delete') {
            delete_schedule((int)($_POST['id'] ?? 0));
            hub_set_flash('Schedule deleted.');
        }
        if ($action === 'run') {
            run_schedule_now((int)($_POST['id'] ?? 0));
            hub_set_flash('Prompt email sent. ChatGPT Work should receive this unique job.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$edit = isset($_GET['edit']) ? get_schedule((int)$_GET['edit']) : null;
$selectedDays = $edit ? schedule_days((string)$edit['weekdays']) : [1,2,3,4,5,6,7];
$pageTitle = 'Schedules · ' . app_config()['app_name'];
require __DIR__ . '/partials/header.php';
?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert success"><?= e($notice) ?></div><?php endif; ?>

<section class="two-col">
    <div class="card">
        <div class="section-title">
            <div><div class="eyebrow"><?= $edit ? 'Edit schedule' : 'New schedule' ?></div><h2><?= $edit ? e((string)$edit['name']) : 'Create automation' ?></h2></div>
            <?php if ($edit): ?><a class="text-link" href="schedules.php">Cancel edit</a><?php endif; ?>
        </div>
        <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
            <label>Schedule name
                <input name="name" maxlength="120" required value="<?= e((string)($edit['name'] ?? '')) ?>" placeholder="Morning Tech Brief">
            </label>
            <label>Prompt sent to ChatGPT Work
                <textarea name="prompt" rows="12" required placeholder="Research the newest verified technology news and create a complete Hindi Instagram carousel..."><?= e((string)($edit['prompt'] ?? '')) ?></textarea>
            </label>
            <label>Send prompt at (IST)
                <input type="time" name="trigger_time" required value="<?= e((string)($edit['trigger_time'] ?? '08:00')) ?>">
            </label>
            <fieldset>
                <legend>Run on</legend>
                <div class="days">
                    <?php foreach ($dayNames as $num => $name): ?>
                        <label class="day-pill"><input type="checkbox" name="weekdays[]" value="<?= $num ?>" <?= in_array($num, $selectedDays, true) ? 'checked' : '' ?>><span><?= e($name) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <button class="button primary" type="submit"><?= $edit ? 'Save changes' : 'Create schedule' ?></button>
        </form>
    </div>

    <div class="card">
        <div class="eyebrow">How timing works</div>
        <h2>Website owns the schedule</h2>
        <p>Your Hostinger cron calls <code>cron.php</code>. When a schedule is due, this site sends a unique Gmail job to ChatGPT Work. ChatGPT does not own the clock.</p>
        <p class="muted">Current publisher mode: <strong><?= e(match(publisher_mode()){'email_bridge'=>'Auto Email Bridge','instagram_mcp'=>'Direct Instagram MCP',default=>'Metricool'}) ?></strong>. The website inserts the correct completion route into every trigger email automatically.</p>
        <p class="muted">Recommended times: 08:00, 12:30, 17:30, 21:30 IST.</p>
        <div class="quick-actions">
            <a class="button primary" href="trigger.php?edit_instruction=1">Change Trigger Instruction</a>
            <a class="button secondary" href="trigger.php">View Work Trigger</a>
        </div>
    </div>
</section>

<section class="card">
    <div class="section-title"><div><div class="eyebrow">Automation queue</div><h2>Your schedules</h2></div></div>
    <?php $schedules = all_schedules(); ?>
    <?php if (!$schedules): ?><div class="empty">No schedules yet.</div>
    <?php else: ?><div class="schedule-list">
        <?php foreach ($schedules as $schedule): ?>
        <article class="schedule <?= (int)$schedule['enabled'] === 1 ? '' : 'disabled' ?>">
            <div class="schedule-main">
                <div class="schedule-topline"><strong><?= e((string)$schedule['name']) ?></strong><span class="badge <?= (int)$schedule['enabled'] === 1 ? 'on' : 'off' ?>"><?= (int)$schedule['enabled'] === 1 ? 'Enabled' : 'Paused' ?></span></div>
                <p><?= e(app_excerpt((string)$schedule['prompt'], 220)) ?></p>
                <div class="meta"><span>Trigger <?= e((string)$schedule['trigger_time']) ?> IST</span><span><?= e(implode(' · ', array_map(fn($d) => $dayNames[$d], schedule_days((string)$schedule['weekdays'])))) ?></span></div>
            </div>
            <div class="actions">
                <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="run"><input type="hidden" name="id" value="<?= (int)$schedule['id'] ?>"><button class="button small primary" type="submit">Run now</button></form>
                <a class="button small ghost" href="?edit=<?= (int)$schedule['id'] ?>">Edit</a>
                <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$schedule['id'] ?>"><button class="button small ghost" type="submit"><?= (int)$schedule['enabled'] === 1 ? 'Pause' : 'Enable' ?></button></form>
                <form method="post" onsubmit="return confirm('Delete this schedule?');"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$schedule['id'] ?>"><button class="button small danger-button" type="submit">Delete</button></form>
            </div>
        </article>
        <?php endforeach; ?>
    </div><?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
