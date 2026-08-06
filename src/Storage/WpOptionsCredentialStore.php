<?php

namespace Appneck\Sdk\Storage;

/**
 * The production store: one wp_options row per product, holding the id
 * and secret together.
 *
 * Both halves live in ONE option, as an array, rather than two options.
 * That is the point: a pair written in two separate writes can be half
 * restored from a backup or half deleted, leaving an installation id
 * with no secret — an unauthenticatable, unrecoverable state, since the
 * secret is issued once and never re-disclosed.
 *
 * autoload is 'no': these are read only when the SDK actually talks to
 * the API, which is not on every page load, and adding to the autoloaded
 * option blob is a cost paid by every request on the host's site.
 *
 * The option name is namespaced by the product's API key so two plugins
 * from the same vendor on one site keep separate installations. The key
 * is hashed into the name rather than embedded raw: option names are not
 * secret, they surface in exports and debug tooling, and the API key —
 * while not a credential on its own (journal §9.2a) — still does not
 * belong scattered through wp_options.
 */
final class WpOptionsCredentialStore implements CredentialStore {

	const OPTION_PREFIX = 'appneck_sdk_credentials_';

	/** @var string */
	private $option_name;

	public function __construct( $api_key ) {
		$this->option_name = self::OPTION_PREFIX . substr( hash( 'sha256', (string) $api_key ), 0, 32 );
	}

	public function option_name() {
		return $this->option_name;
	}

	public function get_installation_id() {
		$stored = $this->read();

		return isset( $stored['installation_id'] ) ? $stored['installation_id'] : null;
	}

	public function get_installation_secret() {
		$stored = $this->read();

		return isset( $stored['installation_secret'] ) ? $stored['installation_secret'] : null;
	}

	public function save( $installation_id, $installation_secret ) {
		$value = array(
			'installation_id'     => (string) $installation_id,
			'installation_secret' => (string) $installation_secret,
		);

		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		// update_option returns false when the value is unchanged, which
		// is not a failure — re-saving identical credentials is a no-op,
		// not something the caller should treat as "storage broke".
		$result = update_option( $this->option_name, $value, false );

		return true === $result || $value === $this->read();
	}

	public function forget() {
		if ( ! function_exists( 'delete_option' ) ) {
			return false;
		}

		delete_option( $this->option_name );

		return true;
	}

	public function has_credentials() {
		$stored = $this->read();

		return ! empty( $stored['installation_id'] ) && ! empty( $stored['installation_secret'] );
	}

	/**
	 * Deliberately NOT cached on the instance.
	 *
	 * An earlier version kept a per-instance cache, and it caused a real
	 * bug: two stores for the same product can exist in one request (the
	 * Lifecycle has one, a plugin's own client another), and a store that
	 * had already read "no credentials" kept saying so for the rest of the
	 * request even after the other instance registered and saved them —
	 * which reads as "registration silently did nothing" and invites a
	 * second registration attempt.
	 *
	 * WordPress already caches options in memory for the duration of a
	 * request, so the second layer bought nothing and cost correctness.
	 *
	 * @return array<string, string>
	 */
	private function read() {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$stored = get_option( $this->option_name, array() );

		// A corrupted or hand-edited option must read as "no credentials"
		// rather than propagating a non-array into the signing path.
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return $stored;
	}
}
