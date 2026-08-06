<?php

namespace Appneck\Sdk\Tests;

use Appneck\Sdk\Admin\DeactivationSurvey;
use Appneck\Sdk\Client;
use Appneck\Sdk\Config;
use Appneck\Sdk\Http\Response;
use Appneck\Sdk\Storage\ArrayCredentialStore;
use Appneck\Sdk\Survey;
use PHPUnit\Framework\TestCase;

/**
 * The modal and the two AJAX operations behind it.
 *
 * The interception itself is JavaScript and is not executed here — what is
 * asserted is everything the script depends on (the markup it fills, the
 * plugin basename it matches, the nonce and action it posts to) and every
 * server-side decision it triggers, including the ones that must never
 * block a deactivation.
 */
class DeactivationSurveyTest extends TestCase {

	const API_KEY        = 'pk_deactivation_test';
	const PRODUCT_SECRET = 'sk_product_secret_value';
	const INSTALL_SECRET = 'sk_installation_secret_value';
	const INSTALL_ID     = '019fb200-0000-7000-8000-eeeeeeeeeeee';
	const BASE_URL       = 'https://api.example.test';

	const RADIO_ID  = '11111111-1111-7111-8111-111111111111';
	const RATING_ID = '33333333-3333-7333-8333-333333333333';
	const TEXT_ID   = '55555555-5555-7555-8555-555555555555';

	const KEY = 'abc123key';

	/** @var QueueingTransport */
	private $transport;

	/** @var Survey */
	private $survey;

	/** @var DeactivationSurvey */
	private $modal;

	protected function setUp(): void {
		require_once __DIR__ . '/wp-option-polyfill.php';
		require_once __DIR__ . '/wp-admin-polyfill.php';
		require_once __DIR__ . '/QueueingTransport.php';
		require_once __DIR__ . '/RecordingLogger.php';

		$GLOBALS['appneck_test_options'] = array();
		appneck_test_reset_admin();

		$this->transport = new QueueingTransport();

		$client = new Client(
			new Config( self::API_KEY, self::PRODUCT_SECRET, self::BASE_URL ),
			new ArrayCredentialStore( self::INSTALL_ID, self::INSTALL_SECRET ),
			$this->transport
		);

		$this->survey = new Survey( $client, new RecordingLogger() );
		$this->modal  = new DeactivationSurvey(
			$this->survey,
			self::KEY,
			null,
			array( 'product_name' => 'Acme Bookings' )
		);
		$this->modal->set_plugin_basename( 'acme-bookings/acme-bookings.php' );

		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	/** @param array<int, array<string, mixed>>|null $questions */
	private function queue_questions( ?array $questions = null ): void {
		$payload = null !== $questions ? $questions : array(
			array(
				'id'       => self::RADIO_ID,
				'position' => 1,
				'type'     => 'radio',
				'text'     => 'Why are you deactivating?',
				'options'  => array( 'choices' => array( 'Too complicated', 'Found a better plugin' ) ),
			),
			array(
				'id'       => self::RATING_ID,
				'position' => 2,
				'type'     => 'rating',
				'text'     => 'How would you rate it?',
				'options'  => array( 'max' => 5 ),
			),
			array(
				'id'       => self::TEXT_ID,
				'position' => 3,
				'type'     => 'text_area',
				'text'     => 'Anything else?',
				'options'  => null,
			),
		);

		$this->transport->queue( Response::from_http( 200, array(), json_encode( array( 'questions' => $payload ) ) ) );
	}

	private function render(): string {
		ob_start();
		$this->modal->render();

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $answers
	 * @return array<string, mixed>|null
	 */
	private function ajax( $op, array $answers = array() ) {
		$_POST = array(
			'action'  => $this->modal->action(),
			'nonce'   => 'nonce-for-' . $this->modal->action(),
			'op'      => $op,
			'answers' => json_encode( $answers ),
		);

		return $this->modal->handle_ajax();
	}

	// -----------------------------------------------------------------
	// The modal shell
	// -----------------------------------------------------------------

	public function test_it_prints_the_modal_and_its_script(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'appneck-sdk-survey-' . self::KEY, $html );
		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
		$this->assertStringContainsString( 'data-appneck-fields', $html );
		$this->assertStringContainsString( '<script>', $html );
		$this->assertStringContainsString( 'action=deactivate', $html, 'the script matches WordPress own link' );
	}

	/**
	 * The script matches the Deactivate link by this exact string, so it is
	 * the one piece of config that cannot be missing or wrong. Compared in
	 * its JSON-encoded form because that is what reaches the browser.
	 */
	public function test_the_plugin_basename_reaches_the_script(): void {
		$html = $this->render();

		$this->assertStringContainsString(
			trim( json_encode( 'acme-bookings/acme-bookings.php' ), '"' ),
			$html
		);
	}

	public function test_it_offers_submit_skip_and_cancel(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'data-appneck-submit', $html );
		$this->assertStringContainsString( 'data-appneck-skip', $html );
		$this->assertStringContainsString( 'data-appneck-cancel', $html );
		$this->assertStringContainsString( 'Skip &amp; deactivate', $html );
	}

	/**
	 * The questions must NOT be in the page: printing them would mean a
	 * signed API call on every visit to the plugins screen.
	 */
	public function test_the_questions_are_not_printed_into_the_page(): void {
		$this->queue_questions();

		$html = $this->render();

		$this->assertStringNotContainsString( 'Why are you deactivating?', $html );
		$this->assertSame( 0, $this->transport->count(), 'rendering makes no request at all' );
	}

	public function test_it_starts_hidden(): void {
		$this->assertStringContainsString( 'hidden', $this->render() );
	}

	/**
	 * Without the basename the script cannot tell which row's Deactivate
	 * link belongs to this plugin, and intercepting every plugin's link
	 * would be unforgivable.
	 */
	public function test_nothing_is_printed_without_a_plugin_basename(): void {
		$modal = new DeactivationSurvey( $this->survey, self::KEY );

		ob_start();
		$modal->render();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_nothing_is_printed_for_a_user_who_cannot_deactivate_plugins(): void {
		$GLOBALS['appneck_test_admin']['can'] = false;

		$this->assertSame( '', $this->render() );
	}

	public function test_the_ajax_action_is_namespaced_per_product(): void {
		$other = new DeactivationSurvey( $this->survey, 'a-different-key' );

		$this->assertNotSame( $this->modal->action(), $other->action() );
		$this->assertStringContainsString( self::KEY, $this->modal->action() );
	}

	public function test_the_product_name_is_escaped_in_the_prompt(): void {
		$this->modal->set_product_name( 'Acme <script>alert(1)</script>' );

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	// -----------------------------------------------------------------
	// Fetching the questions
	// -----------------------------------------------------------------

	public function test_the_questions_operation_returns_them_for_the_modal(): void {
		$this->queue_questions();

		$result = $this->ajax( 'questions' );

		$this->assertCount( 3, $result['questions'] );
		$this->assertSame( 'Why are you deactivating?', $result['questions'][0]['text'] );
	}

	/**
	 * The script's cue to get out of the way entirely — no modal, straight
	 * to deactivation.
	 */
	public function test_no_configured_survey_returns_an_empty_list(): void {
		$this->queue_questions( array() );

		$this->assertSame( array( 'questions' => array() ), $this->ajax( 'questions' ) );
	}

	public function test_an_unreachable_api_also_returns_an_empty_list(): void {
		// Nothing queued in the transport.
		$this->assertSame( array( 'questions' => array() ), $this->ajax( 'questions' ) );
	}

	// -----------------------------------------------------------------
	// Submitting
	// -----------------------------------------------------------------

	public function test_a_valid_submission_is_sent_and_reported_as_submitted(): void {
		$this->queue_questions();
		$this->transport->queue( Response::from_http( 201, array(), json_encode( array( 'id' => 'r1' ) ) ) );

		$result = $this->ajax(
			'submit',
			array(
				self::RADIO_ID  => 'Found a better plugin',
				self::RATING_ID => '4',
				self::TEXT_ID   => 'Wrong fit for us.',
			)
		);

		$this->assertTrue( $result['submitted'] );
		$this->assertSame( 201, $result['status'] );

		$body = json_decode( $this->transport->last_request()['body'], true );
		$this->assertCount( 3, $body['answers'] );
	}

	public function test_a_skipped_survey_sends_nothing(): void {
		$this->queue_questions();

		// What the script posts when every field is left alone.
		$result = $this->ajax( 'submit', array() );

		$this->assertFalse( $result['submitted'] );
		$this->assertSame( 1, $this->transport->count(), 'only the questions fetch happened' );
	}

	/**
	 * The one refusal the modal acts on instead of proceeding: nothing has
	 * been sent, and the site owner can fix it.
	 */
	public function test_locally_invalid_answers_come_back_as_field_errors(): void {
		$this->queue_questions();

		$result = $this->ajax( 'submit', array( self::RATING_ID => '99' ) );

		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( self::RATING_ID, $result['errors'] );
		$this->assertSame( 1, $this->transport->count(), 'nothing was sent' );
	}

	/**
	 * The whole point of the flow: a lost submission must still let the
	 * deactivation happen, so the handler reports a normal result rather
	 * than an error the script would stall on.
	 */
	public function test_a_failed_submission_does_not_become_a_blocking_error(): void {
		$this->queue_questions();

		// Nothing queued for the POST: the network is gone.
		$result = $this->ajax( 'submit', array( self::RADIO_ID => 'Too complicated' ) );

		$this->assertArrayNotHasKey( 'errors', $result );
		$this->assertFalse( $result['submitted'] );
		$this->assertSame( 0, $result['status'], 'a transport error, reported without drama' );
	}

	public function test_a_server_rejection_does_not_become_a_blocking_error_either(): void {
		$this->queue_questions();
		$this->transport->queue( Response::from_http( 422, array(), json_encode( array( 'message' => 'nope' ) ) ) );

		$result = $this->ajax( 'submit', array( self::RADIO_ID => 'Too complicated' ) );

		$this->assertArrayNotHasKey( 'errors', $result );
		$this->assertFalse( $result['submitted'] );
		$this->assertSame( 422, $result['status'] );
	}

	// -----------------------------------------------------------------
	// Guards
	// -----------------------------------------------------------------

	public function test_a_user_without_activate_plugins_is_refused(): void {
		$GLOBALS['appneck_test_admin']['can'] = false;

		$this->assertNull( $this->ajax( 'questions' ) );
		$this->assertNotNull( $this->modal->denied );
		$this->assertSame( 0, $this->transport->count() );
	}

	public function test_a_bad_nonce_is_refused(): void {
		$GLOBALS['appneck_test_admin']['nonce_ok'] = false;

		$this->assertNull( $this->ajax( 'questions' ) );
		$this->assertSame( 0, $this->transport->count() );
	}

	public function test_an_unknown_operation_is_refused(): void {
		$this->assertNull( $this->ajax( 'whatever' ) );
		$this->assertSame( 0, $this->transport->count() );
	}

	/**
	 * WordPress slashes $_POST, so a free-text answer containing a quote
	 * arrives escaped — and the JSON would fail to decode if that were not
	 * undone.
	 */
	public function test_a_slashed_answer_survives_wordpress_own_escaping(): void {
		$this->queue_questions();
		$this->transport->queue( Response::from_http( 201, array(), '{"id":"r1"}' ) );

		$comment = 'It\'s "not" for me';

		$_POST = array(
			'action'  => $this->modal->action(),
			'nonce'   => 'nonce-for-' . $this->modal->action(),
			'op'      => 'submit',
			'answers' => addslashes( json_encode( array( self::TEXT_ID => $comment ) ) ),
		);

		$result = $this->modal->handle_ajax();

		$this->assertTrue( $result['submitted'] );

		$body = json_decode( $this->transport->last_request()['body'], true );
		$this->assertSame( $comment, $body['answers'][0]['value'] );
	}

	public function test_a_malformed_answers_payload_is_treated_as_a_skip(): void {
		$this->queue_questions();

		$_POST = array(
			'action'  => $this->modal->action(),
			'nonce'   => 'nonce-for-' . $this->modal->action(),
			'op'      => 'submit',
			'answers' => 'not json at all',
		);

		$result = $this->modal->handle_ajax();

		$this->assertFalse( $result['submitted'] );
		$this->assertSame( 1, $this->transport->count() );
	}

	public function test_an_answer_for_an_unknown_question_is_dropped_not_sent(): void {
		$this->queue_questions();
		$this->transport->queue( Response::from_http( 201, array(), '{"id":"r1"}' ) );

		$this->ajax(
			'submit',
			array(
				self::RADIO_ID              => 'Too complicated',
				'99999999-9999-7999-8999-999999999999' => 'smuggled',
			)
		);

		$body = json_decode( $this->transport->last_request()['body'], true );

		$this->assertCount( 1, $body['answers'] );
		$this->assertSame( self::RADIO_ID, $body['answers'][0]['question_id'] );
	}
}
