# Hostinger Files MCP

Remote MCP server for safely working with a Hostinger website over SSH/SFTP.

The MCP server does **not** need your Hostinger password in source code. It uses an SSH private key stored as an environment variable and confines file operations to one configured website root.

## What it can do

- Test Hostinger SSH connectivity
- List website files and directories
- Read UTF-8 files
- Create or replace files
- Replace exact text in a file
- Create directories
- Move/rename files and directories
- Delete files or empty directories with explicit confirmation
- Run one pre-configured deploy command
- Generate Instagram captions and images with OpenAI
- Preview or publish a post to one configured Instagram account
- Run a persistent time-based Instagram posting schedule

## Safety model

- `HOSTINGER_ROOT` is the only writable/readable filesystem boundary.
- `..` traversal outside that root is rejected.
- Existing files are backed up before write/replace by default.
- File reads/writes have configurable byte limits.
- `run_deploy` cannot accept arbitrary commands from the MCP client. It can only execute the exact `HOSTINGER_DEPLOY_COMMAND` configured on the server.
- `.env`, SSH keys, PEM files and key files are excluded by `.gitignore`.
- The remote MCP HTTP endpoint uses a bearer token by default.

## Requirements

- Node.js 20+
- A publicly reachable HTTPS URL for this MCP service
- Hostinger SSH access enabled for the hosting account
- An SSH key authorized on Hostinger

The MCP service may run on a Hostinger plan that supports Node.js apps, or on another Node host. It then connects to the PHP website through Hostinger SSH/SFTP.

## 1. Install

```bash
npm install
cp .env.example .env
```

## 2. Configure Hostinger

Open hPanel and copy the SSH host, port and username from the website's SSH Access section.

Set these values in `.env`:

```env
HOSTINGER_SSH_HOST=your-ssh-host
HOSTINGER_SSH_PORT=65002
HOSTINGER_SSH_USER=u123456789
HOSTINGER_ROOT=/home/u123456789/domains/example.com/public_html
```

Use SSH-key authentication. Put the private key in one of these variables:

```env
HOSTINGER_SSH_PRIVATE_KEY="-----BEGIN OPENSSH PRIVATE KEY-----\n...\n-----END OPENSSH PRIVATE KEY-----"
```

or base64-encode it and use:

```env
HOSTINGER_SSH_PRIVATE_KEY_BASE64=...
```

Do not commit the real `.env` file.

## 3. Protect the MCP endpoint

Generate a long random token and set:

```env
MCP_AUTH_MODE=token
MCP_ACCESS_TOKEN=your-long-random-secret
```

`MCP_AUTH_MODE=none` is available only for isolated testing and should not be used on a public URL.

## 4. Optional deploy command

If a fixed deployment command is useful, configure it once on the server:

```env
HOSTINGER_DEPLOY_COMMAND=cd /home/u123456789/domains/example.com/public_html && git pull --ff-only
```

The MCP client cannot substitute a different shell command.

## 5. Run

```bash
npm run check
npm start
```

Health endpoint:

```text
GET /healthz
```

MCP endpoint:

```text
POST /mcp
Authorization: Bearer <MCP_ACCESS_TOKEN>
```

## MCP tools

| Tool | Purpose |
| --- | --- |
| `hostinger_status` | Verify SSH and website-root connectivity |
| `list_files` | List files/folders under the configured root |
| `read_file` | Read a UTF-8 text file |
| `write_file` | Create/replace a file, backing up existing files by default |
| `replace_in_file` | Exact text replacement with optional replace-all |
| `make_directory` | Create one directory |
| `move_path` | Rename/move a path within the root |
| `delete_path` | Delete a file/symlink or empty directory; requires `confirm=true` |
| `run_deploy` | Execute only the configured deployment command |

## Recommended production setup

Expose the MCP service only over HTTPS. Keep bearer-token authentication enabled, use a dedicated Hostinger SSH key, and set `HOSTINGER_ROOT` to the specific site's `public_html` directory rather than the entire hosting account.

For live PHP sites, keep backups enabled until the workflow is proven. The server automatically creates timestamped `.mcp-backup-*` copies before file replacements unless `backup=false` is explicitly requested.

## Instagram auto-poster (single account)

This repository also supports a private, single-owner Instagram workflow. It is intentionally not a multi-user SaaS login system.

### How the automation works

The reliable unattended flow is:

```text
schedule -> OpenAI API -> caption + image -> public /generated URL -> Instagram API -> publish
```

An MCP server cannot independently push a new message into an already-open ChatGPT conversation. MCP tools are normally called by the MCP host. For scheduled posting, this server therefore runs its own scheduler and calls the OpenAI API directly. You can still connect the same MCP to ChatGPT/Claude and use its Instagram tools manually.

### Instagram requirement

The configured Instagram account must be an Instagram **Professional** account (Business or Creator) with publishing permissions. A consumer Personal account is not supported by Meta's official content-publishing API. If this is your own personal-use account, convert that account to Creator or Business first.

Create a Meta developer app, authorize your Instagram professional account, and obtain the Instagram user ID plus a suitable long-lived access token with content-publishing permission. Store them only in environment variables:

```env
INSTAGRAM_USER_ID=...
INSTAGRAM_ACCESS_TOKEN=...
INSTAGRAM_API_VERSION=v26.0
```

### OpenAI and public image configuration

Scheduled generation uses the OpenAI API, which is billed separately from a ChatGPT subscription.

```env
OPENAI_API_KEY=...
OPENAI_MODEL=gpt-5.6-luna
OPENAI_IMAGE_MODEL=gpt-image-2
PUBLIC_BASE_URL=https://your-mcp-domain.example
DEFAULT_TIMEZONE=Asia/Kolkata
```

`PUBLIC_BASE_URL` must be an HTTPS URL reachable by Instagram. Generated JPEGs are exposed at random URLs under `/generated/` so Meta can fetch them during publishing.

### Instagram MCP tools

| Tool | Purpose |
| --- | --- |
| `instagram_status` | Check configuration, schedule, and recent runs without exposing secrets |
| `instagram_set_schedule` | Set timezone, posting times, default prompt, enable/disable, and dry-run mode |
| `instagram_preview_post` | Generate caption + image but do not publish |
| `instagram_generate_and_publish` | Generate and publish one post; requires `confirm=true` |
| `instagram_publish_image` | Publish an existing public image URL + caption; requires `confirm=true` |

A safe first setup is:

```json
{
  "timezone": "Asia/Kolkata",
  "times": ["10:00", "19:00"],
  "prompt": "Create one useful post about AI tools and web design for small business owners.",
  "enabled": true,
  "dryRun": true
}
```

Run `instagram_preview_post` first. When the output is correct, change the schedule to `"dryRun": false`; scheduled jobs will then publish automatically.

The scheduler checks every 30 seconds and deduplicates each configured time slot. The Node process must stay running continuously. If your hosting sleeps or stops Node processes, use a persistent Node host or configure your host to keep/restart the app.

Generated state and media are stored under `.data/` by default and are excluded from Git.
