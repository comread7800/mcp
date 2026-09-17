import fs from 'node:fs/promises';
import process from 'node:process';

const token = process.env.METRICOOL_TOKEN || '';
const userId = process.env.METRICOOL_USER_ID || '';
const blogId = process.env.METRICOOL_BLOG_ID || '';
const timezone = process.env.METRICOOL_TIMEZONE || 'Asia/Calcutta';
const creator = process.env.METRICOOL_CREATOR_EMAIL || '';
const mediaUrl = process.env.MEDIA_URL || '';
const metaPath = process.env.META_PATH || '';
const targetTime = process.env.TARGET_TIME || '';
const publish = (process.env.PUBLISH || 'false').toLowerCase() === 'true';

if (!publish) {
  console.log('PUBLISH=false: skipping Metricool scheduling.');
  process.exit(0);
}

for (const [name, value] of Object.entries({
  METRICOOL_TOKEN: token,
  METRICOOL_USER_ID: userId,
  METRICOOL_BLOG_ID: blogId,
  MEDIA_URL: mediaUrl,
  META_PATH: metaPath,
})) {
  if (!value) throw new Error(`${name} is required.`);
}

const meta = JSON.parse(await fs.readFile(metaPath, 'utf8'));
const hashtags = Array.isArray(meta.hashtags) ? meta.hashtags : [];
const caption = `${meta.caption || ''}\n\n${hashtags.join(' ')}`.trim().slice(0, 2180);

function localParts(date = new Date()) {
  const fmt = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone,
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
    hour12: false,
  });
  return Object.fromEntries(fmt.formatToParts(date).filter((x) => x.type !== 'literal').map((x) => [x.type, x.value]));
}

function publicationDate() {
  const now = new Date();
  const p = localParts(now);
  if (targetTime) {
    const [hh, mm] = targetTime.split(':').map(Number);
    const currentMins = Number(p.hour) * 60 + Number(p.minute);
    const targetMins = hh * 60 + mm;
    if (targetMins > currentMins + 2) {
      return `${p.year}-${p.month}-${p.day}T${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}:00`;
    }
  }
  const q = localParts(new Date(now.getTime() + 10 * 60 * 1000));
  return `${q.year}-${q.month}-${q.day}T${q.hour}:${q.minute}:00`;
}

const body = {
  publicationDate: { dateTime: publicationDate(), timezone },
  text: caption,
  providers: [{ network: 'instagram' }],
  autoPublish: true,
  draft: false,
  shortener: false,
  saveExternalMediaFiles: true,
  media: [mediaUrl],
  instagramData: {
    type: 'POST',
    showReelOnFeed: true,
    isAiGenerated: true,
  },
};
if (creator) body.creatorUserMail = creator;

const endpoint = new URL('https://app.metricool.com/api/v2/scheduler/posts');
endpoint.searchParams.set('blogId', blogId);
endpoint.searchParams.set('userId', userId);

const res = await fetch(endpoint, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-Mc-Auth': token,
  },
  body: JSON.stringify(body),
});
const raw = await res.text();
if (!res.ok) throw new Error(`Metricool scheduling failed (${res.status}): ${raw.slice(0, 1200)}`);
console.log(`Scheduled Instagram post in Metricool for ${body.publicationDate.dateTime} ${timezone}`);
