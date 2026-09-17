# IDTA Partial Applications

Captures an eIDTA application at the end of step 3, reminds the customer once if
no order follows, and marks the lead converted when the WooCommerce order
arrives. Standalone plugin; `idta-pdf` is untouched apart from one meta field.

```
e-idta.com  ──POST /partial──▶  Cloudflare Worker  ──X-IDTA-Key──▶  WordPress
 (step 3)                       (origin allowlist)                  (this plugin)
                                                                         │
                                                        wp_idta_partial_applications
                                                                         │
                                                        Action Scheduler, +12 min
                                                                         │
                                            ┌────────────────────────────┴───────┐
                                     order arrived?                        no order
                                     mark converted,                    one reminder
                                     send nothing                        email, then
                                                                        status=reminded
```

## Install

1. Activate the plugin. The table is created on activation **and** on any
   request where `idta_partial_db_version` is behind — so replacing the folder
   over SFTP is enough to apply a schema change.
2. Put the shared secret in `wp-config.php`:
   ```php
   define( 'IDTA_PARTIAL_API_KEY', '…' );
   ```
   Without the constant, activation generates a key and shows it under
   **IDTA Partials → Settings** in the admin sidebar. The constant is preferred:
   it is not in the database, so it does not travel with a backup or a staging
   clone.
3. Give the Worker the same key and the endpoint URL:
   ```
   npx wrangler secret put PARTIAL_API_KEY
   # WP_PARTIAL_URL is already in worker/wrangler.toml
   ```
4. Check **WooCommerce → Settings → Emails → Unfinished application reminder**
   is enabled. That switch is the fastest way to stop reminders without
   touching anything else.

## Settings

**IDTA Partials → Settings** (top-level admin menu, alongside IDTA PDF).

| Setting | Default | Notes |
|---|---|---|
| Capture partial applications | on | Off: the endpoint returns 200 and stores nothing. |
| Reminder delay | 12 min | From the moment step 3 was completed. |
| Count a lead as converted | order created | See below. |
| Application URL | `https://e-idta.com/application.html` | The reminder link's base. |
| Reminder cooldown | 7 days | Per address, not per lead. |
| Keep unconverted leads | 90 days | Then deleted. |
| Keep converted lead details | 365 days | Then anonymised; the conversion is kept. |
| Submissions per hour | 20 | Per IP and per address. |

### order created vs paid

The storefront creates orders **unpaid** and sends the customer to the payment
page, so `woocommerce_new_order` means *reached checkout*, not *paid*. The
default counts that as converted, which is what the spec asks for.

Switching to **only when paid** means someone who abandons on the payment page
still gets a reminder. In that mode the pre-send order check narrows to the paid
statuses too, so the mode actually holds.

## Reading the logs

**WooCommerce → Status → Scheduled Actions**, group `idta-partial`.

Every job narrates itself into its own action's log, so one lead's outcome is one
action's log rather than something to infer from the database:

```
Queued for partial application #412 (j*******@e******.com). Due 2026-09-16 10:42:00 UTC…
Processing the reminder for partial application #412.
[1/8] Lead loaded: j*******@e******.com, status "new", created 2026-09-16 10:30:02 UTC.
[2/8] Status is "new", so a reminder is still due.
[3/8] Email address is valid.
[4/8] Address is not on the suppression list.
[5/8] No reminder has gone to this address recently.
[6/8] No WooCommerce order exists for this address; the application really was abandoned.
[7/8] Reminder handed to the mailer for j*******@e******.com.
[8/8] Lead marked "reminded".
→ Reminder sent.
```

A converted lead's action is **left queued rather than cancelled**, and
annotated. Cancelling deletes the action and with it any record that a reminder
was ever due, so the ordinary successful case would leave nothing to read:

```
Queued for partial application #413 (a*****@g****.com). Due …
Partial application #413 converted to order #8871. This reminder will send nothing when it runs.
Processing the reminder for partial application #413.
[1/8] Lead loaded: a*****@g****.com, status "converted", created …
[2/8] Status is "converted", not "new".
→ The customer completed order #8871; nothing sent.
```

Addresses are masked and no other personal data is written. Action Scheduler logs
outlive the retention job that cleans the leads themselves.

## The leads screen

**IDTA Partials** in the admin sidebar. Each row carries status pills, the
customer, a truncated lead token with a copy button, the plan, and when the
reminder went or next runs. Click the ID or name for the full record.

Row actions, all nonce-protected and `manage_woocommerce` only:

| Action | Effect |
|---|---|
| **Send now** | Sends immediately, ignoring the cooldown, the staff toggle and "already reminded" — those exist to stop the *automatic* system over-sending, and a human clicking this has made that call. Counts toward `reminder_count`. |
| **Turn reminders off / on** | Staff switch, per lead. A queued reminder still runs and logs that it sent nothing, so the decision is visible in the Action Scheduler log. |
| **Delete** | Removes the row and unschedules its reminder. Irreversible — it is the honest answer to "forget me", which a soft delete would not be. |

### Two switches, deliberately not one

| Column | Set by | Overridable by staff |
|---|---|---|
| `unsubscribed` | the customer, via the email link | **never** |
| `reminder_enabled` | staff, on this screen | yes |

Collapsing them would let staff re-enable reminders for someone who had opted
out. **Send now** is blocked by `unsubscribed` and by nothing else.

### Next-run time

Read from Action Scheduler, not from the row's `reminder_due_at`. The two can
legitimately disagree: the column says when the reminder was *meant* to go, the
queue knows when it will actually be picked up — and Action Scheduler only runs
on site traffic, so on a quiet site that is later. Staff asking "when will this
send?" want the second answer.

---

## What is never stored

Portrait, licence front and back, signature. Not sent by the frontend, not
accepted by the endpoint, not in the schema. A resumed application therefore
starts with the uploads empty, and the form says so.

## What is captured

Everything steps 1–3 collect, except images:

* **Step 1** — whether they hold a licence (read from `sessionStorage`, which is
  the only place that answer lives).
* **Step 2** — licence-issuing country, destination country.
* **Step 3** — name, email, phone (dial code and national part kept separately),
  date of birth, gender, country of birth, country of residence, licence number,
  licence classes as code **and** label, the chosen package, validity, price,
  add-ons and the quoted total.
* **Provenance** — landing path, referrer and campaign parameters.

Step 4 fields (billing and shipping address) are **not** captured, because the
capture fires before step 4 exists. The referrer is reduced to host and path —
someone else's query string can carry their own customers' identifiers — and the
landing path is accepted only if it is a path on this site.

## Endpoint

`POST /wp-json/idta/v1/partial`, header `X-IDTA-Key`.

```json
{ "lead_token": "7d0f…", "email": "a@b.com", "first_name": "…", "last_name": "…",
  "phone": "+880…", "application_type": "print_digital", "validity_years": 3,
  "product_id": 19, "currency": "USD", "locale": "en", "payload": { … } }
```

`400` malformed · `401` bad key · `429` rate limited · `500` storage failure.
`200` otherwise, including when capture is switched off.

Idempotent on `lead_token` (a UNIQUE index plus `ON DUPLICATE KEY UPDATE`). A
converted row is never downgraded: every mutable column is guarded with
`IF(status = 'converted', …)`, so a late or retried POST cannot reopen a lead and
earn it a reminder for an order already placed.

## Resume

The reminder links to
`…/application.html?resume=1&package=printed&validity=3&license_country=BD&destination_country=IT`
and makes **no API call**. Everything in that URL is application state; none of
it is personal data, because a URL reaches browser history, CDN and proxy logs,
the `Referer` header sent to every third party the page loads, and mail-provider
link scanners.

The consequence is that the personal fields come back empty. That is the trade,
not an oversight.

## Uninstall

Deactivating unschedules the jobs and **keeps the table** — deactivation is a
routine debugging step and must not destroy lead history. Deleting the plugin
runs `uninstall.php`, which drops the table and this plugin's options. Order meta
is left alone: `_idp_lead_token` belongs to the checkout flow.
