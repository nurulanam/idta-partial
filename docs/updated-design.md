# Partial / Abandoned Application Reminder

## 1. Overview

Add a partial-application tracking and reminder system to the existing IDTA application flow.

When a visitor completes **Step 3** but does not continue to create an order, the application should be saved as a partial lead. If an order is not created within the configured delay, the system sends one reminder email containing a parameterized resume URL.

If the visitor later completes the application and creates an order, the existing partial record is marked as converted and the pending reminder is cancelled or ignored.

### Core requirements

1. Capture the application immediately after successful Step 3 validation.
2. Save the partial application through the existing Cloudflare Worker.
3. Do not store uploaded images.
4. Do not make another API request when the customer clicks the reminder link.
5. Resume the application using safe URL parameters.
6. Correlate the partial record with the WooCommerce order using `lead_token`.
7. Do not send a reminder after conversion.
8. Keep existing PDF, order, payment and release-notification functionality unchanged.
9. Use Action Scheduler when available.
10. Keep the feature inside the existing `idta-pdf` plugin.

---

# 2. Existing Architecture

```text
e-idta.com
(static HTML)
     │
     │ POST /upload
     │ POST /create-order
     │ POST /partial
     ▼
Cloudflare Worker
"idta-upload"
     │
     │ WooCommerce credentials
     │ API secret
     ▼
e-iaa.com
(WooCommerce)
     │
     │ _idp_* order meta
     ▼
idta-pdf plugin
     │
     ├── Order_Data
     ├── Generator
     ├── Release_Schedule
     ├── Release_Notifier
     │
     └── Partial
           ├── Repository
           ├── REST Controller
           ├── Reminder Scheduler
           ├── Reminder Email
           ├── Conversion Listener
           └── Cleanup
```

The browser must never communicate directly with WordPress.

The Cloudflare Worker remains the only browser-to-backend gateway.

---

# 3. Important Design Decision: No Resume API

The resume email must **not** call a second API to retrieve the saved partial record.

The email link should contain only safe application-state parameters.

Example:

```text
https://e-idta.com/application.html?resume=1&package=printed&validity=3
```

The frontend reads these values directly from the URL and restores the appropriate application state.

## Do not put sensitive data in the URL

Never put the following into the resume URL:

* Email
* Phone
* Date of birth
* Driver licence number
* Passport number
* Address
* Uploaded image data
* Signature data
* Personal identification information
* Full saved payload
* API credentials
* WooCommerce credentials

URLs can appear in:

* Browser history
* Server logs
* CDN logs
* Analytics systems
* Referrer headers
* Screenshots
* Email security scanners

Therefore the URL must contain only non-sensitive application state.

---

# 4. Resume Strategy

The partial record contains the complete server-side application snapshot for tracking and future use.

However, the email resume link contains only safe values required to reconstruct the user's basic application state.

Example:

```text
https://e-idta.com/application.html
    ?resume=1
    &package=printed
    &validity=3
```

Possible parameters:

```text
resume=1
package=printed
validity=3
application_type=print_digital
```

Only parameters that are actually required by the frontend should be included.

The frontend should validate all parameters before using them.

Unknown or invalid parameters must be ignored.

---

# 5. Step 3 Capture Point

Step 3 is the correct point to create the partial application.

Existing flow:

```text
Step 1
   ↓
Step 2
   ↓
Step 3
   ↓
Continue to Checkout
   ↓
Step 4
   ↓
Create WooCommerce Order
```

The partial request should happen:

```text
Step 3 validated
      ↓
sendPartial()
      ↓
move to Step 4
```

It should **not** wait until Step 4.

In `application.html`, the existing Continue button is associated with:

```html
[data-next="4"]
```

In `application.js`, the existing `[data-next]` handler already validates the current step.

The partial capture should be inserted after successful validation and before moving to Step 4.

---

# 6. Lead Token

Every application attempt receives a `lead_token`.

The token is generated in the browser.

Preferred:

```javascript
crypto.randomUUID()
```

The token should be stored in:

```javascript
sessionStorage
```

Example:

```text
idta_lead_token
```

The same token is reused during the current application attempt.

## Purpose

The token is an idempotency/correlation key.

It allows:

```text
Partial Application
       │
       │ lead_token
       ▼
WooCommerce Order
       │
       ▼
Converted Partial Application
```

The `lead_token` is **not a secret**.

It must therefore not be used as the resume credential.

---

# 7. Lead Token Fallback

If `crypto.randomUUID()` is unavailable, generate a sufficiently random fallback using:

```javascript
crypto.getRandomValues()
```

The fallback must still produce a collision-resistant value.

The server validates the token with:

```text
^[A-Za-z0-9-]{16,64}$
```

---

# 8. Database Table

Create:

```text
{$wpdb->prefix}idta_partial_applications
```

Schema:

```text
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY

lead_token CHAR(36) NOT NULL UNIQUE

resume_token CHAR(32) NOT NULL UNIQUE

status VARCHAR(20) NOT NULL DEFAULT 'new'

source VARCHAR(20) NOT NULL DEFAULT 'idta'

email VARCHAR(190) NOT NULL

first_name VARCHAR(100) NULL

last_name VARCHAR(100) NULL

phone VARCHAR(40) NULL

application_type VARCHAR(40) NULL

validity_years TINYINT UNSIGNED NULL

product_id BIGINT UNSIGNED NULL

currency CHAR(3) NULL

locale VARCHAR(10) NULL

payload LONGTEXT NULL

ip_hash CHAR(64) NULL

unsubscribed TINYINT(1) NOT NULL DEFAULT 0

order_id BIGINT UNSIGNED NULL

created_at DATETIME NOT NULL

updated_at DATETIME NOT NULL

reminder_due_at DATETIME NULL

reminder_sent_at DATETIME NULL

converted_at DATETIME NULL
```

Indexes:

```text
PRIMARY KEY (id)

UNIQUE KEY lead_token (lead_token)

UNIQUE KEY resume_token (resume_token)

KEY status_created (status, created_at)

KEY email (email)

KEY order_id (order_id)
```

---

# 9. Status Values

Supported statuses:

```text
new
reminded
converted
expired
```

## `new`

Partial application exists and has not been reminded or converted.

## `reminded`

The reminder process has already been processed for this partial application.

Only one reminder is sent per lead.

## `converted`

The application resulted in a WooCommerce order.

No reminder should be sent.

## `expired`

The partial application is no longer resumable.

Expired records are eventually removed according to retention rules.

---

# 10. Payload

The `payload` column stores additional non-image application data as JSON.

Example:

```json
{
  "gender": "male",
  "date_of_birth": "1990-04-12",
  "country_of_birth": {
    "code": "BD",
    "name": "Bangladesh"
  },
  "country_of_residence": {
    "code": "AE",
    "name": "United Arab Emirates"
  },
  "license_issued_country": {
    "code": "BD",
    "name": "Bangladesh"
  },
  "destination_country": {
    "code": "IT",
    "name": "Italy"
  },
  "driver_license_number": "DL-99-1234",
  "license_categories": [
    "B",
    "C"
  ],
  "package": "printed",
  "validity_label": "3 Years",
  "plan_price": "49.00",
  "cart_items": [
    {
      "id": "20",
      "name": "Faster Processing",
      "price": "$9.00",
      "qty": 1
    }
  ],
  "summary_total": "$58.00",
  "step_reached": 3,
  "referrer": "https://www.google.com/",
  "utm": {
    "source": "google",
    "medium": "cpc",
    "campaign": "idp-uae"
  }
}
```

The payload must be:

* Allowlisted
* Validated
* Size-limited
* JSON encoded
* Maximum approximately 32 KB

---

# 11. Images Must Never Be Stored

The partial system must never store:

* Portrait
* Licence front
* Licence back
* Signature
* Any other uploaded image

The existing upload flow remains unchanged.

When a user resumes a partial application, the image fields must remain empty.

The UI should clearly explain:

> Your application details were saved, but uploaded photos are not stored. Please attach your photos again before submitting.

This is intentional.

---

# 12. Database Installation

Create:

```text
includes/partial/class-table.php
```

Use WordPress:

```php
dbDelta()
```

Store:

```text
idta_partial_db_version
```

The table installation must run from both:

```text
Plugin::activate()
```

and:

```text
Plugin::maybe_upgrade()
```

This is required because the production plugin may be updated by replacing plugin files through SFTP without triggering activation.

---

# 13. Plugin Upgrade

On every plugin load, check:

```text
idta_partial_db_version
```

If the installed database version is older than the current version:

```text
Partial\Table::install()
```

must run.

This makes the feature resilient to normal plugin folder replacement.

---

# 14. Plugin Deactivation

On deactivation:

```php
as_unschedule_all_actions(
    'idta_partial_send_reminder',
    array(),
    'idta-pdf'
);
```

Also unschedule cleanup actions where appropriate.

The database table must **not** be deleted on deactivation.

---

# 15. Uninstall

`uninstall.php` may remove:

```text
wp_idta_partial_applications
idta_partial_db_version
idta_partial_suppressed_emails
partial settings
```

Only when the plugin is actually uninstalled.

Deactivation must never delete application data.

---

# 16. WordPress REST Endpoint

Create:

```text
POST /wp-json/idta/v1/partial
```

The endpoint receives the partial application from the Worker.

Example:

```json
{
  "lead_token": "7d0f1a6e-....",
  "email": "customer@example.com",
  "first_name": "John",
  "last_name": "Doe",
  "phone": "+8801XXXXXXXXX",
  "application_type": "print_digital",
  "validity_years": 3,
  "product_id": 1984,
  "currency": "USD",
  "locale": "en",
  "payload": {
    "...": "..."
  }
}
```

---

# 17. REST Authentication

Use:

```text
X-IDTA-Key
```

The WordPress endpoint must verify the shared secret.

Preferred configuration:

```php
define(
    'IDTA_PARTIAL_API_KEY',
    '...'
);
```

A WordPress option may be used as a fallback.

Compare using:

```php
hash_equals()
```

Never use:

```php
'__return_true'
```

as the permission callback.

Invalid credentials return:

```text
401
```

---

# 18. REST Validation

Validate:

* `lead_token`
* Email
* String lengths
* Application type
* Product ID
* Validity
* Currency
* Locale
* Payload structure
* Payload size
* Allowed fields
* Nested array limits

Possible responses:

```text
400
Malformed request
```

```text
401
Invalid API key
```

```text
429
Rate limit exceeded
```

```text
500
Database error
```

Successful response:

```json
{
  "ok": true,
  "status": "new",
  "reminder_due_at": "2026-09-16 10:42:00"
}
```

If the feature is disabled:

```json
{
  "ok": true,
  "enabled": false
}
```

---

# 19. Upsert / Idempotency

Use the unique:

```text
lead_token
```

as the idempotency key.

The repository should use:

```sql
INSERT ...
ON DUPLICATE KEY UPDATE ...
```

A second Step 3 submission with the same `lead_token` must update the existing row rather than create another row.

---

# 20. Converted Rows Must Never Be Downgraded

A converted row must not become `new` again because of a late browser request.

For example:

```text
new
 ↓
converted
 ↓
late partial POST
```

must remain:

```text
converted
```

Mutable application fields may be updated only while the row is not converted.

Do not rotate:

```text
resume_token
created_at
reminder_due_at
```

during an ordinary duplicate update.

---

# 21. Resume Token

A server-generated:

```php
bin2hex(random_bytes(16))
```

may still be stored as `resume_token` for identifying the reminder record and future internal use.

However, because the frontend does not retrieve the record from the server, the resume token does **not** need to be exposed as a data-fetch credential.

The email resume URL should use safe state parameters instead.

Example:

```text
/application.html?resume=1&package=printed&validity=3
```

The database `resume_token` can remain available for future functionality such as internal identification or unsubscribe handling.

---

# 22. Cloudflare Worker

Add:

```text
POST /partial
```

to the Worker.

Flow:

```text
Browser
  │
  │ POST /partial
  ▼
Cloudflare Worker
  │
  │ X-IDTA-Key
  ▼
WordPress REST API
```

The Worker must continue to own:

```text
WC_CONSUMER_KEY
WC_CONSUMER_SECRET
ALLOWED_ORIGIN
PARTIAL_API_KEY
WP_PARTIAL_URL
```

---

# 23. Worker Origin Protection

Add:

```text
/partial
```

to:

```text
ORIGIN_LOCKED_PATHS
```

Only the allowed storefront origin may submit partial applications.

The Worker must reject unauthorized browser origins.

---

# 24. Worker Response

The Worker should not expose the WordPress response body directly.

Return a minimal response such as:

```json
{
  "ok": true
}
```

or:

```json
{
  "ok": false
}
```

Detailed WordPress errors should remain server-side.

---

# 25. `/create-order` Change

The existing order creation payload must receive:

```text
lead_token
```

Add it to:

```text
IDP_META_KEYS
```

The resulting WooCommerce order receives:

```text
_idp_lead_token
```

Example:

```text
_idp_lead_token = 7d0f1a6e-....
```

This becomes the authoritative connection between:

```text
partial application
        ↓
WooCommerce order
```

---

# 26. Conversion Listener

Create:

```text
includes/partial/class-conversion-listener.php
```

Register:

```php
woocommerce_new_order
```

at priority:

```text
20
```

and:

```php
woocommerce_payment_complete
```

at priority:

```text
5
```

The listener must be wrapped in:

```php
try {
    ...
} catch (Throwable $e) {
    ...
}
```

Partial tracking must never break checkout or order creation.

---

# 27. Conversion Logic

Read:

```text
_idp_lead_token
```

from the order.

Find the partial record by:

```text
lead_token
```

Then:

```text
new → converted
```

or:

```text
reminded → converted
```

Conversion must be idempotent.

Example SQL:

```sql
UPDATE wp_idta_partial_applications
SET
    status = 'converted',
    order_id = %d,
    converted_at = %s,
    updated_at = %s
WHERE
    id = %d
    AND status <> 'converted'
```

---

# 28. Conversion Timing

WooCommerce order creation happens before payment completion.

Therefore there are two possible meanings of conversion.

## Option A — `order_created`

Default.

When:

```text
woocommerce_new_order
```

fires, mark the partial as converted.

This means:

> The visitor completed the application far enough to create an order.

## Option B — `payment_complete`

Optional.

In this mode:

```text
woocommerce_new_order
```

stores the `order_id` but does not mark the lead converted.

Only:

```text
woocommerce_payment_complete
```

changes the status to:

```text
converted
```

This allows a customer who reaches the payment page but abandons payment to still receive a reminder.

Setting:

```text
partial_convert_on
```

supports:

```text
order_created
payment_complete
```

Default:

```text
order_created
```

---

# 29. Email Fallback Conversion

If an order does not contain:

```text
_idp_lead_token
```

the listener may optionally attempt a fallback match using:

```text
billing email
```

Constraints:

* Only unconverted records
* Newest matching record
* Maximum 24-hour window
* Never overwrite an already converted record

The token-based match remains authoritative.

---

# 30. Reminder Delay

Default:

```text
12 minutes
```

Configurable through:

```text
partial_reminder_delay
```

Example:

```text
Step 3 completed
       ↓
10:00
       ↓
Reminder scheduled
       ↓
10:12
       ↓
Reminder processed
```

The reminder should be scheduled only when the partial record is first inserted.

Updating an existing partial must not continuously move the reminder time forward.

---

# 31. Action Scheduler

Prefer WooCommerce Action Scheduler.

Hook:

```text
idta_partial_send_reminder
```

Schedule:

```php
as_schedule_single_action(
    $due_timestamp,
    'idta_partial_send_reminder',
    array(
        'partial_id' => $id
    ),
    'idta-pdf'
);
```

Before scheduling:

```php
as_has_scheduled_action()
```

must be checked.

Group:

```text
idta-pdf
```

---

# 32. WP-Cron Fallback

If Action Scheduler is unavailable, use:

```php
wp_schedule_single_event()
```

The system must not create duplicate scheduled events.

---

# 33. Reminder Job

The reminder job must perform all checks again immediately before sending.

Required checks:

1. Record exists.
2. Status is `new`.
3. Email is valid.
4. `unsubscribed = 0`.
5. Email is not globally suppressed.
6. Cooldown rules allow sending.
7. The record has not already converted.
8. WooCommerce does not contain a matching order created after the partial record.

The final order check is important because an order may have been created but the conversion listener may not yet have run.

---

# 34. Order Recheck

Before sending:

```text
Partial created
       ↓
Search WooCommerce orders
       ↓
Matching email?
       ↓
YES → mark converted → do not send
NO  → continue
```

This provides an additional safety layer against sending reminders for completed applications.

---

# 35. Reminder Race Handling

There is a small timing boundary between:

```text
reminder job
```

and:

```text
order creation
```

Therefore the reminder job must perform the order check as close to sending as possible.

Conversion remains authoritative.

The system should never intentionally resend a reminder after:

```text
status = converted
```

---

# 36. One Reminder Per Lead

A partial lead receives at most one reminder.

After processing:

```text
status = reminded
```

and:

```text
reminder_sent_at = current UTC time
```

If email delivery fails, the failure must be logged through Action Scheduler/WooCommerce logging.

No automatic repeated reminder should be generated by the partial system.

---

# 37. Email Class

Create:

```text
Partial\Reminder_Email
```

extending:

```php
WC_Email
```

Example title:

```text
Unfinished application reminder
```

Register through:

```php
woocommerce_email_classes
```

alongside the existing:

```text
IDTA_PDF_Release
```

The email should reuse the store's existing WooCommerce email header/footer and styling.

---

# 38. Reminder Email Content

The email should contain:

* Customer first name
* Short reminder
* Application/package information
* Validity period
* Resume button
* Explanation that uploaded photos must be attached again
* Unsubscribe link

Example:

```text
Hi John,

You started your IDTA application but haven't completed it yet.

You can continue your application here:

[Continue My Application]

Your saved application:
Application: Printed
Validity: 3 Years

For security, uploaded photos are not stored. Please attach them again when you continue.

If you no longer want these reminders, you can unsubscribe here.
```

---

# 39. Parameterized Resume URL

Generate the resume URL from safe values.

Example:

```text
https://e-idta.com/application.html?resume=1&package=printed&validity=3
```

Possible safe parameters:

```text
resume
package
validity
application_type
```

Do not serialize the complete payload into the URL.

Do not Base64-encode sensitive data into the URL and assume that it is secure.

Base64 is encoding, not encryption.

---

# 40. Frontend Resume Logic

When `application.html` loads:

```javascript
const params = new URLSearchParams(window.location.search);

if (params.get('resume') === '1') {
    applyResumeParameters(params);
}
```

The function should:

1. Read allowed parameters.
2. Validate values.
3. Restore package.
4. Restore validity.
5. Recalculate cart/summary.
6. Show a resume notice.
7. Navigate to Step 3.
8. Remove the query parameters from the browser address bar.

Example:

```javascript
history.replaceState(
    {},
    '',
    window.location.pathname
);
```

No API request is required.

---

# 41. Resume Notice

Add a hidden element near the top of Step 3:

```html
<div id="resumeNotice" hidden>
    Your application details were saved.
    Uploaded photos are not stored, so please attach them again.
</div>
```

Show it only when:

```text
resume=1
```

was successfully processed.

---

# 42. Existing Frontend Helpers

Reuse existing application functionality rather than duplicating logic.

Examples:

```text
applyCountryFromCode()
ValiditySelect.select()
ensureValidityOnSelectedCard()
Cart.addItem()
updateSummary()
```

The resume implementation should call these existing helpers.

---

# 43. Partial Request State

In:

```text
application.js
```

maintain:

```javascript
let partialSent = false;
```

This prevents unnecessary duplicate requests during the same navigation event.

---

# 44. `sendPartial()`

The function should:

1. Obtain the current `lead_token`.
2. Validate that an email exists.
3. Build the allowlisted payload.
4. Send it to the Worker.
5. Use `keepalive: true` where supported.
6. Never block navigation.
7. Fail silently from the customer's perspective.
8. Log useful information only in development/debug mode.

Example conceptual flow:

```javascript
function sendPartial() {
    if (partialSent) return;

    const email = getApplicationEmail();

    if (!email) return;

    partialSent = true;

    fetch(WORKER_URL + '/partial', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(buildPartialBody()),
        keepalive: true
    }).catch(() => {});
}
```

---

# 45. Step 3 Handler

Modify the existing navigation handler conceptually:

```javascript
btn.addEventListener('click', function () {
    const panel = btn.closest('.form-step');

    if (!validateStep(panel)) {
        return;
    }

    let target = Number(btn.dataset.next);

    if (target === 2 && countriesStepDone()) {
        target = 3;
    }

    if (target === 4) {
        sendPartial();
    }

    goToStep(target);
});
```

The important requirement is:

```text
validate
   ↓
sendPartial
   ↓
goToStep
```

not:

```text
sendPartial
   ↓
wait
   ↓
goToStep
```

The partial request must not make checkout feel slower.

---

# 46. Submission Payload

Add:

```text
lead_token
```

to the normal order submission payload.

Example:

```json
{
  "order_from": "idta",
  "lead_token": "7d0f1a6e-...."
}
```

The backend then writes:

```text
_idp_lead_token
```

to the WooCommerce order.

---

# 47. Consent / Disclosure

Under the Step 3 Continue button, show a short disclosure such as:

> We'll save your progress so you can pick up where you left off.

If reminder emails are being sent, the store's privacy/consent wording should be reviewed for the jurisdictions where the service is offered.

Do not assume that this wording alone establishes compliance with GDPR, UK GDPR, CAN-SPAM, or any other legal framework.

The production implementation should be reviewed against the store's actual privacy notice and applicable legal requirements.

---

# 48. Unsubscribe

The email should contain an unsubscribe link.

Example:

```text
https://e-idta.com/?partial_unsubscribe=...
```

or a Worker endpoint:

```text
GET /partial/unsubscribe?token=...
```

The Worker forwards the request to WordPress.

The unsubscribe action should:

1. Validate the token.
2. Find the associated email.
3. Mark matching partial records as unsubscribed.
4. Add the email to the suppression list.
5. Redirect to a simple confirmation page.

Example:

```text
You have been unsubscribed from application reminder emails.
```

---

# 49. Suppression List

Use:

```text
idta_partial_suppressed_emails
```

as a WordPress option for globally suppressed addresses.

Also retain:

```text
unsubscribed
```

on each partial row.

Before every reminder:

```text
row unsubscribed?
       ↓
     YES → stop

email globally suppressed?
       ↓
     YES → stop

otherwise
       ↓
    continue
```

---

# 50. Reminder Cooldown

Default:

```text
7 days
```

Setting:

```text
partial_reminder_cooldown_days
```

The same email address should not receive multiple partial reminders inside the configured cooldown window.

This protects against repeated abandoned application attempts.

---

# 51. Rate Limiting

Recommended limits:

```text
20 partial submissions / hour
per IP/email combination
```

and:

```text
60 resume/unsubscribe requests / hour
```

The IP should not be stored in plaintext.

Use:

```text
SHA-256(IP + wp_salt)
```

and store the result as:

```text
ip_hash
```

This value is for rate limiting only.

It must not be displayed in the admin UI or exported.

---

# 52. Request Size

Maximum partial request body:

```text
32 KB
```

The Worker should reject oversized requests before forwarding them.

WordPress should also validate the final body size.

---

# 53. SQL Security

All database queries must use:

```php
$wpdb->prepare()
```

Never concatenate user input directly into SQL.

All admin output must be escaped.

---

# 54. Worker Security

The Worker must:

* Verify allowed origin.
* Keep WooCommerce credentials private.
* Keep the WordPress API key private.
* Use HTTPS.
* Never expose WordPress API responses directly.
* Reject oversized requests.
* Apply rate limits.
* Validate request content where practical.

---

# 55. Resume URL Security

The resume URL contains no PII.

It must not contain:

```text
email
phone
DOB
licence number
address
images
complete payload
```

The URL therefore remains a simple application-state shortcut rather than a data-storage mechanism.

---

# 56. Retention

Default retention:

```text
90 days
```

for unconverted partial records.

Setting:

```text
partial_retention_days
```

controls this.

Converted records:

```text
365 days
```

Setting:

```text
partial_converted_retention_days
```

controls this.

---

# 57. Cleanup Job

Create:

```text
idta_partial_cleanup
```

Run daily through Action Scheduler.

Process maximum:

```text
500 rows
```

per batch.

## Unconverted records

Delete:

```text
new
reminded
expired
```

older than:

```text
partial_retention_days
```

## Converted records

After:

```text
partial_converted_retention_days
```

anonymize:

```text
email
first_name
last_name
phone
payload
```

Keep:

```text
id
status
order_id
created_at
converted_at
```

for aggregate conversion analytics.

---

# 58. Expiration

A partial application becomes non-resumable after the configured retention period.

When appropriate, it may be marked:

```text
expired
```

before cleanup.

Once expired:

```text
no reminder
no resume
no conversion downgrade
```

The actual row can later be deleted during cleanup.

---

# 59. Admin Page

Create:

```text
includes/partial/class-admin-page.php
```

Add an admin screen under the existing plugin settings.

Suggested columns:

```text
ID
Status
Created
Email
Name
Application Type
Validity
Reminder
Converted
Order ID
```

Do not expose:

```text
ip_hash
```

by default.

Payload should only be shown to authorized administrators.

---

# 60. Admin Permissions

Require an appropriate WooCommerce/WordPress capability such as:

```text
manage_woocommerce
```

for partial application administration.

All destructive/admin actions must use:

* WordPress nonce
* Capability checks
* Sanitized input

CSV export must be protected in the same way.

Avoid exporting the full payload by default because it may contain sensitive customer information.

---

# 61. Settings

Add:

```text
partial_enabled
```

Default:

```text
true
```

```text
partial_reminder_delay
```

Default:

```text
12
```

```text
partial_convert_on
```

Values:

```text
order_created
payment_complete
```

Default:

```text
order_created
```

```text
partial_retention_days
```

Default:

```text
90
```

```text
partial_converted_retention_days
```

Default:

```text
365
```

```text
partial_reminder_cooldown_days
```

Default:

```text
7
```

Also provide an API key status section.

The actual secret should be masked.

---

# 62. API Key Configuration

Preferred:

```php
define(
    'IDTA_PARTIAL_API_KEY',
    '...'
);
```

Fallback:

```text
WordPress option
```

The settings screen may show:

```text
API key: Configured
```

rather than displaying the actual secret.

If a generation button is provided, the newly generated key must only be displayed once and must not be written into logs.

---

# 63. File Structure

```text
idta-pdf/
├── idta-pdf.php
├── uninstall.php
│
├── includes/
│   ├── class-plugin.php
│   ├── class-settings.php
│   ├── class-settings-page.php
│   │
│   └── partial/
│       ├── class-module.php
│       ├── class-table.php
│       ├── class-record.php
│       ├── class-repository.php
│       ├── class-rest-controller.php
│       ├── class-reminder-scheduler.php
│       ├── class-reminder-email.php
│       ├── class-conversion-listener.php
│       ├── class-cleanup.php
│       └── class-admin-page.php
│
└── templates/
    └── emails/
        ├── partial-reminder.php
        └── plain/
            └── partial-reminder.php
```

Frontend:

```text
idta/
├── application.html
├── assets/
│   └── js/
│       └── application.js
│
└── worker/
    └── src/
        └── index.js
```

---

# 64. Module Bootstrap

The partial feature should have one registration seam:

```text
Partial\Module::register()
```

The existing plugin bootstrap calls this once.

The existing autoloader already maps:

```text
IDTA\PDF\Partial\Foo
```

to:

```text
includes/partial/class-foo.php
```

Therefore no custom autoloading mechanism is required.

---

# 65. Existing Code Must Remain Untouched Where Possible

The following existing systems should not be modified unnecessarily:

```text
Order_Data
Generator
Download_Handler
Release_Schedule
Release_Notifier
```

The partial feature should be additive.

The only normal order-path addition should be:

```text
_idp_lead_token
```

plus the conversion listener.

---

# 66. Expected Flow

## Normal completed application

```text
Step 1
  ↓
Step 2
  ↓
Step 3
  ↓
Partial saved
  ↓
Step 4
  ↓
Create Order
  ↓
woocommerce_new_order
  ↓
Partial marked converted
  ↓
Reminder cancelled/ignored
  ↓
Existing PDF/release flow
```

No reminder is sent.

---

# 67. Abandoned Application

```text
Step 1
  ↓
Step 2
  ↓
Step 3
  ↓
Partial saved
  ↓
User leaves
  ↓
12 minutes
  ↓
Reminder job
  ↓
No order found
  ↓
Reminder email
```

The email contains:

```text
/application.html?resume=1&package=...&validity=...
```

No resume API call occurs.

---

# 68. Resume Flow

```text
Reminder Email
      ↓
User clicks link
      ↓
application.html
      ↓
Read URL parameters
      ↓
Validate parameters
      ↓
Restore safe application state
      ↓
Show Step 3
      ↓
User re-attaches images
      ↓
Continue
      ↓
Create Order
      ↓
Partial converted
```

---

# 69. No API Call During Resume

The following endpoint is intentionally **not required**:

```text
GET /wp-json/idta/v1/partial/resume
```

The Worker also does not need:

```text
GET /partial/resume
```

The browser should not make a second backend request merely to reconstruct the saved application.

The resume URL itself carries the required safe state.

---

# 70. Important Limitation of Parameterized Resume

Because sensitive information must not be placed into URLs, a resumed application will not automatically restore every field.

The user may need to re-enter:

```text
Personal information
Licence information
Other sensitive fields
```

and must reattach:

```text
Photos
Signature
```

This is intentional and preferable to exposing sensitive application data through URLs.

If full server-side resume is required later, a dedicated authenticated/tokenized resume endpoint can be introduced as a separate feature.

---

# 71. Email URL Construction

Example helper:

```javascript
function buildResumeUrl() {
    const url = new URL(
        '/application.html',
        window.location.origin
    );

    url.searchParams.set('resume', '1');

    if (safePackage) {
        url.searchParams.set('package', safePackage);
    }

    if (safeValidity) {
        url.searchParams.set('validity', safeValidity);
    }

    return url.toString();
}
```

Only allow known values.

Never construct the URL by blindly serializing the entire form object.

---

# 72. Resume Parameter Validation

Example allowed values:

```text
package:
    printed
    digital

validity:
    1
    2
    3
    5
```

Invalid:

```text
package=<script>
validity=DROP TABLE
```

must simply be ignored.

The frontend should never inject raw query parameters into HTML using `innerHTML`.

---

# 73. Referrer / UTM Data

Analytics data may be stored in the partial payload, but it must be sanitized.

Avoid storing URLs containing unexpected PII.

Prefer storing:

```text
hostname
path
utm_source
utm_medium
utm_campaign
```

with strict length limits.

---

# 74. Logging

Do not log:

```text
email
phone
DOB
licence number
payload
API key
resume token
```

unless absolutely necessary for controlled debugging.

Action Scheduler logs should contain safe identifiers such as:

```text
partial_id
order_id
processing result
```

---

# 75. Error Handling

Partial tracking must be best-effort.

If:

```text
Worker fails
```

or:

```text
WordPress API fails
```

the customer should still be able to continue to checkout.

The partial system must never prevent:

```text
application submission
order creation
payment
PDF generation
release notification
```

---

# 76. Build Order

Implement in this order.

## Phase 1 — Database

Create:

```text
Table
Record
Repository
Module
```

Then implement:

```text
maybe_upgrade()
```

Verify that replacing the plugin files creates/upgrades the table.

---

## Phase 2 — REST API

Implement:

```text
POST /partial
```

Add:

```text
X-IDTA-Key
```

authentication.

Test directly using a REST request.

---

## Phase 3 — Worker

Add:

```text
POST /partial
```

and:

```text
WP_PARTIAL_URL
PARTIAL_API_KEY
```

Add `/partial` to origin protection.

---

## Phase 4 — Frontend Capture

Modify:

```text
application.js
```

Add:

```text
leadToken()
buildPartialBody()
sendPartial()
```

Hook into Step 3 → Step 4.

Verify database rows.

---

## Phase 5 — Order Correlation

Add:

```text
lead_token
```

to the order payload.

Store:

```text
_idp_lead_token
```

in WooCommerce.

Implement:

```text
Conversion_Listener
```

Test a complete order.

---

## Phase 6 — Scheduler

Implement:

```text
Reminder_Scheduler
```

Use a staging delay of:

```text
1 minute
```

for testing.

Then restore production default:

```text
12 minutes
```

---

## Phase 7 — Email

Implement:

```text
Reminder_Email
```

and:

```text
partial-reminder.php
plain/partial-reminder.php
```

Verify WooCommerce email rendering.

---

## Phase 8 — Parameterized Resume

Add:

```text
resume=1
package=...
validity=...
```

to the email URL.

Implement frontend restoration.

No resume API should be added.

---

## Phase 9 — Unsubscribe

Implement:

```text
/partial/unsubscribe
```

and suppression handling.

---

## Phase 10 — Admin / Cleanup

Implement:

```text
Admin Page
Cleanup Job
Settings
Retention
```

---

# 77. Frontend Build

After modifying:

```text
application.js
```

run the existing minification process:

```text
tools/minify.sh
```

Then bump:

```text
application.min.js?v=...
```

in the HTML if cache busting is currently handled through the query string.

---

# 78. Regression Testing

## Test 1 — Normal order

```text
Step 3
 ↓
Partial created
 ↓
Step 4
 ↓
Order created
```

Expected:

```text
status = converted
```

No reminder.

---

## Test 2 — Abandoned application

```text
Step 3
 ↓
Leave site
 ↓
12 minutes
```

Expected:

```text
status = reminded
```

One reminder email.

---

## Test 3 — Resume

Click:

```text
/application.html?resume=1&package=printed&validity=3
```

Expected:

* No API request
* Package restored
* Validity restored
* Step 3 displayed
* Resume notice displayed
* URL cleaned
* Images remain empty

---

## Test 4 — Resume then order

```text
Reminder
 ↓
Resume
 ↓
Complete
 ↓
Order
```

Expected:

```text
partial = converted
```

No additional reminder.

---

## Test 5 — Duplicate Step 3

Same browser session:

```text
Step 3
 ↓
partial POST
 ↓
back
 ↓
Step 3
 ↓
partial POST
```

Expected:

```text
one database row
```

because of:

```text
UNIQUE lead_token
```

---

## Test 6 — Converted row receives late POST

```text
new
 ↓
converted
 ↓
late partial POST
```

Expected:

```text
converted
```

Never:

```text
new
```

---

## Test 7 — Missing token order

Create an order without:

```text
_idp_lead_token
```

Expected:

* Existing order flow works
* Existing PDFs work
* Existing release notification works
* Partial tracking does not throw fatal errors

---

## Test 8 — Partial API failure

Temporarily break the Worker endpoint.

Expected:

* User can continue
* Order flow remains functional
* No visible checkout failure caused by partial tracking

---

## Test 9 — Unsubscribe

Click unsubscribe.

Expected:

```text
unsubscribed = 1
```

and email added to suppression list.

Future reminder:

```text
not sent
```

---

## Test 10 — Cleanup

Create old records.

Run:

```text
idta_partial_cleanup
```

Expected:

```text
old unconverted → deleted
old converted → anonymized
```

---

# 79. Production Acceptance Criteria

The feature is considered complete when all of the following are true:

* Step 3 creates a partial record.
* Duplicate submissions are idempotent.
* No uploaded image is stored.
* Worker credentials remain private.
* WordPress REST API is authenticated.
* Partial records can be converted through order meta.
* Converted leads do not receive reminders.
* Abandoned leads receive one reminder.
* Reminder scheduling uses Action Scheduler.
* Email uses WooCommerce email infrastructure.
* Resume requires no API call.
* Resume URL contains no sensitive PII.
* Safe package/validity state is restored from URL parameters.
* Users are warned to reattach images.
* Unsubscribe works.
* Suppression is respected.
* Retention/cleanup works.
* Existing order/PDF/release functionality remains unchanged.
* Partial failures cannot break checkout.
* Database installation works after plugin file replacement.
* Admin access is protected.

---

# 80. Final Architecture

```text
                         ┌─────────────────────┐
                         │    e-idta.com       │
                         │   Static Frontend   │
                         └──────────┬──────────┘
                                    │
                         POST /partial
                                    │
                                    ▼
                         ┌─────────────────────┐
                         │  Cloudflare Worker  │
                         │                     │
                         │ Origin Protection   │
                         │ API Secret          │
                         │ Rate Limiting       │
                         └──────────┬──────────┘
                                    │
                           X-IDTA-Key
                                    │
                                    ▼
                         ┌─────────────────────┐
                         │      WordPress      │
                         │      WooCommerce    │
                         └──────────┬──────────┘
                                    │
                                    ▼
                         ┌─────────────────────┐
                         │      idta-pdf       │
                         │                     │
                         │ Partial Repository  │
                         │ REST Controller     │
                         │ Conversion Listener │
                         │ Reminder Scheduler  │
                         │ Reminder Email      │
                         │ Cleanup             │
                         └──────────┬──────────┘
                                    │
                                    ▼
                       idta_partial_applications
```

Resume flow is intentionally separate:

```text
        Reminder Email
              │
              │
              ▼
┌─────────────────────────────────────────┐
│ https://e-idta.com/application.html     │
│ ?resume=1&package=printed&validity=3    │
└────────────────────┬────────────────────┘
                     │
                     ▼
              application.js
                     │
                     ├── validate parameters
                     ├── restore package
                     ├── restore validity
                     ├── update summary
                     ├── show resume notice
                     └── remove query string
```

There is **no resume API request** in this flow.

The backend database remains the authoritative record for:

```text
lead tracking
reminder status
conversion
retention
analytics
```

while the email URL is only a lightweight, non-sensitive shortcut for restoring the customer's application state.
