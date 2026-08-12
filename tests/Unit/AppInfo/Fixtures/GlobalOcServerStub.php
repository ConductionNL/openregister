<?php

/**
 * Minimal, guarded global `\OC` fake for exercising the REAL
 * Application::getRegisteredAppContainer() seam.
 *
 * HISTORY: this fixture was written when bootstrap-unit-local.php did NOT load
 * tests/stubs/NextcloudInternalStubs.php and therefore left `\OC` undefined.
 * That is no longer true — the local bootstrap now requires those stubs, which
 * define `\OC` with `public static OC_FakeServer $server`. The class_exists()
 * guard below therefore never fires under the current bootstraps, and this
 * file is effectively inert. It is kept for bootstraps that still leave `\OC`
 * undefined; tests assigning `\OC::$server` MUST extend `\OC_FakeServer` so the
 * fake stays assignable under the stub's narrower property type (an anonymous
 * class with no parent raises "Cannot assign class@anonymous to property
 * OC::$server of type OC_FakeServer").
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

if (class_exists('OC', false) === false) {
	/**
	 * Global service-locator fake — only the static `$server` seam is used.
	 */
	class OC {

		/**
		 * The server container fake (assigned per test).
		 *
		 * @var object
		 */
		public static object $server;

	}//end class
}//end if
