<?php

namespace Appneck\Sdk;

use Appneck\Sdk\Admin\ConsentNotice;
use Appneck\Sdk\Http\Transport;
use Appneck\Sdk\Queue\EventQueue;
use Appneck\Sdk\Queue\TableEventQueue;
use Appneck\Sdk\Logging\Logger;
use Appneck\Sdk\Storage\CredentialStore;
use Appneck\Sdk\Storage\WpOptionsCredentialStore;

/**
 * The one entry point a plugin author is expected to touch.
 *
 * Everything else in this package is constructible by hand for testing
 * and for callers with unusual needs, but the intended use is:
 *
 *     $client = \Appneck\Sdk\Sdk::client( 'pk_...', 'sk_...', 'https://app.appneck.com' );
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
	 * @param string $plugin_file __FILE__ of the plugin's main file.
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
		?EventQueue $queue = null
	) {
		$client = self::client( $api_key, $product_secret, $base_url, $credentials, $transport, $logger );

		$queue     = null !== $queue ? $queue : new TableEventQueue( $api_key );
		$telemetry = new Telemetry( $client, $queue, $logger );
		$consent   = new Consent( $client, $telemetry, $logger );
		$lifecycle = new Lifecycle( $client, $plugin_file, null, $telemetry );

		// Mutual: Telemetry asks Consent whether the owner refused, Consent
		// acts on Telemetry the moment they answer. Wired here rather than
		// in either constructor so both stay independently constructible.
		$telemetry->set_consent( $consent );

		$environment  = new Environment( $plugin_file );
		$plugin_name  = $environment->plugin_name();
		$notice       = new ConsentNotice(
			$consent,
			null !== $plugin_name ? array( 'product_name' => $plugin_name ) : array()
		);

		$lifecycle->register_hooks();
		$telemetry->register_hooks();
		$consent->register_hooks();
		$notice->register_hooks();

		return new Plugin( $client, $lifecycle, $telemetry, $consent, $notice );
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
	 *     \Appneck\Sdk\Sdk::uninstall( 'pk_...', 'sk_...', 'https://app.appneck.com' );
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
