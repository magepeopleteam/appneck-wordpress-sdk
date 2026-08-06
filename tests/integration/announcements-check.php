<?php
/**
 * Announcements against the REAL backend: a real signed fetch of what a
 * real organization authored, rendered as real admin notices, dismissed
 * through the real admin-post handler.
 *
 * Author the announcements in the Org Panel first (this script does not
 * create them — the authoring flow is S2's and is exercised as-is). The
 * expected shape for a full run is:
 *
 *   - one PUBLISHED `security` with no window
 *   - one PUBLISHED `discount` inside its window
 *   - one PUBLISHED but EXPIRED
 *   - one DRAFT
 *   - one PUBLISHED but not yet STARTED
 *
 * The last three must never reach this client, and the point of including
 * them is that the client is proven not to surface something the server
 * already excluded.
 *
 * WordPress's wp_die/wp_send_json_* helpers are deliberately NOT
 * polyfilled, so a refusal returns instead of exiting.
 *
 * Usage:
 *   php announcements-check.php <base_url> <api_key> <product_secret> \
 *       [<other_api_key> <other_product_secret>] [<site_domain>]
 *
 * The second key is a product with NO announcements — the zero case.
 */

require __DIR__ . '/wp-http-polyfill.php';
require dirname( __DIR__ ) . '/wp-option-polyfill.php';
require dirname( __DIR__ ) . '/wp-cron-polyfill.php';
require dirname( __DIR__ ) . '/wp-hook-polyfill.php';
require dirname( __DIR__ ) . '/wp-admin-polyfill.php';
require dirname( __DIR__, 2 ) . '/appneck-sdk.php';

// The hook polyfill above means add_action() exists, so the loader defers
// to `plugins_loaded` — which nothing fires in this harness. Calling the
// loader directly is the documented, idempotent way to load early; this
// script needs the hook system precisely so it can prove no second cron
// schedule is created.
appneck_sdk_load_latest();

// After the SDK, so the Logger interface it implements exists.
require dirname( __DIR__ ) . '/RecordingLogger.php';

use Appneck\Sdk\Admin\AnnouncementNotices;
use Appneck\Sdk\Announcements;
use Appneck\Sdk\Client;
use Appneck\Sdk\Config;
use Appneck\Sdk\Environment;
use Appneck\Sdk\Lifecycle;
use Appneck\Sdk\Storage\WpOptionsCredentialStore;
use Appneck\Sdk\Telemetry;
use Appneck\Sdk\Tests\RecordingLogger;

$base_url       = isset( $argv[1] ) ? $argv[1] : 'http://nginx';
$api_key        = isset( $argv[2] ) ? $argv[2] : '';
$product_secret = isset( $argv[3] ) ? $argv[3] : '';
$other_key      = isset( $argv[4] ) ? $argv[4] : '';
$other_secret   = isset( $argv[5] ) ? $argv[5] : '';
$site_domain    = isset( $argv[6] ) ? $argv[6] : 's46-announcements-test.example.com';

$GLOBALS['appneck_test_options'] = array();
$GLOBALS['appneck_test_cron']    = array();
$GLOBALS['appneck_test_hooks']   = array();

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

function render( AnnouncementNotices $notices ) {
	ob_start();
	$notices->render();

	return (string) ob_get_clean();
}

/** Exactly what a Dismiss click posts to admin-post.php. */
function dismiss( AnnouncementNotices $notices, $id ) {
	$_POST = array(
		'action'                   => $notices->action(),
		AnnouncementNotices::FIELD => $id,
		'_wpnonce'                 => 'nonce-for-' . $notices->action(),
	);

	return $notices->handle_dismiss();
}

$logger        = new RecordingLogger();
$client        = make_client( $base_url, $api_key, $product_secret );
$announcements = new Announcements( $client, $logger );
$key           = substr( hash( 'sha256', $api_key ), 0, 32 );
$notices       = new AnnouncementNotices( $announcements, $key );

$redirects = array();
$notices->set_redirect_handler(
	function ( $url ) use ( &$redirects ) {
		$redirects[] = $url;
	}
);

// -------------------------------------------------------------------
echo "1. Register\n";

$lifecycle    = new Lifecycle( $client, null, new Environment( null, array( 'site_domain' => $site_domain ) ) );
$lifecycle->on_activate();
$registration = $lifecycle->ensure_registered();

check( 'registered (201)', 201 === $registration->status(), 'got ' . $registration->status() . ' — ' . $registration->error_message() );

$installation_id = ( new WpOptionsCredentialStore( $api_key ) )->get_installation_id();
echo "        installation: {$installation_id}\n";

// -------------------------------------------------------------------
echo "\n2. No second cron schedule — it rides the heartbeat tick\n";

$GLOBALS['appneck_test_cron']  = array();
$GLOBALS['appneck_test_hooks'] = array();

$announcements->register_hooks();

check( 'a listener was added to the heartbeat hook', isset( $GLOBALS['appneck_test_hooks'][ Telemetry::CRON_HOOK ] ) );
check( 'and no wp_cron event of its own was created', array() === $GLOBALS['appneck_test_cron'], json_encode( array_keys( $GLOBALS['appneck_test_cron'] ) ) );
check( 'nothing hooks admin_notices globally', ! isset( $GLOBALS['appneck_test_hooks']['admin_notices'] ) );

// -------------------------------------------------------------------
echo "\n3. A real signed fetch of what the organization published\n";

$fetched = $announcements->refresh();

check( 'the fetch succeeded', is_array( $fetched ), 'null — ' . implode( '; ', array_column( $logger->lines, 'message' ) ) );
check( 'exactly the two currently-visible announcements came back', 2 === count( $fetched ), (string) count( $fetched ) );

foreach ( $fetched as $announcement ) {
	echo '        [' . $announcement['type'] . '] ' . $announcement['title'] . "\n";
}

$titles = array_column( $fetched, 'title' );

check( 'the expired one was excluded server-side', ! in_array( 'This one already expired', $titles, true ) );
check( 'the draft was excluded server-side', ! in_array( 'Still a draft', $titles, true ) );
check( 'the not-yet-started one was excluded server-side', ! in_array( 'Scheduled for next month', $titles, true ) );
echo "        (and the client re-filters nothing — see AnnouncementsTest)\n";

$security = null;
$discount = null;

foreach ( $fetched as $announcement ) {
	if ( 'security' === $announcement['type'] ) {
		$security = $announcement;
	}

	if ( 'discount' === $announcement['type'] ) {
		$discount = $announcement;
	}
}

check( 'the security notice is present', null !== $security );
check( 'the discount is present', null !== $discount );

// -------------------------------------------------------------------
echo "\n4. Rendering on the plugin's own screen\n";

$html = render( $notices );

check( 'both notices printed', false !== strpos( $html, $security['title'] ) && false !== strpos( $html, $discount['title'] ) );
check( 'the security notice uses the urgent class', false !== strpos( $html, 'notice notice-error' ) );
check( 'the discount does not', false !== strpos( $html, 'notice notice-success' ) );
check(
	'the security notice is printed first',
	strpos( $html, $security['title'] ) < strpos( $html, $discount['title'] ),
	'urgency must beat recency'
);
check( 'the body survived with its line break', false !== strpos( $html, '<br' ) );
check( 'each carries its own dismiss form', 2 === substr_count( $html, '<form method="post"' ), (string) substr_count( $html, '<form method="post"' ) );
check( 'nonce-protected', false !== strpos( $html, 'name="_wpnonce"' ) );

$before = count( $logger->lines );

render( $notices );

check( 'a second render made no API call', $before === count( $logger->lines ) );

// -------------------------------------------------------------------
echo "\n5. Dismiss the security notice\n";

$dismissed = dismiss( $notices, $security['id'] );

check( 'the dismissal was applied', $security['id'] === $dismissed, var_export( $dismissed, true ) );
check( 'the site owner was redirected back', 1 === count( $redirects ) );

$html = render( $notices );

check( 'it is gone from the screen', false === strpos( $html, $security['title'] ) );
check( 'the discount is still there', false !== strpos( $html, $discount['title'] ) );

// -------------------------------------------------------------------
echo "\n6. Refresh again — the dismissal must survive it\n";

// The announcement is untouched server-side and still inside its validity
// window, so the server serves it again. The cache is replaced wholesale;
// the dismissal lives in its own option, which is the whole point.
$refetched = $announcements->refresh();

check( 'the server still serves it', 2 === count( $refetched ), (string) count( $refetched ) );
check( 'it is still recorded as dismissed', $announcements->is_dismissed( $security['id'] ) );

$html = render( $notices );

check( 'and it did NOT come back on screen', false === strpos( $html, $security['title'] ) );
check( 'while the discount still shows', false !== strpos( $html, $discount['title'] ) );

// -------------------------------------------------------------------
echo "\n7. A dismissal cannot be forged, and a stale click does not break\n";

$GLOBALS['appneck_test_admin']['nonce_ok'] = false;
check( 'a bad nonce is refused', null === dismiss( $notices, $discount['id'] ) );
check( 'nothing was dismissed', ! $announcements->is_dismissed( $discount['id'] ) );
$GLOBALS['appneck_test_admin']['nonce_ok'] = true;

$GLOBALS['appneck_test_admin']['can'] = false;
check( 'a user without manage_options is refused', null === dismiss( $notices, $discount['id'] ) );
check( 'still nothing dismissed', ! $announcements->is_dismissed( $discount['id'] ) );
$GLOBALS['appneck_test_admin']['can'] = true;

$redirects = array();
check( 'an unknown id redirects rather than dying', null === dismiss( $notices, 'aaaaaaaa-0000-0000-0000-000000000000' ) );
check( 'and still sends them back to their page', 1 === count( $redirects ) );

// -------------------------------------------------------------------
echo "\n8. Zero announcements renders nothing at all\n";

if ( '' !== $other_key && '' !== $other_secret ) {
	$other_client    = make_client( $base_url, $other_key, $other_secret );
	$other_lifecycle = new Lifecycle( $other_client, null, new Environment( null, array( 'site_domain' => $site_domain ) ) );
	$other_lifecycle->on_activate();
	$other_registration = $other_lifecycle->ensure_registered();

	check( 'a second product registered on the same site (201)', 201 === $other_registration->status(), 'got ' . $other_registration->status() );

	$other_announcements = new Announcements( $other_client, $logger );
	$other_notices       = new AnnouncementNotices( $other_announcements, substr( hash( 'sha256', $other_key ), 0, 32 ) );

	$other_fetched = $other_announcements->refresh();

	check( 'it sees no announcements — not the first product\'s', array() === $other_fetched, json_encode( $other_fetched ) );
	check( 'and prints absolutely nothing', '' === render( $other_notices ), 'an empty container would be worse than nothing' );

	$other_lifecycle->on_uninstall();
	$other_announcements->forget();
} else {
	echo "  SKIP  no second product key passed\n";
}

// -------------------------------------------------------------------
echo "\n9. Deactivated: the endpoint is `active` tier, and the cache is kept\n";

$deactivation = $lifecycle->on_deactivate();

check( 'reported deactivated (200)', null !== $deactivation && 200 === $deactivation->status(), $deactivation ? (string) $deactivation->status() : 'null' );

$result = $announcements->refresh();

check( 'the refresh was refused', null === $result );
check( 'the server said 403', 403 === $client->last_response()->status(), (string) $client->last_response()->status() );
check( 'the cached copy was NOT blanked', 2 === count( $announcements->all() ), (string) count( $announcements->all() ) );
check( 'the failure was logged', $logger->contains( 'the cached copy is kept' ) );
echo "        (a reactivation a minute later must not have lost the security notice)\n";

// -------------------------------------------------------------------
echo "\n10. Cleanup\n";

$lifecycle->on_uninstall();
$announcements->forget();

check( 'the cache and dismissals are gone', array() === $announcements->all() && array() === $announcements->dismissed() );
echo "        (uninstalled)\n";

echo "\n" . ( 0 === $failures ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n" );
echo "ANNOUNCEMENTS_INSTALLATION_ID={$installation_id}\n";

exit( 0 === $failures ? 0 : 1 );
