# Enspire Email Leads Tracker — Architecture

Architecture document for the "Enspire Email Leads Tracker" WordPress plugin.
Final version: **Postmark only**, asynchronous sending, recipient taken from the `email` post meta, administrator alerts, WP-CLI, optional captcha, GDPR consent, real IP detection, and a failed-leads list in the admin. Delivery is split into **v1**, **v2** and **v3**.

---

## 1. Decisions

| # | Decision |
| --- | --- |
| 1 | There is no `manual_review`. Failures are reported by **email to the administrator**, and are handled through **WP-CLI** (v1) and the **failed-leads list** on the plugin page (v3). |
| 2 | Sending is **asynchronous**: the form saves the lead and responds immediately; the email is sent right after. |
| 3 | A single provider: **Postmark** (API for sending, webhook for real delivery). No generic SMTP and no other providers. |
| 4 | The recipient email **never appears in the HTML**. It is read on the server from the post meta with the fixed key **`email`**. The key is not configurable and there is no restriction by post type. |
| 5 | The public form **does not use a nonce** (incompatible with page caching). Protection: signed context, honeypot, time-trap, rate limiting and an **optional captcha** (Cloudflare Turnstile). |
| 6 | A GDPR consent checkbox with text editable in the settings; integration with the WordPress personal-data export/erase tools. |
| 7 | **v1** = sending, retry, retention, settings, alerts, CLI, GDPR, captcha, IP detection. **v2** = Postmark delivery webhook + reconciliation. **v3** = failed-leads list in the admin, with selection and bulk resend, administrators only. Postmark only. |
| 8 | Rate limiting uses the visitor's **real IP**, detected automatically on any hosting setup (direct, Cloudflare, proxy). |

---

## 2. Delivery stages

### v1 — Reliable sending

- Shortcode + form (recipient from post meta, nothing sensitive in the HTML).
- Save lead + content, then send asynchronously through the Postmark API.
- Automatic retry with backoff for temporary errors.
- Duplicate-send protection for ambiguous timeouts (search in Postmark).
- Retention: content is deleted a number of hours after acceptance.
- Settings page.
- Email alerts for the administrator.
- WP-CLI commands.
- Optional captcha (Turnstile), GDPR checkbox, personal-data export/erase.

**v1 outcome**: every lead is saved, sent and, on failure, reported. We know how many leads were **accepted** by Postmark.

### v2 — Delivery confirmation

- Webhook endpoint for the Postmark **Delivery** and **Bounce** events.
- `delivery_status`: `delivered`, `bounced`, `deferred`.
- Content is deleted **right after confirmed delivery**.
- Hourly reconciliation through the Messages API for missed webhook events.
- Bounce alert and an alert when the webhook seems to be stopped.
- CLI statistics: delivered / bounced leads.

**v2 outcome**: we know how many emails were actually **delivered**.

### v3 — Failed leads in the admin

- A **"Failed leads"** tab on the plugin page, visible to administrators only.
- A list with filters, pagination and selection (including "select all").
- Bulk actions: **Resend**, **Dismiss**, **Delete content**.
- Message details can be viewed on demand, to contact the visitor manually.
- The number of failed leads shown in the admin menu.

**v3 outcome**: failures are resolved from wp-admin, without WP-CLI. The CLI commands remain available.

The database schema includes the v2 and v3 columns from v1 onwards, so no migration is needed.

---

## 3. File structure

The implemented structure (repo `gzalinski/email-leads-tracker`):

```
enspire-email-leads-tracker.php              # Bootstrap: header, PSR-4 autoload, activation/deactivation
uninstall.php                          # Data removal (if enabled in the settings)
includes/
├── Plugin.php                         # Registers all components
├── Installer.php                      # dbDelta, schema version, cron events
├── State.php                          # Internal state (the llt_state option)
├── Template.php                       # Template rendering, theme overrides
├── Util.php                           # UTC dates, email masking, IDs
├── Settings/
│   ├── Settings.php                   # Reading settings + defaults
│   ├── SettingsFields.php             # Tabs and fields (extensible via the llt_settings_tabs filter)
│   ├── SettingsPage.php               # The Settings → Enspire Email Leads Tracker page
│   ├── SettingsSanitizer.php          # Validation on save (current tab only)
│   ├── SecretStore.php                # libsodium encryption
│   ├── TestActions.php                # Test email, test alert, IP diagnostics
│   ├── StatusTab.php                  # The Status tab
│   └── AdminNotice.php                # Messages after actions
├── Shortcode/
│   ├── ContactShortcode.php           # [location_contact]
│   ├── RecipientResolver.php          # object_id → "email" post meta
│   └── ContextSigner.php              # HMAC for object_id + render time
├── Form/
│   ├── SubmissionHandler.php          # admin-post.php?action=llt_submit
│   ├── Validator.php                  # Fields + consent
│   ├── RateLimiter.php                # Per-IP limit (HMAC hash)
│   ├── Messages.php                   # Messages for the visitor
│   └── Captcha/TurnstileVerifier.php  # Server-side verification
├── Http/
│   ├── ClientIp.php                   # The real IP (direct / Cloudflare / proxy)
│   ├── ProxyRanges.php                # The Cloudflare list, refreshed weekly
│   └── Cidr.php                       # IP ↔ network membership (IPv4/IPv6)
├── Lead/
│   ├── LeadService.php                # Creating lead + content (transaction)
│   ├── LeadStatus.php                 # Statuses + the monotonic rule
│   └── LeadActions.php                # Resend / Dismiss / Restore / Delete content (CLI and admin)
├── Postmark/
│   ├── PostmarkClient.php             # POST /email, search by metadata, message details, bounce
│   ├── EmailBuilder.php               # The email payload
│   ├── SendResult.php                 # accepted / temporary / ambiguous / permanent / config
│   └── ErrorClassifier.php            # HTTP + ErrorCode + cURL errors → category
├── Queue/
│   ├── SendWorker.php                 # Immediate and queued sending
│   ├── BackoffPolicy.php              # Intervals + jitter + Retry-After
│   └── Lock.php                       # Row lock and global worker lock
├── Alerts/
│   ├── AdminNotifier.php              # Alerts via wp_mail(), rate limiting, recovery, bounce, stopped webhook
│   └── HealthCheck.php                # Hourly check
├── Retention/RetentionService.php     # Content deletion
├── Privacy/                           # Export, erasure, privacy policy text
├── Database/                          # Schema, LeadRepository, MessageRepository
├── Cli/Commands.php                   # wp llt ...
├── Webhook/                           # v2
│   ├── WebhookController.php          # POST /wp-json/llt/v1/postmark
│   ├── WebhookAuth.php                # Basic Auth + IP filter
│   ├── EventProcessor.php             # Delivery / Bounce → lead
│   ├── Reconciler.php                 # Hourly reconciliation through the Messages API
│   └── WebhookSettings.php            # The Webhook tab, v2 fields, rows in Status/CLI
└── Admin/                             # v3
    ├── FailedLeadsTab.php             # The "Failed leads" tab
    ├── FailedLeadsTable.php           # WP_List_Table
    ├── FailedLeadsQuery.php           # Views, filters, "all matching the filter"
    ├── BulkActionHandler.php          # Row and bulk actions
    ├── LeadDetailsAjax.php            # "View message"
    └── MenuBadge.php                  # Menu counter
templates/   form.php, email.php
assets/      form.js, form.css, admin.js, admin.css
tests/       run.php, check-calls.php, cases/ (no WordPress: stubs + SQLite)
```

Namespace: `EnspireEmailLeadsTracker\`. PSR-4 autoload.

---

## 4. Component diagram

```mermaid
flowchart LR
    V[Visitor] -->|POST| SH[SubmissionHandler]
    SC[ContactShortcode] -->|form + signed object_id| V
    SH --> LS[LeadService]
    LS --> DB[(Plugin tables)]
    LS -.->|after the response| SW[SendWorker]
    CRON[Server cron] --> SW
    CRON --> RS[RetentionService]
    CRON --> HC[HealthCheck]
    CRON -->|v2| REC[Reconciler]
    SW --> PC[PostmarkClient]
    PC --> PM[(Postmark API)]
    SW --> AN[AdminNotifier]
    HC --> AN
    AN -->|wp_mail| ADM[Administrator]
    PM -->|v2: Delivery / Bounce| WC[WebhookController]
    WC --> EP[EventProcessor]
    REC --> PC
    REC --> EP
    EP --> DB
    CLI[WP-CLI] --> LA[LeadActions]
    ADMIN[Admin v3] --> LA
    LA --> DB
    RS --> DB
```

---

## 5. Shortcode and recipient

### 5.1. Usage

```
[location_contact object_id="245"]
[location_contact]                    ← uses the current post
```

- `object_id` is optional; if missing, `get_the_ID()` is used.
- The `email` attribute **no longer exists**.

### 5.2. Resolving the recipient (`RecipientResolver`)

```php
$post  = get_post( $object_id );
$email = trim( (string) get_post_meta( $object_id, 'email', true ) );   // fixed key
```

Validity conditions:

1. `object_id` is a positive integer (if it is missing and `get_the_ID()` does not return a post, for example in a widget outside the loop, the condition fails).
2. The post exists and has the `publish` status.
3. `is_email( $email )` is true.

The meta key is fixed (`email`) and does not appear in the settings. There is no restriction by post type: any published post with a valid `email` field can be a recipient. It also works if the field was created with ACF, because ACF saves the value in post meta under the same key.

The conditions are checked **twice**: at render time and on submit (the post may be unpublished or the email deleted in between).

- **At render time**, if a condition fails: editors (`edit_posts`) see an explanatory message, for example "Post #312 does not have a valid "email" field."; visitors see no form.
- **On submit**, if a condition fails: no lead is created, and the visitor gets "The form is not available right now. Please try again later." The event is logged without personal data.

Note: since there is no type restriction, the `object_id` in the shortcode must point to the right post. A wrong ID that points to another post with a valid `email` field will send messages there. Visitors cannot change the ID (it is signed, §5.3); the risk is only in how editors configure the shortcode.

### 5.3. What ends up in the HTML

| Hidden field | Content |
| --- | --- |
| `llt_ctx` | `object_id` + render time, base64url-encoded |
| `llt_sig` | `HMAC-SHA256(llt_ctx, secret)` |
| `llt_hp` | Honeypot (empty) |
| Turnstile widget | only if enabled |

**The recipient email appears nowhere.** On submit it is read again from post meta.

The signature prevents a visitor from changing `object_id` to send messages to another post. The render time is also in the signed context, so a bot cannot forge it to get past the time-trap. The HMAC secret is derived from `wp_salt( 'auth' )` and is never exposed.

The context does not expire: on a cached page the render time can be days old, which passes the time-trap anyway (it only requires a minimum).

### 5.4. Snapshot

When the lead is created, the resolved email is saved in `recipient_email`. If the meta changes later, retries still go to the address from the time of submission.

---

## 6. The form

| Field | Required |
| --- | --- |
| Full Name | Yes |
| Email | Yes |
| Phone Number | No |
| Message | Yes |
| GDPR consent checkbox | Yes |
| Turnstile | Yes, if enabled |

### 6.1. GDPR consent

- The text next to the checkbox comes from the **"GDPR consent text"** setting (textarea).
- Allowed HTML: `<a href target rel>`, `<strong>`, `<em>`, `<br>` (filtered with `wp_kses`).
- On output, links automatically get `rel="noopener"` if they have `target="_blank"`.
- Submitting without the checkbox ticked → validation error.
- `consent_at` is saved on the lead as proof of consent, without personal data.

### 6.2. Anti-spam (no nonce)

| Measure | Details |
| --- | --- |
| Signed context | Protects `object_id`; cache-compatible (does not expire) |
| Honeypot | A hidden field that must stay empty |
| Time-trap | At least N seconds between render (from the signed context) and submit (setting, default 3) |
| Rate limiting | At most N submissions / IP / hour (setting, default 5); key `hash(IP + salt)`; real IP detected as in §6.5 |
| Captcha | Optional, Cloudflare Turnstile |

### 6.3. Captcha: Cloudflare Turnstile

Chosen because:

- it works with cached pages;
- most of the time it is invisible to the visitor;
- it is free and collects less data than reCAPTCHA.

Implementation:

- The Turnstile script is loaded **only** on pages that contain the shortcode.
- On submit, the token is verified server-side at `https://challenges.cloudflare.com/turnstile/v0/siteverify`, with the secret key from the settings and the visitor's real IP (`remoteip`, from `ClientIp`, §6.5).
- If Cloudflare does not respond: the **"If the check is unavailable"** setting → *reject* (default) or *accept*.

Turnstile loads resources from Cloudflare, so it must be mentioned in the privacy policy (§11.3).

### 6.4. The response to the visitor

Sending is asynchronous, so the message does not claim the email was sent:

> "Thank you! Your request has been received."

The text can be changed in the settings.

### 6.5. Detecting the visitor's real IP (`ClientIp`)

**The problem.** Rate limiting counts messages per IP address. If the site is behind Cloudflare, a load balancer or an nginx proxy, the request reaches WordPress from the proxy, not directly from the visitor. PHP then sees the proxy's IP, which is the same for all visitors. The result: all visitors share the same limit and, after 5 messages per hour from anyone, the form is blocked for everyone.

The proxy passes the real IP in a header (`CF-Connecting-IP` for Cloudflare, `X-Forwarded-For` for most others). Those headers, however, can be forged by anyone who sends requests directly to the server. So the plugin reads them **only** if the request actually comes from a known proxy.

**Algorithm ("Automatic" mode, the default, works on any site):**

1. `$remote = $_SERVER['REMOTE_ADDR']` — the address that actually connected to the server.
2. If `$remote` belongs to the Cloudflare networks → real IP = `CF-Connecting-IP`.
3. Otherwise, if `$remote` is a private or local address (`127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `::1`, `fc00::/7`) or belongs to the additional proxies list from the settings → a proxy on the same server or in the internal network (nginx, Docker, load balancer). Real IP = the first address in `X-Forwarded-For`, read **from right to left**, that is not itself a known proxy; if the header is missing, `X-Real-IP`.
4. Otherwise → direct connection; real IP = `$remote`. Headers are ignored.
5. Every value is validated with `filter_var( $ip, FILTER_VALIDATE_IP )`; an invalid value falls back to `$remote`.

So:

| Site setup | What happens |
| --- | --- |
| No proxy | Step 4, direct IP |
| Cloudflare | Step 2, real IP from `CF-Connecting-IP` |
| nginx / Varnish / Docker in front of PHP | Step 3, real IP from `X-Forwarded-For` |
| Cloudflare + local nginx | Step 3, then walking the chain reaches the Cloudflare IP, then the visitor |
| Attacker sending a forged `CF-Connecting-IP` directly to the server | Step 4, the header is ignored |
| Another CDN / load balancer with a public IP (Sucuri, AWS ALB, etc.) | The administrator adds its networks to "Additional proxies"; otherwise it behaves as in step 4 |

**The Cloudflare list (`ProxyRanges`):**

- The plugin ships with a copy of the list, so it works from day one.
- A weekly cron refreshes it from `https://www.cloudflare.com/ips-v4/` and `https://www.cloudflare.com/ips-v6/`. If the download fails, the existing list is kept.
- The list rarely changes (last published change: September 2023), so the risk of it being outdated is low.

**Settings** (Anti-spam tab):

| Field | Values | Default |
| --- | --- | --- |
| IP source | Automatic / Direct connection only / Cloudflare only | Automatic |
| Additional proxies | CIDR, one per line | empty |
| Diagnostics | read-only, e.g. "Your detected IP: 85.x.x.x — via Cloudflare" | — |

The downloaded Cloudflare list is stored in the `llt_proxy_ranges` option (§14.3).

The diagnostics let the administrator see immediately whether detection works: if the IP shown is not theirs, the setup needs an additional proxy.

The same class is used for the webhook's optional IP filter (§13.1).

---

## 7. The submit and send flow

```mermaid
sequenceDiagram
    participant V as Visitor
    participant SH as SubmissionHandler
    participant DB as Database
    participant SW as SendWorker
    participant PM as Postmark

    V->>SH: POST
    SH->>SH: HMAC context, honeypot, time-trap, rate limit, Turnstile, field validation, consent
    SH->>SH: resolve the recipient from post meta (§5.2)
    SH->>DB: TRANSACTION: INSERT lead (pending, next_retry_at = now) + INSERT message
    SH-->>V: "Your request has been received."
    Note over SH,SW: after the response is sent
    SH->>SW: process(lead_id)
    SW->>DB: claim (lock)
    SW->>PM: POST /email
    PM-->>SW: result
    SW->>DB: status update
```

### 7.1. Sending right after the response

1. The response to the visitor is sent in full.
2. If `fastcgi_finish_request()` exists (PHP-FPM, the most common case), the connection is closed and the same process sends the email immediately.
3. Otherwise, `wp_schedule_single_event( time(), 'llt_send_lead', [ $id ] )` is scheduled, and cron picks it up within a minute.
4. In any case, the periodic worker picks up any due `pending` lead. Nothing stays unsent if step 2 or 3 fails.

The lock (§9.3) guarantees a lead is never sent twice, even if steps 2 and 4 run at the same time.

The form is submitted via AJAX (`form.js`) to `admin-post.php?action=llt_submit` and gets a JSON response. Without JavaScript, the same endpoint redirects back to the page with the confirmation or error message.

### 7.2. The Postmark request

`POST https://api.postmarkapp.com/email`, header `X-Postmark-Server-Token`.

| Field | Value |
| --- | --- |
| `From` | `"{sender name}" <{sender email}>` (signature verified in Postmark) |
| `To` | `recipient_email` |
| `ReplyTo` | the visitor's email |
| `Subject` | from the settings, with `{post_title}`, `{site_name}` |
| `TextBody` / `HtmlBody` | from `templates/email.php`, escaped content; `HtmlBody` only if the format in the settings is HTML |
| `MessageStream` | from the settings (default `outbound`) |
| `Tag` | `location-lead` |
| `Metadata` | `{ "llt_lead_id": "123" }` |
| `TrackOpens` / `TrackLinks` | `false` |

`Metadata.llt_lead_id` is used for duplicate-send protection (§7.4) and for correlating webhooks (v2).

### 7.3. Classifying results (`ErrorClassifier`)

Verified against the Postmark documentation (API overview, October 2026). Postmark returns `ErrorCode` in the JSON body and in the `X-PM-ApiErrorCode` header.

| HTTP | `ErrorCode` | Meaning | Category |
| --- | --- | --- | --- |
| 200 | 0 | Accepted | `accepted` — save `MessageID`, `sent_at` |
| — | — | Connection failed before sending (DNS, refused, TLS) | `temporary` |
| — | — | Timeout **after** the request was sent | `ambiguous` → §7.4 |
| 429 | — | Rate limit exceeded | `temporary`, respects `Retry-After` |
| 500 | 101 | Postmark internal error (the message is lost, confirmed by Postmark) | `temporary` |
| 503 | 100 | Maintenance | `temporary` |
| 401 | 10 | Invalid token or wrong token type | `config` |
| 422 | 412 | Account pending approval: the recipient must be on the sender's domain | `config` |
| 422 | 413 | The account is not approved for sending | `config` |
| 422 | 422 | Invalid server | `config` |
| 422 | 1235 / 1236 | Message Stream does not exist or is of the wrong type | `config` |
| 422 | 1480 | The server's IP is not allowed to send | `config` |
| 422 | 403 | Invalid fields (e.g. an invalid or unverified `From`) | `config` |
| 422 | 400 | Sender signature not found (older code, handled defensively if it appears) | `config` |
| 422 | 402 | Invalid JSON (a plugin bug) | `config` |
| 422 | 300 | Send validation: invalid address, metadata or header limits | `permanent` (this lead only) |
| 422 | 406 | Inactive recipient (suppressed after a bounce or spam complaint) | `permanent` (this lead only) |
| 413 | — | Message too large (over 10 MB) | `permanent` |
| other | other | Unknown | `permanent` |

- `temporary` = the lead stays `pending` and is rescheduled according to the backoff (§9.2).
- `ambiguous` = the lead stays `pending` and is checked before resending (§7.4).
- `config` = an error that affects **all** leads: the lead becomes `failed` and an **immediate alert** is sent, without waiting for the threshold.
- `permanent` = the problem concerns only this lead: `failed`, alert according to the threshold.
- The error text saved in `last_error_message` is the Postmark message truncated to 255 characters, with email addresses replaced by `***`.
- For 406, the alert and the admin list (v3) explain that the location's address must be reactivated in Postmark (Suppressions / Bounces) before resending.

### 7.3.1. Test message

The "Send test email" button in the settings uses the same classification and shows the explanation and the code, for example: "The test email was not sent: The Postmark account is still pending approval: you can only send to addresses on the sender's domain. pm_412".

### 7.4. Ambiguous timeout, without `manual_review`

The problem: the request went out, but no response came back. Postmark may or may not have sent the email.

The fully automatic solution:

1. The lead stays `pending`, with `last_error_code = ambiguous_timeout` and `next_retry_at = now + 5 minutes`.
2. On the next run, before resending, the worker searches for the message in Postmark:
   `GET /messages/outbound?count=1&offset=0&messagestream={stream}&metadata_llt_lead_id={id}`
3. **Found** (status `Sent`, `Processed` or `Queued`) → `accepted`, with the `MessageID` from the result. Not resent.
4. **Not found** → resent normally.
5. **The search fails** → rescheduled according to the backoff; the number of attempts is still capped by `max_retries`.

**Verified against the Postmark documentation (Messages API):**

- The `metadata_{key}` filter exists; you can filter by one metadata field at a time, which is enough here.
- `count` and `offset` are required.
- Without `messagestream`, the search covers only the `outbound` stream. The plugin always passes the stream from the settings, so the check also works with a custom stream.
- Date filters use Eastern Time; the plugin does not use them, so this is not an issue.
- Messages stay searchable for the Postmark account's retention period (45 days by default), far beyond the check window.

The 5-minute pause gives the message time to appear in search; the exact indexing delay is not documented, so it should be tested on the real account.

---

## 8. State model

### 8.1. `send_status`

```mermaid
stateDiagram-v2
    [*] --> pending: lead created
    pending --> accepted: Postmark accepted
    pending --> pending: temporary error or ambiguous timeout
    pending --> failed: permanent error / retries exhausted
    failed --> pending: manual resend (CLI or admin v3)
    accepted --> [*]
```

`failed` is the only status that needs attention; the administrator is notified by email and can retry it from the CLI or, from v3, from the failed-leads list. A `failed` lead can be marked as dismissed (`dismissed_at`) without changing its status.

### 8.2. `delivery_status` (v2)

```mermaid
stateDiagram-v2
    [*] --> unknown
    unknown --> deferred: temporary bounce
    unknown --> delivered: Delivery
    unknown --> bounced: permanent bounce
    deferred --> delivered
    deferred --> bounced
    delivered --> [*]
    bounced --> [*]
```

In v1, `delivery_status` always stays `unknown`.

The monotonic rule: `delivered` and `bounced` are never overwritten. The transition is done with `UPDATE ... WHERE delivery_status IN (<allowed states>)`.

---

## 9. The send queue

### 9.1. Cron

- Recommended: `DISABLE_WP_CRON = true` + a real server cron every minute:
  `* * * * * wp cron event run --due-now --path=/path/to/site`
- Without a real cron, the plugin works with the standard WP-Cron, but retries depend on traffic.

| Event | Frequency | Component | Stage |
| --- | --- | --- | --- |
| `llt_send_lead` | once, on submit | `SendWorker` | v1 |
| `llt_process_queue` | every minute | `SendWorker` | v1 |
| `llt_health_check` | hourly | `HealthCheck` | v1 |
| `llt_retention_cleanup` | daily | `RetentionService` | v1 |
| `llt_refresh_proxy_ranges` | weekly | `ProxyRanges` | v1 |
| `llt_reconcile_delivery` | hourly | `Reconciler` | v2 |

The worker saves a heartbeat in `llt_state`; if it has not run for more than 15 minutes, the **Status** tab and `wp llt status` show a warning, and `HealthCheck` reports leads stuck in `pending` by alert.

### 9.2. Backoff

A configurable schedule (default `1, 5, 15, 60, 360` minutes), ±20% jitter, respects `Retry-After`:

`next_retry_at = now + max( interval[retry_count] × (1 ± 0.2), retry_after )`

After the last retry: `failed`, `last_error_code = retries_exhausted`.

### 9.3. Locking

```sql
UPDATE {prefix}location_leads
SET locked_until = UTC_TIMESTAMP() + INTERVAL 5 MINUTE, lock_token = %s
WHERE id = %d
  AND send_status = 'pending'
  AND next_retry_at <= UTC_TIMESTAMP()
  AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP());
```

`rows_affected = 1` → the worker owns the lead. The lock expires on its own if the process dies.

### 9.4. Algorithm

1. Select up to 20 due `pending` leads, ordered by `next_retry_at`.
2. For each: claim → (if `ambiguous_timeout`: check as in §7.4) → load the message → send → update → release the lock.
3. Missing content → `failed`, `content_missing`.
4. After each `failed`, `AdminNotifier` is informed.

---

## 10. Administrator alerts (`AdminNotifier`)

### 10.1. When

| Trigger | Timing |
| --- | --- |
| Configuration error (the `config` category, §7.3) | immediately |
| N consecutive `failed` leads (setting, default 3) | when the threshold is reached |
| `pending` leads overdue by more than 1 hour but not sent (cron stopped or Postmark unavailable); leads waiting for a scheduled retry are not counted | at the hourly check (`HealthCheck`) |
| v2: bounce | optional (setting), grouped |
| v2: webhook seems to be stopped (§13.5) | at the hourly check |
| Back to normal after an alert | a single "Sending works again" notification |

The consecutive-failure counter resets on the first accepted send.

### 10.2. How

- Channel: `wp_mail()`, **not** Postmark. If the problem is Postmark itself, the alert still gets through (via the server's default mailer or the site's SMTP plugin).
- Risk: many hosts block PHP's `mail()` function. Without a configured SMTP plugin, `wp_mail()` can fail silently and alerts don't arrive. Therefore:
  - the **Alerts** tab has a **"Send test alert"** button, which sends through exactly the same channel and shows whether `wp_mail()` reported an error (the `wp_mail_failed` hook);
  - if sending an alert fails, the failure is saved in `llt_state` and shown in the **Status** tab and in `wp llt status`;
  - from v3, the counter in the admin menu flags failed leads even if the alert email did not arrive.
- An option in the Alerts tab: **"Fallback"** — send alerts through Postmark if `wp_mail()` fails. Useful when the server cannot send email but Postmark works (e.g. a single lead with an invalid address). It does not help when the problem is the Postmark account itself.
- Recipient: the **"Alert email"** setting (default `admin_email`).
- Rate limiting: at most one alert of the same type every X hours (setting, default 6); events in that interval are accumulated into the next alert.
- Content **without personal data**: the number of affected leads, IDs, error code and message, the date of the first and last failure, plus the recommended CLI commands.

Example:

```
Subject: [Site] 3 leads could not be sent

Postmark rejected the send: the API token is invalid (ErrorCode 10).
Check the token in Settings → Enspire Email Leads Tracker → Postmark.
Affected leads: #812, #813, #814 (first failure: 2026-10-20 14:02 UTC).

The content of these messages is kept for 14 days.
After fixing the problem, resend them from:
https://example.com/wp-admin/options-general.php?page=enspire-email-leads-tracker&tab=failed
or run:  wp llt retry --failed
```

The link to the failed-leads list appears from v3; in v1 the alert contains only the CLI command.

---

## 11. Privacy and GDPR

### 11.1. What "exporters / erasers" are

WordPress has two tools under **Tools → Export Personal Data** and **Tools → Erase Personal Data**. When a person requests their data (a right guaranteed by the GDPR), the administrator enters their email address, and WordPress asks every plugin what data it holds about them.

The plugin registers with these tools:

- **Export** (`PersonalDataExporter`): finds the messages in `location_lead_messages` with `sender_email` = the requested address and includes them in the archive generated by WordPress (name, email, phone, message, date).
- **Erasure** (`PersonalDataEraser`): deletes those messages. The minimal lead remains, since it contains no visitor data.

So a GDPR request is handled from the standard WordPress interface, without database access.

### 11.2. What data is kept

| Data | Where | How long |
| --- | --- | --- |
| Visitor name, email, phone, message | `location_lead_messages` | Temporarily (§12) |
| Post ID, recipient email (location), statuses, dates | `location_leads` | Permanently (history) |
| Consent date | `location_leads.consent_at` | Permanently |
| Visitor IP | Only a hash, in the rate-limiting transient | 1 hour |
| Copy of the sent email | Postmark (Activity) | The Postmark account's retention (45 days by default, 7–14 recommended) |

### 11.3. Privacy policy text

Through `wp_add_privacy_policy_content()`, the plugin suggests a text under **Settings → Privacy** that describes: the data collected, the purpose, sending through Postmark, Cloudflare Turnstile (if enabled), and the retention periods. The administrator copies it into their own page.

---

## 12. Retention (`RetentionService`)

| Situation | Content in `lead_messages` | Stage |
| --- | --- | --- |
| `pending` (retry in progress) | kept | v1 |
| `accepted` | deleted after X hours (setting, default 24) | v1 |
| `failed` | deleted after Y days (setting, default 14) | v1 |
| `delivered` | **deleted immediately** by `EventProcessor` | v2 |
| `accepted` + `unknown` / `deferred` (no confirmation) | deleted after Z days (setting, default 7) | v2 |
| `bounced` | deleted after Y days | v2 |
| Any time | deleted manually (CLI, admin v3) or on a GDPR request (§11.1) | v1 |

In v2, the "X hours after acceptance" rule is replaced by "immediately after delivery / Z days without confirmation".

Deletion runs daily, in batches of 500. The minimal lead is never deleted automatically.

`failed` leads can be resent only while their content exists (Y days). If more time is needed to fix the problem, increase Y in the settings.

Backups, server logs and the copies kept by Postmark are not affected, and are mentioned in the privacy policy. Postmark keeps messages (including content) for the account's retention period: 45 days by default, configurable between 7 and 365 days in the Postmark account. For data minimization, Postmark retention can be reduced to 7 days; duplicate-send protection (§7.4) and reconciliation (§13.5) only need a few days.

---

## 13. Delivery webhook (v2)

### 13.1. Endpoint

```
POST https://example.com/wp-json/llt/v1/postmark
```

Configuration in Postmark (Server → Message Stream → Webhooks): the **Delivery** and **Bounce** events, a URL with Basic Auth:

```
https://{user}:{password}@example.com/wp-json/llt/v1/postmark
```

The user and password are generated on the settings page; the full URL is copied with a button.

**Verified against the Postmark documentation:**

- Postmark **does not sign** webhooks (no HMAC). The recommended methods are exactly Basic Auth + HTTPS + optional IP filtering.
- When a webhook is created or edited, Postmark sends a test request for each event type and expects `200`. So the endpoint must also respond `200` to test payloads (uncorrelated events → `200`).
- Delivery and Bounce are enabled separately, on the same URL.

**Application Passwords:** on sites that use application passwords, WordPress would try to authenticate Postmark's Basic Auth credentials as a WordPress user and reject the request with 401 before it reaches the plugin. The plugin excludes only the webhook route from this check (the `application_password_is_api_request` filter).

**Optional IP filter** (setting, off by default): accepts requests only from the webhook IPs published by Postmark (`3.134.147.250`, `50.31.156.6`, `50.31.156.77`, `18.217.206.57` at the time of checking). The source IP is determined with `ClientIp` (§6.5), so it also works behind Cloudflare. It is off by default because Postmark may change the IPs, and Basic Auth is enough.

### 13.2. Processing

```mermaid
sequenceDiagram
    participant PM as Postmark
    participant WC as WebhookController
    participant EP as EventProcessor
    participant DB as Database

    PM->>WC: POST JSON
    WC->>WC: Basic Auth (hash_equals), optional IP filter, body limit
    alt authentication failed
        WC-->>PM: 401
    end
    WC->>EP: handle(payload)
    EP->>DB: look up by MessageID, then Metadata.llt_lead_id
    EP->>DB: UPDATE with the monotonic rule
    alt Delivery applied
        EP->>DB: DELETE temporary content
    end
    alt internal error
        WC-->>PM: 500 (Postmark retries)
    else
        WC-->>PM: 200
    end
```

### 13.3. Event mapping

Verified against the Postmark documentation (Bounce API → Bounce types, Bounce webhook). The Bounce payload contains `Type`, `TypeCode`, `Inactive` (Postmark deactivated the address), `CanActivate`, `BouncedAt`, `MessageID` and `Metadata`. Spam complaints and manual deactivations normally have separate webhooks; if they do arrive at this endpoint, they are handled according to the table.

**The main rule:** if `Inactive = true`, Postmark has suppressed the address, so the result is final → `bounced`, regardless of type.

Otherwise, by `TypeCode`:

| `Type` (`TypeCode`) | `delivery_status` |
| --- | --- |
| `Delivery` (a separate event) | `delivered`, `delivered_at = DeliveredAt` |
| `HardBounce` (1), `BadEmailAddress` (100000), `Blocked` (100006), `DMARCPolicy` (100009), `SMTPApiError` (100007), `ManuallyDeactivated` (100002) | `bounced` |
| `Transient` (2), `DnsError` (256), `SoftBounce` (4096), `Unknown` (2048) | `deferred` — Postmark may still deliver; waiting for `Delivery` or a final bounce |
| `AutoResponder` (64), `SpamNotification` (512) | ignored — the message was actually delivered (auto-reply / filtered as spam at the recipient); waiting for `Delivery` |
| `Unsubscribe`, `Subscribe`, `AddressChange`, `OpenRelayTest`, `VirusNotification`, `ChallengeVerification` and any other type | ignored |

The bounce type is saved in `last_error_code` (e.g. `bounce:HardBounce`), and `Inactive` in `last_error_message`, so they can be shown in the admin list (v3).

### 13.4. Rules

**Response codes** (verified: Postmark retries only on 5xx, 408, 429 and network errors; any other 4xx means the event is dropped):

| Situation | Response |
| --- | --- |
| Wrong Basic Auth / IP not allowed | `401` (Postmark does not retry — correct, the request is not from Postmark or the setup is wrong) |
| Event processed, duplicate, uncorrelated or of an ignored type | `200` |
| Database error or another internal error | `500` — Postmark retries |
| Invalid payload (not JSON, missing `RecordType`) | `200` + log, so a broken event does not block sending |
| Payload too large | `413` |

**Idempotency:** the conditional update (`UPDATE ... WHERE delivery_status NOT IN ('delivered','bounced')`) makes a repeated event have no effect. Postmark also sends the `X-PM-Webhook-Trace-Id` header, stable across retries; it is logged for debugging but is not needed for correctness.

**Other rules:**

- The webhook never creates leads.
- A webhook arriving before `MessageID` is saved (possible, because sending is asynchronous) → correlated through `Metadata.llt_lead_id`, and `postmark_message_id` is filled in then. Metadata pointing to a lead that already has a different `MessageID` is not correlated.
- Fast handler, no external calls.

### 13.5. Reconciling missed events (`Reconciler`)

**Why:** the Postmark documentation states that a failed event is retried 6 times, at 1, 5, 10, 10, 10 and 15 minutes (about 50 minutes in total), and then dropped. If the endpoint fails repeatedly, Postmark marks the event type as unverified and **stops** sending it until it is manually verified again. A site down for an hour or a bad update would mean confirmations are lost for good.

**The solution:** an hourly job checks directly in Postmark the leads still without confirmation.

1. Select up to 50 leads with `send_status = 'accepted'`, `delivery_status IN ('unknown','deferred')`, `sent_at` older than 2 hours and newer than Z days.
2. For each: `GET /messages/outbound/{MessageID}/details`.
3. From `MessageEvents`:
   - `Delivered` → `delivered`, with `ReceivedAt` as `delivered_at`;
   - `Bounced` → the event contains only `BounceID`, so `GET /bounces/{BounceID}` is requested for `TypeCode` and `Inactive`, then the mapping from §13.3 is applied;
   - `Transient` → `deferred`.
4. The update goes through the same `EventProcessor`, so the same monotonic and content-deletion rules apply.
5. Requests are sequential, at most 50 leads per run, so as not to hit the Postmark rate limit; a 429 response stops the current run. A lead with no events is moved to the end of the line (its `updated_at` is refreshed).

**Stopped-webhook alert:** if there are leads accepted in the last 24 hours, but no webhook was received in that interval, and reconciliation finds deliveries, `AdminNotifier` sends "The Postmark webhook seems to be stopped", asking the administrator to check it in Postmark → Server → Message Stream → Webhooks and click Verify.

Reconciliation turns the webhook from the single source of truth into an optimization: confirmation arrives within seconds when the webhook works and within an hour at most when it doesn't.

---

## 14. Data model

### 14.1. `{prefix}location_leads`

```sql
CREATE TABLE {prefix}location_leads (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  object_id           BIGINT UNSIGNED NOT NULL,
  recipient_email     VARCHAR(254)    NOT NULL,
  postmark_message_id VARCHAR(64)     NULL,
  send_status         VARCHAR(20)     NOT NULL DEFAULT 'pending',
  delivery_status     VARCHAR(20)     NOT NULL DEFAULT 'unknown',
  retry_count         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_retries         TINYINT UNSIGNED NOT NULL DEFAULT 5,
  next_retry_at       DATETIME        NULL,
  locked_until        DATETIME        NULL,
  lock_token          CHAR(32)        NULL,
  last_attempt_at     DATETIME        NULL,
  last_error_code     VARCHAR(40)     NULL,
  last_error_message  VARCHAR(255)    NULL,
  consent_at          DATETIME        NOT NULL,
  manual_resend_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_resend_by      BIGINT UNSIGNED NULL,
  last_resend_at      DATETIME        NULL,
  dismissed_at        DATETIME        NULL,
  dismissed_by        BIGINT UNSIGNED NULL,
  created_at          DATETIME        NOT NULL,
  sent_at             DATETIME        NULL,
  delivered_at        DATETIME        NULL,
  updated_at          DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_message_id (postmark_message_id),
  KEY idx_object (object_id),
  KEY idx_send_status (send_status),
  KEY idx_delivery_status (delivery_status),
  KEY idx_queue (send_status, next_retry_at),
  KEY idx_reconcile (send_status, delivery_status, sent_at),
  KEY idx_created (created_at)
);
```

- `UNIQUE` on `postmark_message_id` allows multiple `NULL` values in MySQL/MariaDB, so it is unique only when it has a value.
- All dates are stored in UTC and displayed in the site's time zone.

Columns added compared to the initial specification: `locked_until`, `lock_token` (locking), `consent_at` (proof of consent), `manual_resend_count`, `last_resend_by`, `last_resend_at` (trail of manual resends, v3), `dismissed_at`, `dismissed_by` (dismissed leads, v3). The `*_by` columns hold the WordPress user ID.

### 14.2. `{prefix}location_lead_messages`

```sql
CREATE TABLE {prefix}location_lead_messages (
  lead_id      BIGINT UNSIGNED NOT NULL,
  sender_name  VARCHAR(200)    NOT NULL,
  sender_email VARCHAR(254)    NOT NULL,
  sender_phone VARCHAR(50)     NULL,
  message      TEXT            NOT NULL,
  created_at   DATETIME        NOT NULL,
  PRIMARY KEY  (lead_id),
  KEY idx_sender_email (sender_email),
  KEY idx_created (created_at)
);
```

The index on `sender_email` serves GDPR export/erasure.

### 14.3. Options

| Option | Content | Autoload |
| --- | --- | --- |
| `llt_settings` | All settings; secrets encrypted | no |
| `llt_db_version` | Schema version | yes |
| `llt_state` | Cron heartbeat, consecutive-failure counter, last alert per type, last failed alert, last webhook received, last reconciliation | no |
| `llt_proxy_ranges` | The Cloudflare IP list + update date | no |

---

## 15. Settings page

**Settings → Enspire Email Leads Tracker**, capability `manage_options`, Settings API.

| Tab | Fields | Stage |
| --- | --- | --- |
| **General** | Sender email, sender name, email subject (with `{post_title}`, `{site_name}`), email format (plain text / HTML) | v1 |
| **Postmark** | Server API Token (secret), Message Stream, timeout; **"Send test email"** button | v1 |
| **Form** | GDPR consent text (textarea, limited HTML), confirmation message for the visitor | v1 |
| **Anti-spam** | Cloudflare Turnstile, Site Key, Secret Key (secret), behavior if the check is unavailable, messages per IP per hour, minimum time to fill in, IP source, additional proxies, IP diagnostics (§6.5) | v1 |
| **Retries** | Maximum number of retries, intervals in minutes | v1 |
| **Alerts** | Alert email, consecutive failure threshold, minimum interval between alerts (hours), Postmark fallback, "Send test alert" button, bounce alert (v2) | v1 |
| **Retention** | Hours after acceptance (v1), days for failed leads, days without confirmation (v2), delete data on uninstall | v1 / v2 |
| **Webhook** | Delivery confirmation (enable), URL (read-only, "Copy" button), Basic Auth user + password ("Generate credentials" button), Postmark IP filter (optional), date of the last webhook received, date of the last reconciliation | v2 |
| **Status** | Last cron run, `pending` / `failed` leads, last alert sent, last failed alert, date of the Cloudflare list (read-only) | v1 |
| **Failed leads** | List, filters, bulk resend (§17) | v3 |

### 15.1. Secrets (`SecretStore`)

- The Postmark token, the Turnstile Secret Key and the webhook password are stored encrypted (`sodium_crypto_secretbox`).
- Key: `LLT_ENCRYPTION_KEY` from `wp-config.php` if it exists (optional), otherwise derived from `wp_salt( 'secure_auth' )`.
- A saved secret is never shown back; the field shows `••••••••`. An empty field on save = the value is left unchanged.
- If the WordPress salts change, the secrets can no longer be decrypted: the page detects this and asks for them to be re-entered.

No other setting is hardcoded in constants.

### 15.2. Validation

- Emails with `is_email()`; the retry schedule: positive, increasing integers, at most 10 values; the number of retries cannot exceed the number of intervals.
- Additional proxies: a valid IPv4 or IPv6 CIDR on each line.
- The GDPR text goes through `wp_kses` with the list of allowed tags.
- Errors are reported with `add_settings_error()`.

---

## 16. WP-CLI

The commands never show visitors' personal data unless explicitly requested.

| Command | Action |
| --- | --- |
| `wp llt status` | Cron status, leads by status, latest errors, valid / invalid configuration |
| `wp llt leads list [--status=failed] [--since=2026-10-01] [--format=table\|csv\|json]` | Lead list (without content) |
| `wp llt leads show <id> [--with-content]` | Lead details; with `--with-content` also the message, if it still exists |
| `wp llt retry <id>…` / `wp llt retry --failed` | Puts the leads back into `pending` and resets `retry_count` |
| `wp llt send-test <email>` | Test email through Postmark |
| `wp llt queue run` | Runs the worker immediately |
| `wp llt cleanup run [--dry-run]` | Runs retention |
| `wp llt reconcile run` | Runs reconciliation immediately (v2) |
| `wp llt content delete <id>` | Deletes a lead's content manually |
| `wp llt stats [--since] [--until] [--by=object]` | Number of accepted / failed leads; in v2 also delivered / bounced, optionally per location |

`retry` refuses leads whose content was already deleted and explains why.

The CLI commands and the admin page (v3) use the same `LeadActions` class, so they behave identically.

---

## 17. Failed leads in the admin (v3)

### 17.1. Access

- The **"Failed leads"** tab in **Settings → Enspire Email Leads Tracker**:
  `options-general.php?page=enspire-email-leads-tracker&tab=failed`
- Capability: `manage_options` (administrators only), checked on display, on every action and on every AJAX request. Editors and other roles see neither the tab nor the counter.
- Menu counter: **"Enspire Email Leads Tracker (3)"** — the number of `failed`, non-dismissed leads with content available. The count is cached (a 5-minute transient, invalidated on any status change).

### 17.2. Configuration banner

If the last failure is a configuration error (the `config` category, §7.3), this appears above the list:

> **Sending is not working: the Postmark token is invalid (pm_10).** Fix the settings in the Postmark tab and send a test email before resending the leads.

The banner links to the Postmark tab. Resending is not blocked, but without a fix the leads will fail again.

### 17.3. The list (`FailedLeadsTable`, based on `WP_List_Table`)

| Column | Content |
| --- | --- |
| ☐ | Checkbox (enabled for all rows, so leads without content can be dismissed in bulk; on resend they are skipped and reported) |
| ID | `#812` |
| Date | `created_at`, in the site's time zone |
| Location | The post title, with an edit link |
| Recipient | `recipient_email` |
| Error | Code + short message; a warning icon for errors that will likely repeat (invalid address, recipient inactive in Postmark) |
| Attempts | "N automatic, M manual" (`retry_count` + `manual_resend_count`) |
| Last attempt | `last_attempt_at` |
| Content | "Available — deleted in 9 days" or "Deleted — cannot be resent" |

**Views** (links above the list, with counts):

| View | Criterion |
| --- | --- |
| Failed (default) | `send_status = failed`, `dismissed_at IS NULL` |
| Stuck | `send_status = pending` and `next_retry_at` overdue by more than 1 hour |
| Bounced (v2) | `delivery_status = bounced`, `dismissed_at IS NULL` |
| Dismissed | `dismissed_at IS NOT NULL` |

**Filters**: error code (dropdown), location (search by title), date range.
**Pagination**: 20 per page (configurable in "Screen Options"). **Sorting**: date, location, attempts.

**Visitor data does not appear in the list.** The **"View message"** row action loads it on demand (AJAX, nonce, `manage_options`): name, email, phone, message. Useful when the administrator wants to contact the visitor directly.

### 17.4. Selection

- The header checkbox selects all leads on the current page (standard WordPress behavior).
- After the page is selected, the **"Select all 47 leads matching the filter"** link appears. The action is then applied on the server to all leads matching the current filters, not just the visible ones.

### 17.5. Actions

| Action | Type | Effect |
| --- | --- | --- |
| **Resend** | row + bulk | Puts the lead back into the send queue |
| **Dismiss** | row + bulk | Sets `dismissed_at`, `dismissed_by`; the lead leaves "Failed", the status stays `failed` |
| **Restore** | row + bulk (in "Dismissed") | Clears `dismissed_at` |
| **Delete content** | row + bulk | Deletes the visitor's data immediately; asks for confirmation |
| **View message** | row | Shows the visitor's data (§17.3) |

Actions available per view:

| View | Actions |
| --- | --- |
| Failed | Resend, Dismiss, Delete content, View message |
| Stuck | View message (the leads are already in the queue; check cron in the Status tab) |
| Bounced (v2) | Dismiss, Delete content, View message — no Resend, because the address rejected the message; the visitor can be contacted directly |
| Dismissed | Restore, Delete content, View message |

### 17.6. The "Resend" flow

```mermaid
sequenceDiagram
    participant A as Administrator
    participant LA as LeadActions
    participant DB as Database
    participant SW as SendWorker

    A->>LA: Resend (IDs or "all matching the filter")
    LA->>LA: check_admin_referer, manage_options
    LA->>DB: UPDATE … SET pending, retry_count = 0, next_retry_at = now, manual_resend_count + 1, last_resend_by, last_resend_at, dismissed_at = NULL WHERE send_status = 'failed' AND content exists
    LA->>LA: schedule llt_process_queue + spawn_cron()
    LA-->>A: redirect + notice: "12 leads were put in the send queue… 2 skipped: their content was already deleted by retention."
    SW->>DB: picks up the leads within the next minute
```

Rules:

- Resending **does not send the emails in the admin request**: it queues them. So 200 leads don't block the page and don't exceed PHP's maximum execution time.
- `UPDATE ... WHERE send_status = 'failed'` is atomic: two quick clicks or two administrators at once do not cause double sends.
- Leads without content are skipped and reported in the notice.
- Leads with errors that will likely repeat (recipient inactive in Postmark) are resent if selected, but the notice warns that they may fail again. For a recipient deactivated after a bounce, the address must first be reactivated in Postmark.
- Resending resets `retry_count`, so the lead gets the full automatic retry cycle again.
- Email alerts (§10) are not sent again for manually resent leads until a new complete failure.
- A successfully resent lead moves to `accepted` and follows normal retention.

### 17.7. Notices after actions

The result of each action is kept in a per-user transient and shown as an admin notice after the redirect, with the current filters preserved. Resent leads appear in the "Stuck" view only if they are not sent more than an hour after becoming due.

---

## 18. Security

| Risk | Measure |
| --- | --- |
| Exposing the recipient email | Not in the HTML; read server-side from post meta |
| Sending to another post | `object_id` HMAC-signed; re-read and validated on submit |
| A bot bypassing the time-trap | The render time is in the signed context |
| Spam | Honeypot, time-trap, rate limiting, optional Turnstile |
| Forged IP in headers (`CF-Connecting-IP`, `X-Forwarded-For`) | Headers are read only if the connection comes from a known proxy (§6.5) |
| Blocking the form for all visitors behind a proxy | Automatic real-IP detection (§6.5) + diagnostics in the settings |
| Cached form | No nonce; a signed context that does not expire |
| Access to settings | `manage_options` + nonce in the admin |
| Secrets | Encrypted in the database; never shown or logged |
| SQL injection | `$wpdb->prepare()`, `insert()`, `update()` |
| XSS | Escaping in templates and email; `wp_kses` on the GDPR text |
| Header injection | Stripping `\r\n` from the name and subject |
| Forged webhook (v2) | Constant-time Basic Auth, HTTPS, body limit, optional Postmark IP filter (Postmark does not sign webhooks) |
| Personal data in logs / alerts | Only IDs, error codes, statuses |
| Access to leads in the admin (v3) | `manage_options` on display, actions and AJAX; visitor data only on demand |
| CSRF in the admin (v3) | `check_admin_referer` for bulk actions, nonce for AJAX |
| Double sends from the admin (v3) | `UPDATE` conditioned on `send_status = 'failed'` + the queue lock |

---

## 19. WordPress hooks

| Hook | Component |
| --- | --- |
| `init` | Shortcode |
| `wp_enqueue_scripts` | `form.js` + Turnstile only on pages with the shortcode |
| `admin_menu`, `admin_init` | Settings page |
| `admin_post_llt_test_email` | Test email |
| `admin_post_llt_test_alert` | Test alert |
| `admin_post_nopriv_llt_submit`, `admin_post_llt_submit` | Submit |
| `llt_send_lead`, `llt_process_queue`, `llt_health_check`, `llt_retention_cleanup` | Cron (§9.1) |
| `wp_mail_failed` | Detecting unsent alerts |
| `wp_privacy_personal_data_exporters`, `wp_privacy_personal_data_erasers` | GDPR |
| `admin_init` → `wp_add_privacy_policy_content` | Policy text |
| `rest_api_init` | Webhook (v2) |
| `application_password_is_api_request` | Skipping Application Passwords on the webhook route (v2) |
| `llt_reconcile_delivery` (hourly) | `Reconciler` (v2) |
| `llt_refresh_proxy_ranges` (weekly) | `ProxyRanges` |
| `cli_init` / `WP_CLI::add_command` | WP-CLI |
| `admin_post_llt_lead_action` | Lead actions (v3) |
| `wp_ajax_llt_lead_details` | "View message" (v3) |
| `admin_menu` (counter) | `MenuBadge` (v3) |
| `set-screen-option` | Rows per page (v3) |
| activation / deactivation | `Installer` |

Filters: `llt_email_subject`, `llt_email_body`, `llt_form_template`, `llt_recipient_email`.

---

## 20. Testing

| Level | v1 | v2 |
| --- | --- | --- |
| Unit | `ErrorClassifier`, `BackoffPolicy`, `ContextSigner`, `SecretStore`, `RecipientResolver`, `ClientIp` (direct, Cloudflare, local proxy, forged header) | Event mapping (including `Inactive`), monotonicity |
| Recipient | Post without `email`, with an invalid `email`, unpublished between render and submit, shortcode without `object_id` outside the loop | — |
| Reconciliation | — | Stopped webhook → leads become `delivered` / `bounced` through the Messages API; 429 stops the run |
| Integration | Full submit with a mocked Postmark (`pre_http_request`); Postmark test token (`POSTMARK_API_TEST`) | Real Delivery / Bounce payloads |
| Async | Fast response to the visitor; sending after the response; cron fallback | — |
| Ambiguous timeout | Message found in Postmark → no resend; not found → resend | — |
| Concurrency | Immediate send + worker on the same lead → a single email | Webhook before `MessageID` is saved |
| Alerts | Threshold, rate limiting, recovery alert, delivery via `wp_mail` when Postmark is down | Bounce alert |
| GDPR | Export and erasure by email; required checkbox | — |
| Retention | Deletion after X hours / Y days | Immediate deletion after `Delivery` |
| CLI | All commands, including `retry` on deleted content | `stats` with delivered / bounced |

**v3**:

| What is checked | Expected result |
| --- | --- |
| Access | Editors don't see the tab or the counter and cannot call the actions or the AJAX |
| Bulk resend | Leads become `pending` and are sent by the worker; the page responds quickly even with 500 leads |
| "Select all matching the filter" | The action applies exactly to the leads matching the filters |
| Double click / two administrators | A single send per lead |
| Deleted content | The lead is skipped and reported |
| Dismiss / Restore | The lead disappears and reappears in the views without a status change |
| Menu counter | Correct after every action |

The automated suite (`php tests/run.php`) runs without WordPress: WordPress functions are stubbed and `$wpdb` is backed by in-memory SQLite. `php tests/check-calls.php` statically checks that every `Class::method` call resolves.

---

## 21. Acceptance criteria

### v1

- The shortcode shows the form; the recipient email does not appear in the HTML.
- The recipient is read from the `email` post meta of the specified or current post, at render time and again on submit.
- Rate limiting works per visitor, also behind Cloudflare or a proxy; forged headers are ignored.
- The lead and message are saved before sending; the visitor gets a response without waiting for Postmark.
- The email is sent through the Postmark API, and the `MessageID` is attached to the lead.
- Temporary errors are retried automatically; ambiguous timeouts do not cause duplicates.
- The administrator receives an email on failures, without personal data.
- Failed leads can be inspected and retried from WP-CLI.
- The GDPR checkbox is required, with text from the settings.
- A visitor's data can be exported and erased from the WordPress tools.
- Turnstile works when enabled.
- All settings are on the settings page; secrets are encrypted.
- Content is deleted according to retention; the minimal lead remains.

### v2

- The Postmark webhook updates `delivery_status` and `delivered_at`.
- Duplicate or late events do not change final statuses.
- Content is deleted right after `Delivery`.
- Events missed by the webhook are recovered by reconciliation within an hour.
- `wp llt stats` reports delivered and bounced leads.

### v3

- The "Failed leads" tab is visible only to administrators.
- Failed leads can be filtered, selected (on the page or all matching the filter) and resent without WP-CLI.
- Resending uses the queue; it causes no duplicates and does not block the page.
- Leads without content cannot be resent and are clearly flagged.
- Leads can be dismissed and restored; content can be deleted manually.
- Visitor data appears only on demand.
- The menu shows the number of failed leads that need attention.

---

## 22. Installation and requirements

### 22.1. Requirements

| Requirement | Details |
| --- | --- |
| WordPress | 6.0+ |
| PHP | 7.4+, with `sodium` (bundled by default since PHP 7.2) |
| Database | MySQL 5.7+ / MariaDB 10.3+ |
| Install type | **Single site.** WordPress Multisite is not supported in this version (tables, settings and cron would have to be separated per site in the network). |
| HTTPS | Required for the webhook (v2) |
| Cron | A real server cron every minute is recommended (§9.1) |

### 22.2. Postmark setup

1. **The sender domain** is verified in Postmark (Sender Signatures → Domains) with the **DKIM** and **Return-Path** records added to DNS. Without them, emails are more likely to land in spam. The webhook correctly reports "delivered" (the recipient's server accepted the message), but that does not guarantee it reached the Inbox — explicitly confirmed in the Postmark documentation.
2. **Message Stream:** contact messages are transactional, so the **`outbound`** stream (Default Transactional Stream) is used, not a Broadcast stream. "Send test email" checks that the stream exists (errors 1235 / 1236).
3. **Account pending approval:** until the Postmark account is approved, you can only send to addresses on the same domain as the sender (error 412). Tests must use an address on your own domain.
4. **Postmark retention:** 7–14 days recommended, in the Postmark server settings (§12).
5. **Webhook (v2):** created on the `outbound` stream, with Delivery and Bounce enabled, using the URL copied from the settings, then click **Verify**.

### 22.3. Moving the site (staging → production)

The secrets (Postmark token, Turnstile keys, webhook password) are encrypted with a key derived from the WordPress salts. If the site is moved to an environment with different salts in `wp-config.php`, they can no longer be decrypted and must be re-entered; the settings page detects this and shows a warning.

To avoid re-entering them, the same key can be defined on all environments:

```php
define( 'LLT_ENCRYPTION_KEY', '...' ); // 32+ random characters, identical on staging and production
```

If `LLT_ENCRYPTION_KEY` is added after the secrets were saved, they need to be re-entered once.

### 22.4. Post-installation check

1. Each location's post has the `email` meta field filled in with a valid address.
2. **Postmark** tab → "Send test email".
3. **Alerts** tab → "Send test alert" (confirms that `wp_mail()` works).
4. **Anti-spam** tab → the IP diagnostics show the administrator's real IP.
5. **Status** tab → cron ran in the last few minutes.
6. (v2) Postmark → Webhooks → Verify, then send a test lead and confirm the `delivered` status in `wp llt leads list`.

---

## 23. Verified sources

The Postmark and Cloudflare behavior described in this document was verified against the official documentation in October 2026:

- Postmark — [API overview: HTTP codes and ErrorCode](https://postmarkapp.com/developer/api/overview)
- Postmark — [Messages API: search by metadata, message details](https://postmarkapp.com/developer/api/messages-api)
- Postmark — [Bounce API: bounce types](https://postmarkapp.com/developer/api/bounce-api)
- Postmark — [Webhooks overview: authentication, retries, verification](https://postmarkapp.com/developer/webhooks/webhooks-overview)
- Postmark — [Delivery webhook](https://postmarkapp.com/developer/webhooks/delivery-webhook)
- Postmark — [Bounce webhook](https://postmarkapp.com/developer/webhooks/bounce-webhook)
- Postmark — [IPs for firewalls (webhooks)](https://postmarkapp.com/support/article/800-ips-for-firewalls)
- Cloudflare — [IP Ranges](https://www.cloudflare.com/ips/)
