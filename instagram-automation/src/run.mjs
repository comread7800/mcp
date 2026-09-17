import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import sharp from 'sharp';

const cfg = {
  openaiKey: process.env.OPENAI_API_KEY || '',
  textModel: process.env.OPENAI_TEXT_MODEL || 'gpt-5.6-luna',
  imageModel: process.env.OPENAI_IMAGE_MODEL || 'gpt-image-2.5-flare',
  metricoolToken: process.env.METRICOOL_TOKEN || '',
  metricoolUserId: process.env.METRICOOL_USER_ID || '',
  metricoolBlogId: process.env.METRICOOL_BLOG_ID || '',
  metricoolTimezone: process.env.METRICOOL_TIMEZONE || 'Asia/Calcutta',
  metricoolCreatorEmail: process.env.METRICOOL_CREATOR_EMAIL || '',
  repo: process.env.GITHUB_REPOSITORY || 'comread7800/mcp',
  branch: process.env.GITHUB_REF_NAME || 'main',
  targetTime: process.env.TARGET_TIME || '',
  publish: (process.env.PUBLISH || 'false').toLowerCase() === 'true',
};

const fail = (msg) => {
  console.error(msg);
  process.exitCode = 1;
};

if (!cfg.openaiKey) {
  console.log('OPENAI_API_KEY is not configured. Skipping generation.');
  process.exit(0);
}

function stripCodeFence(text) {
  return text.trim().replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/i, '');
}

function clamp(text, max) {
  const s = String(text || '').trim();
  return s.length <= max ? s : `${s.slice(0, max - 1).trim()}…`;
}

function escapeXml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&apos;');
}

function wrapWords(text, maxChars = 27, maxLines = 3) {
  const words = String(text || '').trim().split(/\s+/).filter(Boolean);
  const lines = [];
  let current = '';
  for (const word of words) {
    const next = current ? `${current} ${word}` : word;
    if (next.length > maxChars && current) {
      lines.push(current);
      current = word;
      if (lines.length >= maxLines - 1) break;
    } else {
      current = next;
    }
  }
  if (current && lines.length < maxLines) lines.push(current);
  const usedWords = lines.join(' ').split(/\s+/).length;
  if (usedWords < words.length && lines.length) {
    lines[lines.length - 1] = `${lines[lines.length - 1].replace(/[.…]+$/, '')}…`;
  }
  return lines;
}

async function openaiJson(endpoint, body) {
  const res = await fetch(`https://api.openai.com/v1/${endpoint}`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${cfg.openaiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
  });
  const raw = await res.text();
  if (!res.ok) throw new Error(`OpenAI ${endpoint} failed (${res.status}): ${raw.slice(0, 800)}`);
  return JSON.parse(raw);
}

function extractResponseText(response) {
  if (typeof response.output_text === 'string') return response.output_text;
  const parts = [];
  for (const item of response.output || []) {
    if (item.type !== 'message') continue;
    for (const content of item.content || []) {
      if (content.type === 'output_text' && content.text) parts.push(content.text);
    }
  }
  return parts.join('\n').trim();
}

async function researchTrend() {
  const today = new Date().toISOString();
  const prompt = `
Current UTC time: ${today}

You are the research and editorial engine for Webkitti, a Website Designer brand.
Before proposing a post, use web search to inspect what is genuinely current right now.
Prioritize developments from the last 24-72 hours in:
- website design and UX/UI
- AI tools useful for websites
- SEO, Google Search, AEO and analytics
- WordPress, web development, hosting, performance and security
- ecommerce and conversion optimization

Rules:
- Pick one topic that is both current and useful to website designers/business owners.
- Verify meaningful factual claims against credible current sources.
- Do not copy another creator's wording or layout.
- If a viral claim is misleading, make a fact-check angle instead.
- Do NOT position the brand as "Pharma Website Designer". Use exactly "Website Designer".
- Avoid hype, fake urgency and unsupported numbers.
- Keep emojis at zero or extremely limited.
- The visual must be portrait, white-background-first, clean and realistic.
- Artwork text must be short, large and readable; no dense paragraphs.
- Use imagery only if it genuinely helps explain the topic.
- Caption should be detailed but remain under 2000 characters.
- Hashtags: 6-12 relevant tags, not spammy.

Return ONLY valid JSON in this shape:
{
  "topic": "short topic",
  "headline": "max 10 words",
  "subheadline": "max 16 words",
  "bullets": ["short point", "short point", "short point"],
  "caption": "detailed caption",
  "hashtags": ["#WebsiteDesigner"],
  "visual_needed": true,
  "visual_prompt": "description of a minimal realistic supporting visual with NO text",
  "sources": [{"title":"source title","url":"https://..."}]
}
`;
  const response = await openaiJson('responses', {
    model: cfg.textModel,
    input: prompt,
    tools: [{ type: 'web_search' }],
    include: ['web_search_call.action.sources'],
  });
  const text = stripCodeFence(extractResponseText(response));
  const data = JSON.parse(text);
  if (!data.headline || !data.caption || !Array.isArray(data.bullets)) {
    throw new Error('AI returned incomplete post JSON.');
  }
  data.headline = clamp(data.headline, 72);
  data.subheadline = clamp(data.subheadline, 120);
  data.bullets = data.bullets.slice(0, 4).map((x) => clamp(x, 88));
  data.caption = clamp(data.caption, 1900);
  data.hashtags = Array.isArray(data.hashtags) ? data.hashtags.slice(0, 12) : [];
  if (!data.hashtags.some((x) => String(x).toLowerCase() === '#websitedesigner')) {
    data.hashtags.unshift('#WebsiteDesigner');
  }
  return data;
}

async function generateVisual(plan) {
  if (!plan.visual_needed || !plan.visual_prompt) return null;
  const result = await openaiJson('images/generations', {
    model: cfg.imageModel,
    prompt: `Create a minimal, realistic supporting visual for an Instagram website-design post.
NO words, NO letters, NO logos, NO UI text.
Mostly white or very light background.
Simple composition, professional and modern, not stock-photo-looking.
Subject: ${plan.visual_prompt}`,
    size: '1024x1024',
    quality: 'medium',
    output_format: 'webp',
    n: 1,
  });
  const b64 = result?.data?.[0]?.b64_json;
  return b64 ? Buffer.from(b64, 'base64') : null;
}

function buildPosterSvg(plan) {
  const headline = wrapWords(plan.headline, 25, 3);
  const sub = wrapWords(plan.subheadline, 48, 2);
  const bullets = plan.bullets.slice(0, 4);

  let y = 190;
  const headlineSvg = headline.map((line) => {
    const out = `<text x="80" y="${y}" font-size="72" font-weight="750" fill="#111827">${escapeXml(line)}</text>`;
    y += 88;
    return out;
  }).join('\n');

  y += 20;
  const subSvg = sub.map((line) => {
    const out = `<text x="80" y="${y}" font-size="34" font-weight="500" fill="#4B5563">${escapeXml(line)}</text>`;
    y += 48;
    return out;
  }).join('\n');

  y += 50;
  const bulletSvg = bullets.map((point, index) => {
    const lines = wrapWords(point, 44, 2);
    const baseY = y + index * 105;
    const lineSvg = lines.map((line, i) =>
      `<text x="126" y="${baseY + i * 38}" font-size="30" font-weight="600" fill="#1F2937">${escapeXml(line)}</text>`
    ).join('\n');
    return `<circle cx="94" cy="${baseY - 10}" r="9" fill="#2563EB"/>\n${lineSvg}`;
  }).join('\n');

  return `
<svg width="1080" height="1350" viewBox="0 0 1080 1350" xmlns="http://www.w3.org/2000/svg">
  <rect width="1080" height="1350" fill="#FFFFFF"/>
  <rect x="80" y="70" width="150" height="8" rx="4" fill="#2563EB"/>
  <text x="80" y="125" font-size="24" font-weight="700" letter-spacing="1.3" fill="#374151">WEBKITTI · WEBSITE DESIGNER</text>
  ${headlineSvg}
  ${subSvg}
  ${bulletSvg}
  <line x1="80" y1="1195" x2="1000" y2="1195" stroke="#E5E7EB" stroke-width="2"/>
  <text x="80" y="1255" font-size="30" font-weight="750" fill="#111827">Website Designer</text>
  <text x="80" y="1302" font-size="23" font-weight="500" fill="#6B7280">Current trend · researched before publishing</text>
</svg>`;
}

async function renderPoster(plan, visualBuffer) {
  const svg = Buffer.from(buildPosterSvg(plan));
  let pipeline = sharp(svg).resize(1080, 1350);
  if (visualBuffer) {
    const visual = await sharp(visualBuffer)
      .resize(320, 320, { fit: 'cover' })
      .modulate({ saturation: 0.85, brightness: 1.08 })
      .webp({ quality: 88 })
      .toBuffer();
    pipeline = pipeline.composite([
      {
        input: visual,
        left: 680,
        top: 835,
        blend: 'over',
      },
    ]);
  }
  return pipeline.webp({ quality: 92 }).toBuffer();
}

async function main() {
  const plan = await researchTrend();
  console.log(`Selected trend: ${plan.topic || plan.headline}`);

  const visual = await generateVisual(plan);
  const poster = await renderPoster(plan, visual);

  const now = new Date();
  const stamp = now.toISOString().replace(/[:.]/g, '-');
  const relDir = path.posix.join('generated', now.toISOString().slice(0, 10));
  const fileName = `${stamp}.webp`;
  const metaName = `${stamp}.json`;
  const imagePath = path.join(relDir, fileName);
  const metaPath = path.join(relDir, metaName);

  await fs.mkdir(relDir, { recursive: true });
  await fs.writeFile(imagePath, poster);
  await fs.writeFile(metaPath, JSON.stringify({
    generatedAt: now.toISOString(),
    topic: plan.topic,
    headline: plan.headline,
    subheadline: plan.subheadline,
    bullets: plan.bullets,
    caption: plan.caption,
    hashtags: plan.hashtags,
    sources: plan.sources || [],
    status: cfg.publish ? 'ready-to-schedule' : 'generated',
  }, null, 2));

  const rawUrl = `https://raw.githubusercontent.com/${cfg.repo}/${cfg.branch}/instagram-automation/${relDir}/${fileName}`;
  console.log(`MEDIA_URL=${rawUrl}`);
  console.log(`POST_IMAGE_PATH=${imagePath}`);
  console.log(`POST_META_PATH=${metaPath}`);

  if (process.env.GITHUB_OUTPUT) {
    await fs.appendFile(process.env.GITHUB_OUTPUT, `media_url=${rawUrl}\nimage_path=${imagePath}\nmeta_path=${metaPath}\n`);
  }
}

main().catch((err) => {
  fail(err instanceof Error ? err.stack || err.message : String(err));
});
