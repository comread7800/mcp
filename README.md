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
