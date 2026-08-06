<?php
/**
 * Telemetry against the REAL backend: enqueue → batch → send → partial
 * success → offline resilience.
 *
 * Uses the real Client, WpHttpTransport, Lifecycle and Telemetry. The
 * queue is ArrayEventQueue rather than TableEventQueue because there is
 * no WordPress database in this harness — the two implement the same
 * EventQueue contract and ArrayEventQueue mirrors the cap semantics, but
 * TableEventQueue's SQL is genuinely not exercised here; see the S4.4
 * report.
 *
 * Usage:
 *   php telemetry-check.php <base_url> <api_key> <product_secret> [<site_domain>]
 */

require __DIR__ . '/wp-http-polyfill.php';
require dirname( __DIR__ ) . '/wp-option-polyfill.php';
require dirname( __DIR__ ) . '/wp-cron-polyfill.php';
require dirname( __DIR__, 2 ) . '/appneck-sdk.php';
// After the SDK, so the Logger interface it implements exists.
require dirname( __DIR__ ) . '/RecordingLogger.php';

use Appneck\Sdk\Client;
use Appneck\Sdk\Config;
use Appneck\Sdk\Environment;
use Appneck\Sdk\Lifecycle;
use Appneck\Sdk\Queue\ArrayEventQueue;
use Appneck\Sdk\Storage\WpOptionsCredentialStore;
use Appneck\Sdk\Telemetry;
use Appneck\Sdk\Tests\RecordingLogger;

$base_url       = isset( $argv[1] ) ? $argv[1] : 'http://nginx';
$api_key        = isset( $argv[2] ) ? $argv[2] : '';
$product_secret = isset( $argv[3] ) ? $argv[3] : '';
$site_domain    = isset( $argv[4] ) ? $argv[4] : 's4-telemetry-test.example.com';

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

function make_client( $base_url, $api_key, $product_secret ) {
	return new Client(
		new Config( $api_key, $product_secret, $base_url ),
		new WpOptionsCredentialStore( $api_key )
	);
}

$logger = new RecordingLogger();
$queue  = new ArrayEventQueue();
$client = make_client( $base_url, $api_key, $product_secret );

// -------------------------------------------------------------------
echo "1. Register, then grant consent (journal §5.4's fail-closed gate)\n";

$lifecycle = new Lifecycle( $client, null, new Environment( null, array( 'site_domain' => $site_domain ) ) );
$lifecycle->on_activate();
$registration = $lifecycle->ensure_registered();

check( 'registered (201)', 201 === $registration->status(), 'got ' . $registration->status() . ' — ' . $registration->error_message() );

$store           = new WpOptionsCredentialStore( $api_key );
$installation_id = $store->get_installation_id();
echo "        installation: {$installation_id}\n";

$telemetry = new Telemetry( $client, $queue, $logger );

// Fail-closed: telemetry must be refused until consent exists.
$telemetry->track( 'before_consent' );
$refused = $telemetry->flush();

check( 'telemetry refused before consent (403)', null !== $refused && 403 === $refused->status(), $refused ? (string) $refused->status() : 'null' );
check( 'the event survived the refusal', 1 === $queue->count() );
check( 'not stopped — consent is recoverable', ! $telemetry->is_stopped() );

$consent = $client->post(
	'/sdk/v1/consent',
	array(
		'status'                 => 'accepted',
		'privacy_policy_version' => '1.0',
	)
);
check( 'consent accepted (200)', 200 === $consent->status(), 'got ' . $consent->status() );

// The consent refusal set a back-off; lift it now that consent exists.
$telemetry->resume();

// -------------------------------------------------------------------
echo "\n2. track() must not make an HTTP call\n";

$before = $queue->count();

$telemetry->track( 'booking_created', array( 'source' => 'checkout', 'total' => 42 ) );
$telemetry->track( 'feature_used', array( 'feature' => 'bulk_export' ) );
$telemetry->track_error( 'Payment gateway timeout', array( 'gateway' => 'stripe' ) );
$telemetry->heartbeat();

check( 'four events queued locally', ( $before + 4 ) === $queue->count(), (string) $queue->count() );
echo "        (no request was made — proven structurally by the unit suite too)\n";

// -------------------------------------------------------------------
echo "\n3. Flush — one signed batch to the real backend\n";

$queued_before = $queue->count();
$response      = $telemetry->flush();

check( 'HTTP 202 Accepted', 202 === $response->status(), 'got ' . $response->status() . ' — ' . $response->error_message() );
check( 'server accepted every event', $queued_before === (int) $response->get( 'accepted_count' ), (string) $response->get( 'accepted_count' ) );
check( 'local queue cleared', 0 === $queue->count() );
check( 'rate limit headers read', null !== $response->rate_limit()->limit() );
echo '        accepted ' . $response->get( 'accepted_count' ) . ', rate limit ' .
	$response->rate_limit()->remaining() . '/' . $response->rate_limit()->limit() . " remaining\n";

// -------------------------------------------------------------------
echo "\n4. Partial success — a mix of valid and deliberately invalid events\n";

$telemetry->track( 'valid_one', array( 'ok' => true ) );

// Pushed straight onto the queue: track() cannot produce an invalid
// event, which is the point — this simulates a corrupted row or an
// older SDK version's output.
$queue->push( 'not_a_real_type', array( 'nope' => true ) );
$queue->push( 'custom_event', array( 'event' => 'valid_two' ) );

$response = $telemetry->flush();

check( 'HTTP 202', 202 === $response->status(), 'got ' . $response->status() );
check( 'two accepted', 2 === (int) $response->get( 'accepted_count' ), (string) $response->get( 'accepted_count' ) );
check( 'one rejected', 1 === (int) $response->get( 'rejected_count' ), (string) $response->get( 'rejected_count' ) );
check( 'queue fully cleared — rejected dropped, not retried forever', 0 === $queue->count() );
check( 'the rejection was logged', $logger->contains( 'permanently rejected' ) );

// -------------------------------------------------------------------
echo "\n5. Offline resilience\n";

$offline_client    = make_client( 'http://127.0.0.1:9', $api_key, $product_secret );
$offline_telemetry = new Telemetry( $offline_client, $queue, $logger );

$offline_telemetry->track( 'queued_while_offline_1' );
$offline_telemetry->track( 'queued_while_offline_2' );
$offline_telemetry->heartbeat();

$response = $offline_telemetry->flush();

check( 'the flush failed as a transport error', null !== $response && $response->is_transport_error() );
check( 'all three events survived', 3 === $queue->count(), (string) $queue->count() );

// The API comes back.
$recovered = new Telemetry( $client, $queue, $logger );
$response  = $recovered->flush();

check( 'the backlog sent once the API returned (202)', 202 === $response->status(), 'got ' . $response->status() );
check( 'all three accepted', 3 === (int) $response->get( 'accepted_count' ), (string) $response->get( 'accepted_count' ) );
check( 'queue empty', 0 === $queue->count() );

// -------------------------------------------------------------------
echo "\n6. Cleanup — mark this installation removed\n";

$lifecycle->on_uninstall();
echo "        (uninstalled)\n";

echo "\n" . ( 0 === $failures ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n" );
echo "TELEMETRY_INSTALLATION_ID={$installation_id}\n";

exit( 0 === $failures ? 0 : 1 );
