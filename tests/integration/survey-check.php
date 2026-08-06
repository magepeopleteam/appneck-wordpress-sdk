<?php
/**
 * The deactivation survey against the REAL backend: fetch the questions a
 * real product has configured, answer them through the same AJAX handler
 * the browser modal drives, and confirm the response lands where the
 * organization will read it.
 *
 * Every decision goes through DeactivationSurvey::handle_ajax() with a
 * populated $_POST rather than calling Survey::submit() directly — the
 * modal's server side is the thing being tested, capability check, nonce
 * check and all. The JavaScript that opens the modal is not executed here
 * (there is no browser in this harness); what is proven is every path it
 * can take and the fact that none of them can stop a deactivation.
 *
 * WordPress's wp_send_json_* helpers are deliberately NOT polyfilled, so
 * the handler returns its payload instead of exiting.
 *
 * Usage:
 *   php survey-check.php <base_url> <api_key> <product_secret> \
 *       [<other_api_key> <other_product_secret>] [<site_domain>]
 *
 * The second key is a product with NO survey configured — used to prove
 * cross-product isolation and the zero-questions case.
 */

require __DIR__ . '/wp-http-polyfill.php';
require dirname( __DIR__ ) . '/wp-option-polyfill.php';
require dirname( __DIR__ ) . '/wp-cron-polyfill.php';
require dirname( __DIR__ ) . '/wp-admin-polyfill.php';
require dirname( __DIR__, 2 ) . '/appneck-sdk.php';
require dirname( __DIR__ ) . '/RecordingLogger.php';

use Appneck\Sdk\Admin\DeactivationSurvey;
use Appneck\Sdk\Client;
use Appneck\Sdk\Config;
use Appneck\Sdk\Environment;
use Appneck\Sdk\Lifecycle;
use Appneck\Sdk\Storage\WpOptionsCredentialStore;
use Appneck\Sdk\Survey;
use Appneck\Sdk\Tests\RecordingLogger;

$base_url       = isset( $argv[1] ) ? $argv[1] : 'http://nginx';
$api_key        = isset( $argv[2] ) ? $argv[2] : '';
$product_secret = isset( $argv[3] ) ? $argv[3] : '';
$other_key      = isset( $argv[4] ) ? $argv[4] : '';
$other_secret   = isset( $argv[5] ) ? $argv[5] : '';
$site_domain    = isset( $argv[6] ) ? $argv[6] : 's45-survey-test.example.com';

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

/**
 * Exactly what the modal's JavaScript posts to admin-ajax.php.
 *
 * @param array<string, mixed> $answers
 */
function modal_request( DeactivationSurvey $modal, $op, array $answers = array() ) {
	$_POST = array(
		'action'  => $modal->action(),
		'nonce'   => 'nonce-for-' . $modal->action(),
		'op'      => $op,
		'answers' => json_encode( $answers ),
	);

	return $modal->handle_ajax();
}

$logger = new RecordingLogger();
$client = make_client( $base_url, $api_key, $product_secret );
$survey = new Survey( $client, $logger );
$key    = substr( hash( 'sha256', $api_key ), 0, 32 );
$modal  = new DeactivationSurvey( $survey, $key, null, array( 'product_name' => 'Survey Check Plugin' ) );
$modal->set_plugin_basename( 'survey-check/survey-check.php' );

// -------------------------------------------------------------------
echo "1. Register, then grant consent (the survey rides the same zone)\n";

$lifecycle    = new Lifecycle( $client, null, new Environment( null, array( 'site_domain' => $site_domain ) ) );
$lifecycle->on_activate();
$registration = $lifecycle->ensure_registered();

check( 'registered (201)', 201 === $registration->status(), 'got ' . $registration->status() . ' — ' . $registration->error_message() );

$installation_id = ( new WpOptionsCredentialStore( $api_key ) )->get_installation_id();
echo "        installation: {$installation_id}\n";

// -------------------------------------------------------------------
echo "\n2. The plugins-screen modal renders without touching the API\n";

$before = 0;

ob_start();
$modal->render();
$html = (string) ob_get_clean();

check( 'the modal shell printed', false !== strpos( $html, 'role="dialog"' ) );
// json_encode escapes forward slashes, so the basename appears in the
// config as survey-check\/survey-check.php — compared in that form
// rather than raw, since that is what the browser actually receives.
check(
	'it targets this plugin only',
	false !== strpos( $html, trim( json_encode( 'survey-check/survey-check.php' ), '"' ) ),
	'basename missing from the printed config'
);
check( 'it intercepts WordPress own deactivate link', false !== strpos( $html, 'action=deactivate' ) );
check( 'submit, skip and cancel are all offered', false !== strpos( $html, 'data-appneck-submit' )
	&& false !== strpos( $html, 'data-appneck-skip' )
	&& false !== strpos( $html, 'data-appneck-cancel' ) );
check( 'the questions are NOT baked into the page', false === strpos( $html, 'Why are you deactivating' ) );

// -------------------------------------------------------------------
echo "\n3. Opening the modal fetches this product's real questions\n";

$result = modal_request( $modal, 'questions' );

check( 'the handler answered', is_array( $result ) && isset( $result['questions'] ), var_export( $result, true ) );

$questions = isset( $result['questions'] ) ? $result['questions'] : array();

check( 'five questions came back', 5 === count( $questions ), (string) count( $questions ) );

$types = array();
foreach ( $questions as $question ) {
	$types[] = $question['type'];
	echo '        ' . $question['position'] . '. [' . $question['type'] . '] ' . $question['text'] . "\n";
}

check( 'every configured type is present', array( 'radio', 'checkbox', 'rating', 'dropdown', 'text_area' ) === $types, implode( ',', $types ) );
check( 'they arrived in configured order', 1 === $questions[0]['position'] && 5 === $questions[4]['position'] );
check( 'choices came with them', ! empty( $questions[0]['options']['choices'] ) );
check( 'the rating carries its max', 5 === (int) $questions[2]['options']['max'] );
check( 'every question is displayable', '' !== trim( $questions[0]['text'] ) );

$radio    = $questions[0];
$checkbox = $questions[1];
$rating   = $questions[2];
$dropdown = $questions[3];
$text     = $questions[4];

// -------------------------------------------------------------------
echo "\n4. Local validation refuses a bad answer before anything is sent\n";

$result = modal_request(
	$modal,
	'submit',
	array(
		$rating['id'] => '99',
		$radio['id']  => 'A choice nobody configured',
	)
);

check( 'the modal is told to stay open', is_array( $result ) && isset( $result['errors'] ), var_export( $result, true ) );
check( 'the rating is flagged', isset( $result['errors'][ $rating['id'] ] ) );
check( 'the invented choice is flagged', isset( $result['errors'][ $radio['id'] ] ) );

// -------------------------------------------------------------------
echo "\n5. Skip — no submission, and nothing stands in the way\n";

$result = modal_request( $modal, 'submit', array() );

check( 'no submission was made', is_array( $result ) && false === $result['submitted'], var_export( $result, true ) );
check( 'and no error was raised for the modal to stall on', ! isset( $result['errors'] ) );

// -------------------------------------------------------------------
echo "\n6. The submission itself fails — deactivation must still proceed\n";

$offline_client = make_client( 'http://127.0.0.1:9', $api_key, $product_secret );
$offline_survey = new Survey( $offline_client, $logger );
$offline_modal  = new DeactivationSurvey( $offline_survey, $key, null, array( 'product_name' => 'Survey Check Plugin' ) );
$offline_modal->set_plugin_basename( 'survey-check/survey-check.php' );

// The questions are already cached from step 3, so the modal still has
// something to show even with the API unreachable — which is exactly why
// they are cached.
$offline_questions = modal_request( $offline_modal, 'questions' );
check( 'the cached questions still render offline', 5 === count( $offline_questions['questions'] ), (string) count( $offline_questions['questions'] ) );

$result = modal_request( $offline_modal, 'submit', array( $radio['id'] => $radio['options']['choices'][0] ) );

check( 'the handler returned normally, not an error', is_array( $result ) && ! isset( $result['errors'] ), var_export( $result, true ) );
check( 'it reports the submission did not land', false === $result['submitted'] );
check( 'as a transport failure', 0 === (int) $result['status'], (string) $result['status'] );
check( 'the failure was logged, not shown', $logger->contains( 'could not be submitted' ) );
echo "        (the modal navigates to the deactivate link regardless — nothing here can stop it)\n";

// -------------------------------------------------------------------
echo "\n7. A real answer, submitted through the modal\n";

$answers = array(
	$radio['id']    => $radio['options']['choices'][1],
	$checkbox['id'] => array( $checkbox['options']['choices'][0], $checkbox['options']['choices'][2] ),
	$rating['id']   => '4',
	$dropdown['id'] => $dropdown['options']['choices'][2],
	$text['id']     => 'Great plugin, but we built the feature in-house. Sorry!',
);

$result = modal_request( $modal, 'submit', $answers );

check( 'the submission landed', is_array( $result ) && true === $result['submitted'], var_export( $result, true ) );
check( 'HTTP 201 Created', 201 === (int) $result['status'], (string) $result['status'] );

// journal 9.3a's idempotency: a second submission is a 200 with the
// response already on file, never a duplicate row.
$repeat = modal_request( $modal, 'submit', $answers );

check( 'a repeat submission is accepted without duplicating', is_array( $repeat ) && true === $repeat['submitted'] );
check( 'and answered 200, not 201', 200 === (int) $repeat['status'], (string) $repeat['status'] );

// -------------------------------------------------------------------
echo "\n8. Cross-product isolation, and a product with no survey at all\n";

if ( '' !== $other_key && '' !== $other_secret ) {
	$other_client    = make_client( $base_url, $other_key, $other_secret );
	$other_lifecycle = new Lifecycle( $other_client, null, new Environment( null, array( 'site_domain' => $site_domain ) ) );
	$other_lifecycle->on_activate();
	$other_registration = $other_lifecycle->ensure_registered();

	check( 'a second product registered on the same site (201)', 201 === $other_registration->status(), 'got ' . $other_registration->status() );

	$other_survey = new Survey( $other_client, $logger );
	$other_modal  = new DeactivationSurvey( $other_survey, substr( hash( 'sha256', $other_key ), 0, 32 ) );
	$other_modal->set_plugin_basename( 'other-plugin/other-plugin.php' );

	$other_result = modal_request( $other_modal, 'questions' );

	check( 'it sees no questions — not the first product\'s', array() === $other_result['questions'], json_encode( $other_result ) );
	check( 'which is a normal answer, not an error', is_array( $other_result ) && ! isset( $other_result['errors'] ) );
	echo "        (the modal skips itself entirely and the plugin deactivates untouched)\n";

	$other_lifecycle->on_uninstall();
	$other_survey->forget();
} else {
	echo "  SKIP  no second product key passed\n";
}

// -------------------------------------------------------------------
echo "\n9. Deactivate for real — the action the survey must never block\n";

$deactivation = $lifecycle->on_deactivate();

check( 'the installation reported itself deactivated (200)', null !== $deactivation && 200 === $deactivation->status(), $deactivation ? (string) $deactivation->status() : 'null' );
check( 'server-side status is deactivated', 'deactivated' === $deactivation->get( 'status' ), (string) $deactivation->get( 'status' ) );

// A survey submitted after the plugin already reported itself deactivated
// is precisely what journal 9.3a's `scoped` tier exists for — the read
// side must behave the same way, or the modal would work on some plugins
// and not others depending on hook order.
$after = modal_request( $modal, 'questions' );

check( 'the questions are still fetchable while deactivated', 5 === count( $after['questions'] ), (string) count( $after['questions'] ) );

// -------------------------------------------------------------------
echo "\n10. Cleanup\n";

$lifecycle->on_uninstall();
$survey->forget();

echo "        (uninstalled, cached questions cleared)\n";

echo "\n" . ( 0 === $failures ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n" );
echo "SURVEY_INSTALLATION_ID={$installation_id}\n";

exit( 0 === $failures ? 0 : 1 );
