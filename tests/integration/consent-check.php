<?php
/**
 * Consent against the REAL backend: prompt → Accept → queued events send;
 * Reject → nothing collected and telemetry stays refused server-side;
 * change of mind → the queue unblocks again.
 *
 * Every decision here goes through the actual admin-post handler
 * (ConsentNotice::handle) with a populated $_POST, not through
 * Consent::accept() directly — the click is the thing being tested, and
 * the capability check, the nonce check and the redirect are part of it.
 *
 * Uses the real Client, WpHttpTransport, Lifecycle, Telemetry and Consent.
 * The queue is ArrayEventQueue because this harness has no WordPress
 * database; TableEventQueue's SQL is still unexercised (same gap S4.3
 * reported).
 *
 * Usage:
 *   php consent-check.php <base_url> <api_key> <product_secret> [<site_domain>]
 */

require __DIR__ . '/wp-http-polyfill.php';
require dirname( __DIR__ ) . '/wp-option-polyfill.php';
require dirname( __DIR__ ) . '/wp-cron-polyfill.php';
require dirname( __DIR__ ) . '/wp-admin-polyfill.php';
require dirname( __DIR__, 2 ) . '/appneck-sdk.php';
require dirname( __DIR__ ) . '/RecordingLogger.php';

use Appneck\Sdk\Admin\ConsentNotice;
use Appneck\Sdk\Client;
use Appneck\Sdk\Config;
use Appneck\Sdk\Consent;
use Appneck\Sdk\Environment;
use Appneck\Sdk\Lifecycle;
use Appneck\Sdk\Queue\ArrayEventQueue;
use Appneck\Sdk\Storage\WpOptionsCredentialStore;
use Appneck\Sdk\Telemetry;
use Appneck\Sdk\Tests\RecordingLogger;

$base_url       = isset( $argv[1] ) ? $argv[1] : 'http://nginx';
$api_key        = isset( $argv[2] ) ? $argv[2] : '';
$product_secret = isset( $argv[3] ) ? $argv[3] : '';
$site_domain    = isset( $argv[4] ) ? $argv[4] : 's4-consent-test.example.com';

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

/** Simulates a site owner clicking a button in the admin notice. */
function click( ConsentNotice $notice, $status ) {
	$_POST = array(
		'action'             => $notice->action(),
		ConsentNotice::FIELD => $status,
		'_wpnonce'           => 'nonce-for-' . $notice->action(),
	);

	return $notice->handle();
}

$logger    = new RecordingLogger();
$queue     = new ArrayEventQueue();
$client    = make_client( $base_url, $api_key, $product_secret );
$telemetry = new Telemetry( $client, $queue, $logger );
$consent   = new Consent( $client, $telemetry, $logger );
$telemetry->set_consent( $consent );
$consent->set_privacy_policy_version( '2026-08-06' );

$redirects = array();
$notice    = new ConsentNotice( $consent, array( 'product_name' => 'Consent Check Plugin' ) );
$notice->set_redirect_handler(
	function ( $url ) use ( &$redirects ) {
		$redirects[] = $url;
	}
);

// -------------------------------------------------------------------
echo "1. Register, and confirm the prompt is showing\n";

$lifecycle    = new Lifecycle( $client, null, new Environment( null, array( 'site_domain' => $site_domain ) ), $telemetry );
$lifecycle->on_activate();
$registration = $lifecycle->ensure_registered();

check( 'registered (201)', 201 === $registration->status(), 'got ' . $registration->status() . ' — ' . $registration->error_message() );

$installation_id = ( new WpOptionsCredentialStore( $api_key ) )->get_installation_id();
echo "        installation: {$installation_id}\n";

check( 'consent starts pending', $consent->is_pending() );
check( 'the prompt needs an answer', $consent->needs_decision() );

ob_start();
$notice->render();
$html = (string) ob_get_clean();

check( 'the notice renders', false !== strpos( $html, 'notice notice-info' ) );
check( 'it names the plugin', false !== strpos( $html, 'Consent Check Plugin' ) );
check( 'it offers both answers', false !== strpos( $html, 'value="accepted"' ) && false !== strpos( $html, 'value="rejected"' ) );
check( 'it is not dismissible', false === strpos( $html, 'is-dismissible' ) );

// -------------------------------------------------------------------
echo "\n2. Events queue while unanswered, and the server refuses them\n";

$telemetry->track( 'queued_before_consent_1' );
$telemetry->track( 'queued_before_consent_2' );

check( 'two events queued locally', 2 === $queue->count(), (string) $queue->count() );

$refused = $telemetry->flush();

check( 'telemetry refused (403)', null !== $refused && 403 === $refused->status(), $refused ? (string) $refused->status() : 'null' );
check( 'both events survived the refusal', 2 === $queue->count(), (string) $queue->count() );

// -------------------------------------------------------------------
echo "\n3. Accept clicked → POST /consent, and the backlog goes out\n";

$applied = click( $notice, 'accepted' );

check( 'the click was applied', 'accepted' === $applied, var_export( $applied, true ) );
check( 'redirected back to where they were', 1 === count( $redirects ), (string) count( $redirects ) );
check( 'the consent call succeeded', 200 === $client->last_response()->status(), (string) $client->last_response()->status() );
check( 'stored locally as accepted', $consent->is_accepted() );
check( 'the server has it — nothing pending', ! $consent->is_sync_pending() );
check( 'the prompt is answered', ! $consent->needs_decision() );
check( 'the consent back-off was lifted', 0 === $telemetry->suppressed_until(), (string) $telemetry->suppressed_until() );
check( 'the backlog is still queued', 2 === $queue->count(), (string) $queue->count() );

$flush = $telemetry->flush();

check( 'the previously-refused batch is accepted (202)', null !== $flush && 202 === $flush->status(), $flush ? (string) $flush->status() : 'null' );
check( 'both previously-queued events accepted', 2 === (int) $flush->get( 'accepted_count' ), (string) $flush->get( 'accepted_count' ) );
check( 'queue cleared', 0 === $queue->count() );

ob_start();
$notice->render();
check( 'the notice no longer renders', '' === (string) ob_get_clean() );

// -------------------------------------------------------------------
echo "\n4. Reject clicked → nothing is collected at all\n";

$telemetry->track( 'collected_while_accepted' );
check( 'an event was collected while accepted', 1 === $queue->count() );

$applied = click( $notice, 'rejected' );

check( 'the click was applied', 'rejected' === $applied, var_export( $applied, true ) );
check( 'the consent call succeeded', 200 === $client->last_response()->status(), (string) $client->last_response()->status() );
check( 'stored locally as rejected', $consent->is_rejected() );

check( 'track() is a no-op', false === $telemetry->track( 'after_refusal' ) );
check( 'track_error() is a no-op', false === $telemetry->track_error( 'after_refusal' ) );
check( 'heartbeat() is a no-op', false === $telemetry->heartbeat() );
check( 'the local queue is empty — collection stopped, not parked', 0 === $queue->count(), (string) $queue->count() );
check( 'flush() makes no request', null === $telemetry->flush() );

// The client-side no-op is a courtesy; the server is the enforcement.
// Prove the server still refuses by going around Telemetry entirely.
$direct = $client->post(
	'/sdk/v1/telemetry',
	array(
		'events' => array(
			array(
				'type'        => 'custom_event',
				'payload'     => array( 'event' => 'should_never_be_stored' ),
				'occurred_at' => gmdate( 'c' ),
			),
		),
	)
);

check( 'the server refuses telemetry under a rejection (403)', 403 === $direct->status(), 'got ' . $direct->status() );
check( 'and says why', false !== stripos( (string) $direct->error_message(), 'consent' ), (string) $direct->error_message() );

// -------------------------------------------------------------------
echo "\n5. Change of mind: rejected → accepted unblocks the queue\n";

ob_start();
$notice->render_settings_section();
$settings = (string) ob_get_clean();

check( 'the settings control shows the current decision', false !== strpos( $settings, 'not sharing usage data' ) );
check( 'and offers the opposite action only', false !== strpos( $settings, 'value="accepted"' ) && false === strpos( $settings, 'value="rejected"' ) );

$applied = click( $notice, 'accepted' );

check( 'the click was applied', 'accepted' === $applied, var_export( $applied, true ) );
check( 'the consent call succeeded', 200 === $client->last_response()->status(), (string) $client->last_response()->status() );
check( 'collection resumes', true === $telemetry->track( 'after_changing_my_mind' ) );

$flush = $telemetry->flush();

check( 'and it sends again (202)', null !== $flush && 202 === $flush->status(), $flush ? (string) $flush->status() : 'null' );
check( 'accepted by the server', 1 === (int) $flush->get( 'accepted_count' ), (string) $flush->get( 'accepted_count' ) );

// -------------------------------------------------------------------
echo "\n6. The consent call itself failing must not break anything\n";

$offline_client    = make_client( 'http://127.0.0.1:9', $api_key, $product_secret );
$offline_telemetry = new Telemetry( $offline_client, $queue, $logger );
$offline_consent   = new Consent( $offline_client, $offline_telemetry, $logger );
$offline_telemetry->set_consent( $offline_consent );
$offline_consent->set_privacy_policy_version( '2026-08-06' );

$offline_redirects = array();
$offline_notice    = new ConsentNotice( $offline_consent );
$offline_notice->set_redirect_handler(
	function ( $url ) use ( &$offline_redirects ) {
		$offline_redirects[] = $url;
	}
);

$applied = click( $offline_notice, 'rejected' );

check( 'the click still applied', 'rejected' === $applied, var_export( $applied, true ) );
check( 'the consent call failed as a transport error', $offline_client->last_response()->is_transport_error() );
check( 'the decision is stored locally anyway', $offline_consent->is_rejected() );
check( 'and enforced locally anyway', false === $offline_telemetry->track( 'after_offline_refusal' ) );
check( 'flagged as not yet sent', $offline_consent->is_sync_pending() );
check( 'a retry is scheduled', appneck_test_is_scheduled( Consent::CRON_HOOK ) );
check( 'the site owner was still redirected, not shown an error', 1 === count( $offline_redirects ) );
check( 'the failure was logged', $logger->contains( 'stored locally and will retry' ) );

// The API comes back: the scheduled retry is what runs next.
$recovered = new Consent( $client, $telemetry, $logger );
$response  = $recovered->sync();

check( 'the retry delivered the decision (200)', null !== $response && 200 === $response->status(), $response ? (string) $response->status() : 'null' );
check( 'nothing outstanding now', ! $recovered->is_sync_pending() );
check( 'the retry is unscheduled', ! appneck_test_is_scheduled( Consent::CRON_HOOK ) );
check( 'a further sync makes no request', null === $recovered->sync() );

// The site is left REJECTED by that recovery, which is what the last
// decision actually was — assert the server agrees rather than assuming.
$blocked = $client->post(
	'/sdk/v1/telemetry',
	array(
		'events' => array(
			array(
				'type'        => 'heartbeat',
				'payload'     => array( 'sdk_version' => '0.1.0' ),
				'occurred_at' => gmdate( 'c' ),
			),
		),
	)
);

check( 'the server is back to refusing (403)', 403 === $blocked->status(), 'got ' . $blocked->status() );

// -------------------------------------------------------------------
echo "\n7. Cleanup — mark this installation removed and forget locally\n";

$lifecycle->on_uninstall();
$consent->forget();

check( 'the local decision is gone', $consent->is_pending() );
echo "        (uninstalled)\n";

echo "\n" . ( 0 === $failures ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n" );
echo "CONSENT_INSTALLATION_ID={$installation_id}\n";

exit( 0 === $failures ? 0 : 1 );
