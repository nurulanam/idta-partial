# Partial / abandoned application capture — design

Feature tag: `idta-partial`. Ships as a module **inside the existing** `idta-pdf`**plugin** (`includes/partial/`), not as a separate plugin.

---

## 1. What exists today, and why the design follows it

```
e-idta.com (static HTML)
   │  POST /upload          multipart  ─┐
   │  POST /create-order    JSON        │  Cloudflare Worker "idta-upload"
   └────────────────────────────────────┘  (holds WC_CONSUMER_KEY/SECRET,
                                            enforces ALLOWED_ORIGIN)
                                              │  wc/v3/orders
                                              ▼
                                        e-iaa.com (WooCommerce)
                                              │  _idp_* order meta
                                              ▼
                                        idta-pdf plugin
                                          Order_Data → PDFs, Release_Notifier → email
```

Three facts from the current code drive every decision below:

1. **The browser never talks to WordPress.** `worker/src/index.js` is the only thing that does, and it is the only place the WooCommerce credentials and the origin allowlist live. The partial endpoint goes through the same door: `POST /partial` on the Worker → `POST /wp-json/idta/v1/partial` on WordPress. Exposing a public write endpoint on WordPress directly would mean a second, parallel CORS/anti-abuse policy to keep in sync, and CORS alone stops nothing (see the `originAllowed()` comment in the Worker).
2. **Step 3 is the "Continue to Checkout" button**, `[data-next="4"]` at `application.html:810`. It already runs `validateStep(panel)` in the `[data-next]` handler (`application.js:400`), so there is exactly one place to hook the capture, and it fires only once the details are known-valid.
3. **The order is created at step 4 submit**, minutes later — so a partial row always predates its order. Conversion is never a race against the reminder in the normal case; it only is when the customer sits on the payment page.

The plugin's autoloader already maps `IDTA\PDF\Partial\Foo` → `includes/partial/class-foo.php` (`Autoloader::load()` lowercases each namespace segment into a directory), so the module needs no new bootstrapping.

---

## 2. Data model

### 2.1 Table

`{$wpdb->prefix}idta_partial_applications`, created with `dbDelta()`.

```sql
CREATE TABLE {$prefix}idta_partial_applications (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_token          CHAR(36)        NOT NULL,           -- client-generated UUIDv4, idempotency key
  resume_token        CHAR(32)        NOT NULL,           -- server-generated, only ever in the email link
  status              VARCHAR(20)     NOT NULL DEFAULT 'new',   -- new | reminded | converted | expired
  source              VARCHAR(20)     NOT NULL DEFAULT 'idta',  -- mirrors _idp_order_from
  email               VARCHAR(190)    NOT NULL DEFAULT '',
  first_name          VARCHAR(100)    NOT NULL DEFAULT '',
  last_name           VARCHAR(100)    NOT NULL DEFAULT '',
  phone               VARCHAR(40)     NOT NULL DEFAULT '',
  application_type    VARCHAR(40)     NOT NULL DEFAULT '',  -- print_digital | digital_only
  validity_years      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  product_id          BIGINT UNSIGNED  NOT NULL DEFAULT 0,
  currency            CHAR(3)         NOT NULL DEFAULT '',
  locale              VARCHAR(10)     NOT NULL DEFAULT '',
  payload             LONGTEXT        NULL,                -- JSON, everything else (see 2.3)
  order_id            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ip_hash             CHAR(64)        NOT NULL DEFAULT '', -- sha256(ip + wp_salt), for rate limiting only
  unsubscribed        TINYINT(1)      NOT NULL DEFAULT 0,
  created_at          DATETIME        NOT NULL,            -- all times UTC (gmdate)
  updated_at          DATETIME        NOT NULL,
  reminder_due_at     DATETIME        NULL,
  reminder_sent_at    DATETIME        NULL,
  converted_at        DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY lead_token (lead_token),
  UNIQUE KEY resume_token (resume_token),
  KEY status_created (status, created_at),
  KEY email (email),
  KEY order_id (order_id)
) {$charset_collate};
```

Notes:

- `lead_token` is **UNIQUE** — that constraint *is* the idempotency guarantee (§4.2), not application-level checking.
- `email` is `VARCHAR(190)` so the index fits under utf8mb4's 767-byte limit.
- No image columns, ever. Portrait / licence front+back / signature are not sent and not stored; see §7 for what that costs the resume flow.
- Times are UTC `DATETIME` written with `gmdate( 'Y-m-d H:i:s' )`, matching how the rest of the plugin treats time (`Release_Schedule` works in timestamps).

### 2.2 Installation and upgrade

`Partial\Table::install()` runs `dbDelta()` and stores `idta_partial_db_version`. It is called from **both**:

- `Plugin::activate()`, and
- `Plugin::maybe_upgrade()` — this one matters. The existing plugin's own docblock says this site is updated by overwriting the folder over SFTP, so `register_activation_hook` never fires. Table creation must ride on the version-gated upgrade path or the feature silently 500s on every deploy.

`Plugin::deactivate()` gains `as_unschedule_all_actions()` for the two new hooks. `uninstall.php` gains `DROP TABLE` + `delete_option` for the new options (the existing file already deletes only what the plugin owns; the table is owned).

### 2.3 `payload` JSON

Everything that is useful for the log/analytics but not worth a column. Capped at 32 KB, validated key-by-key against an allowlist, never echoed back unescaped:

```json
{
  "gender": "male",
  "date_of_birth": "1990-04-12",
  "country_of_birth": {"code": "BD", "name": "Bangladesh"},
  "country_of_residence": {"code": "AE", "name": "United Arab Emirates"},
  "license_issued_country": {"code": "BD", "name": "Bangladesh"},
  "destination_country": {"code": "IT", "name": "Italy"},
  "driver_license_number": "DL-99-1234",
  "license_categories": ["B", "C"],
  "package": "printed",
  "validity_label": "3 Years",
  "plan_price": "49.00",
  "cart_items": [{"id": "20", "name": "Faster Processing", "price": "$9.00", "qty": 1}],
  "summary_total": "$58.00",
  "step_reached": 3,
  "referrer": "https://www.google.com/",
  "utm": {"source": "google", "medium": "cpc", "campaign": "idp-uae"}
}
```

---

## 3. Endpoints

### 3.1 Worker (`worker/src/index.js`)

Three additions, all thin proxies. `WP_PARTIAL_URL` as a `[vars]` entry and `PARTIAL_API_KEY` as an encrypted secret (`wrangler secret put PARTIAL_API_KEY`).

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/partial` | Upsert a partial. Added to `ORIGIN_LOCKED_PATHS`. |
| `GET` | `/partial/resume?token=…` | Read a partial back for prefill. Not origin-locked (the link is opened by a fresh navigation, which sends no `Origin`) — gated by the token itself. |
| `GET` | `/partial/unsubscribe?token=…` | Suppression, see §6.4. Redirects to a confirmation page. |

```js
// idempotency + secrecy live on the WordPress side; the Worker's job is to keep
// the WP endpoint unreachable from anywhere but this storefront
async function handlePartial(request, env, cors) {
    var body = await request.json();                     // 400 on parse failure
    var res = await fetch(env.WP_PARTIAL_URL, {          // .../wp-json/idta/v1/partial
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-IDTA-Key': env.PARTIAL_API_KEY
        },
        body: JSON.stringify(body)
    });
    // never surface WP's body verbatim — it can carry PHP notices
    var ok = res.ok;
    return new Response(JSON.stringify({ ok: ok }), {
        status: ok ? 200 : 502,
        headers: Object.assign({ 'Content-Type': 'application/json' }, cors)
    });
}
```

`/create-order` needs **one line** changed: add `'lead_token'` to `IDP_META_KEYS`, so the order carries `_idp_lead_token` (§5).

### 3.2 WordPress (`idta/v1`)

Registered on `rest_api_init` by `Partial\REST_Controller`.

#### `POST /wp-json/idta/v1/partial`

Auth: `X-IDTA-Key` compared with `hash_equals()` against `IDTA_PARTIAL_API_KEY` (wp-config constant preferred) or the `idta_partial_api_key` option as a fallback. `permission_callback` returns a `WP_Error` with 401 on mismatch — never `__return_true`.

Request:

```json
{
  "lead_token": "7d0f1a6e-8f4f-4d2c-9a0f-2f4b4d9a1e77",
  "email": "a@b.com",
  "first_name": "Ayesha",
  "last_name": "Rahman",
  "phone": "+8801711000000",
  "application_type": "print_digital",
  "validity_years": 3,
  "product_id": 19,
  "currency": "USD",
  "locale": "en",
  "source": "idta",
  "payload": { … §2.3 … }
}
```

Response `200`:

```json
{ "ok": true, "status": "new", "reminder_due_at": "2026-09-16T10:42:00Z" }
```

Status codes: `400` malformed / missing `lead_token` / bad email, `401` bad key, `409` never (an upsert has no conflict), `429` rate limited, `500` DB failure.

#### `GET /wp-json/idta/v1/partial/resume?token=…`

`permission_callback` → `__return_true`, but the token is the credential: 32 hex chars, single-use-ish, expires with the row (§6.5). Returns **only** what the form needs to prefill — never `ip_hash`, never `lead_token`, never `order_id`:

```json
{
  "ok": true,
  "expired": false,
  "lead_token": null,
  "fields": { "email": "…", "first_name": "…", … },
  "payload": { … }
}
```

A converted, expired or unknown token returns `200` with `{"ok": false, "reason": "expired"}` — not `404`, so the token can't be probed for existence by status code.

#### `GET /wp-json/idta/v1/partial/unsubscribe?token=…`

Sets `unsubscribed = 1` on every row with that email, adds it to the suppression option, redirects to a plain confirmation page.

#### Optional: `POST /wp-json/idta/v1/partial/convert`

Body `{"lead_token": "…", "order_id": 123}`. **Not required** — order meta already carries the token and the WordPress-side listener is authoritative (§5). Worth adding only as a belt-and-braces call from the Worker right after `/create-order` returns, for the case where WooCommerce order meta is later edited or lost. Same `X-IDTA-Key` auth.

---

## 4. Lead token and idempotency

### 4.1 Token generation

`lead_token` is generated **in the browser**, once per application attempt, stored in `sessionStorage`:

```js
function leadToken() {
    var t = null;
    try { t = sessionStorage.getItem('idtaLeadToken'); } catch (e) {}
    if (!t) {
        t = (window.crypto && crypto.randomUUID)
            ? crypto.randomUUID()
            : 'x'.repeat(0) + Date.now().toString(16) + Math.random().toString(16).slice(2);
        try { sessionStorage.setItem('idtaLeadToken', t); } catch (e) {}
    }
    return t;
}
```

Client generation is safe **because the token is never a credential**. It is an idempotency key and a join key, nothing more: knowing someone's lead token lets you overwrite an unconverted partial row you would have had to guess a UUIDv4 to find, and lets you do nothing else. Everything that *is* a credential — `resume_token`, the API key — is generated server-side with `random_bytes()`.

Server-side validation: `^[A-Za-z0-9-]{16,64}$`. Anything else is a `400`.

`sessionStorage` (not `localStorage`) so a second application in a new tab gets its own row rather than overwriting the first.

### 4.2 Upsert

One statement, atomic, leaning on the `UNIQUE KEY lead_token`:

```php
$sql = "INSERT INTO {$table}
          (lead_token, resume_token, status, source, email, first_name, last_name,
           phone, application_type, validity_years, product_id, currency, locale,
           payload, ip_hash, created_at, updated_at, reminder_due_at)
        VALUES (%s, %s, 'new', %s, %s, %s, %s, %s, %s, %d, %d, %s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
          email            = IF(status = 'converted', email, VALUES(email)),
          first_name       = IF(status = 'converted', first_name, VALUES(first_name)),
          /* …same guard on every mutable column… */
          payload          = IF(status = 'converted', payload, VALUES(payload)),
          updated_at       = VALUES(updated_at)";
```

Three properties worth stating explicitly:

- `resume_token` **and** `created_at` **are in the INSERT list only.** A repeat request never rotates the token that may already be sitting in a sent email, and never resets the age the cleanup job measures.
- `reminder_due_at` **is not updated either.** The reminder is scheduled once, from the first time step 3 was completed. If it were pushed back on every edit, a customer who fiddles with their plan for twenty minutes would never be reminded.
- **A** `converted` **row is never downgraded.** The `IF(status = 'converted', …)`guard means a late, out-of-order partial POST (a retried request, a customer who hits Back after paying) cannot resurrect a converted lead into `new` and trigger a reminder for an order that was already placed.

`$wpdb->insert()` cannot express this, so it is a prepared `$wpdb->query()`. The table name is interpolated from `$wpdb->prefix` and a class constant — never from input.

---

## 5. Conversion

### 5.1 How the token reaches the order

`application.js` already builds `payload.meta`, which the Worker copies to `_idp_*` order meta via `IDP_META_KEYS`. Add `lead_token` to both. The order then carries `_idp_lead_token`.

**Deliberately not added to** `Order_Data::FIELDS`**.** That constant drives the editable "IDP Driver Details" meta box (`Order_Fields::render()`); a lead token is machine state, not something a shop manager should be typing into a text input. The key lives on `Partial\Conversion_Listener::TOKEN_META` instead, and `Order_Data`, `REQUIRED_FIELDS`, PDF generation and `Release_Notifier` are untouched.

### 5.2 The listener

```php
public function register(): void {
    // Fires for REST-created orders, which is how every order from e-idta.com
    // is created (Worker → wc/v3/orders).
    add_action( 'woocommerce_new_order', array( $this, 'mark_converted' ), 20, 1 );

    // Backstop: an order whose meta was written after creation, or an order
    // taken through some other path, still converts when it is paid.
    add_action( 'woocommerce_payment_complete', array( $this, 'mark_converted' ), 5, 1 );
}

public function mark_converted( $order_id ): void {
    try {
        $order = wc_get_order( absint( $order_id ) );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $token = trim( (string) $order->get_meta( self::TOKEN_META, true ) );
        $record = '' !== $token
            ? $this->repository->find_by_lead_token( $token )
            : $this->repository->find_recent_unconverted_by_email( $order->get_billing_email() );

        if ( null === $record || 'converted' === $record->status ) {
            return;
        }

        $this->repository->mark_converted( $record->id, $order->get_id() );
        $this->scheduler->unschedule( $record->id );
    } catch ( \Throwable $e ) {
        // Never let lead tracking break order creation. This runs inside the
        // REST order request that the storefront is waiting on, and a fatal
        // here would fail the checkout for a customer who has already paid
        // nothing yet — but would also never reach the pay page.
        $this->log( 'conversion failed: ' . $e->getMessage() );
    }
}
```

`mark_converted()` in the repository is `UPDATE … SET status='converted', order_id=%d, converted_at=%s WHERE id=%d AND status <> 'converted'` — idempotent, so both hooks firing costs one no-op UPDATE.

**Email fallback**, used only when no token is present: newest row with `status='new'` and matching `email`, created within the last 24 h. Narrow on purpose — a wider window would wrongly convert a returning customer's fresh lead.

### 5.3 "Order created" vs "order paid"

The Worker creates orders with `status: 'pending'` and sends the customer to `/checkout/order-pay/`. So `woocommerce_new_order` means *reached checkout*, not *paid*. Per the requirement, that counts as converted and cancels the reminder.

A setting, `partial_convert_on` = `order_created` (default) | `payment_complete`, makes the other choice available without a code change: on `payment_complete`, `woocommerce_new_order` instead stamps `order_id` and leaves `status = 'new'`, so a customer who bails on the payment page still gets the reminder — and the reminder's "resume" link becomes the order-pay URL. Worth having; recovering abandoned *payments* is usually the higher-value half of this funnel. Default it off, ship the simple behaviour first.

---

## 6. Reminder

### 6.1 Scheduling

`Partial\Reminder_Scheduler`, modelled directly on `Release_Notifier`:

```php
public const HOOK = 'idta_partial_send_reminder';

public function schedule( int $partial_id, int $due_ts ): void {
    if ( function_exists( 'as_schedule_single_action' ) ) {
        if ( as_has_scheduled_action( self::HOOK, array( 'partial_id' => $partial_id ), 'idta-pdf' ) ) {
            return;
        }
        as_schedule_single_action( $due_ts, self::HOOK, array( 'partial_id' => $partial_id ), 'idta-pdf' );
        return;
    }
    wp_schedule_single_event( $due_ts, self::HOOK, array( $partial_id ) );
}
```

Scheduled from the REST insert branch only (never the update branch), at `time() + partial_reminder_delay * MINUTE_IN_SECONDS`, default **12 minutes**(the 10–15 min window). Group `idta-pdf`, so the new actions sit with the existing ones on WooCommerce → Status → Scheduled Actions.

Action Scheduler is guaranteed present — the plugin already requires WooCommerce and `Release_Notifier` uses `as_schedule_single_action()` — but the WP-Cron fallback is kept for symmetry with the existing code.

### 6.2 The job

The scheduler's own state is **not** the guard; the row's `status` is. Same reasoning as `Release_Notifier`'s `SENT_META`: a cleared queue or a lost job must not be able to produce a second email, and a row converted after the job was queued must produce none.

```php
public function send( $args ): void {
    $partial_id = is_array( $args ) ? (int) ( $args['partial_id'] ?? 0 ) : (int) $args;
    $record     = $this->repository->find( $partial_id );

    if ( null === $record )                    { $this->log_action( 'Lead gone; nothing sent.' ); return; }
    if ( 'new' !== $record->status )           { $this->log_action( sprintf( 'Lead %d is %s; nothing sent.', $partial_id, $record->status ) ); return; }
    if ( $record->unsubscribed )               { $this->log_action( 'Lead has opted out; nothing sent.' ); return; }
    if ( ! is_email( $record->email ) )        { $this->log_action( 'No usable address; nothing sent.' ); return; }
    if ( $this->suppressed( $record->email ) ) { $this->log_action( 'Address reminded recently; nothing sent.' ); return; }

    // Re-check against WooCommerce itself, not just our own status column: an
    // order created while the plugin's listener was disabled (or by an operator
    // by hand) must not earn the customer a "you didn't finish" email.
    if ( $this->repository->order_exists_for_email( $record->email, $record->created_at ) ) {
        $this->repository->mark_converted( $record->id, 0 );
        $this->log_action( 'An order already exists for this address; nothing sent.' );
        return;
    }

    $sent = $this->email()->trigger( $record );

    $this->repository->mark_reminded( $record->id, $sent );
    $this->log_action( $sent ? sprintf( 'Reminder sent to %s.', $record->email ) : 'Reminder failed to send.' );
}
```

`log_action()` is copied verbatim in spirit from `Release_Notifier` — it writes into the running Action Scheduler action's log via `\ActionScheduler::logger()->log()`, so each lead's outcome is readable individually in the admin rather than inferred from "action completed".

**One reminder per lead. No retries.** A failed send marks the row `reminded`anyway. A nudge email is worth strictly less than the risk of a mail-stack fault sending three copies, and there is no equivalent of the order screen's "Resend" that a customer would ever ask for.

### 6.3 The email

`Partial\Reminder_Email extends \WC_Email` — same choice and same reasons as `Release_Email`: it inherits the store's header/footer, and its subject, heading and on/off switch land in WooCommerce → Settings → Emails where a shop manager already looks.

```php
$this->id             = 'idta_partial_reminder';
$this->customer_email = true;
$this->title          = __( 'Unfinished application reminder', 'idta-pdf' );
$this->template_html  = 'emails/partial-reminder.php';
$this->template_plain = 'emails/plain/partial-reminder.php';
$this->template_base  = plugin_dir_path( PLUGIN_FILE ) . 'templates/';
$this->placeholders   = array( '{first_name}' => '', '{validity_years}' => '' );
```

`trigger( Partial\Record $record )` sets `$this->object = $record` and `$this->recipient = $record->email`, then reuses `Release_Email`'s empty-content guard verbatim — `wc_get_template_html()` returns `''` when the template is missing (which is what a half-finished folder upload looks like on this site), and `wp_mail()` reports success for a blank email as readily as a real one.

Registered through the existing `woocommerce_email_classes` filter alongside `IDTA_PDF_Release`.

Content: first name, what they were applying for (`{validity_years}`-year, printed or digital), one primary button to the resume URL, a line saying the uploaded photos will need re-attaching, and an unsubscribe link in the footer.

### 6.4 Suppression and consent

- `unsubscribed` column + `idta_partial_suppressed_emails` option, both checked before every send.
- `partial_reminder_cooldown_days` (default 7): the same address is not reminded twice within the window, however many leads it creates. Checked with one indexed query on `email` + `reminder_sent_at`.
- The unsubscribe link is in the email footer and works without a login.
- Step 3 gets a short line under the Continue button: *"We'll save your progress so you can pick up where you left off."* That is the disclosure that makes this a transactional continuation of a process the visitor started, rather than marketing they never asked for — which is also the distinction CAN-SPAM and GDPR legitimate-interest both turn on. Get it reviewed if the store sells into the EU/UK.

### 6.5 Retention / cleanup

A daily recurring Action Scheduler job, `idta_partial_cleanup`, group `idta-pdf`:

- `status IN ('new','reminded')` older than `partial_retention_days` (default **90**) → **deleted** (they are un-consented PII with no business value left).
- `status = 'converted'` older than 365 days → **anonymised**, not deleted: `email`, names, `phone`, `payload` blanked; `id`, `status`, `order_id`, timestamps kept so conversion-rate reporting stays honest. This is what the requirement's "keep converted records for tracking" and "allow old records to be cleaned up later" resolve to together.
- Deletes run in batches of 500 with `LIMIT`, so a backlog can't time out.

---

## 7. Resume flow

### 7.1 Chosen approach: token in the link, data over the API

```
Email → https://e-idta.com/application.html?resume=<resume_token>
          → application.js reads ?resume
          → GET  <WORKER>/partial/resume?token=…
          → prefill fields, restore plan/validity/cart, goToStep(3)
```

### 7.2 Why not put every field in the URL

The brief offers "parameterise everything into the application URL" as the simple option. It is not actually simpler — the prefill code on the receiving end is the same either way, and `applyCountryFromCode()` / the `?package=` / `?duration=`handlers at the bottom of `application.js` already show the shape of it — but it is meaningfully worse:

- The full name, email, phone, DOB and licence number end up in a URL, and a URL is the single leakiest place to put PII: it lands in the `Referer` header sent to every third party the page loads (this site runs GTM), in browser history, in any proxy or CDN access log, and in the email client's own link-scanning telemetry.
- It is long. Several mail clients and security gateways rewrite or truncate long links, and a truncated link fails silently and unreproducibly.
- Corrections made on the backend can never reach an already-sent link.

One opaque token costs the same frontend code and has none of that. The `?resume`param is also stripped from the address bar with `history.replaceState()` after the fetch, so the token does not linger in history either.

### 7.3 What cannot be resumed

The portrait, both licence photos and the signature are deliberately never sent to the partial endpoint, so they cannot be restored. The resumed form must:

- leave the four upload controls empty and `required`,
- show a one-line notice at the top of step 3 — *"Your details are saved. For security we don't store your photos, so please re-attach them."*,
- land the visitor on step 3 (not step 4), since `validateStep(3)` will fail on the empty uploads anyway.

This is the right trade. Storing identity documents against an *unconverted, unpaid, unverified* lead — for 90 days, keyed by a token that arrives in an email — is a materially larger liability than asking for a re-upload.

---

## 8. Frontend changes

### 8.1 `assets/js/application.js`

Roughly 90 lines, in one new section. Sources are the source of truth; the pages load `application.min.js`, so `./tools/minify.sh` **must be re-run and the** `?v=` **query bumped in** `application.html:1155` or nothing changes in production.

```js
// ---------- partial / abandoned application capture ----------
// Fired once step 3 validates, before the visitor reaches checkout. Images are
// never included: see DESIGN.md §7.3.
var partialSent = false;

function buildPartialBody() {
    var activeTabIndex = selectedValidityIndex();
    var isPrinted      = selectedPackage() === 'printed';
    var planProductIds = PLAN_PRODUCT_IDS[activeTabIndex] || PLAN_PRODUCT_IDS[0];

    return {
        lead_token:       leadToken(),
        email:            val('app-email'),
        first_name:       val('app-first-name'),
        last_name:        val('app-last-name'),
        phone:            '+' + val('app-phone-dial-code') + val('app-phone'),
        application_type: isPrinted ? 'print_digital' : 'digital_only',
        validity_years:   activeTabIndex + 1,
        product_id:       isPrinted ? planProductIds.printed : planProductIds.digital,
        currency:         selectedCurrencyCode(),
        locale:           document.documentElement.lang || 'en',
        source:           'idta',
        payload: {
            gender:                 val('genderValue'),
            date_of_birth:          val('app-dob'),
            country_of_birth:       countryInfo('appBirthCountry'),
            country_of_residence:   countryInfo('appResidenceCountry'),
            license_issued_country: countryInfo('appLicenseCountry'),
            destination_country:    countryInfo('appDestinationCountry'),
            driver_license_number:  val('app-license-no'),
            license_categories:     Array.prototype.map.call(
                document.querySelectorAll('input[name="license_class"]:checked'),
                function (el) { return el.value; }
            ),
            package:         selectedPackage(),
            validity_label:  selectedValidityLabel(),
            summary_total:   (document.getElementById('summaryTotal') || {}).textContent || null,
            cart_items:      window.Cart ? Cart.getItems() : [],
            step_reached:    3
        }
    };
}

function sendPartial() {
    if (partialSent) return;                                  // once per page load
    if (!window.ENV || !window.ENV.R2_WORKER_URL) return;
    var body = buildPartialBody();
    if (!body.email) return;
    partialSent = true;

    // keepalive: the request must survive the step transition and, in the
    // fast-checkout case, the navigation away to the pay page.
    fetch(window.ENV.R2_WORKER_URL.replace(/\/$/, '') + '/partial', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        keepalive: true
    }).catch(function (err) {
        // Capture is best-effort and must never block or alarm the customer:
        // this is our analytics, not their application.
        console.warn('application.js: partial capture failed —', err);
    });
}
```

Hooked into the existing `[data-next]` handler — three lines, after validation, before the panel switch:

```js
btn.addEventListener('click', function () {
    var panel = btn.closest('.form-step');
    if (!validateStep(panel)) return;
    var target = Number(btn.dataset.next);
    if (target === 2 && countriesStepDone()) target = 3;
    if (target === 4) sendPartial();                 // ← step 3 completed
    goToStep(target);
});
```

`lead_token` is added to `payload.meta` in `buildSubmissionPayload()` (one line, next to `order_from: 'idta'`) so it rides through to the order.

Resume, at the bottom of the file next to the other `urlParams` prefills:

```js
var resumeToken = urlParams.get('resume');
if (resumeToken && window.ENV && window.ENV.R2_WORKER_URL) {
    fetch(window.ENV.R2_WORKER_URL.replace(/\/$/, '') + '/partial/resume?token=' +
          encodeURIComponent(resumeToken))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.ok) return;             // expired / converted / unknown
            applyPartial(data);                        // fills fields, plan, validity, cart
            var notice = document.getElementById('resumeNotice');
            if (notice) notice.hidden = false;         // "re-attach your photos"
            goToStep(3, true);
        })
        .catch(function () { /* fall through to a blank form */ })
        .finally(function () {
            // keep the token out of history and out of any Referer header
            history.replaceState({}, '', window.location.pathname);
        });
}
```

`applyPartial()` reuses what is already there: `applyCountryFromCode()` for the four country selects, `ValiditySelect.select()` + `ensureValidityOnSelectedCard()`for the plan, `Cart.addItem()` for add-ons, then `updateSummary()`.

### 8.2 `application.html`

- A hidden `#resumeNotice` banner at the top of panel 3.
- The consent line under the step-3 Continue button (§6.4).
- Bump `application.min.js?v=` .

---

## 9. File layout

```
idta-pdf/
├─ idta-pdf.php                       (unchanged)
├─ uninstall.php                      + DROP TABLE, + delete_option (new keys)
├─ includes/
│  ├─ class-plugin.php                + ( new Partial\Module( $this->settings ) )->register();
│  │                                  + Partial\Table::install() in maybe_upgrade() and activate()
│  │                                  + unschedule the two new hooks in deactivate()
│  ├─ class-settings.php              + 6 defaults + their sanitisers (§10)
│  ├─ class-settings-page.php         + 'partials' tab
│  └─ partial/                        ← the whole feature, IDTA\PDF\Partial\*
│     ├─ class-module.php             wiring; the only thing Plugin knows about
│     ├─ class-table.php              dbDelta schema + version option
│     ├─ class-record.php             typed row object
│     ├─ class-repository.php         upsert / find / mark_converted / mark_reminded / purge
│     ├─ class-rest-controller.php    idta/v1 routes, auth, sanitising, rate limit
│     ├─ class-reminder-scheduler.php Action Scheduler: schedule / unschedule / send
│     ├─ class-reminder-email.php     WC_Email
│     ├─ class-conversion-listener.php woocommerce_new_order / payment_complete
│     ├─ class-cleanup.php            daily retention job
│     └─ class-admin-page.php         WP_List_Table screen + CSV export
└─ templates/emails/
   ├─ partial-reminder.php
   └─ plain/partial-reminder.php

idta/ (frontend)
├─ application.html                   resume notice, consent line, ?v= bump
├─ assets/js/application.js           capture + resume (§8.1)  → re-run tools/minify.sh
└─ worker/src/index.js                /partial, /partial/resume, /partial/unsubscribe,
                                      + 'lead_token' in IDP_META_KEYS
```

`Partial\Module::register()` is the single seam. Everything the feature touches outside `includes/partial/` is additive: one line in `Plugin::boot()`, defaults in `Settings`, a tab, two templates. Nothing hooks a filter that the order, PDF or permit-ready-email path runs through, and `Order_Data`, `Generator`, `Download_Handler`, `Release_Schedule` and `Release_Notifier` are not modified at all.

### Why not a separate plugin

Weighed and rejected. It would need its own copy of the autoloader, settings screen, Action Scheduler group and WC_Email registration, and — the deciding point — conversion detection has to read `_idp_lead_token` off the order at the same moment `idta-pdf` is reading the rest of the `_idp_*` meta. Splitting that across two plugins puts a load-order dependency between them for no gain. The module boundary above gives the isolation; a plugin boundary would only add coordination.

---

## 10. Settings (new `Settings::defaults()` keys)

| Key | Default | Meaning |
| --- | --- | --- |
| `partial_enabled` | `true` | Master switch. Off = REST returns 200 and stores nothing. |
| `partial_reminder_delay` | `12` | Minutes after step 3. |
| `partial_convert_on` | `'order_created'` | or `'payment_complete'` (§5.3). |
| `partial_retention_days` | `90` | Unconverted rows deleted after this. |
| `partial_converted_retention_days` | `365` | Converted rows anonymised after this. |
| `partial_reminder_cooldown_days` | `7` | Per-address reminder cooldown. |

Plus a read-only "API key" row on the tab showing whether `IDTA_PARTIAL_API_KEY` is defined in `wp-config.php`, with a generate button that falls back to the option.

---

## 11. Security summary

| Surface | Control |
| --- | --- |
| Worker → WP | `X-IDTA-Key` shared secret, `hash_equals()`, wp-config constant preferred over an option. HTTPS only. |
| Browser → Worker | Existing `ALLOWED_ORIGIN` allowlist; `/partial` added to `ORIGIN_LOCKED_PATHS`. |
| Abuse | Per-`ip_hash` and per-email transient rate limit (20/hour) on `POST /partial`; 60/hour on resume. Body capped at 32 KB. |
| `lead_token` | Client-generated, never a credential (§4.1), validated `^[A-Za-z0-9-]{16,64}$`. |
| `resume_token` | `bin2hex( random_bytes( 16 ) )`, server-only, dies with the row, absent from every API response. |
| Injection | Every write through `$wpdb->prepare()`; table name from `$wpdb->prefix` + a constant. |
| Output | `esc_html()` / `esc_url()` on the admin screen and both email templates; `payload` is JSON-decoded into an allowlist, never rendered raw. |
| PII | No images, ever. Retention + anonymisation job. Token stripped from the URL after resume. Nothing beyond a message logged to `error_log`. |
| Blast radius | The conversion listener is wrapped in `try/catch`; a broken partial table cannot fail an order. |

---

## 12. Build order

1. `Table` + `Repository` + `Record`, wired into `maybe_upgrade()`. Verify the table appears after a plain file overwrite, not just on activation.
2. `REST_Controller` + auth. Test with `curl` against WP directly.
3. Worker `/partial` proxy + `lead_token` in `IDP_META_KEYS`. Deploy.
4. `application.js` capture + `tools/minify.sh` + `?v=` bump. Confirm rows land.
5. `Conversion_Listener`. **Place a full test order and confirm the PDF and permit-ready email are unchanged** — this is the one step that touches the existing path.
6. `Reminder_Scheduler` + `Reminder_Email` + templates. Set the delay to 1 minute on staging to exercise it.
7. `/partial/resume` + frontend resume + unsubscribe.
8. `Admin_Page`, `Cleanup`, settings tab.

### Regression checks before each release

- An order with no `_idp_lead_token` still generates both PDFs and sends `IDTA_PDF_Release` exactly as now.
- Dropping the partial table entirely leaves checkout working end to end.
- Step 3 → order within 12 min: the row is `converted`, the scheduled action runs and logs "nothing sent".
- Step 3 → nothing: one reminder, at \~12 min, once — and a second run of the same action sends nothing.
- Two step-3 completions in one session: one row, updated, one reminder.