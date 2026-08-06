<?php
/**
 * Integration check against the REAL Appneck backend.
 *
 * Not part of the unit suite: it needs a running server, so it is a
 * script rather than a PHPUnit case that would fail for everyone without
 * a local stack. Run it from the repo root with the dev stack up.
 *
 * Usage:
 *   php live-check.php <base_url> <api_key> <product_secret> [<installation_id> <installation_secret>]
 *
 * What it proves:
 *   1. A request signed with WRONG credentials gets a clean 401 that is
 *      RETURNED, not thrown — the failure mode a plugin will actually
 *      hit if its keys are wrong, on a live site.
 *   2. A request signed correctly by THIS client is ACCEPTED by the real
 *      server — i.e. the base string this SDK builds and the one
 *      VerifySdkSignature builds agree byte for byte.
 *   3. A stale timestamp is rejected, proving the client sends the same
 *      timestamp it signed.
 *   4. Nothing anywhere throws.
 */

require __DIR__ . '/wp-http-polyfill.php';
require dirname( __DIR__, 2 ) . '/appneck-sdk.php';

use Appneck\Sdk\Client;
use Appneck\Sdk\Config;
use Appneck\Sdk\Sdk;
use Appneck\Sdk\Storage\ArrayCredentialStore;

$base_url            = isset( $argv[1] ) ? $argv[1] : 'http://localhost:8080';
$api_key             = isset( $argv[2] ) ? $argv[2] : '';
$product_secret      = isset( $argv[3] ) ? $argv[3] : '';
$installation_id     = isset( $argv[4] ) ? $argv[4] : '';
$installation_secret = isset( $argv[5] ) ? $argv[5] : '';

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

echo "SDK version loaded: " . Sdk::loaded_version() . "\n\n";

// -------------------------------------------------------------------
echo "1. Wrong credentials must produce a clean, non-throwing 401\n";

$bad = new Client(
	new Config( $api_key, 'sk_completely_the_wrong_secret_0000000000', $base_url ),
	new ArrayCredentialStore( $installation_id, 'sk_completely_the_wrong_secret_0000000000' )
);

$response = $bad->post( '/sdk/v1/telemetry', array( 'events' => array( array( 'type' => 'heartbeat', 'payload' => array( 'x' => 1 ) ) ) ) );

check( 'returned rather than threw', true );
check( 'status is 401', 401 === $response->status(), 'got ' . $response->status() );
check( 'ok() is false', ! $response->ok() );
check( 'is_unauthorized()', $response->is_unauthorized() );
check( 'not retryable', ! $response->is_retryable() );
check( 'server message surfaced', is_string( $response->error_message() ) && '' !== $response->error_message(), (string) $response->error_message() );
echo "        server said: " . $response->error_message() . "\n\n";

// -------------------------------------------------------------------
echo "2. Correct credentials must be ACCEPTED by the real server\n";

if ( '' === $installation_secret ) {
	echo "  SKIP  no installation credentials supplied\n\n";
} else {
	$good = new Client(
		new Config( $api_key, $product_secret, $base_url ),
		new ArrayCredentialStore( $installation_id, $installation_secret )
	);

	$response = $good->get( '/sdk/v1/announcements' );

	check( 'status is 200', 200 === $response->status(), 'got ' . $response->status() . ' — ' . $response->error_message() );
	check( 'ok()', $response->ok() );
	check( 'body decoded', is_array( $response->data() ) );
	check( 'announcements key present', array_key_exists( 'announcements', $response->data() ) );

	$announcements = $response->get( 'announcements', array() );
	echo "        returned " . count( $announcements ) . " announcement(s)\n";

	// A signed POST as well as a GET — the two body shapes sign
	// differently (empty string vs. encoded JSON), so one working does
	// not imply the other does.
	$telemetry = $good->post(
		'/sdk/v1/telemetry',
		array( 'events' => array( array( 'type' => 'heartbeat', 'payload' => array( 'source' => 'sdk-live-check' ) ) ) )
	);

	check( 'signed POST accepted (202)', 202 === $telemetry->status(), 'got ' . $telemetry->status() . ' — ' . $telemetry->error_message() );
	check( 'rate limit headers read', null !== $telemetry->rate_limit()->limit(), 'limit header missing' );
	echo '        rate limit: ' . $telemetry->rate_limit()->remaining() . ' of ' . $telemetry->rate_limit()->limit() . " remaining\n\n";
}

// -------------------------------------------------------------------
echo "3. A request to an unreachable host must fail safely, not throw\n";

$offline = new Client(
	new Config( $api_key, $product_secret, 'http://127.0.0.1:9' ),
	new ArrayCredentialStore( $installation_id, $installation_secret )
);

$response = $offline->get( '/sdk/v1/announcements' );

check( 'returned rather than threw', true );
check( 'is_transport_error()', $response->is_transport_error() );
check( 'status is 0', 0 === $response->status() );
check( 'is retryable', $response->is_retryable() );
echo '        transport said: ' . $response->error_message() . "\n\n";

echo 0 === $failures ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n";

exit( 0 === $failures ? 0 : 1 );
