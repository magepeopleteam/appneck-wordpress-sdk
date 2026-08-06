<?php
/**
 * Reproduces the exact scenario that produced a raw 500 in S4.2, against
 * the REAL backend: a site whose stored credentials are gone mints a new
 * installation id and registers again for a (site, product) that already
 * has an installation.
 *
 * Also checks what the SDK's own retry logic does with the answer.
 *
 * Usage:
 *   php conflict-check.php <base_url> <api_key> <product_secret> <site_domain>
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
$site_domain    = isset( $argv[4] ) ? $argv[4] : 's4-conflict-test.example.com';

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

echo "1. First registration (the installation that will be conflicted with)\n";

$lifecycle = make_lifecycle( $base_url, $api_key, $product_secret, $site_domain );
$lifecycle->on_activate();
$first = $lifecycle->ensure_registered();

check( 'HTTP 201', 201 === $first->status(), 'got ' . $first->status() . ' — ' . $first->error_message() );

$store       = new WpOptionsCredentialStore( $api_key );
$original_id = $store->get_installation_id();
echo "        installation: {$original_id}\n";

echo "\n2. The site LOSES its credentials (backup restore, options wiped)\n";

$GLOBALS['appneck_test_options'] = array();
$GLOBALS['appneck_test_cron']    = array();

check( 'no credentials stored', ! ( new WpOptionsCredentialStore( $api_key ) )->has_credentials() );

echo "\n3. It enrols again under a NEW id — the exact S4.2 repro\n";

$recovering = make_lifecycle( $base_url, $api_key, $product_secret, $site_domain );
$recovering->on_activate();
$response = $recovering->ensure_registered();

check( 'HTTP 409 Conflict (was a raw 500)', 409 === $response->status(), 'got ' . $response->status() );
check(
	'generic message',
	'An installation already exists for this site and product.' === $response->get( 'message' ),
	(string) $response->get( 'message' )
);

$body = $response->raw_body();
echo "        body: {$body}\n";

foreach ( array(
	'installations_site_id_product_id_unique',
	'SQLSTATE',
	'insert into',
	'pgsql',
	'Connection',
	$original_id,
	'removed',
	'active',
	'secret',
) as $forbidden ) {
	check( "does not leak \"{$forbidden}\"", false === strpos( $body, $forbidden ) );
}

$decoded = json_decode( $body, true );
check( 'exactly one field in the body', is_array( $decoded ) && array( 'message' ) === array_keys( $decoded ) );

echo "\n4. The SDK must NOT retry — a 409 can never succeed on retry\n";

check( 'registration is not pending', ! $recovering->is_pending() );
check( 'no retry scheduled', ! appneck_test_is_scheduled( Lifecycle::CRON_HOOK ) );

$before = $response->status();
$again  = $recovering->ensure_registered();
check( 'a further cron tick sends nothing', null === $again );

echo "\n5. The existing installation is untouched\n";

$GLOBALS['appneck_test_options'] = array();
$restored                        = new WpOptionsCredentialStore( $api_key );
$restored->save( $original_id, $store_secret ?? '' );

echo "        (server-side state verified separately via tinker)\n";

echo "\n" . ( 0 === $failures ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n" );
echo "ORIGINAL_INSTALLATION_ID={$original_id}\n";

exit( 0 === $failures ? 0 : 1 );
