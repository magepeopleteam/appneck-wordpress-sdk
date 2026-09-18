<?php

namespace Appneck\Sdk;

use Appneck\Sdk\Admin\AnnouncementNotices;
use Appneck\Sdk\Admin\ConsentNotice;
use Appneck\Sdk\Admin\DeactivationSurvey;
use Appneck\Sdk\Admin\LicenseForm;
use Appneck\Sdk\Http\Transport;
use Appneck\Sdk\Http\WpHttpTransport;
use Appneck\Sdk\Queue\EventQueue;
use Appneck\Sdk\Queue\TableEventQueue;
use Appneck\Sdk\Logging\Logger;
use Appneck\Sdk\Storage\CredentialStore;
use Appneck\Sdk\Storage\WpOptionsCredentialStore;
use Appneck\Sdk\Storage\WpOptionsLicenseStore;

/**
 * The one entry point a plugin author is expected to touch.
 *
 * Everything else in this package is constructible by hand for testing
 * and for callers with unusual needs, but the intended use is:
 *
 *     $client = \Appneck\Sdk\Sdk::client( 'pk_...', 'sk_...', 'https://appneck.com' );
 *
 * which wires the WordPress options store and the WordPress HTTP
 * transport for you.
 */
final class Sdk {

	/**
	 * MUST match appneck-sdk.php's $appneck_sdk_this_version. The loader
	 * cannot read this constant (the whole point is that it decides
	 * which copy to load BEFORE any class exists), so the two are
	 * necessarily separate strings — and SignerTest asserts they agree,
	 * because a version bump applied to only one of them would make the
	 * registry rank this copy wrongly against its siblings.
	 */
	const VERSION = '0.1.0';

	/**
	 * @param string $api_key        Product API key (pk_...).
	 * @param string $product_secret Bootstrap signing secret (sk_...).
	 * @param string $base_url       API root.
	 */
	public static function client(
		$api_key,
		$product_secret,
		$base_url,
		?CredentialStore $credentials = null,
		?Transport $transport = null,
		?Logger $logger = null
	) {
		$config = new Config( $api_key, $product_secret, $base_url );

		if ( null === $credentials ) {
			$credentials = new WpOptionsCredentialStore( $api_key );
		}

		return new Client( $config, $credentials, $transport, $logger );
	}

	/**
	 * The usual one-liner for a plugin: build a client, wire the
	 * activation/deactivation/cron hooks, and hand back the lifecycle so
	 * the caller can reach it from uninstall.php.
	 *
	 * @param string               $plugin_file __FILE__ of the plugin's main file.
	 * @param array<string, mixed> $options     Optional per-product settings.
	 *                                          Licensing reads
	 *                                          `license_fail_mode`
	 *                                          ('open' — the default — or
	 *                                          'closed'), `license_cache_ttl`
	 *                                          (seconds, default 86400) and
	 *                                          `license_domain` (overrides
	 *                                          home_url()). Unknown keys are
	 *                                          ignored, so a plugin built
	 *                                          against a newer SDK's options
	 *                                          still boots on an older copy
	 *                                          that another plugin's bundle
	 *                                          happened to win the registry
	 *                                          with.
	 * @return Plugin Handle exposing track()/track_error() and the rest.
	 */
	public static function bootstrap(
		$api_key,
		$product_secret,
		$base_url,
		$plugin_file,
		?CredentialStore $credentials = null,
		?Transport $transport = null,
		?Logger $logger = null,
		?EventQueue $queue = null,
		array $options = array()
	) {
		$client = self::client( $api_key, $product_secret, $base_url, $credentials, $transport, $logger );

		$queue       = null !== $queue ? $queue : new TableEventQueue( $api_key );
		$environment = new Environment( $plugin_file );

		// 13-realtime-config-delivery.md: the shared circuit breaker and
		// staleness flag for every realtime-config network call (the
		// config poll, the announcements refresh, and the live survey
		// fetch). One instance, one key, shared across all three — see
		// RealtimeConfig's own class doc for why one breaker rather than
		// three.
		$deactivationKey = substr( hash( 'sha256', $api_key ), 0, 32 );
		$realtimeConfig  = new RealtimeConfig( $deactivationKey );

		// A dedicated short-timeout client (rule 3: 3 seconds, not the
		// 10-second default every other SDK feature shares) for exactly
		// the calls this feature makes on a page load a human is
		// actively waiting on: the poll, and the best-effort dismissal
		// report. Same Config/CredentialStore as the main client — only
		// the transport's timeout differs — the same pattern this
		// package already uses for LicenseClient below.
		$fastClient = new Client( $client->config(), $client->credentials(), new WpHttpTransport( 3 ), $logger );

		// Telemetry reads the site's plugin/theme inventory off this same
		// instance for the heartbeat — see Telemetry::environment_payload.
		$telemetry = new Telemetry( $client, $queue, $logger, null, $environment );
		$consent   = new Consent( $client, $telemetry, $logger );
		$lifecycle = new Lifecycle( $client, $plugin_file, $environment, $telemetry, $realtimeConfig );

		// Mutual: Telemetry asks Consent whether the owner refused, Consent
		// acts on Telemetry the moment they answer. Wired here rather than
		// in either constructor so both stay independently constructible.
		$telemetry->set_consent( $consent );

		// Every response Telemetry already receives carries config_version
		// for free (13-realtime-config-delivery.md §4) — wired the same
		// way as Consent above, so RealtimeConfig learns of a change
		// without this costing an extra request.
		$telemetry->set_realtime_config( $realtimeConfig );

		$plugin_name = $environment->plugin_name();
		$notice      = new ConsentNotice(
			$consent,
			null !== $plugin_name ? array( 'product_name' => $plugin_name ) : array()
		);

		// S4.5: the deactivation survey. Uses the FAST client — Part 7's
		// 3-second timeout — and the shared circuit breaker, so a click on
		// Deactivate during an outage skips a doomed 10-second wait and
		// falls back to whatever was last cached instead
		// (Survey::questions(), 13-realtime-config-delivery.md Part 7).
		$survey             = new Survey( $fastClient, $logger, $realtimeConfig );
		$deactivationSurvey = new DeactivationSurvey(
			$survey,
			$deactivationKey,
			$plugin_file,
			null !== $plugin_name ? array( 'product_name' => $plugin_name ) : array()
		);

		// S4.6/13-realtime-config-delivery.md Layers 2-3: announcements.
		// register_hooks() rides the existing heartbeat tick for the cron
		// refresh, same as before. Rendering is now render_globally() by
		// default — every wp-admin page, from cache only, kept fresh by
		// an async admin-ajax script rather than an opt-in screen and a
		// once-an-hour cron-dead fallback. render_on_screen() still exists
		// on the same object for anything that wants the original,
		// narrower behaviour instead — see AnnouncementNotices' class doc.
		$announcements      = new Announcements( $client, $logger, $realtimeConfig );
		$announcementNotice = new AnnouncementNotices( $announcements, $deactivationKey, $realtimeConfig, $fastClient );

		// Phase 5: licensing. Its own client, because journal §23.1's auth
		// is a different scheme signed with a different secret and — the
		// part that matters — must work for a site with no installation
		// at all. See LicenseClient's class doc.
		//
		// Constructing it here costs nothing: License performs no I/O
		// until one of its methods is called, and is_valid()'s hot path
		// is a single autoloaded option read.
		$license = new License(
			new LicenseClient( $client->config(), $transport, $logger ),
			new WpOptionsLicenseStore( $api_key ),
			$logger,
			$options
		);

		$license_form = new LicenseForm(
			$license,
			null !== $plugin_name ? array( 'product_name' => $plugin_name ) : array()
		);

		$lifecycle->register_hooks();
		$telemetry->register_hooks();
		$consent->register_hooks();
		$notice->register_hooks();
		$deactivationSurvey->register_hooks();
		$announcements->register_hooks();
		$announcementNotice->register_hooks();
		$announcementNotice->render_globally();
		// Registers the admin-post handler ONLY. The panel itself prints
		// nowhere until the host plugin calls render() on its own page.
		$license_form->register_hooks();

		return new Plugin(
			$client,
			$lifecycle,
			$telemetry,
			$consent,
			$notice,
			$survey,
			$deactivationSurvey,
			$announcements,
			$announcementNotice,
			$license,
			$license_form,
			// Phase 8: license_page()'s 'product_name' fallback, so a
			// developer who never passes one still gets the plugin's own
			// name rather than the generic "This plugin" both LicenseForm
			// and LicenseNotice fall back to when nothing at all is known.
			$plugin_name
		);
	}

	/**
	 * The uninstall entry point, for a plugin's own uninstall.php.
	 *
	 * uninstall.php is the reliable path rather than
	 * register_uninstall_hook, and the reason is worth stating: if
	 * uninstall.php exists, WordPress IGNORES register_uninstall_hook
	 * entirely, and the hook's callback has to survive being serialized
	 * into the `uninstall_plugins` option — so it must be a static
	 * function name, never a closure or an instance method. Meanwhile
	 * WordPress loads NOTHING of the plugin for uninstall.php except that
	 * one file, so it has to require the SDK loader itself:
	 *
	 *     defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
	 *     require_once __DIR__ . '/vendor/appneck-sdk/appneck-sdk.php';
	 *     \Appneck\Sdk\Sdk::uninstall( 'pk_...', 'sk_...', 'https://appneck.com' );
	 *
	 * Returns null when this site never completed registration — there is
	 * nothing on the server to mark removed, and that is not an error.
	 */
	public static function uninstall(
		$api_key,
		$product_secret,
		$base_url,
		?CredentialStore $credentials = null,
		?Transport $transport = null,
		?Logger $logger = null
	) {
		$client    = self::client( $api_key, $product_secret, $base_url, $credentials, $transport, $logger );
		$lifecycle = new Lifecycle( $client );

		$response = $lifecycle->on_uninstall();

		// The stored decision is the plugin's own data and goes with it.
		// Leaving it behind would mean a re-install silently inheriting an
		// answer given by whoever ran the site months ago — and the server
		// keeps the permanent consent_events history regardless, so nothing
		// auditable is lost. Cleared after on_uninstall(), which needs the
		// credentials that call is signed with.
		( new Consent( $client, null, $logger ) )->forget();

		// The cached survey questions are the plugin's data too, and a
		// stale copy would otherwise outlive the plugin that fetched it.
		( new Survey( $client, $logger ) )->forget();

		// Cached announcements and this site's dismissals go with the
		// plugin too — the server keeps its own analytics copy of a
		// dismissal (13-realtime-config-delivery.md §6.3) but display
		// state has never lived anywhere but here.
		( new Announcements( $client, $logger ) )->forget();

		// The realtime-config staleness flag and circuit-breaker state
		// (13-realtime-config-delivery.md) are this installation's own
		// bookkeeping, not the server's; nothing to reconcile, only to
		// remove.
		( new RealtimeConfig( substr( hash( 'sha256', $api_key ), 0, 32 ) ) )->forget();

		// The license is the one piece of uninstall cleanup with a
		// SERVER-side consequence: the activation slot this domain holds
		// stays held unless it is released. Best-effort on the
		// short-timeout transport and the local row goes either way —
		// an uninstall must never block or fail on a licensing call, and
		// there is no local state left for a retry to live in. See
		// License::on_uninstall().
		( new License(
			new LicenseClient( $client->config(), $transport, $logger ),
			new WpOptionsLicenseStore( $api_key ),
			$logger
		) )->on_uninstall();

		return $response;
	}

	/**
	 * The version actually loaded in this process, which is not
	 * necessarily this file's own — another plugin may have bundled a
	 * newer copy that won the registry. Useful in bug reports.
	 *
	 * @return string
	 */
	public static function loaded_version() {
		return defined( 'APPNECK_SDK_LOADED_VERSION' ) ? APPNECK_SDK_LOADED_VERSION : self::VERSION;
	}
}
