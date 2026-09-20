<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

function hub_handle_logout(): void
{
    if (isset($_GET['logout'])) {
        app_logout();
        header('Location: index.php');
        exit;
    }
}

function hub_require_login(): void
{
    hub_handle_logout();
    if (!app_is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function hub_set_flash(string $message, string $type = 'notice'): never
{
    app_start_session();
    $_SESSION['hub_flash_' . $type] = $message;
    $target = basename((string)($_SERVER['PHP_SELF'] ?? 'index.php'));
    header('Location: ' . $target, true, 303);
    exit;
}

function hub_take_flash(string $type = 'notice'): ?string
{
    app_start_session();
    $key = 'hub_flash_' . $type;
    if (!isset($_SESSION[$key])) {
        return null;
    }
    $message = (string)$_SESSION[$key];
    unset($_SESSION[$key]);
    return $message;
}

function hub_last_scheduler_check(): ?string
{
    return with_state(fn(array $state) => $state['meta']['last_scheduler_check_at'] ?? null);
}

function hub_config_status(): array
{
    $c = app_config();
    $checks = [];

    $checks['Dashboard password'] = $c['admin_password'] !== '' && $c['admin_password'] !== 'CHANGE_ME_NOW';
    $checks['Gmail recipient'] = filter_var($c['mail_to'], FILTER_VALIDATE_EMAIL) !== false;
    $checks['Gmail SMTP user'] = filter_var($c['smtp_username'], FILTER_VALIDATE_EMAIL) !== false;
    $checks['Gmail App Password'] = $c['smtp_app_password'] !== '' && !str_contains($c['smtp_app_password'], 'CHANGE');
    $checks['Cron secret'] = $c['cron_secret'] !== '' && !str_contains($c['cron_secret'], 'CHANGE');
    $checks['Public HTTPS URL'] = str_starts_with((string)$c['public_base_url'], 'https://');
    $checks['Meta App ID'] = $c['instagram_app_id'] !== '' && $c['instagram_app_id'] !== 'CHANGE_ME';
    $checks['Meta App Secret'] = $c['instagram_app_secret'] !== '' && $c['instagram_app_secret'] !== 'CHANGE_ME';
    $checks['Instagram redirect URI'] = str_starts_with((string)$c['instagram_redirect_uri'], 'https://');
    $checks['Result mailbox recipient'] = filter_var($c['result_email_to'], FILTER_VALIDATE_EMAIL) !== false;
    $checks['Trusted result sender'] = filter_var($c['result_email_from'], FILTER_VALIDATE_EMAIL) !== false;
    $checks['IMAP mailbox login'] = filter_var($c['imap_username'], FILTER_VALIDATE_EMAIL) !== false
        && $c['imap_app_password'] !== '' && !str_contains($c['imap_app_password'], 'CHANGE');

    return $checks;
}
