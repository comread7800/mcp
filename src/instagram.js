import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';

const stateFile = path.resolve(process.env.INSTAGRAM_STATE_FILE || '.data/instagram.json');
export const generatedDir = path.resolve(process.env.INSTAGRAM_GENERATED_DIR || '.data/generated');
const graphVersion = process.env.INSTAGRAM_API_VERSION || 'v26.0';
const graphBase = 'https://graph.instagram.com/' + graphVersion;
const publicBaseUrl = (process.env.PUBLIC_BASE_URL || '').replace(/\/$/, '');
const defaultTimezone = process.env.DEFAULT_TIMEZONE || 'Asia/Kolkata';
const defaultPrompt = process.env.INSTAGRAM_DEFAULT_PROMPT || 'Create a useful, polished Instagram post about AI tools, websites, design, coding, or automation. Make it practical and avoid repeating recent posts.';
const textModel = process.env.OPENAI_MODEL || 'gpt-5.6-luna';
const imageModel = process.env.OPENAI_IMAGE_MODEL || 'gpt-image-2';
let busy = false;
let timer = null;

function baseState() {
  return {
    accessToken: null,
    tokenRefreshedAt: null,
    schedule: { enabled: false, timezone: defaultTimezone, times: [], prompt: defaultPrompt, dryRun: true, lastKey: null },
    runs: []
  };
}

async function ensureDirs() {
  await fs.mkdir(path.dirname(stateFile), { recursive: true, mode: 0o700 });
  await fs.mkdir(generatedDir, { recursive: true, mode: 0o700 });
}

async function loadState() {
  await ensureDirs();
  try {
    const data = JSON.parse(await fs.readFile(stateFile, 'utf8'));
    return { ...baseState(), ...data, schedule: { ...baseState().schedule, ...(data.schedule || {}) }, runs: Array.isArray(data.runs) ? data.runs : [] };
  } catch (error) {
    if (error?.code === 'ENOENT') return baseState();
    throw error;
  }
}

async function saveState(state) {
  await ensureDirs();
  const temp = stateFile + '.' + process.pid + '.tmp';
  await fs.writeFile(temp, JSON.stringify(state, null, 2) + '\n', { mode: 0o600 });
  await fs.rename(temp, stateFile);
}

function need(name, value) {
  if (!value) throw new Error(name + ' is not configured');
  return value;
}

async function fetchJson(url, options = {}) {
  const response = await fetch(url, options);
  const raw = await response.text();
  let data;
  try { data = raw ? JSON.parse(raw) : {}; } catch { data = { raw }; }
  if (!response.ok || data?.error) {
    const message = data?.error?.message || data?.message || data?.raw || response.status + ' ' + response.statusText;
    throw new Error('API error: ' + message);
  }
  return data;
}

function tokenFrom(state) {
  return state.accessToken || process.env.INSTAGRAM_ACCESS_TOKEN || '';
}

async function refreshInstagramToken(state) {
  const token = tokenFrom(state);
  if (!token) return state;
  const refreshedAt = state.tokenRefreshedAt ? Date.parse(state.tokenRefreshedAt) : 0;
  if (refreshedAt && Date.now() - refreshedAt < 7 * 24 * 60 * 60 * 1000) return state;
  try {
    const params = new URLSearchParams({ grant_type: 'ig_refresh_token', access_token: token });
    const data = await fetchJson('https://graph.instagram.com/refresh_access_token?' + params.toString());
    if (data.access_token) state.accessToken = data.access_token;
    state.tokenRefreshedAt = new Date().toISOString();
    await saveState(state);
  } catch (error) {
    console.error('[instagram] token refresh skipped:', error.message);
  }
  return state;
}

function validateTimezone(value) {
  try { new Intl.DateTimeFormat('en-US', { timeZone: value }).format(new Date()); }
  catch { throw new Error('Invalid timezone: ' + value); }
}

function normalizeTimes(values) {
  if (!Array.isArray(values)) throw new Error('times must be an array');
  const times = values.map((v) => String(v).trim());
  for (const value of times) if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value)) throw new Error('Invalid time ' + value + '. Use HH:MM.');
  return [...new Set(times)].sort();
}

function localClock(timezone) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date());
  const v = Object.fromEntries(parts.filter((p) => p.type !== 'literal').map((p) => [p.type, p.value]));
  return { date: v.year + '-' + v.month + '-' + v.day, time: v.hour + ':' + v.minute };
}

function outputText(data) {
  if (typeof data.output_text === 'string') return data.output_text.trim();
  const chunks = [];
  for (const item of data.output || []) for (const part of item.content || []) if (part.type === 'output_text') chunks.push(part.text);
  return chunks.join('\n').trim();
}

function parseJsonText(text) {
  const clean = String(text).trim().replace(/^\`\`\`json\s*/i, '').replace(/^\`\`\`\s*/, '').replace(/\s*\`\`\`$/, '');
  return JSON.parse(clean);
}

async function createPostPlan(prompt, state) {
  const apiKey = need('OPENAI_API_KEY', process.env.OPENAI_API_KEY);
  const recent = state.runs.slice(-5).map((r) => r.caption).filter(Boolean).join('\n---\n');
  const instructions = 'Return only JSON with two strings: caption and image_prompt. Create a strong Instagram post for a single owner account. Caption must be useful, ready to publish, not repetitive, and can include a few relevant hashtags. image_prompt must describe a polished square visual and should avoid tiny text.';
  const data = await fetchJson('https://api.openai.com/v1/responses', {
    method: 'POST',
    headers: { Authorization: 'Bearer ' + apiKey, 'Content-Type': 'application/json' },
    body: JSON.stringify({ model: textModel, reasoning: { effort: 'low' }, store: false, instructions, input: prompt + '\n\nRecent captions:\n' + (recent || '(none)') })
  });
  const plan = parseJsonText(outputText(data));
  if (!plan.caption || !plan.image_prompt) throw new Error('OpenAI response is missing caption or image_prompt');
  return { caption: String(plan.caption).trim(), imagePrompt: String(plan.image_prompt).trim() };
}

async function createImage(prompt) {
  const apiKey = need('OPENAI_API_KEY', process.env.OPENAI_API_KEY);
  need('PUBLIC_BASE_URL', publicBaseUrl);
  const data = await fetchJson('https://api.openai.com/v1/images/generations', {
    method: 'POST',
    headers: { Authorization: 'Bearer ' + apiKey, 'Content-Type': 'application/json' },
    body: JSON.stringify({ model: imageModel, prompt, size: '1024x1024', quality: process.env.OPENAI_IMAGE_QUALITY || 'medium', output_format: 'jpeg', output_compression: 88 })
  });
  const image = data?.data?.[0]?.b64_json;
  if (!image) throw new Error('OpenAI returned no image data');
  const name = Date.now() + '-' + crypto.randomBytes(6).toString('hex') + '.jpg';
  await fs.writeFile(path.join(generatedDir, name), Buffer.from(image, 'base64'), { mode: 0o600 });
  return publicBaseUrl + '/generated/' + encodeURIComponent(name);
}

export async function instagramStatus() {
  const state = await loadState();
  return {
    configured: { instagram: Boolean(process.env.INSTAGRAM_USER_ID && tokenFrom(state)), openai: Boolean(process.env.OPENAI_API_KEY), publicBaseUrl: Boolean(publicBaseUrl) },
    instagramUserId: process.env.INSTAGRAM_USER_ID || null,
    schedule: state.schedule,
    recentRuns: state.runs.slice(-5)
  };
}

export async function setInstagramSchedule({ timezone, times, prompt, enabled, dryRun }) {
  const state = await loadState();
  if (timezone !== undefined) { validateTimezone(timezone); state.schedule.timezone = timezone; }
  if (times !== undefined) state.schedule.times = normalizeTimes(times);
  if (prompt !== undefined) { if (!String(prompt).trim()) throw new Error('prompt cannot be empty'); state.schedule.prompt = String(prompt).trim(); }
  if (enabled !== undefined) state.schedule.enabled = Boolean(enabled);
  if (dryRun !== undefined) state.schedule.dryRun = Boolean(dryRun);
  if (state.schedule.enabled && state.schedule.times.length === 0) throw new Error('Add at least one time before enabling');
  await saveState(state);
  return state.schedule;
}

export async function publishInstagramImage({ imageUrl, caption }) {
  let state = await loadState();
  state = await refreshInstagramToken(state);
  const accessToken = need('INSTAGRAM_ACCESS_TOKEN', tokenFrom(state));
  const userId = need('INSTAGRAM_USER_ID', process.env.INSTAGRAM_USER_ID);
  const createBody = new URLSearchParams({ image_url: imageUrl, caption: caption || '', access_token: accessToken });
  const container = await fetchJson(graphBase + '/' + encodeURIComponent(userId) + '/media', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: createBody });
  const creationId = need('Instagram creation id', container.id);
  const publishBody = new URLSearchParams({ creation_id: creationId, access_token: accessToken });
  const published = await fetchJson(graphBase + '/' + encodeURIComponent(userId) + '/media_publish', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: publishBody });
  return { ok: true, mediaId: published.id, creationId };
}

export async function runInstagramPost({ prompt, publish = false, source = 'manual' } = {}) {
  if (busy) throw new Error('Another Instagram job is already running');
  busy = true;
  let state = await loadState();
  const startedAt = new Date().toISOString();
  try {
    const plan = await createPostPlan(String(prompt || state.schedule.prompt).trim(), state);
    const imageUrl = await createImage(plan.imagePrompt);
    const shouldPublish = publish && !state.schedule.dryRun;
    const result = shouldPublish ? await publishInstagramImage({ imageUrl, caption: plan.caption }) : null;
    state = await loadState();
    const run = { id: crypto.randomUUID(), source, startedAt, finishedAt: new Date().toISOString(), status: shouldPublish ? 'published' : 'preview', caption: plan.caption, imagePrompt: plan.imagePrompt, imageUrl, mediaId: result?.mediaId || null };
    state.runs.push(run);
    state.runs = state.runs.slice(-50);
    await saveState(state);
    return run;
  } catch (error) {
    state = await loadState().catch(() => baseState());
    state.runs.push({ id: crypto.randomUUID(), source, startedAt, finishedAt: new Date().toISOString(), status: 'failed', error: error.message || String(error) });
    state.runs = state.runs.slice(-50);
    await saveState(state).catch(() => {});
    throw error;
  } finally { busy = false; }
}

async function tick() {
  try {
    const state = await loadState();
    if (!state.schedule.enabled || state.schedule.times.length === 0) return;
    const clock = localClock(state.schedule.timezone);
    if (!state.schedule.times.includes(clock.time)) return;
    const key = clock.date + '|' + clock.time + '|' + state.schedule.timezone;
    if (state.schedule.lastKey === key) return;
    state.schedule.lastKey = key;
    await saveState(state);
    await runInstagramPost({ publish: true, source: 'schedule' });
  } catch (error) { console.error('[instagram-scheduler]', error); }
}

export function startInstagramScheduler() {
  if (timer) return;
  timer = setInterval(() => { void tick(); }, 30000);
  timer.unref?.();
  void tick();
}
