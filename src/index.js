import 'dotenv/config';

import crypto from 'node:crypto';
import path from 'node:path';
import express from 'express';
import { Client } from 'ssh2';
import { McpServer, createMcpHandler } from '@modelcontextprotocol/server';
import { toNodeHandler } from '@modelcontextprotocol/node';
import * as z from 'zod/v4';
import {
  disconnectInstagram,
  generatedDir,
  getInstagramConnectUrl,
  handleInstagramOAuthCallback,
  instagramStatus,
  publishInstagramImage,
  runInstagramPost,
  setInstagramSchedule,
  startInstagramScheduler
} from './instagram.js';

const posix = path.posix;

const config = {
  port: Number(process.env.PORT || 3000),
  authMode: (process.env.MCP_AUTH_MODE || 'token').toLowerCase(),
  accessToken: process.env.MCP_ACCESS_TOKEN || '',
  sshHost: process.env.HOSTINGER_SSH_HOST || '',
  sshPort: Number(process.env.HOSTINGER_SSH_PORT || 65002),
  sshUser: process.env.HOSTINGER_SSH_USER || '',
  sshPrivateKey: getPrivateKey(),
  sshPassphrase: process.env.HOSTINGER_SSH_PASSPHRASE || undefined,
  root: process.env.HOSTINGER_ROOT ? posix.normalize(process.env.HOSTINGER_ROOT) : '',
  deployCommand: process.env.HOSTINGER_DEPLOY_COMMAND || '',
  maxReadBytes: Number(process.env.MAX_READ_BYTES || 1024 * 1024),
  maxWriteBytes: Number(process.env.MAX_WRITE_BYTES || 1024 * 1024),
  sshReadyTimeoutMs: Number(process.env.SSH_READY_TIMEOUT_MS || 20_000),
  sftpTimeoutMs: Number(process.env.SFTP_OPERATION_TIMEOUT_MS || 30_000),
  deployTimeoutMs: Number(process.env.DEPLOY_TIMEOUT_MS || 120_000)
};

function getPrivateKey() {
  const base64 = process.env.HOSTINGER_SSH_PRIVATE_KEY_BASE64;
  if (base64) return Buffer.from(base64, 'base64').toString('utf8');
  return (process.env.HOSTINGER_SSH_PRIVATE_KEY || '').replace(/\\n/g, '\n');
}

function sshConfigured() {
  return Boolean(config.sshHost && config.sshUser && config.sshPrivateKey && config.root);
}

function assertSshConfigured() {
  const missing = [];
  if (!config.sshHost) missing.push('HOSTINGER_SSH_HOST');
  if (!config.sshUser) missing.push('HOSTINGER_SSH_USER');
  if (!config.sshPrivateKey) missing.push('HOSTINGER_SSH_PRIVATE_KEY or HOSTINGER_SSH_PRIVATE_KEY_BASE64');
  if (!config.root) missing.push('HOSTINGER_ROOT');
  if (missing.length) throw new Error(`Missing configuration: ${missing.join(', ')}`);
}

function withTimeout(promise, ms, label) {
  let timer;
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(`${label} timed out after ${ms}ms`)), ms);
  });
  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

function connectSsh() {
  assertSshConfigured();
  return withTimeout(new Promise((resolve, reject) => {
    const conn = new Client();
    let settled = false;
    conn.once('ready', () => {
      settled = true;
      resolve(conn);
    });
    conn.once('error', (error) => {
      if (!settled) reject(error);
    });
    conn.connect({
      host: config.sshHost,
      port: config.sshPort,
      username: config.sshUser,
      privateKey: config.sshPrivateKey,
      passphrase: config.sshPassphrase,
      readyTimeout: config.sshReadyTimeoutMs,
      keepaliveInterval: 10_000,
      keepaliveCountMax: 3
    });
  }), config.sshReadyTimeoutMs + 2_000, 'SSH connection');
}

async function withConnection(fn) {
  const conn = await connectSsh();
  try {
    return await fn(conn);
  } finally {
    conn.end();
  }
}

function getSftp(conn) {
  return withTimeout(new Promise((resolve, reject) => {
    conn.sftp((error, sftp) => (error ? reject(error) : resolve(sftp)));
  }), config.sftpTimeoutMs, 'Opening SFTP channel');
}

function sftpCall(sftp, method, ...args) {
  return withTimeout(new Promise((resolve, reject) => {
    sftp[method](...args, (error, value) => (error ? reject(error) : resolve(value)));
  }), config.sftpTimeoutMs, `SFTP ${method}`);
}

function isNotFound(error) {
  return error?.code === 2 || /no such file|not found/i.test(String(error?.message || ''));
}

function isWithin(root, candidate) {
  const cleanRoot = root === '/' ? '/' : root.replace(/\/$/, '');
  return candidate === cleanRoot || candidate.startsWith(`${cleanRoot}/`);
}

function lexicalCandidate(inputPath = '.') {
  if (!config.root) throw new Error('HOSTINGER_ROOT is not configured');
  const raw = String(inputPath || '.').trim() || '.';
  const candidate = posix.isAbsolute(raw)
    ? posix.normalize(raw)
    : posix.normalize(posix.join(config.root, raw));
  if (!isWithin(config.root, candidate)) throw new Error('Path is outside HOSTINGER_ROOT');
  return candidate;
}

async function getRootRealPath(sftp) {
  const rootReal = posix.normalize(await sftpCall(sftp, 'realpath', config.root));
  if (!rootReal.startsWith('/')) throw new Error('HOSTINGER_ROOT did not resolve to an absolute path');
  return rootReal;
}

async function resolveExistingPath(sftp, inputPath) {
  const candidate = lexicalCandidate(inputPath);
  const rootReal = await getRootRealPath(sftp);
  const real = posix.normalize(await sftpCall(sftp, 'realpath', candidate));
  if (!isWithin(rootReal, real)) throw new Error('Resolved path escapes HOSTINGER_ROOT');
  return { path: real, rootReal };
}

async function resolveWritablePath(sftp, inputPath) {
  const candidate = lexicalCandidate(inputPath);
  const rootReal = await getRootRealPath(sftp);
  try {
    await sftpCall(sftp, 'lstat', candidate);
    const real = posix.normalize(await sftpCall(sftp, 'realpath', candidate));
    if (!isWithin(rootReal, real)) throw new Error('Resolved path escapes HOSTINGER_ROOT');
    return { path: real, rootReal, exists: true };
  } catch (error) {
    if (!isNotFound(error)) throw error;
  }
  const parent = posix.dirname(candidate);
  const parentReal = posix.normalize(await sftpCall(sftp, 'realpath', parent));
  if (!isWithin(rootReal, parentReal)) throw new Error('Parent directory escapes HOSTINGER_ROOT');
  return { path: posix.join(parentReal, posix.basename(candidate)), rootReal, exists: false };
}

function relativeDisplay(rootReal, remotePath) {
  const relative = posix.relative(rootReal, remotePath);
  return relative ? `/${relative}` : '/';
}

function readStreamToBuffer(sftp, remotePath, maxBytes) {
  return withTimeout(new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    const stream = sftp.createReadStream(remotePath);
    stream.on('data', (chunk) => {
      size += chunk.length;
      if (size > maxBytes) {
        stream.destroy(new Error(`File exceeds read limit of ${maxBytes} bytes`));
        return;
      }
      chunks.push(chunk);
    });
    stream.once('error', reject);
    stream.once('end', () => resolve(Buffer.concat(chunks)));
  }), config.sftpTimeoutMs, 'Reading remote file');
}

function writeBuffer(sftp, remotePath, buffer) {
  return withTimeout(new Promise((resolve, reject) => {
    const stream = sftp.createWriteStream(remotePath, { flags: 'w', mode: 0o644 });
    stream.once('error', reject);
    stream.once('close', resolve);
    stream.end(buffer);
  }), config.sftpTimeoutMs, 'Writing remote file');
}

async function backupExistingFile(sftp, remotePath, rootReal) {
  const original = await readStreamToBuffer(sftp, remotePath, config.maxReadBytes);
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const backupPath = `${remotePath}.mcp-backup-${stamp}`;
  if (!isWithin(rootReal, backupPath)) throw new Error('Backup path escaped HOSTINGER_ROOT');
  await writeBuffer(sftp, backupPath, original);
  return relativeDisplay(rootReal, backupPath);
}

function execCommand(conn, command, timeoutMs) {
  return new Promise((resolve, reject) => {
    conn.exec(command, (error, stream) => {
      if (error) return reject(error);
      let stdout = '';
      let stderr = '';
      let settled = false;
      const timer = setTimeout(() => {
        if (settled) return;
        settled = true;
        stream.close();
        reject(new Error(`Remote command timed out after ${timeoutMs}ms`));
      }, timeoutMs);
      stream.on('data', (chunk) => { stdout += chunk.toString('utf8'); });
      stream.stderr.on('data', (chunk) => { stderr += chunk.toString('utf8'); });
      stream.once('error', (streamError) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        reject(streamError);
      });
      stream.once('close', (code, signal) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve({ code, signal, stdout, stderr });
      });
    });
  });
}

function textResult(value) {
  const text = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
  return { content: [{ type: 'text', text }] };
}

function toolError(error) {
  return { isError: true, content: [{ type: 'text', text: error instanceof Error ? error.message : String(error) }] };
}

function buildMcpServer() {
  const server = new McpServer({ name: 'hostinger-files', version: '1.0.0' });

  server.registerTool('hostinger_status', {
    description: 'Test SSH connectivity to Hostinger and confirm the configured website root is reachable.',
    inputSchema: z.object({}),
    annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true }
  }, async () => {
    try {
      return await withConnection(async (conn) => {
        const result = await execCommand(conn, 'printf MCP_OK', 15_000);
        if (result.code !== 0 || result.stdout !== 'MCP_OK') throw new Error(result.stderr || `SSH test failed with exit code ${result.code}`);
        const sftp = await getSftp(conn);
        const rootReal = await getRootRealPath(sftp);
        return textResult({ ok: true, root: relativeDisplay(rootReal, rootReal) });
      });
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('list_files', {
    description: 'List files and folders inside HOSTINGER_ROOT. Paths are relative to the configured root unless absolute.',
    inputSchema: z.object({ path: z.string().default('.') }),
    annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true }
  }, async ({ path: inputPath }) => {
    try {
      return await withConnection(async (conn) => {
        const sftp = await getSftp(conn);
        const resolved = await resolveExistingPath(sftp, inputPath);
        const entries = await sftpCall(sftp, 'readdir', resolved.path);
        return textResult({
          path: relativeDisplay(resolved.rootReal, resolved.path),
          entries: entries.filter((entry) => entry.filename !== '.' && entry.filename !== '..').map((entry) => ({
            name: entry.filename,
            size: entry.attrs?.size ?? null,
            modified: entry.attrs?.mtime ? new Date(entry.attrs.mtime * 1000).toISOString() : null,
            longname: entry.longname
          }))
        });
      });
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('read_file', {
    description: 'Read a UTF-8 text file inside HOSTINGER_ROOT.',
    inputSchema: z.object({ path: z.string().min(1) }),
    annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true }
  }, async ({ path: inputPath }) => {
    try {
      return await withConnection(async (conn) => {
        const sftp = await getSftp(conn);
        const resolved = await resolveExistingPath(sftp, inputPath);
        const content = await readStreamToBuffer(sftp, resolved.path, config.maxReadBytes);
        return textResult({ path: relativeDisplay(resolved.rootReal, resolved.path), bytes: content.length, content: content.toString('utf8') });
      });
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('write_file', {
    description: 'Create or replace a UTF-8 text file inside HOSTINGER_ROOT. Existing files are backed up by default.',
    inputSchema: z.object({ path: z.string().min(1), content: z.string(), backup: z.boolean().default(true) }),
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true }
  }, async ({ path: inputPath, content, backup }) => {
    try {
      const buffer = Buffer.from(content, 'utf8');
      if (buffer.length > config.maxWriteBytes) throw new Error(`Content exceeds write limit of ${config.maxWriteBytes} bytes`);
      return await withConnection(async (conn) => {
        const sftp = await getSftp(conn);
        const resolved = await resolveWritablePath(sftp, inputPath);
        const backupPath = backup && resolved.exists ? await backupExistingFile(sftp, resolved.path, resolved.rootReal) : null;
        await writeBuffer(sftp, resolved.path, buffer);
        return textResult({ ok: true, path: relativeDisplay(resolved.rootReal, resolved.path), bytes: buffer.length, backup: backupPath });
      });
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('replace_in_file', {
    description: 'Replace exact text in an existing UTF-8 file. Fails if the search text is absent. Creates a backup by default.',
    inputSchema: z.object({ path: z.string().min(1), search: z.string().min(1), replace: z.string(), replaceAll: z.boolean().default(false), backup: z.boolean().default(true) }),
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true }
  }, async ({ path: inputPath, search, replace, replaceAll, backup }) => {
    try {
      return await withConnection(async (conn) => {
        const sftp = await getSftp(conn);
        const resolved = await resolveExistingPath(sftp, inputPath);
        const current = (await readStreamToBuffer(sftp, resolved.path, config.maxReadBytes)).toString('utf8');
        if (!current.includes(search)) throw new Error('Search text was not found in the file');
        const next = replaceAll ? current.split(search).join(replace) : current.replace(search, replace);
        const nextBuffer = Buffer.from(next, 'utf8');
        if (nextBuffer.length > config.maxWriteBytes) throw new Error(`Updated file exceeds write limit of ${config.maxWriteBytes} bytes`);
        const backupPath = backup ? await backupExistingFile(sftp, resolved.path, resolved.rootReal) : null;
        await writeBuffer(sftp, resolved.path, nextBuffer);
        return textResult({ ok: true, path: relativeDisplay(resolved.rootReal, resolved.path), replacements: replaceAll ? current.split(search).length - 1 : 1, backup: backupPath });
      });
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('make_directory', {
    description: 'Create one directory inside HOSTINGER_ROOT. Its parent directory must already exist.',
    inputSchema: z.object({ path: z.string().min(1) }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true }
  }, async ({ path: inputPath }) => {
    try {
      return await withConnection(async (conn) => {
        const sftp = await getSftp(conn);
        const resolved = await resolveWritablePath(sftp, inputPath);
        if (resolved.exists) throw new Error('Path already exists');
        await sftpCall(sftp, 'mkdir', resolved.path, { mode: 0o755 });
        return textResult({ ok: true, path: relativeDisplay(resolved.rootReal, resolved.path) });
      });
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('move_path', {
    description: 'Move or rename a file/folder within HOSTINGER_ROOT.',
    inputSchema: z.object({ source: z.string().min(1), destination: z.string().min(1) }),
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true }
  }, async ({ source, destination }) => {
    try {
      return await withConnection(async (conn) => {
        const sftp = await getSftp(conn);
        const src = await resolveExistingPath(sftp, source);
        const dst = await resolveWritablePath(sftp, destination);
        if (dst.exists) throw new Error('Destination already exists');
        await sftpCall(sftp, 'rename', src.path, dst.path);
        return textResult({ ok: true, source: relativeDisplay(src.rootReal, src.path), destination: relativeDisplay(dst.rootReal, dst.path) });
      });
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('delete_path', {
    description: 'Delete a file/symlink or an empty directory inside HOSTINGER_ROOT. Requires confirm=true.',
    inputSchema: z.object({ path: z.string().min(1), confirm: z.literal(true) }),
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true }
  }, async ({ path: inputPath }) => {
    try {
      return await withConnection(async (conn) => {
        const sftp = await getSftp(conn);
        const candidate = lexicalCandidate(inputPath);
        const rootReal = await getRootRealPath(sftp);
        const attrs = await sftpCall(sftp, 'lstat', candidate);
        const fileType = attrs.mode & 0o170000;
        if (fileType === 0o040000) {
          const real = posix.normalize(await sftpCall(sftp, 'realpath', candidate));
          if (!isWithin(rootReal, real)) throw new Error('Resolved directory escapes HOSTINGER_ROOT');
          if (real === rootReal) throw new Error('Refusing to delete HOSTINGER_ROOT itself');
          await sftpCall(sftp, 'rmdir', real);
          return textResult({ ok: true, deleted: relativeDisplay(rootReal, real), type: 'directory' });
        }
        await sftpCall(sftp, 'unlink', candidate);
        return textResult({ ok: true, deleted: relativeDisplay(rootReal, candidate), type: 'file-or-symlink' });
      });
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('run_deploy', {
    description: 'Run the single deployment command configured in HOSTINGER_DEPLOY_COMMAND. Arbitrary shell commands are not accepted.',
    inputSchema: z.object({}),
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true }
  }, async () => {
    try {
      if (!config.deployCommand) throw new Error('HOSTINGER_DEPLOY_COMMAND is not configured');
      return await withConnection(async (conn) => {
        const result = await execCommand(conn, config.deployCommand, config.deployTimeoutMs);
        return result.code === 0
          ? textResult({ ok: true, stdout: result.stdout, stderr: result.stderr })
          : toolError(new Error(result.stderr || `Deploy command exited with code ${result.code}`));
      });
    } catch (error) {
      return toolError(error);
    }
  });



  server.registerTool('instagram_connect_url', {
    description: 'Create a short-lived Instagram Business Login URL for connecting the single owner account in a browser.',
    inputSchema: z.object({}),
    annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true }
  }, async () => {
    try {
      return textResult({ url: getInstagramConnectUrl(), expiresInMinutes: 15 });
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('instagram_disconnect', {
    description: 'Disconnect the stored Instagram account and disable its posting schedule. Requires confirm=true.',
    inputSchema: z.object({ confirm: z.literal(true) }),
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true }
  }, async () => {
    try {
      return textResult(await disconnectInstagram());
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('instagram_status', {
    description: 'Show Instagram/OpenAI configuration, posting schedule and recent runs without exposing secrets.',
    inputSchema: z.object({}),
    annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true }
  }, async () => {
    try {
      return textResult(await instagramStatus());
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('instagram_set_schedule', {
    description: 'Configure the single-account Instagram auto-post schedule. Times use 24-hour HH:MM in the supplied IANA timezone. dryRun=true generates previews only; set dryRun=false to allow scheduled publishing.',
    inputSchema: z.object({
      timezone: z.string().optional(),
      times: z.array(z.string()).max(12).optional(),
      prompt: z.string().min(1).max(4000).optional(),
      enabled: z.boolean().optional(),
      dryRun: z.boolean().optional()
    }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true }
  }, async (input) => {
    try {
      return textResult(await setInstagramSchedule(input));
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('instagram_preview_post', {
    description: 'Generate a caption and square image with OpenAI but do not publish to Instagram.',
    inputSchema: z.object({ prompt: z.string().min(1).max(4000).optional() }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true }
  }, async ({ prompt }) => {
    try {
      return textResult(await runInstagramPost({ prompt, publish: false, source: 'mcp-preview' }));
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('instagram_generate_and_publish', {
    description: 'Generate a caption and image with OpenAI and publish it to the configured Instagram account. Requires confirm=true and dryRun=false.',
    inputSchema: z.object({ prompt: z.string().min(1).max(4000).optional(), confirm: z.literal(true) }),
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true }
  }, async ({ prompt }) => {
    try {
      return textResult(await runInstagramPost({ prompt, publish: true, source: 'mcp-publish' }));
    } catch (error) {
      return toolError(error);
    }
  });

  server.registerTool('instagram_publish_image', {
    description: 'Publish an existing publicly reachable image URL and caption to the configured Instagram account. Requires confirm=true.',
    inputSchema: z.object({ imageUrl: z.string().url(), caption: z.string().max(2200).default(''), confirm: z.literal(true) }),
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true }
  }, async ({ imageUrl, caption }) => {
    try {
      return textResult(await publishInstagramImage({ imageUrl, caption }));
    } catch (error) {
      return toolError(error);
    }
  });

  return server;
}

function secureTokenEquals(actual, expected) {
  const a = Buffer.from(actual);
  const b = Buffer.from(expected);
  if (a.length !== b.length) return false;
  return crypto.timingSafeEqual(a, b);
}

function authMiddleware(req, res, next) {
  if (config.authMode === 'none') return next();
  if (config.authMode !== 'token') return res.status(500).json({ error: 'Unsupported MCP_AUTH_MODE' });
  if (!config.accessToken) return res.status(503).json({ error: 'MCP_ACCESS_TOKEN is not configured' });
  const header = req.get('authorization') || '';
  const match = /^Bearer\s+(.+)$/i.exec(header);
  if (!match || !secureTokenEquals(match[1], config.accessToken)) {
    res.set('WWW-Authenticate', 'Bearer');
    return res.status(401).json({ error: 'Unauthorized' });
  }
  next();
}

const handler = createMcpHandler(() => buildMcpServer());
const nodeHandler = toNodeHandler(handler);
const app = express();

app.disable('x-powered-by');
app.use(express.json({ limit: '2mb' }));
app.use('/generated', express.static(generatedDir, { index: false, maxAge: '7d' }));


app.get('/instagram/callback', async (req, res) => {
  try {
    if (req.query.error) {
      const detail = req.query.error_description || req.query.error_reason || req.query.error;
      return res.status(400).type('html').send('<!doctype html><meta charset="utf-8"><title>Instagram connection failed</title><h1>Instagram connection failed</h1><p>' + String(detail).replace(/[&<>"']/g, '') + '</p>');
    }
    const result = await handleInstagramOAuthCallback({ code: req.query.code, state: req.query.state });
    const username = result.username ? '@' + result.username : result.instagramUserId;
    res.type('html').send('<!doctype html><meta charset="utf-8"><title>Instagram connected</title><style>body{font-family:system-ui;max-width:680px;margin:80px auto;padding:24px;line-height:1.5}h1{font-size:32px}</style><h1>Instagram connected</h1><p>Connected ' + username + '. You can close this tab and return to ChatGPT/Claude.</p>');
  } catch (error) {
    console.error('[instagram-oauth]', error);
    res.status(400).type('html').send('<!doctype html><meta charset="utf-8"><title>Instagram connection failed</title><h1>Instagram connection failed</h1><p>Check the server logs and Meta app redirect settings, then try again.</p>');
  }
});

app.get('/healthz', (_req, res) => {
  res.json({ ok: true, service: 'hostinger-files-mcp', sshConfigured: sshConfigured(), authMode: config.authMode });
});

app.all('/mcp', authMiddleware, (req, res) => {
  void nodeHandler(req, res, req.body);
});

app.use((error, _req, res, _next) => {
  console.error(error);
  res.status(500).json({ error: 'Internal server error' });
});

startInstagramScheduler();

app.listen(config.port, '0.0.0.0', () => {
  console.error(`Hostinger Files MCP listening on port ${config.port}`);
  if (config.authMode === 'none') console.error('WARNING: MCP_AUTH_MODE=none exposes the MCP endpoint without authentication.');
});
