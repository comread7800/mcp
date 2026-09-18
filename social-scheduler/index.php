<?php
declare(strict_types=1);
require __DIR__ . '/app.php';

$error = null;
$notice = null;
$configError = null;

function redirect_with_notice(string $message): never
{
    app_start_session();
    $_SESSION['flash_notice'] = $message;
    header('Location: index.php', true, 303);
    exit;
}

try {
    $config = app_config();
    app_db();
} catch (Throwable $e) {
    $configError = $e->getMessage();
    $config = ['app_name' => 'Prompt Bridge', 'timezone' => 'Asia/Kolkata'];
}

if ($configError === null) {
    app_start_session();
    if (isset($_SESSION['flash_notice'])) {
        $notice = (string)$_SESSION['flash_notice'];
        unset($_SESSION['flash_notice']);
    }

    if (isset($_GET['logout'])) {
        app_logout();
        header('Location: index.php');
        exit;
    }

    if (!app_is_logged_in()) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
            if (app_login((string)($_POST['password'] ?? ''))) {
                header('Location: index.php');
                exit;
            }
            $error = 'Wrong password, or admin_password is still the default value.';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            csrf_verify($_POST['csrf'] ?? null);
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'save') {
                $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
                save_schedule($_POST, $id);
                redirect_with_notice($id ? 'Schedule updated.' : 'Schedule created.');
            } elseif ($action === 'toggle') {
                toggle_schedule((int)($_POST['id'] ?? 0));
                redirect_with_notice('Schedule status changed.');
            } elseif ($action === 'delete') {
                delete_schedule((int)($_POST['id'] ?? 0));
                redirect_with_notice('Schedule deleted.');
            } elseif ($action === 'run') {
                run_schedule_now((int)($_POST['id'] ?? 0));
                redirect_with_notice('Prompt email sent. ChatGPT Work should receive it through the Gmail event trigger.');
            } elseif ($action === 'test_email') {
                $tag = app_config()['message_tag'];
                email_send('[' . $tag . '] Prompt Bridge TEST', '[' . $tag . "]\nSOURCE: TEST\nPrompt Bridge email connection test. No content should be scheduled for this test message.");
                add_log(null, null, 'sent', 'Email connection test handed to the mail server successfully.');
                redirect_with_notice('Test email sent successfully.');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$loggedIn = $configError === null && app_is_logged_in();
$edit = null;
if ($loggedIn && isset($_GET['edit'])) {
    $edit = get_schedule((int)$_GET['edit']);
}
$selectedDays = $edit ? schedule_days($edit['weekdays']) : [1,2,3,4,5,6,7];
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$lastSchedulerCheck = $loggedIn ? with_state(fn(array $state) => $state['meta']['last_scheduler_check_at'] ?? null) : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($config['app_name']) ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <div>
            <div class="eyebrow">Own scheduler → Email → ChatGPT Work → Metricool</div>
            <h1><?= e($config['app_name']) ?></h1>
        </div>
        <?php if ($loggedIn): ?>
            <a class="button ghost" href="?logout=1">Log out</a>
        <?php endif; ?>
    </header>

    <?php if ($configError): ?>
        <section class="card danger">
            <h2>Setup required</h2>
            <p><?= e($configError) ?></p>
            <p>Copy <code>config.example.php</code> to <code>config.php</code>, fill in the password, email settings and cron secret, then reload.</p>
        </section>
    <?php elseif (!$loggedIn): ?>
        <section class="card auth-card">
            <h2>Dashboard login</h2>
            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="stack">
                <input type="hidden" name="action" value="login">
                <label>Password
                    <input type="password" name="password" autocomplete="current-password" required autofocus>
                </label>
                <button class="button primary" type="submit">Open scheduler</button>
            </form>
        </section>
    <?php else: ?>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="alert success"><?= e($notice) ?></div><?php endif; ?>

        <section class="grid stats">
            <div class="card stat"><span>Timezone</span><strong>Mumbai / IST</strong><small>Asia/Kolkata</small></div>
            <div class="card stat"><span>Active schedules</span><strong><?= count(array_filter(all_schedules(), fn($s) => (int)$s['enabled'] === 1)) ?></strong></div>
            <div class="card stat"><span>Scheduler heartbeat</span><strong><?= $lastSchedulerCheck ? e((new DateTimeImmutable($lastSchedulerCheck))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M, H:i:s')) : 'Not seen yet' ?></strong><small><?= $lastSchedulerCheck ? 'IST' : 'Cron has not called cron.php' ?></small></div>
            <div class="card stat"><span>Trigger transport</span><strong>Email</strong></div>
            <div class="card stat"><span>Publisher</span><strong>Metricool</strong></div>
        </section>

        <section class="card intro">
            <div>
                <div class="eyebrow">Important</div>
                <h2>This site does not use ChatGPT's time scheduler.</h2>
                <p>All schedule times are locked to Mumbai / India Standard Time (IST). Your hosting cron calls the scheduler; jobs never run before the selected time, and if a cron tick is late the scheduler catches up the missed job the same day instead of dropping it.</p>
            </div>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="test_email">
                <button class="button secondary" type="submit">Test Gmail SMTP</button>
            </form>
        </section>

        <section class="two-col">
            <div class="card">
                <div class="section-title">
                    <div>
                        <div class="eyebrow"><?= $edit ? 'Edit schedule' : 'New schedule' ?></div>
                        <h2><?= $edit ? e($edit['name']) : 'Create automation' ?></h2>
                    </div>
                    <?php if ($edit): ?><a class="text-link" href="index.php">Cancel edit</a><?php endif; ?>
                </div>

                <form method="post" class="stack">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">

                    <label>Schedule name
                        <input name="name" maxlength="120" required value="<?= e($edit['name'] ?? '') ?>" placeholder="Morning AI tool post">
                    </label>

                    <label>Prompt sent to ChatGPT Work
                        <textarea name="prompt" rows="10" required placeholder="Research one new useful AI tool. Create a detailed Instagram carousel and caption. Keep it practical, complete and non-repetitive."><?= e($edit['prompt'] ?? '') ?></textarea>
                    </label>

                    <div class="grid form-grid">
                        <label>Send prompt at
                            <input type="time" name="trigger_time" required value="<?= e($edit['trigger_time'] ?? '08:00') ?>">
                        </label>
                    </div>

                    <fieldset>
                        <legend>Run on</legend>
                        <div class="days">
                            <?php foreach ($dayNames as $num => $name): ?>
                                <label class="day-pill">
                                    <input type="checkbox" name="weekdays[]" value="<?= $num ?>" <?= in_array($num, $selectedDays, true) ? 'checked' : '' ?>>
                                    <span><?= e($name) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <button class="button primary" type="submit"><?= $edit ? 'Save changes' : 'Create schedule' ?></button>
                </form>
            </div>

            <div class="card how-card">
                <div class="eyebrow">One-time ChatGPT setup</div>
                <h2>Work trigger instruction</h2>
                <p>Create a Gmail event trigger in ChatGPT Work for new incoming emails whose subject starts with the automation tag. Use an instruction like this:</p>
                <pre>When a new Gmail message has a subject starting with [<?= e($config['message_tag']) ?>], read the email body and execute the PROMPT completely. Create the required Instagram content and media, then publish it as soon as it is ready using my connected Metricool plugin. Do not create a ChatGPT time-based schedule. If Metricool requires a future publication time, use the earliest valid time with autoPublish enabled. Ignore messages marked SOURCE: TEST.</pre>
                <p class="muted">After this one-time setup, the website controls timing. You do not need to open this chat for each post.</p>
            </div>
        </section>

        <section class="card">
            <div class="section-title">
                <div>
                    <div class="eyebrow">Automation queue</div>
                    <h2>Your schedules</h2>
                </div>
            </div>

            <?php $schedules = all_schedules(); ?>
            <?php if (!$schedules): ?>
                <div class="empty">No schedules yet.</div>
            <?php else: ?>
                <div class="schedule-list">
                    <?php foreach ($schedules as $schedule): ?>
                        <article class="schedule <?= (int)$schedule['enabled'] === 1 ? '' : 'disabled' ?>">
                            <div class="schedule-main">
                                <div class="schedule-topline">
                                    <strong><?= e($schedule['name']) ?></strong>
                                    <span class="badge <?= (int)$schedule['enabled'] === 1 ? 'on' : 'off' ?>"><?= (int)$schedule['enabled'] === 1 ? 'Enabled' : 'Paused' ?></span>
                                </div>
                                <p><?= e(app_excerpt($schedule['prompt'], 180)) ?></p>
                                <div class="meta">
                                    <span>Trigger <?= e($schedule['trigger_time']) ?></span>
                                    <span>Publish: immediately after creation</span>
                                    <span><?= e(implode(' · ', array_map(fn($d) => $dayNames[$d], schedule_days($schedule['weekdays'])))) ?></span>
                                </div>
                            </div>
                            <div class="actions">
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="run">
                                    <input type="hidden" name="id" value="<?= (int)$schedule['id'] ?>">
                                    <button class="button small primary" type="submit">Run now</button>
                                </form>
                                <a class="button small ghost" href="?edit=<?= (int)$schedule['id'] ?>">Edit</a>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$schedule['id'] ?>">
                                    <button class="button small ghost" type="submit"><?= (int)$schedule['enabled'] === 1 ? 'Pause' : 'Enable' ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('Delete this schedule?');">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$schedule['id'] ?>">
                                    <button class="button small danger-button" type="submit">Delete</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="eyebrow">Delivery log</div>
            <h2>Recent runs</h2>
            <?php $logs = recent_logs(); ?>
            <?php if (!$logs): ?>
                <div class="empty">No runs yet.</div>
            <?php else: ?>
                <div class="log-list">
                    <?php foreach ($logs as $log): ?>
                        <div class="log-row">
                            <span class="status-dot <?= e($log['status']) ?>"></span>
                            <div>
                                <strong><?= e($log['schedule_name'] ?? 'System') ?></strong>
                                <p><?= e($log['message']) ?></p>
                            </div>
                            <time><?= e((new DateTimeImmutable($log['created_at']))->format('d M, H:i')) ?></time>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
</body>
</html>
