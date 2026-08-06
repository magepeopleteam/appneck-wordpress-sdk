<?php

namespace Appneck\Sdk;

use Appneck\Sdk\Admin\ConsentNotice;

/**
 * What Sdk::bootstrap() hands back: the one object a plugin author keeps
 * a reference to.
 *
 * A facade rather than making them wire Client + Lifecycle + Telemetry
 * themselves — those three exist as separate classes because they have
 * genuinely separate jobs and are separately testable, which is a reason
 * for the SDK's internals to be split, not a reason to make every plugin
 * author learn the split.
 *
 * The forwarding methods here are the entire public surface most plugins
 * will ever touch:
 *
 *     $sdk = \Appneck\Sdk\Sdk::bootstrap( 'pk_…', 'sk_…', 'https://…', __FILE__ );
 *     $sdk->track( 'booking_created', array( 'source' => 'checkout' ) );
 */
final class Plugin {

	/** @var Client */
	private $client;

	/** @var Lifecycle */
	private $lifecycle;

	/** @var Telemetry */
	private $telemetry;

	/** @var Consent|null */
	private $consent;

	/** @var ConsentNotice|null */
	private $consent_notice;

	public function __construct(
		Client $client,
		Lifecycle $lifecycle,
		Telemetry $telemetry,
		?Consent $consent = null,
		?ConsentNotice $consent_notice = null
	) {
		$this->client         = $client;
		$this->lifecycle      = $lifecycle;
		$this->telemetry      = $telemetry;
		$this->consent        = $consent;
		$this->consent_notice = $consent_notice;
	}

	/**
	 * Record an event. Local only — never an HTTP call, so this is safe
	 * to call from anywhere in a page load.
	 *
	 * @param string               $event_name
	 * @param array<string, mixed> $data
	 */
	public function track( $event_name, array $data = array() ) {
		return $this->telemetry->track( $event_name, $data );
	}

	/**
	 * @param string               $message
	 * @param array<string, mixed> $context
	 */
	public function track_error( $message, array $context = array() ) {
		return $this->telemetry->track_error( $message, $context );
	}

	/**
	 * Force a send now instead of waiting for the scheduled flush. Rarely
	 * needed; this one DOES make an HTTP call, so never call it from a
	 * page load a visitor is waiting on.
	 */
	public function flush() {
		return $this->telemetry->flush();
	}

	/** True once this site has registered and can sign requests. */
	public function is_registered() {
		return $this->client->credentials()->has_credentials();
	}

	public function client() {
		return $this->client;
	}

	public function lifecycle() {
		return $this->lifecycle;
	}

	public function telemetry() {
		return $this->telemetry;
	}

	/**
	 * The site owner's telemetry decision. Read it to gate your own
	 * optional features, or set the policy version:
	 *
	 *     if ( $sdk->consent()->is_accepted() ) { … }
	 *
	 * @return Consent|null Null only when built without the consent flow.
	 */
	public function consent() {
		return $this->consent;
	}

	/**
	 * The prompt. The notice renders itself; the one call a plugin makes by
	 * hand is the change-decision control on its own settings page:
	 *
	 *     $sdk->consent_notice()->render_settings_section();
	 *
	 * @return ConsentNotice|null
	 */
	public function consent_notice() {
		return $this->consent_notice;
	}
}
