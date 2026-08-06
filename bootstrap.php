<?php
/**
 * Loads the SDK's classes. Reached only through appneck-sdk.php's
 * version registry, which guarantees exactly one copy of the SDK gets
 * here per process — see that file for why.
 *
 * Every require is still guarded by class_exists as belt-and-braces. The
 * loader should make that impossible, but a plugin author who requires
 * this file directly (or an SDK copy old enough to predate the registry)
 * would otherwise fatal the site, and "the guard was redundant" is a much
 * better outcome than "the site went down".
 *
 * Plain requires rather than a Composer autoloader: a WordPress plugin
 * may bundle this directory wholesale with no vendor/ anywhere, and the
 * SDK cannot assume one exists. See composer.json — Composer users get
 * PSR-4 autoloading of the same classes; this file is what makes the
 * no-Composer path work identically.
 *
 * @package Appneck\Sdk
 */

$appneck_sdk_src = __DIR__ . '/src';

$appneck_sdk_classes = array(
	// Interfaces and value objects first — no load-order surprises, since
	// nothing here executes at include time, but reading top-to-bottom
	// should follow the dependency direction.
	'Appneck\\Sdk\\Logging\\Logger'                 => $appneck_sdk_src . '/Logging/Logger.php',
	'Appneck\\Sdk\\Logging\\NullLogger'             => $appneck_sdk_src . '/Logging/NullLogger.php',
	'Appneck\\Sdk\\Logging\\ErrorLogLogger'         => $appneck_sdk_src . '/Logging/ErrorLogLogger.php',
	'Appneck\\Sdk\\Storage\\CredentialStore'        => $appneck_sdk_src . '/Storage/CredentialStore.php',
	'Appneck\\Sdk\\Storage\\WpOptionsCredentialStore' => $appneck_sdk_src . '/Storage/WpOptionsCredentialStore.php',
	'Appneck\\Sdk\\Storage\\ArrayCredentialStore'   => $appneck_sdk_src . '/Storage/ArrayCredentialStore.php',
	'Appneck\\Sdk\\Queue\\EventQueue'                 => $appneck_sdk_src . '/Queue/EventQueue.php',
	'Appneck\\Sdk\\Queue\\TableEventQueue'            => $appneck_sdk_src . '/Queue/TableEventQueue.php',
	'Appneck\\Sdk\\Queue\\ArrayEventQueue'            => $appneck_sdk_src . '/Queue/ArrayEventQueue.php',
	'Appneck\\Sdk\\Http\\RateLimit'                 => $appneck_sdk_src . '/Http/RateLimit.php',
	'Appneck\\Sdk\\Http\\Response'                  => $appneck_sdk_src . '/Http/Response.php',
	'Appneck\\Sdk\\Http\\Transport'                 => $appneck_sdk_src . '/Http/Transport.php',
	'Appneck\\Sdk\\Http\\WpHttpTransport'           => $appneck_sdk_src . '/Http/WpHttpTransport.php',
	'Appneck\\Sdk\\Signer'                          => $appneck_sdk_src . '/Signer.php',
	'Appneck\\Sdk\\Config'                          => $appneck_sdk_src . '/Config.php',
	'Appneck\\Sdk\\Environment'                     => $appneck_sdk_src . '/Environment.php',
	'Appneck\\Sdk\\Client'                          => $appneck_sdk_src . '/Client.php',
	'Appneck\\Sdk\\Telemetry'                       => $appneck_sdk_src . '/Telemetry.php',
	'Appneck\\Sdk\\Consent'                         => $appneck_sdk_src . '/Consent.php',
	'Appneck\\Sdk\\Admin\\ConsentNotice'            => $appneck_sdk_src . '/Admin/ConsentNotice.php',
	'Appneck\\Sdk\\Survey'                          => $appneck_sdk_src . '/Survey.php',
	'Appneck\\Sdk\\Admin\\DeactivationSurvey'       => $appneck_sdk_src . '/Admin/DeactivationSurvey.php',
	'Appneck\\Sdk\\Announcements'                   => $appneck_sdk_src . '/Announcements.php',
	'Appneck\\Sdk\\Admin\\AnnouncementNotices'      => $appneck_sdk_src . '/Admin/AnnouncementNotices.php',
	'Appneck\\Sdk\\Lifecycle'                       => $appneck_sdk_src . '/Lifecycle.php',
	'Appneck\\Sdk\\Plugin'                          => $appneck_sdk_src . '/Plugin.php',
	'Appneck\\Sdk\\Sdk'                             => $appneck_sdk_src . '/Sdk.php',
);

foreach ( $appneck_sdk_classes as $appneck_sdk_class => $appneck_sdk_file ) {
	if ( ! class_exists( $appneck_sdk_class, false ) && ! interface_exists( $appneck_sdk_class, false ) ) {
		require_once $appneck_sdk_file;
	}
}

unset( $appneck_sdk_src, $appneck_sdk_classes, $appneck_sdk_class, $appneck_sdk_file );
