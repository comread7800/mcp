# Webkitti Automation Hub

One deployable PHP folder for the complete Instagram automation.

Final hands-off flow:

```text
Website schedule
   -> Gmail trigger
   -> ChatGPT Work
   -> research + caption + final carousel images
   -> Gmail result with attachments
   -> website cron
   -> Meta Instagram API
   -> Instagram live
```

Metricool and the custom ChatGPT MCP route remain available as fallbacks, but the recommended final mode is `email_bridge`.

## Pages

- `index.php` — dashboard
- `schedules.php` — create/edit/run schedules
- `trigger.php` — exact Gmail-event / ChatGPT Work instruction + editable global creative direction
- `instagram.php` — Meta/Instagram account connection + controlled direct test
- `bridge.php` — final Auto Bridge status, mailbox test, activation, manual inbox check
- `mcp-status.php` — legacy custom MCP status
- `logs.php` — website trigger / publishing logs
- `setup.php` — complete setup checklist
- `cron.php` — one cron endpoint for both schedule triggers and final-result publishing
- `health.php` — website health endpoint

## Upgrade from the previous folder

Upload/replace the code files but **keep your existing private `config.php` and `storage/` folder**.

The final email bridge is designed to reuse the Gmail credentials you already have:

- trigger sender: `smtp_username`
- ChatGPT Work inbox: `mail_to`
- result inbox: defaults to `smtp_username`
- trusted result sender: defaults to `mail_to`
- IMAP password: defaults to the existing `smtp_app_password`

So an existing working setup normally does **not** need another Gmail password.

Never commit or share the real `config.php`, Gmail App Password, Instagram token, Meta App Secret, cron secret, or MCP key.

## Final one-time activation

1. Deploy the updated `social-scheduler` folder.
2. Keep the existing Hostinger cron running once per minute.
3. Confirm `Instagram` still shows @webkitti connected/healthy.
4. Open `Auto Bridge` and click **Test mailbox login**.
5. Click **Activate Final Auto Mode**.
6. The ChatGPT Work Gmail-event automation must use the Email Bridge routing instruction shown on `trigger.php`.
7. Run one schedule manually for an end-to-end test.

After that, normal posting is automatic.

## Result email protocol

ChatGPT Work returns one result email to the bridge mailbox.

Subject:

```text
[SOCIAL_READY] <exact original trigger subject>
```

Body:

```text
[SOCIAL_READY]
JOB_KEY_BEGIN
<exact original trigger subject>
JOB_KEY_END
SCHEDULE_ID: <id>
TRIGGERED_AT: <time>
SLIDE_COUNT: <count>
CAPTION_BEGIN
<complete Instagram caption>
CAPTION_END
```

Attachments must be the final Instagram images named in posting order:

```text
slide-01.jpg
slide-02.jpg
slide-03.jpg
...
```

The website validates the sender/tag, parses the caption and stages the final images. Correct 4:5 JPEG slides are passed through without re-encoding, smaller sources are not force-upscaled, and only images that actually need normalization are re-encoded at high JPEG quality. The website then publishes through the Meta API, records the result, and protects against duplicate submissions.

## Publisher modes

```text
email_bridge  = recommended final automation
metricool     = fallback
instagram_mcp = legacy/custom MCP route
```

The dashboard can activate `email_bridge` as a runtime override, so you do not need to edit `config.php` just to switch the final publisher mode.

## Cron

The same existing once-per-minute cron is used. In final mode it:

1. sends any due website schedule email to ChatGPT Work
2. checks the result Gmail inbox for `[SOCIAL_READY]`
3. publishes completed jobs directly to Instagram
4. keeps staged public media available for Meta for 24 hours
5. automatically deletes staged post images after the 24-hour retention window

If a result job fails, it is retried up to five processing attempts and the exact failure is written to Logs.


## Image quality and temporary media

- Final Work slides should be exact 1080x1350 (4:5) JPEG whenever possible.
- A correct 4:5 JPEG is uploaded to Meta without another JPEG encode.
- Smaller source images are never enlarged just to hit 1080px; this prevents artificial pixelation.
- Images that must be converted are encoded with the configured high-quality JPEG setting (default 96).
- Staged public images are retained for 24 hours so Meta has a stable fetch window.
- Cron removes only generated staged files matching the random media filename pattern after the retention period.


## Editable global trigger instruction

The dashboard now includes **Change Trigger Instruction** on both Dashboard and Schedules.

The saved instruction is stored in the existing private state file and is inserted into every future trigger email as `GLOBAL TRIGGER INSTRUCTION`. This lets you change visual/creative direction without editing PHP or rebuilding the ChatGPT Work event trigger.

The built-in default uses the WebKitti premium creator-studio visual direction: bright white/ice-blue scene, bold navy/electric-blue hierarchy, WebKitti branding, realistic topic-relevant props, original polished 3D creator/product visuals, strong cover slide, mobile-readable cards, exact 1080x1350 output when possible, and high-quality final JPEGs.

The final publishing route remains protected: in Email Bridge mode the website still requires the structured `[SOCIAL_READY]` Gmail result and publishes through Meta API even if an older schedule prompt still mentions Metricool.
