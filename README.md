# Appneck SDK for WordPress plugins

The client library a plugin embeds to talk to Appneck: signed telemetry, consent,
uninstall surveys and announcements.

**S4.1 (foundation)** ships the version-safe loader, the signed HTTP client,
credential storage and error handling. **S4.2 (lifecycle)** adds registration on
activation and status updates on deactivate/uninstall. **S4.3 (telemetry)** adds
the local queue, `track()`, and batched sending on a schedule. **S4.4 (consent)**
adds the site owner's prompt and the accept/reject/change flow. Survey and
announcement helpers are still to come; today you call `post()` / `get()` for
those.

Requires PHP 7.2+. No runtime dependencies.

---

## Installing it in a plugin

Two supported paths. **Both work; neither is preferred.** A plugin distributed
through wordpress.org usually cannot assume Composer, so the SDK is built to
work identically without it.

### 1. Bundled (no Composer)

Copy this directory into your plugin, and require the loader — **not** any file
in `src/`:

```php
require_once __DIR__ . '/vendor/appneck-sdk/appneck-sdk.php';
```

`appneck-sdk.php` defines no classes. It registers this copy's version and
arranges for exactly one copy on the site — the newest — to load its classes on
`plugins_loaded` priority 0. See "Version safety" below for why that matters.

Because the SDK is loaded on `plugins_loaded` priority 0, use it from priority 1
or later:

```php
add_action( 'plugins_loaded', function () {
    $client = \Appneck\Sdk\Sdk::client(
        'pk_your_product_key',
        'sk_your_product_secret',
        'https://app.appneck.com'
    );
}, 20 );
```

If you genuinely need the SDK earlier, call `appneck_sdk_load_latest()` yourself.
It is idempotent and safe — but copies belonging to plugins that have not loaded
yet cannot have registered, so an older copy may win. That is the trade-off, and
it is why the default waits.

### 2. Composer

```bash
composer require appneck/wordpress-sdk
```

Composer's PSR-4 autoloader resolves `Appneck\Sdk\*` from `src/`, so the classes
are available with no `require` of ours at all. If your plugin also ships to
users who install it without Composer, require the loader as well — it is safe
under both, since the loader's own bootstrap is guarded against classes that
already exist.

---

## Version safety (why the loader exists)

Several plugins on one site may each bundle their own copy of this SDK. If each
one simply required its classes, the second would raise
`Cannot redeclare class Appneck\Sdk\Client` — a fatal that takes down **the whole
site**, not just one plugin.

Guarding each class with `class_exists()` avoids the fatal but picks the wrong
winner: whichever plugin loads first wins, even if its copy is a year old, so a
plugin shipping a newer SDK silently runs on older code.

This package uses the **version-registry** pattern (the approach Action Scheduler
uses, and a variant of what Freemius does):

1. At include time, every copy appends `version => bootstrap path` to
   `$GLOBALS['appneck_sdk_versions']`. Nothing else happens — no classes, no I/O.
2. On `plugins_loaded` priority 0, `appneck_sdk_load_latest()` sorts the registry
   with `version_compare()` and loads the **highest version only**.

Two things in `appneck-sdk.php` are frozen and must stay backward compatible
forever, because the copy that defines them may be any version present on the
site — including one written before the version you are editing:

- the shape of `$GLOBALS['appneck_sdk_versions']`, and
- the behaviour of `appneck_sdk_load_latest()`.

To find out which copy actually won: `\Appneck\Sdk\Sdk::loaded_version()`.

---

## It will not take down the host site

This library runs inside other people's production WordPress sites. An uncaught
exception there is a white screen on a site whose owner has never heard of
Appneck, and no telemetry heartbeat is worth that.

So: **no public method throws.** Every call returns an
`Appneck\Sdk\Http\Response`, including for network failure, non-2xx responses,
malformed bodies, and any `Throwable` raised inside the SDK itself (`Error` as
well as `Exception`).

```php
$response = $client->get( '/sdk/v1/announcements' );

if ( ! $response->ok() ) {
    // Never throws. Inspect and move on.
    $response->status();            // 0 when no HTTP response was received
    $response->error_message();     // the server's own message where there is one
    $response->is_unauthorized();   // 401
    $response->is_rate_limited();   // 429
    $response->is_retryable();      // transport error, 429 or 5xx
    $response->rate_limit()->retry_after();
    return;
}

$announcements = $response->get( 'announcements', array() );
```

Logging is opt-in — pass a `Logger` to `Sdk::client()` if you want SDK failures
in your log. The default writes nothing, because filling a site owner's error log
is not ours to decide.

---

## Lifecycle (S4.2)

```php
add_action( 'plugins_loaded', function () {
    \Appneck\Sdk\Sdk::bootstrap(
        'pk_your_product_key',
        'sk_your_product_secret',
        'https://app.appneck.com',
        __FILE__          // your plugin's main file
    );
}, 20 );
```

That wires activation, deactivation, the registration cron and the
admin_init fallback. For uninstall, add `uninstall.php` to your plugin root:

```php
<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/appneck-sdk/appneck-sdk.php';

\Appneck\Sdk\Sdk::uninstall( 'pk_...', 'sk_...', 'https://app.appneck.com' );
```

`uninstall.php` rather than `register_uninstall_hook`, and the reason
matters: if `uninstall.php` exists WordPress **ignores**
`register_uninstall_hook` entirely, and that hook's callback has to survive
being serialized into the `uninstall_plugins` option — so it can only ever
be a static function name, never a closure or an instance method.
WordPress also loads **nothing** of your plugin for `uninstall.php` except
that one file, which is why it has to require the loader itself.

### Activation never waits on the API

`register_activation_hook` performs **no network I/O**. It marks the site
as needing registration and schedules an immediate cron event; the first
attempt runs on the next page load, in a request nobody is watching.

Calling the API during activation with a short timeout was considered and
rejected: if the API is slow or a firewall blackholes the connection,
every activation stalls for the full timeout and the person doing it
experiences that as "this plugin is broken". Deferring also means the
first attempt and every retry run the same code, so the path that matters
is the one exercised every time.

Failures retry on a widening backoff (1m → 5m → 15m → 1h → 6h → 24h),
capped at 12 attempts. A `403` (archived product) stops immediately —
that is a permanent refusal, and retrying for a day changes nothing.
WP-Cron only fires on traffic, which is correct: a site nobody visits has
nothing to report. `admin_init` is a fallback for sites with
`DISABLE_WP_CRON`, rate-limited to one attempt per hour.

### Reactivation

There is no client-side reactivation logic. The server's
`POST /sdk/v1/installations` is create-or-reactivate, so reactivating just
runs the registration flow again with the **stored** id — the server
reactivates that record instead of creating a duplicate, and correctly
declines to re-issue its secret, which the SDK expects and keeps the
stored one.

### Multisite: lazily, once per site

Each site in a network registers **itself**, the first time the cron or
`admin_init` path runs in its context.

Network activation fires the activation hook exactly once, so the
tempting implementation is to loop the network's sites and register each.
That is wrong at any real scale: a 500-site network becomes 500
synchronous API calls inside one activation request — a guaranteed
timeout, and exactly the kind of thing that gets an SDK blamed for taking
a network down.

Lazy per-site registration falls out of how WordPress already works.
`wp_options` is per-site, so `has_credentials()` is naturally answered
per-site: each site independently observes that it has none and enrols.
That also handles sites **created later** with no site-creation hook, and
spreads registrations across real traffic instead of one burst.

One site per installation is also what the data model already says: the
server's Site is keyed by domain and Installation is (site, product), and
every subsite of a network has its own domain. `is_multisite` is reported
so the backend can tell these apart.

**Known limitation:** network-wide *deactivation* fires once and cannot
feasibly notify every subsite, so subsites go silent rather than
reporting `deactivated`. The server's lost-installation detection
(journal 8.4) is what covers that case.

---

## Telemetry (S4.3)

```php
$sdk = \Appneck\Sdk\Sdk::bootstrap( 'pk_…', 'sk_…', 'https://app.appneck.com', __FILE__ );

$sdk->track( 'booking_created', array( 'source' => 'checkout', 'total' => 42 ) );
$sdk->track_error( 'Payment gateway timeout', array( 'gateway' => 'stripe' ) );
```

**`track()` never makes an HTTP call.** It writes one row to a local
queue and returns, so it is safe to call anywhere in a page load. An API
that is slow to call is one that careful developers move to a background
job and everyone else calls inline — so it simply isn't slow to call.
Sending happens on a scheduled flush.

`$sdk->flush()` forces a send now. That one *does* make a request, so
don't call it from a page load a visitor is waiting on.

### Local queue

A small custom table (`{prefix}appneck_sdk_events`), created with
`dbDelta` at activation — **not** `wp_options`. An option holding a
growing array is wrong three times over: every push means read →
unserialize → append → re-serialize → write the whole list (O(n) work on
a page load we're a guest in); two simultaneous `track()` calls silently
lose one event; and clearing only the events the server accepted means
rewriting the whole option again. A table gives O(1) appends, ordered
reads with a LIMIT, and deletes by id.

Capped at **1000 events**, dropping the **oldest** when full. That is ten
full batches — over two hours of complete backlog at the default
interval, and far more in practice. Dropping oldest rather than refusing
new ones means a site recovering from an outage reports what is happening
*now*; the alternative keeps a snapshot of whenever the outage started
and then goes blind.

If the table can't be created (hosts that revoke `CREATE`), the queue
degrades to storing nothing and the SDK simply never sends. Losing
analytics is our bad day; a fatal error would be the site owner's.

### Heartbeat

Every 15 minutes by default, filterable:

```php
add_filter( 'appneck_sdk_flush_interval', fn() => 30 * MINUTE_IN_SECONDS );
```

Journal §9.1 sizes the server's rate limits around "one heartbeat every
five minutes per installation", so 15 sits comfortably inside what the
server expects; §8.4 notes WP-Cron only fires on page load (so any
interval is a ceiling, not a promise) and sets the lost-installation
threshold at 24 hours — 15 minutes leaves ~96x margin, so a quiet site
can miss many ticks before it looks lost. There is a 60-second floor so a
filter can't turn into a request storm.

A heartbeat is an ordinary event of type `heartbeat` on the same queue,
sent in the same batch — not a private code path. That way the retry and
partial-success behaviour is exercised constantly by the most common
event there is, rather than being a rarely-tested branch.

### What happens to each response

| Response | Queue | Sending |
|---|---|---|
| `202` accepted | cleared | continues |
| `202` partially rejected | accepted **and** rejected cleared, rejections logged | continues |
| `422` all invalid | cleared, logged | continues |
| `429` | kept | paused for the server's `Retry-After` |
| `403` consent | kept, keeps accumulating | paused 1h — consent may be granted |
| `403` inactive install | purged | stopped until re-registration |
| `401` / `5xx` / network | kept | retries next tick |

Permanently-invalid events are dropped rather than retried: they can never
become valid, and keeping them blocks the queue behind events that can
never leave it.

---

## Consent (S4.4)

Nothing extra to wire: `Sdk::bootstrap()` registers the prompt, the
`admin-post.php` handler and the retry hooks. Two things are worth doing
by hand.

Tell the prompt which policy it is asking about — the server records a
privacy policy version on every consent decision, and only you know yours:

```php
add_filter( 'appneck_sdk_privacy_policy_version', fn() => '2026-08-06' );
```

And give site owners a way to change their mind, inside your own settings
page:

```php
$sdk->consent_notice()
    ->set_privacy_policy_url( 'https://acme.test/privacy' )
    ->render_settings_section();
```

### The prompt

An admin notice, shown until it is answered, with **Allow usage data** /
**No thanks**. Not a settings page of our own: an embedded library must
not add a top-level menu to somebody else's plugin, and two plugins
bundling this SDK would each add one. It is also **not dismissible** — a
dismiss button is a third answer meaning neither yes nor no, and the state
it leaves behind is the one where buffering continues, so it would read as
a way to make the question go away while collection quietly carried on.

Both buttons are form submits to `admin-post.php`, nonce-checked, gated on
`manage_options`, on a per-product action name (a shared action would mean
one plugin's Accept click answering for every other SDK copy on the site).

### The three states, and what `track()` does in each

| State | `track()` | Local queue | Sending |
|---|---|---|---|
| `pending` (never asked) | buffers | kept | attempted; server refuses with 403, events survive |
| `accepted` | buffers | kept | normal |
| `rejected` | **no-op** | **purged** | nothing sent, nothing collected |

The server is the enforcement (`/sdk/v1/telemetry` fails closed on
anything but `accepted`, journal §5.4). The client-side behaviour above is
about behaving decently on the site owner's own machine.

**Why a reject stops collecting rather than parking events:** continuing to
write rows into their database that can never be sent is still behaving
like a tracker on a system that said no — "we collect but don't transmit"
is not a defence anyone accepts, and the local buffer's only justification
was imminent transmission. Purging rather than parking also matters: a
retained backlog would mean a later change of mind shipping events
collected during exactly the window they had refused.

**Why `pending` is not treated the same way:** never-asked and said-no are
different facts. While the question is open the prompt is on screen, a
grant may be seconds away, and the backlog is exactly what should go out
when it comes — which is the behaviour the telemetry phase already proved.

### If the API is unreachable when they click

The decision is written locally **first**, then sent. So the click always
lands: the prompt does not come back, the local consequences (stop
collecting, or lift the consent back-off) apply immediately, and the site
owner is redirected back to the page they were on rather than to an error.
The unsent decision retries on a widening backoff (1m → … → 24h, capped at
12 attempts), plus an hourly `admin_init` fallback for sites where WP-Cron
cannot run.

A decision made in the first seconds after activation — before
registration has finished, since activation performs no network I/O —
costs no attempts at all: there is nothing to sign with yet, so nothing is
attempted, and it goes out once credentials exist.

### Privacy policy versions

A version change re-shows the prompt for a previously **accepted**
decision, with different wording, and the re-confirmation records a fresh
consent event under the new version. It does **not** block telemetry while
unanswered: the server stores the version each decision was made under but
has no notion of a *current* version to compare, so blocking client-side
would leave an installation the server reads as `accepted` while it
silently stopped reporting — which trips lost-installation detection
(journal §8.4) for a healthy site. A **rejected** decision is never
re-prompted by a version change. Bumping a version over a typo:

```php
add_filter( 'appneck_sdk_reprompt_on_policy_change', fn() => false );
```

### Reading it yourself

```php
$sdk->consent()->is_accepted();   // gate your own optional features
$sdk->consent()->status();        // pending|accepted|rejected
$sdk->consent()->decided_at();
$sdk->consent()->is_sync_pending();
```

The decision lives in one autoloaded `wp_options` row per product
(status, date and policy version together — a status without them is a
fragment, not a consent record). Autoloaded, unlike the credentials,
because `track()` reads it on page loads where the credentials are never
touched. `Sdk::uninstall()` deletes it; the server keeps the permanent
`consent_events` history regardless.

---

## Signing

Every request is HMAC-signed per journal §9.2a:

```
X-Signature = HMAC_SHA256(base_string, secret)
base_string = METHOD \n /path \n installation-id \n timestamp \n raw-body
```

`Appneck\Sdk\Signer` is pure and deterministic. Two signing modes:

- **bootstrap** — `POST /sdk/v1/installations` only, signed with the product
  secret shipped in your plugin.
- **installation** — everything else, signed with the per-installation secret the
  server issues at registration and never re-discloses.

The client will **not** fall back to the product secret when no installation
secret is stored. That fallback would let any installation sign as any other
installation of the same product, which is precisely the hole per-installation
secrets exist to close.

---

## Storage

`installation_id` and `installation_secret` are stored **together in one
`wp_options` row** (`WpOptionsCredentialStore`, `autoload = no`). Together,
because a pair written separately can be half-restored from a backup, leaving an
id with no secret — an unauthenticatable state with no recovery, since the secret
is issued once and never re-issued. Supply your own `CredentialStore` if you need
different persistence.

---

## Tests

```bash
# from the repo root, with the dev stack up
docker compose run --rm --no-deps -v "$(pwd)/packages:/packages" \
  -w /packages/wordpress-sdk backend \
  /var/www/html/vendor/bin/phpunit --cache-directory /tmp/pu

# live check against the running backend
docker compose run --rm --no-deps -v "$(pwd)/packages:/packages" \
  -w /packages/wordpress-sdk backend \
  php tests/integration/live-check.php http://nginx <api_key> <product_secret> \
     <installation_id> <installation_secret>

# and the per-phase live checks, each registering its own throwaway site
#   tests/integration/lifecycle-check.php  <base_url> <api_key> <product_secret>
#   tests/integration/telemetry-check.php  <base_url> <api_key> <product_secret> [<domain>]
#   tests/integration/consent-check.php    <base_url> <api_key> <product_secret> [<domain>]
```

The unit suite runs with **no Composer autoloader and no WordPress**, on purpose:
that is the environment a bundled copy actually runs in.

Style is **WordPress Coding Standards** (`phpcs.xml.dist`), not this monorepo's
Pint/PSR-12 setup — see that file for the reasoning.
