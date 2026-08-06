<?php

namespace Appneck\Sdk\Tests;

use Appneck\Sdk\Admin\AnnouncementNotices;
use Appneck\Sdk\Announcements;
use Appneck\Sdk\Client;
use Appneck\Sdk\Config;
use Appneck\Sdk\Http\Response;
use Appneck\Sdk\Storage\ArrayCredentialStore;
use PHPUnit\Framework\TestCase;

/**
 * What the site owner sees, and what a Dismiss click does.
 */
class AnnouncementNoticesTest extends TestCase {

	const API_KEY        = 'pk_announcement_notices_test';
	const PRODUCT_SECRET = 'sk_product_secret_value';
	const INSTALL_SECRET = 'sk_installation_secret_value';
	const INSTALL_ID     = '019fb200-0000-7000-8000-abcabcabcabc';
	const BASE_URL       = 'https://api.example.test';

	const KEY = 'noticekey123';

	const SECURITY_ID = 'aaaaaaaa-1111-7111-8111-111111111111';
	const FEATURE_ID  = 'bbbbbbbb-2222-7222-8222-222222222222';
	const DISCOUNT_ID = 'cccccccc-3333-7333-8333-333333333333';
	const UPDATE_ID   = 'dddddddd-4444-7444-8444-444444444444';

	/** @var QueueingTransport */
	private $transport;

	/** @var Announcements */
	private $announcements;

	/** @var AnnouncementNotices */
	private $notices;

	/** @var array<int, string> */
	private $redirects = array();

	protected function setUp(): void {
		require_once __DIR__ . '/wp-option-polyfill.php';
		require_once __DIR__ . '/wp-admin-polyfill.php';
		require_once __DIR__ . '/QueueingTransport.php';
		require_once __DIR__ . '/RecordingLogger.php';

		$GLOBALS['appneck_test_options'] = array();
		appneck_test_reset_admin();

		$this->transport = new QueueingTransport();
		$this->redirects = array();

		$client = new Client(
			new Config( self::API_KEY, self::PRODUCT_SECRET, self::BASE_URL ),
			new ArrayCredentialStore( self::INSTALL_ID, self::INSTALL_SECRET ),
			$this->transport
		);

		$this->announcements = new Announcements( $client, new RecordingLogger() );
		$this->notices       = new AnnouncementNotices( $this->announcements, self::KEY );
		$this->notices->set_redirect_handler(
			function ( $url ) {
				$this->redirects[] = $url;
			}
		);

		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	/** @param array<int, array<string, mixed>>|null $announcements */
	private function seed( ?array $announcements = null ): void {
		$payload = null !== $announcements ? $announcements : array(
			array(
				'id'    => self::SECURITY_ID,
				'type'  => 'security',
				'title' => 'Security release 2.4.1',
				'body'  => "Please update.\nDetails in the changelog.",
			),
			array(
				'id'    => self::FEATURE_ID,
				'type'  => 'feature',
				'title' => 'Bulk export is here',
				'body'  => '',
			),
		);

		$this->transport->queue(
			Response::from_http( 200, array(), json_encode( array( 'announcements' => $payload ) ) )
		);

		$this->announcements->refresh();
	}

	private function render(): string {
		ob_start();
		$this->notices->render();

		return (string) ob_get_clean();
	}

	/** @param string $id */
	private function dismiss( $id ) {
		$_POST = array(
			'action'                    => $this->notices->action(),
			AnnouncementNotices::FIELD  => $id,
			'_wpnonce'                  => 'nonce-for-' . $this->notices->action(),
		);

		return $this->notices->handle_dismiss();
	}

	// -----------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------

	public function test_it_renders_each_announcement_as_a_wordpress_notice(): void {
		$this->seed();

		$html = $this->render();

		$this->assertStringContainsString( 'Security release 2.4.1', $html );
		$this->assertStringContainsString( 'Bulk export is here', $html );
		$this->assertStringContainsString( 'class="notice notice-error"', $html );
		$this->assertStringContainsString( 'class="notice notice-info"', $html );
	}

	public function test_the_type_decides_the_notice_class(): void {
		$this->seed(
			array(
				array( 'id' => self::SECURITY_ID, 'type' => 'security', 'title' => 'S', 'body' => '' ),
				array( 'id' => self::UPDATE_ID, 'type' => 'update', 'title' => 'U', 'body' => '' ),
				array( 'id' => self::FEATURE_ID, 'type' => 'feature', 'title' => 'F', 'body' => '' ),
				array( 'id' => self::DISCOUNT_ID, 'type' => 'discount', 'title' => 'D', 'body' => '' ),
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'notice-info', $html );
		// Four announcements, but only MAX_VISIBLE print.
		$this->assertSame( AnnouncementNotices::MAX_VISIBLE, substr_count( $html, 'class="notice ' ) );
	}

	public function test_an_unknown_type_renders_neutrally_never_as_urgent(): void {
		$this->seed(
			array( array( 'id' => self::UPDATE_ID, 'type' => 'from_the_future', 'title' => 'X', 'body' => '' ) )
		);

		$html = $this->render();

		$this->assertStringContainsString( 'notice-info', $html );
		$this->assertStringNotContainsString( 'notice-error', $html );
	}

	public function test_the_most_urgent_announcement_prints_first(): void {
		$this->seed(
			array(
				array( 'id' => self::DISCOUNT_ID, 'type' => 'discount', 'title' => 'A discount', 'body' => '' ),
				array( 'id' => self::SECURITY_ID, 'type' => 'security', 'title' => 'A security notice', 'body' => '' ),
			)
		);

		$html = $this->render();

		$this->assertLessThan(
			strpos( $html, 'A discount' ),
			strpos( $html, 'A security notice' ),
			'a security notice must never be queued behind a discount'
		);
	}

	/**
	 * The zero case: nothing at all, not an empty box. A plugin calls this
	 * unconditionally from its settings page.
	 */
	public function test_nothing_is_printed_when_there_is_nothing_to_show(): void {
		$this->seed( array() );

		$this->assertSame( '', $this->render() );
	}

	public function test_nothing_is_printed_before_the_first_fetch(): void {
		// No refresh at all, and the transport has nothing queued — the
		// fallback attempt fails and must leave the page untouched.
		$this->assertSame( '', $this->render() );
	}

	public function test_nothing_is_printed_for_a_user_who_cannot_administer_the_site(): void {
		$this->seed();

		$GLOBALS['appneck_test_admin']['can'] = false;

		$this->assertSame( '', $this->render() );
	}

	/**
	 * The body is display-only per journal 12.2. Remote HTML must not reach
	 * wp-admin, but real line breaks should survive.
	 */
	public function test_the_body_is_escaped_but_keeps_its_line_breaks(): void {
		$this->seed(
			array(
				array(
					'id'    => self::SECURITY_ID,
					'type'  => 'security',
					'title' => 'Careful',
					'body'  => "Line one\nLine two <script>alert(1)</script>",
				),
			)
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '<br', $html );
	}

	public function test_the_title_is_escaped(): void {
		$this->seed(
			array( array( 'id' => self::SECURITY_ID, 'type' => 'security', 'title' => '<img src=x onerror=1>', 'body' => '' ) )
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;img', $html );
	}

	/**
	 * Core's own X is added by core JS to `.notice.is-dismissible` and only
	 * hides the box for that page view — the opposite of a stored
	 * dismissal. One honest control beats two where one lies.
	 */
	public function test_it_does_not_use_cores_view_only_dismiss_button(): void {
		$this->seed();

		$html = $this->render();

		$this->assertStringNotContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'Dismiss', $html );
	}

	public function test_the_dismiss_control_posts_with_a_nonce(): void {
		$this->seed();

		$html = $this->render();

		$this->assertStringContainsString( '<form method="post"', $html );
		$this->assertStringContainsString( 'admin-post.php', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'value="' . self::SECURITY_ID . '"', $html );
	}

	public function test_the_action_is_namespaced_per_product(): void {
		$other = new AnnouncementNotices( $this->announcements, 'another-key' );

		$this->assertNotSame( $this->notices->action(), $other->action() );
	}

	/**
	 * Nothing may hook admin_notices globally: another plugin's screen is
	 * no place for this product's discount.
	 */
	public function test_registering_hooks_does_not_print_anywhere_by_itself(): void {
		require_once __DIR__ . '/wp-hook-polyfill.php';

		$GLOBALS['appneck_test_hooks'] = array();

		$this->notices->register_hooks();

		$this->assertArrayNotHasKey( 'admin_notices', $GLOBALS['appneck_test_hooks'] );
		$this->assertArrayHasKey( 'admin_post_' . $this->notices->action(), $GLOBALS['appneck_test_hooks'] );
	}

	public function test_render_on_screen_is_what_opts_in(): void {
		require_once __DIR__ . '/wp-hook-polyfill.php';

		$GLOBALS['appneck_test_hooks'] = array();

		$this->notices->render_on_screen( 'settings_page_acme' );

		$this->assertArrayHasKey( 'admin_notices', $GLOBALS['appneck_test_hooks'] );
	}

	// -----------------------------------------------------------------
	// Dismissal
	// -----------------------------------------------------------------

	public function test_dismissing_hides_it_and_redirects_back(): void {
		$this->seed();

		$this->assertSame( self::SECURITY_ID, $this->dismiss( self::SECURITY_ID ) );
		$this->assertTrue( $this->announcements->is_dismissed( self::SECURITY_ID ) );
		$this->assertSame( array( 'https://example.test/wp-admin/options-general.php' ), $this->redirects );

		$html = $this->render();

		$this->assertStringNotContainsString( 'Security release 2.4.1', $html );
		$this->assertStringContainsString( 'Bulk export is here', $html );
	}

	/**
	 * The behaviour this whole feature turns on.
	 */
	public function test_a_dismissed_announcement_does_not_come_back_after_a_refresh(): void {
		$this->seed();
		$this->dismiss( self::SECURITY_ID );

		// The server still serves it — nothing expired.
		$this->seed();

		$this->assertStringNotContainsString( 'Security release 2.4.1', $this->render() );
	}

	public function test_dismissing_the_last_one_leaves_an_empty_page_not_an_empty_box(): void {
		$this->seed();

		$this->dismiss( self::SECURITY_ID );
		$this->dismiss( self::FEATURE_ID );

		$this->assertSame( '', $this->render() );
	}

	public function test_a_user_without_the_capability_cannot_dismiss(): void {
		$this->seed();

		$GLOBALS['appneck_test_admin']['can'] = false;

		$this->assertNull( $this->dismiss( self::SECURITY_ID ) );
		$this->assertFalse( $this->announcements->is_dismissed( self::SECURITY_ID ) );
		$this->assertNotNull( $this->notices->denied );
	}

	public function test_a_bad_nonce_is_refused(): void {
		$this->seed();

		$GLOBALS['appneck_test_admin']['nonce_ok'] = false;

		$this->assertNull( $this->dismiss( self::SECURITY_ID ) );
		$this->assertFalse( $this->announcements->is_dismissed( self::SECURITY_ID ) );
	}

	/**
	 * The likeliest cause is a stale page whose announcement has since
	 * expired. The owner's click did what they wanted either way, so this
	 * redirects rather than dying on them.
	 */
	public function test_an_unknown_id_redirects_instead_of_dying(): void {
		$this->seed();

		$this->assertNull( $this->dismiss( 'not-a-real-id' ) );
		$this->assertCount( 1, $this->redirects );
		$this->assertSame( array(), $this->announcements->dismissed() );
	}

	public function test_a_missing_id_changes_nothing(): void {
		$this->seed();

		$_POST = array( 'action' => $this->notices->action() );

		$this->assertNull( $this->notices->handle_dismiss() );
		$this->assertSame( array(), $this->announcements->dismissed() );
	}
}
