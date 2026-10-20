# Location Lead Tracker

A WordPress plugin for location contact forms. Emails are sent through [Postmark](https://postmarkapp.com), every lead is recorded in the database, failed sends are retried automatically, and visitors' personal data is deleted after a configurable period.

Full architecture: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Requirements

- WordPress 6.0+, PHP 7.4+ (with the `sodium` extension, bundled by default), MySQL 5.7+ / MariaDB 10.3+
- Single site (Multisite is not supported)
- A Postmark account with a verified sender domain (DKIM and Return-Path)
- Recommended: a real server cron every minute

## Installation

1. Copy the folder into `wp-content/plugins/` and activate the plugin.
2. **Settings → Location Lead Tracker → Postmark**: enter the Server API Token and click "Send test email".
3. **General**: the sender email and name (on the domain verified in Postmark).
4. **Form**: the GDPR consent text, with a link to the privacy page.
5. **Alerts**: the alert address, then "Send test alert".
6. On each location, fill in the `email` meta field (an ACF field named `email` works too).
7. Add the shortcode to the page or template.

### Server cron (recommended)

In `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

On the server, every minute:

```
* * * * * wp cron event run --due-now --path=/path/to/site >/dev/null 2>&1
```

The plugin works without a real cron, but retries and alerts then depend on traffic.

### Moving the site (staging → production)

Tokens are encrypted with a key derived from the WordPress salts. If the environments have different salts, define the same key on all of them:

```php
define( 'LLT_ENCRYPTION_KEY', 'a-random-value-of-at-least-32-characters' );
```

## Usage

```
[location_contact object_id="245"]
[location_contact]
```

- `object_id` — the location post; if missing, the current post is used.
- The recipient is read from the post's `email` meta and **never appears in the HTML**.
- The post must be published and have a valid `email`; otherwise editors see an explanatory message and visitors see no form.

Templates can be overridden from the theme, in `location-lead-tracker/form.php` and `location-lead-tracker/email.php`.

## How it works

1. The visitor submits the form. The plugin checks the signed context, the honeypot, the minimum fill-in time, the per-IP limit and, optionally, Cloudflare Turnstile.
2. The lead and message are saved, and the visitor gets the confirmation immediately.
3. The email goes out through Postmark right after the response (PHP-FPM / LiteSpeed) or within a minute (cron).
4. Temporary errors are retried after 1, 5, 15, 60 and 360 minutes (configurable). After an ambiguous timeout, the plugin checks in Postmark whether the message went out before resending it.
5. On failures, the administrator receives an email (without visitor data).
6. Personal data is deleted after the configured period; only the minimal lead record remains.

## Delivery confirmation (v2)

1. **Settings → Location Lead Tracker → Webhook**: click "Generate credentials" and copy the full URL (the password is shown only once).
2. In Postmark: Server → Message Stream `outbound` → Webhooks → Add webhook. Paste the URL, tick **Delivery** and **Bounce**, click **Verify**.
3. Tick "Delivery confirmation" and save.

From then on:

- leads get the `delivered`, `bounced` or `deferred` status;
- visitor data is deleted right after confirmed delivery, or after "Days without confirmation" (Retention tab);
- an hourly reconciliation asks Postmark about unconfirmed leads, in case a webhook was lost;
- if the webhook seems to be stopped, the administrator receives an alert.

If Postmark's **Verify** returns 401 although the credentials are correct, the server is stripping the `Authorization` header. On Apache, add to `.htaccess`:

```
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

## Failed leads in the admin (v3)

**Settings → Location Lead Tracker → Failed leads**, administrators only (`manage_options`). The number of leads that need attention appears next to the plugin name in the menu.

- Views: Failed, Stuck (overdue in the queue by more than an hour), Bounced (v2), Dismissed.
- Filters: error code, date range, search by location.
- Select on the page or "all leads matching the filter".
- Actions: Resend, Dismiss, Restore, Delete content. Resending puts the leads in the queue; the emails go out within the next minute.
- "View message" loads the visitor's data only on demand.

Leads whose content was already deleted by retention can no longer be resent; the list marks them as such.

## WP-CLI

```
wp llt status
wp llt leads list [--status=failed] [--since=2026-10-01] [--format=table|csv|json]
wp llt leads show <id> [--with-content]
wp llt retry <id>... | --failed
wp llt send-test <email>
wp llt queue run
wp llt cleanup run [--dry-run]
wp llt content delete <id>...
wp llt stats [--since=…] [--until=…] [--by=object]
wp llt reconcile run
```

## Testing

The tests run without WordPress (WordPress functions are stubbed and the database is in-memory SQLite):

```
php tests/run.php          # 388 / 388 checks passed
php tests/check-calls.php  # 389 calls checked, 0 problems
```

Before production, go through the checklist in `docs/ARCHITECTURE.md` §22.4 on a staging site with real WordPress.

Note: some security plugins block visitor access to `wp-admin/admin-post.php`. The form submits to it, so it must be allowed.

## Versions

- **1.2.2** — all code comments, UI strings, tests and documentation translated to English. No functional changes.
- **1.2.1** — fixes: the return URL on subdirectory installs, the definition of stuck leads (overdue, not just old), webhook compatibility with Application Passwords.
- **1.2.0 (v3)** — the "Failed leads" tab: views, filters, select by filter, bulk resend and dismiss, "View message", menu counter.
- **1.1.0 (v2)** — Postmark webhook (Delivery, Bounce) with Basic Auth, the `delivered` / `bounced` / `deferred` statuses, data deletion on delivery, hourly reconciliation, alerts for bounces and a stopped webhook.
- **1.0.0 (v1)** — sending through Postmark, a queue with retries, duplicate-send protection, a settings page with encrypted secrets, alerts, WP-CLI, GDPR, Turnstile, real IP detection, retention.
