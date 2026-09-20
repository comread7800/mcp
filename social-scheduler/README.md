# Webkitti Automation Hub

One deployable PHP folder for the full automation control panel.

```text
Website schedules -> Gmail -> ChatGPT Work -> Publisher -> Instagram
```

The publisher can be switched in `config.php`:

```php
'publisher_mode' => 'metricool',
```

or, after a successful direct test:

```php
'publisher_mode' => 'instagram_mcp',
```

## Pages

- `index.php` — dashboard
- `schedules.php` — create/edit/run schedules
- `trigger.php` — Gmail + ChatGPT Work trigger instructions
- `instagram.php` — Meta/Instagram OAuth connection + controlled direct test
- `mcp-status.php` — remote MCP endpoint and tools
- `logs.php` — website Gmail delivery logs
- `setup.php` — complete setup checklist
- `cron.php` — Hostinger scheduler endpoint
- `health.php` — basic website health endpoint
- `mcp.php` — direct Instagram remote MCP endpoint

## Upgrade from the old social-scheduler folder

Upload/replace the code files but **keep your existing private `config.php` and `storage/` folder** so existing Gmail credentials, cron secret, and schedules remain intact.

Then add the new keys from `config.example.php` into your existing `config.php`:

- `publisher_mode`
- `public_base_url`
- `instagram_app_id`
- `instagram_app_secret`
- `instagram_redirect_uri`
- `instagram_scopes`
- `graph_api_version`
- `mcp_api_key`
- `allowed_origins`
- `media_path`
- `media_max_bytes`

Never commit or share the real `config.php`.

## Safe cutover

Keep `publisher_mode = metricool` until:

1. Meta App is configured.
2. @webkitti is connected on `instagram.php`.
3. `mcp.php` is connected to ChatGPT.
4. A controlled direct Instagram test returns a real published media ID/permalink.

Then switch to `instagram_mcp` and update the Work trigger using the Direct MCP instruction shown on `trigger.php`.
