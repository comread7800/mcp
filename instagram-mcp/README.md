# Instagram Publisher MCP (PHP / Hostinger)

Direct Instagram publishing for the existing Website -> Gmail -> ChatGPT Work automation. This server is designed to replace the final Metricool step **only after a direct test succeeds**.

```text
Website schedule -> Gmail event -> ChatGPT Work -> research/media/caption
                                             -> this MCP -> Instagram API -> Instagram
```

It uses Meta's official Instagram API with Instagram Login. The Instagram password is never stored. OAuth access tokens stay server-side in `storage/`.

## Platform settings

- Graph API: `v26.0` (configured in `config.php`).
- Required scopes: `instagram_business_basic`, `instagram_business_content_publish`.
- Instagram account: Professional account (Business or Creator).
- Instagram Login flow does not require a linked Facebook Page.
- API carousel: 2-10 items.
- Feed media must be reachable by Meta through a public HTTPS URL.
- The media staging tool produces JPEG files and converts PNG/WEBP when PHP GD is available.

## Files

```text
instagram-mcp/
├── .gitignore
├── .htaccess
├── README.md
├── bootstrap.php
├── config.example.php
├── index.php
├── mcp.php
├── lib/
│   ├── InstagramClient.php
│   ├── McpServer.php
│   ├── MediaStager.php
│   └── Store.php
└── media/
    └── .htaccess
```

`config.php`, OAuth token state, event logs and staged post images are intentionally not committed.

## 1. Deploy on Hostinger

Upload the complete `instagram-mcp` folder under your HTTPS site, for example:

```text
public_html/instagram-mcp/
```

Copy:

```text
config.example.php -> config.php
```

Fill `config.php` with:

- dashboard admin password
- exact public HTTPS base URL
- Meta/Instagram App ID
- Meta/Instagram App Secret
- exact OAuth redirect URI
- a long random MCP API key

PHP must be able to create/write:

```text
storage/
media/
```

The root `.htaccess` blocks `storage/`, `lib/`, `bootstrap.php` and config files from direct web access. `media/` stays publicly readable because Meta has to fetch the images, while executable script extensions are blocked there.

## 2. Meta App setup

In Meta for Developers:

1. Create or use a Meta Business App.
2. Enable **Instagram API with Instagram Login**.
3. Configure Instagram Business Login.
4. Add the exact OAuth redirect URI from `config.php`, for example:
   `https://YOUR_DOMAIN/instagram-mcp/index.php?action=oauth_callback`
5. Request:
   - `instagram_business_basic`
   - `instagram_business_content_publish`
6. While the app is in development/test mode, authorize the Instagram Professional account as required by Meta.

Then open:

```text
https://YOUR_DOMAIN/instagram-mcp/
```

Log in with `admin_password` and click **Connect Instagram**.

## 3. MCP connection

Remote endpoint:

```text
https://YOUR_DOMAIN/instagram-mcp/mcp.php
```

Preferred authentication:

```http
Authorization: Bearer YOUR_MCP_API_KEY
```

Fallback when a client cannot set a Bearer header:

```text
https://YOUR_DOMAIN/instagram-mcp/mcp.php?key=YOUR_MCP_API_KEY
```

Bearer auth is preferable because query-string secrets can appear in server logs.

The transport handles MCP protocol versions `2026-07-28`, `2025-11-25`, `2025-06-18`, and `2025-03-26`.

## 4. MCP tools

- `instagram_connection_status` — verifies the connected account/token.
- `instagram_recent_posts` — reads recent posts for duplicate avoidance.
- `instagram_publishing_limit` — reads Meta's publishing quota response.
- `instagram_stage_media` — converts/stages a public source image or base64 image and returns a public JPEG URL.
- `instagram_publish_image` — direct single-image post.
- `instagram_publish_carousel` — direct 2-10 image carousel in exact item order.
- `instagram_publication_status` — reads the saved outcome for a unique job.

## 5. Duplicate protection

Every publish call requires a unique `job_id`. Use the website/Gmail trigger identity, for example:

```text
[SOCIAL_AUTOMATION] Morning Tech Brief | 2026-09-20 08:00:00 | JOB-4
```

If the same job is already `in_progress` or `published`, the MCP returns the saved record instead of blindly submitting another post.

## 6. Carousel workflow for ChatGPT Work

1. `instagram_connection_status`
2. `instagram_recent_posts`
3. research and create the final caption/slides
4. `instagram_stage_media` for every slide that does not already have a durable public HTTPS JPEG URL
5. `instagram_publish_carousel` exactly once with the unique `job_id`
6. verify returned `status`, `media_id`, and `permalink`
7. after any uncertain timeout, call `instagram_publication_status` before attempting another publish

The server creates Meta child containers, waits for processing, creates the carousel container, calls `media_publish`, and then tries to retrieve the final permalink.

## 7. Safe cutover from Metricool

Do **not** remove Metricool from the current live Work trigger yet.

Cut over in this order:

1. deploy this folder
2. create `config.php`
3. connect Instagram in the dashboard
4. connect this MCP to ChatGPT
5. call `instagram_connection_status`
6. publish one controlled direct test
7. verify the post on Instagram
8. only then replace Metricool publishing in the live Work trigger with this MCP

## Security

- Never commit real `config.php`.
- HTTPS only.
- Use a strong random `mcp_api_key`.
- App secret and Instagram access tokens are never returned through MCP tools.
- Event logs omit secret fields.
- Media staging blocks localhost/private/reserved source hosts.
- No shell, SQL, arbitrary filesystem, or generic HTTP-request tool is exposed.

## Health

Dashboard health:

```text
https://YOUR_DOMAIN/instagram-mcp/index.php?action=health
```

Authenticated MCP transport health:

```text
https://YOUR_DOMAIN/instagram-mcp/mcp.php?health=1
```
