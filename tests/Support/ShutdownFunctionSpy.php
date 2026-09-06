<?php

/**
 * Intercepts register_shutdown_function() for the two services that defer
 * work to request shutdown.
 *
 * WHY THIS EXISTS
 * ---------------
 * `ListenerDeferralService::flushAll()` and
 * `SearchQueryHandler::flushSearchTrails()` are only ever reached from a
 * shutdown callback. PHP offers no way to inspect the registered shutdown
 * functions, so the single line that makes deferred work actually run had no
 * test at all: delete it and every existing test still passes while, in
 * production, nothing is ever enqueued or persisted.
 *
 * An unqualified call to a global function resolves to the CURRENT namespace
 * first and only then falls back to the global one. Declaring
 * `register_shutdown_function()` inside the services' own namespaces
 * therefore intercepts the real call in the real production line — no seam
 * in the production class, no subclass that could stub out the very
 * statement under test.
 *
 * Loading this file makes shutdown registration a no-op for the whole test
 * process, which is also what the suites want: no test should leave a live
 * shutdown callback behind for PHPUnit to run after the assertions are over.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support {

	/**
	 * Records the callbacks the namespaced stubs below intercept.
	 */
	final class ShutdownFunctionSpy {

		/**
		 * Callbacks handed to register_shutdown_function(), in order.
		 *
		 * @var array<int, callable>
		 */
		private static array $callbacks = [];

		/**
		 * Forget every recorded callback. Call from setUp().
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$callbacks = [];
		}//end reset()

		/**
		 * Record one intercepted callback.
		 *
		 * @param callable $callback The callback that was registered.
		 *
		 * @return void
		 */
		public static function record(callable $callback): void {
			self::$callbacks[] = $callback;
		}//end record()

		/**
		 * Every callback recorded since the last reset().
		 *
		 * @return array<int, callable>
		 */
		public static function callbacks(): array {
			return self::$callbacks;
		}//end callbacks()

		/**
		 * Run every recorded callback, as PHP would at request shutdown.
		 *
		 * @return void
		 */
		public static function runAll(): void {
			foreach (self::$callbacks as $callback) {
				$callback();
			}
		}//end runAll()
	}//end class
}

namespace OCA\OpenRegister\Service\Deferral {

	use OCA\OpenRegister\Tests\Support\ShutdownFunctionSpy;

	/**
	 * Namespaced override of the global register_shutdown_function().
	 *
	 * @param callable $callback Callback the service registers.
	 * @param mixed    ...$args  Ignored; the services pass no extra args.
	 *
	 * @return void
	 */
	function register_shutdown_function(callable $callback, mixed ...$args): void {
		ShutdownFunctionSpy::record($callback);
	}//end register_shutdown_function()
}

namespace OCA\OpenRegister\Service\Object {

	use OCA\OpenRegister\Tests\Support\ShutdownFunctionSpy;

	/**
	 * Namespaced override of the global register_shutdown_function().
	 *
	 * @param callable $callback Callback the handler registers.
	 * @param mixed    ...$args  Ignored; the handler passes no extra args.
	 *
	 * @return void
	 */
	function register_shutdown_function(callable $callback, mixed ...$args): void {
		ShutdownFunctionSpy::record($callback);
	}//end register_shutdown_function()
}
