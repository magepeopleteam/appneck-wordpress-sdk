<?php

namespace Appneck\Sdk\Admin;

use Appneck\Sdk\Announcements;

/**
 * Announcements as the site owner sees them: admin notices on the
 * plugin's OWN screen.
 *
 * ## Not a global admin notice
 *
 * Nothing here hooks `admin_notices` unconditionally. A discount from one
 * plugin's vendor has no business appearing on the Media Library, the post
 * editor, or another plugin's settings page, and a site running five
 * Appneck-instrumented plugins would stack five vendors' messages on every
 * screen. So the host plugin says where:
 *
 *     // inside the plugin's own settings page callback
 *     $sdk->announcement_notices()->render();
 *
 *     // or, hooked once, printed only on that screen
 *     $sdk->announcement_notices()->render_on_screen( 'settings_page_acme' );
 *
 * ## All of them, urgent first — not one at a time
 *
 * Every undismissed announcement is rendered, ordered by type urgency and
 * then by the server's own recency (see Announcements::visible()). One at a
 * time with pagination was rejected: it needs state, nonces and a
 * "next" control for a list that is realistically zero to two items, and
 * it can queue a Security Notice behind a discount — the one outcome worth
 * actively avoiding. Stacking on a page the owner deliberately opened is
 * what WordPress itself does with notices.
 *
 * MAX_VISIBLE caps how many print at once so an organization publishing
 * ten cannot bury the plugin's actual settings under a wall of boxes; the
 * rest surface as earlier ones are dismissed.
 *
 * ## Dismissal is local, because there is nothing to tell the server
 *
 * journal §9.3b's endpoint is display-only — no read receipts, no seen
 * state, nothing to POST. So a dismissal is a row in this site's own
 * options, and it deliberately outlives cache refreshes: the cached list
 * is replaced wholesale every tick, and an announcement still inside its
 * validity window would otherwise come back every time.
 */
final class AnnouncementNotices {

	const ACTION_PREFIX = 'appneck_sdk_dismiss_announcement_';

	const FIELD = 'appneck_sdk_announcement_id';

	/** How many notices print at once. */
	const MAX_VISIBLE = 3;

	/**
	 * type => WordPress's own notice class. Core's four levels already
	 * carry the right connotations, so there is no custom palette here —
	 * `security` reads as an error because that is the one a site owner
	 * must not skim past, and `discount` reads as good news.
	 */
	const NOTICE_CLASSES = array(
		'security' => 'notice-error',
		'update'   => 'notice-warning',
		'feature'  => 'notice-info',
		'discount' => 'notice-success',
	);

	/** @var Announcements */
	private $announcements;

	/** @var string */
	private $key;

	/** @var string|null */
	private $screen_id = null;

	/** @var callable|null */
	private $redirect_handler = null;

	/**
	 * The last refusal, recorded only when WordPress's wp_die() is
	 * unavailable (this package's own tests) so it stays observable.
	 *
	 * @var string|null
	 */
	public $denied = null;

	public function __construct( Announcements $announcements, $key ) {
		$this->announcements = $announcements;
		$this->key           = (string) $key;
	}

	public function action() {
		return self::ACTION_PREFIX . $this->key;
	}

	/**
	 * Registers only the dismissal handler. Rendering is opt-in by screen —
	 * see the class doc for why this does not hook `admin_notices` itself.
	 */
	public function register_hooks() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_' . $this->action(), array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Print the notices on one specific admin screen.
	 *
	 * @param string $screen_id e.g. 'settings_page_acme', or
	 *                          'toplevel_page_acme'. get_current_screen()->id.
	 */
	public function render_on_screen( $screen_id ) {
		$this->screen_id = (string) $screen_id;

		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_notices', array( $this, 'render_if_on_screen' ) );
		}

		return $this;
	}

	/** For tests and hosts that render their own confirmation. */
	public function set_redirect_handler( ?callable $handler ) {
		$this->redirect_handler = $handler;

		return $this;
	}

	// -----------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------

	/** The `admin_notices` callback installed by render_on_screen(). */
	public function render_if_on_screen() {
		if ( null === $this->screen_id || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! is_object( $screen ) || ! isset( $screen->id ) || $screen->id !== $this->screen_id ) {
			return;
		}

		$this->render();
	}

	/**
	 * Prints the notices. Safe to call with nothing to show — it prints
	 * absolutely nothing rather than an empty container, so a plugin can
	 * call it unconditionally without leaving a stray box on the page.
	 */
	public function render() {
		if ( ! $this->can_render() ) {
			return;
		}

		// The WP-Cron fallback, and the only place it runs: the owner is
		// already on the plugin's own screen, so at most one API call an
		// hour here is acceptable in a way it would not be anywhere else.
		$this->announcements->maybe_refresh();

		$visible = $this->announcements->visible();

		if ( array() === $visible ) {
			return;
		}

		$printed = 0;

		foreach ( $visible as $announcement ) {
			if ( $printed >= self::MAX_VISIBLE ) {
				break;
			}

			$this->render_one( $announcement );
			++$printed;
		}
	}

	/** @param array<string, mixed> $announcement */
	private function render_one( array $announcement ) {
		$class = isset( self::NOTICE_CLASSES[ $announcement['type'] ] )
			? self::NOTICE_CLASSES[ $announcement['type'] ]
			// An unknown type from a newer server. Neutral rather than
			// guessed at — and never the urgent one.
			: 'notice-info';

		echo '<div class="notice ' . esc_attr( $class ) . '">';
		echo '<p><strong>' . esc_html( $announcement['title'] ) . '</strong></p>';

		if ( '' !== $announcement['body'] ) {
			// esc_html FIRST, then nl2br on the escaped string, so line
			// breaks survive without any tag from the server surviving with
			// them. journal §12.2 makes this content display-only; letting
			// remote HTML into wp-admin would quietly undo that.
			echo '<p>' . nl2br( esc_html( $announcement['body'] ) ) . '</p>';
		}

		echo '<p>';
		echo '<form method="post" action="' . esc_url( $this->post_url() ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $this->action() ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="' . esc_attr( $announcement['id'] ) . '" />';

		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( $this->action() );
		}

		// A plain submit rather than core's dismissible X: the X is added
		// by core's own JS to `.notice.is-dismissible` and only hides the
		// box for that page view, which is the opposite of what a stored
		// dismissal means. Two dismiss controls where one is a lie is
		// worse than one that is honest.
		echo '<button type="submit" class="button-link">' . esc_html( 'Dismiss' ) . '</button>';
		echo '</form>';
		echo '</p>';

		echo '</div>';
	}

	// -----------------------------------------------------------------
	// Dismissal
	// -----------------------------------------------------------------

	/**
	 * The `admin_post_<action>` callback.
	 *
	 * @return string|null The dismissed id, or null when refused.
	 */
	public function handle_dismiss() {
		if ( ! $this->current_user_can_dismiss() ) {
			$this->deny( 'You are not allowed to do that.' );

			return null;
		}

		if ( function_exists( 'check_admin_referer' ) && false === check_admin_referer( $this->action() ) ) {
			$this->deny( 'That page has expired. Please reload and try again.' );

			return null;
		}

		$id = isset( $_POST[ self::FIELD ] ) ? $this->sanitize_id( $_POST[ self::FIELD ] ) : '';

		if ( '' === $id || ! $this->announcements->dismiss( $id ) ) {
			// An unknown id is not worth a wp_die() — the likeliest cause is
			// a stale page whose announcement has since expired, and the
			// owner's click did what they wanted either way: it is gone.
			$this->denied = 'unknown announcement';
			$this->redirect_back();

			return null;
		}

		$this->redirect_back();

		return $id;
	}

	private function redirect_back() {
		$url = null;

		if ( function_exists( 'wp_get_referer' ) ) {
			$referer = wp_get_referer();
			$url     = is_string( $referer ) && '' !== $referer ? $referer : null;
		}

		if ( null === $url ) {
			$url = function_exists( 'admin_url' ) ? admin_url() : '/wp-admin/';
		}

		if ( null !== $this->redirect_handler ) {
			call_user_func( $this->redirect_handler, $url );

			return;
		}

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $url );
		}

		exit;
	}

	/** @param string $message */
	private function deny( $message ) {
		if ( function_exists( 'wp_die' ) ) {
			wp_die( esc_html( $message ), '', array( 'response' => 403 ) );

			return;
		}

		$this->denied = (string) $message;
	}

	// -----------------------------------------------------------------
	// Environment
	// -----------------------------------------------------------------

	/**
	 * `manage_options` — the capability that gates a plugin's settings
	 * screen, which is the only place these print. Dismissal is a stored
	 * decision for the whole site, so it belongs to whoever administers it.
	 */
	private function current_user_can_dismiss() {
		if ( ! function_exists( 'current_user_can' ) ) {
			return true;
		}

		return (bool) current_user_can( 'manage_options' );
	}

	private function can_render() {
		if ( ! function_exists( 'esc_html' ) || ! function_exists( 'esc_attr' ) || ! function_exists( 'esc_url' ) ) {
			return false;
		}

		return $this->current_user_can_dismiss();
	}

	private function post_url() {
		return function_exists( 'admin_url' ) ? admin_url( 'admin-post.php' ) : '/wp-admin/admin-post.php';
	}

	/** @param mixed $value */
	private function sanitize_id( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		// The ids are uuids. Anything else cannot match a cached
		// announcement anyway, and dismiss() rejects unknown ids — this
		// just keeps junk out of the comparison entirely.
		return preg_replace( '/[^a-zA-Z0-9\-]/', '', $value );
	}
}
