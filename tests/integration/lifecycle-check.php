<?php
/**
 * Full plugin lifecycle against the REAL backend:
 * activate → register → deactivate → reactivate → uninstall,
 * plus the API-down activation path and its retry.
 *
 * Runs the real Lifecycle, the real Client, the real WpHttpTransport and
 * the real WpOptionsCredentialStore — only WordPress's own option, cron
 * and HTTP functions are polyfilled, so what is exercised here is the
 * code a plugin actually ships.
 *
 * Usage:
 *   php lifecycle-check.php <base_url> <api_key> <product_secret> [<site_domain>]
 */

require __DIR__ . '/wp-http-polyfill.php';
require dirname( __DIR__ ) . '/wp-option-polyfill.php';
require dirname( __DIR__ ) . '/wp-cron-polyfill.php';
require dirname( __DIR__, 2 ) . '/appneck-sdk.php';

use Appneck\Sdk\Client;
use Appneck\Sdk\Config;
use Appneck\Sdk\Environment;
use Appneck\Sdk\Lifecycle;
use Appneck\Sdk\Storage\WpOptionsCredentialStore;

$base_url       = isset( $argv[1] ) ? $argv[1] : 'http://nginx';
$api_key        = isset( $argv[2] ) ? $argv[2] : '';
$product_secret = isset( $argv[3] ) ? $argv[3] : '';
$site_domain    = isset( $argv[4] ) ? $argv[4] : 's4-lifecycle-test.example.com';

$GLOBALS['appneck_test_options'] = array();
$GLOBALS['appneck_test_cron']    = array();

$failures = 0;

function check( $label, $condition, $detail = '' ) {
	global $failures;

	if ( $condition ) {
		echo "  PASS  {$label}\n";
	} else {
		++$failures;
		echo "  FAIL  {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
	}
}

function make_lifecycle( $base_url, $api_key, $product_secret, $site_domain ) {
	$client = new Client(
		new Config( $api_key, $product_secret, $base_url ),
		new WpOptionsCredentialStore( $api_key )
	);

	return new Lifecycle( $client, null, new Environment( null, array( 'site_domain' => $site_domain ) ) );
}

$store     = new WpOptionsCredentialStore( $api_key );
$lifecycle = make_lifecycle( $base_url, $api_key, $product_secret, $site_domain );

// -------------------------------------------------------------------
echo "1. ACTIVATE — must not touch the network, must not fail\n";

$lifecycle->on_activate();

check( 'activation completed without throwing', true );
check( 'no credentials yet', ! $store->has_credentials() );
check( 'registration is pending', $lifecycle->is_pending() );
check( 'a cron attempt is scheduled', appneck_test_is_scheduled( Lifecycle::CRON_HOOK ) );

// -------------------------------------------------------------------
echo "\n2. CRON FIRES — the deferred registration runs for real\n";

$response = $lifecycle->ensure_registered();

check( 'HTTP 201 Created', 201 === $response->status(), 'got ' . $response->status() . ' — ' . $response->error_message() );
check( 'credentials stored', $store->has_credentials() );
check( 'installation id stored', ! empty( $store->get_installation_id() ) );
check( 'secret stored', ! empty( $store->get_installation_secret() ) );
check( 'pending cleared', ! $lifecycle->is_pending() );
check( 'no stale cron entry left behind', ! appneck_test_is_scheduled( Lifecycle::CRON_HOOK ) );

$installation_id = $store->get_installation_id();
echo "        installation: {$installation_id}\n";
echo "        server status: " . $response->get( 'status' ) . "\n";

// -------------------------------------------------------------------
echo "\n3. DEACTIVATE\n";

$response = $lifecycle->on_deactivate();

check( 'HTTP 200', 200 === $response->status(), 'got ' . $response->status() . ' — ' . $response->error_message() );
check( 'server reports deactivated', 'deactivated' === $response->get( 'status' ), (string) $response->get( 'status' ) );
check( 'credentials retained', $store->has_credentials() );

// -------------------------------------------------------------------
echo "\n4. REACTIVATE — same id, no duplicate installation\n";

$lifecycle->on_activate();
$response = $lifecycle->ensure_registered();

check( 'HTTP 200 (reactivated, not 201 created)', 200 === $response->status(), 'got ' . $response->status() );
check( 'server reports active', 'active' === $response->get( 'status' ), (string) $response->get( 'status' ) );
check( 'same installation id', $installation_id === $store->get_installation_id() );
check( 'no secret re-disclosed', null === $response->get( 'installation_secret' ) );
check( 'stored secret survived reactivation', ! empty( $store->get_installation_secret() ) );

// -------------------------------------------------------------------
echo "\n5. A SIGNED CALL still works after the round trip\n";

$client = new Client(
	new Config( $api_key, $product_secret, $base_url ),
	new WpOptionsCredentialStore( $api_key )
);

$announcements = $client->get( '/sdk/v1/announcements' );
check( 'GET /sdk/v1/announcements → 200', 200 === $announcements->status(), 'got ' . $announcements->status() . ' — ' . $announcements->error_message() );

// -------------------------------------------------------------------
echo "\n6. UNINSTALL\n";

$response = $lifecycle->on_uninstall();

check( 'HTTP 200', 200 === $response->status(), 'got ' . $response->status() . ' — ' . $response->error_message() );
check( 'server reports removed', 'removed' === $response->get( 'status' ), (string) $response->get( 'status' ) );
check( 'local credentials cleared', ! $store->has_credentials() );

// -------------------------------------------------------------------
echo "\n7. ACTIVATION WITH THE API DOWN — must still succeed, must retry\n";

$GLOBALS['appneck_test_options'] = array();
$GLOBALS['appneck_test_cron']    = array();

$offline = make_lifecycle( 'http://127.0.0.1:9', $api_key, $product_secret, $site_domain );

$offline->on_activate();
check( 'activation completed with the API unreachable', true );
check( 'a retry is scheduled', appneck_test_is_scheduled( Lifecycle::CRON_HOOK ) );

$response = $offline->ensure_registered();
check( 'the attempt failed as a transport error', $response->is_transport_error() );
check( 'still no credentials', ! ( new WpOptionsCredentialStore( $api_key ) )->has_credentials() );
check( 'another retry is queued', appneck_test_is_scheduled( Lifecycle::CRON_HOOK ) );
check( 'attempt counter advanced', 1 === $offline->attempts() );

$delay_after_first = appneck_test_next_scheduled( Lifecycle::CRON_HOOK ) - time();
$response          = $offline->ensure_registered();
$delay_after_second = appneck_test_next_scheduled( Lifecycle::CRON_HOOK ) - time();

check( 'the retry actually fired again', 2 === $offline->attempts() );
check( 'backoff widened', $delay_after_second > $delay_after_first, "{$delay_after_first}s then {$delay_after_second}s" );
echo "        backoff: {$delay_after_first}s → {$delay_after_second}s\n";

// -------------------------------------------------------------------
echo "\n8. RETRY EVENTUALLY SUCCEEDS once the API comes back\n";

// A DIFFERENT site domain on purpose. Re-registering the first domain
// after uninstall hits a server-side unique constraint on
// (site_id, product_id) — a real gap, reported rather than worked around
// in the SDK; see the S4.2 report. This step is about proving the retry
// path recovers, so it uses a site that has never registered.
$recovered = make_lifecycle( $base_url, $api_key, $product_secret, 's4-retry-test.example.com' );
$response  = $recovered->ensure_registered();

check( 'registered on the retry', $response->ok(), 'status ' . $response->status() . ' — ' . $response->error_message() );
check( 'credentials now stored', ( new WpOptionsCredentialStore( $api_key ) )->has_credentials() );

$recovered_id = ( new WpOptionsCredentialStore( $api_key ) )->get_installation_id();
echo "        installation: {$recovered_id}\n";

// Leave the backend tidy: this second installation exists only to prove
// the retry path, so report it removed rather than leaving a phantom
// active install in the dev data.
$recovered->on_uninstall();
echo "        (marked removed and cleaned up)\n";

echo "\n" . ( 0 === $failures ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n" );
echo "TEST_INSTALLATION_ID={$installation_id}\n";
echo "RETRY_INSTALLATION_ID={$recovered_id}\n";

exit( 0 === $failures ? 0 : 1 );
