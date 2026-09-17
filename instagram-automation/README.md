# Webkitti Instagram automation

This module runs independently of ChatGPT Scheduled Tasks.

Flow:

1. GitHub Actions starts 10 minutes before each target slot.
2. OpenAI web search researches current website/design/AI/SEO/WordPress trends.
3. The automation selects one current topic and creates an original caption.
4. A clean 1080x1350 portrait poster is rendered with a white-background-first layout and large readable text.
5. If the topic benefits from imagery, an AI-generated supporting visual is added with no embedded text.
6. The generated image and source metadata are committed to `generated/`, creating a permanent public media URL.
7. Metricool schedules the Instagram post and auto-publishes it at the target time.

## Daily targets

- 10:00 IST
- 12:00 IST
- 18:00 IST
- 20:00 IST

The GitHub workflow starts 10 minutes earlier so research is fresh while Metricool still receives a future publication time.

## Brand/content rules

- Positioning: **Website Designer**
- Do not use `Pharma Website Designer` as the default brand label.
- Portrait-first: 1080x1350.
- White background is the default.
- Large readable typography; no dense paragraph blocks on the artwork.
- Minimal imagery; use it only when it helps.
- Current trend must be relevant to web design, UX/UI, AI tools, SEO/AEO, analytics, WordPress, development, hosting, performance, security, ecommerce, or conversion.
- Verify factual claims with current web research.
- Never copy another creator's text or layout.
- Very limited emoji use.
- Detailed caption, 6-12 relevant hashtags.
- Instagram AI-generation disclosure is enabled when scheduling.

## Required GitHub Actions secrets

Open **Settings > Secrets and variables > Actions** and add:

- `OPENAI_API_KEY`
- `METRICOOL_TOKEN`
- `METRICOOL_USER_ID`
- `METRICOOL_BLOG_ID`

Optional secret:

- `METRICOOL_CREATOR_EMAIL`

Optional repository variables:

- `OPENAI_TEXT_MODEL` (default `gpt-5.6-luna`)
- `OPENAI_IMAGE_MODEL` (default `gpt-image-2.5-flare`)
- `METRICOOL_TIMEZONE` (default `Asia/Calcutta`)

Metricool's API token is available from the API section of supported Metricool plans.

## Safe test

Go to **Actions > Webkitti Instagram Auto Post > Run workflow**.

For the first test:
- leave `target_time` blank
- leave `publish` OFF

This researches a live trend and generates a poster without sending anything to Instagram.

For a live test:
- set `target_time` to a time at least a few minutes in the future, for example `19:30`
- turn `publish` ON

## Important

GitHub's scheduled workflows are not guaranteed to start at the exact second. The script therefore falls back to scheduling 10 minutes from the actual run time if the intended target time is already too close or has passed.

OpenAI API usage is billed separately from a ChatGPT subscription.
Metricool API access can depend on the Metricool plan.
