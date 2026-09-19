<?php

/**
 * OpenRegister SystemOperationContext
 *
 * Scoped elevation for trusted, code-initiated operations (app configuration
 * imports, repair steps, background maintenance) that run without a user
 * session. RBAC fails closed for anonymous principals (#1955), which is
 * correct for requests — but Nextcloud boots apps BEFORE the session user is
 * resolved and webcron requests never have a user at all, so legitimate
 * app-initiated writes (importing the app's own shipped register config,
 * migrating its own objects) are denied as "Anonymous" on every boot.
 *
 * The existing `PHP_SAPI === 'cli'` trust in MultiTenancyTrait covers occ and
 * CLI cron only; this class extends the same trust to explicitly-scoped code
 * blocks regardless of SAPI. Elevation is only enterable from PHP code (never
 * from request data), applies to the current PHP request only, and ends with
 * the callable — including on exceptions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Exception\SystemContextUnavailableException;

final class SystemOperationContext {

	/**
	 * Nesting depth of active system-operation scopes.
	 *
	 * A depth counter (not a boolean) so nested run() calls compose: the
	 * elevation only ends when the OUTERMOST scope exits.
	 *
	 * @var integer
	 */
	private static int $depth = 0;

	/**
	 * This class is a static scope holder and must not be instantiated.
	 */
	private function __construct() {
	}//end __construct()

	/**
	 * Run a callable inside a trusted system-operation scope.
	 *
	 * While the callable executes, RBAC checks in MultiTenancyTrait and
	 * PermissionHandler treat the caller as a trusted system principal —
	 * mirroring the existing CLI trust. The scope is released in a finally
	 * block, so an exception inside the operation cannot leak elevation.
	 *
	 * @param callable $operation The trusted operation to execute.
	 *
	 * @return mixed Whatever the callable returns.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public static function run(callable $operation) {
		self::$depth++;

		try {
			return $operation();
		} finally {
			self::$depth--;
		}
	}//end run()

	/**
	 * Declare that the write about to run is the system's, and fail if it cannot be.
	 *
	 * 🔴 THIS EXISTS BECAUSE THE ALTERNATIVE DEGRADES SILENTLY. A consuming app
	 * cannot hard-depend on this class — OpenRegister may be absent or older —
	 * so every consumer invented the same guard:
	 *
	 *     if (class_exists(SystemOperationContext::class)) {
	 *         return SystemOperationContext::run($operation);
	 *     }
	 *     return $operation();
	 *
	 * The fallback is the bug. It does not decline to elevate; it runs the
	 * identical write as whoever happens to be signed in, and returns the same
	 * value the elevated call would have. Nothing throws, nothing logs, and the
	 * write either succeeds with the wrong principal recorded against it or
	 * fails a permission check somewhere far away for a reason nobody connects
	 * back to a missing class.
	 *
	 * 🔴 AND IT MAKES THE CODEBASE UNSWEEPABLE. A reviewer asking "which writes
	 * run as the system" cannot answer it statically: a call site that says
	 * `SystemOperationContext::run(...)` may or may not have elevated, and a
	 * scan for the elevation idiom counts the degraded path as elevated. That
	 * ambiguity is what stopped integriq's permission sweep: the safe subset
	 * could not be identified, so nothing could be restricted.
	 *
	 * `assertSystem()` says the same thing and refuses to be ambiguous. Either
	 * the operation runs elevated, or it throws with a message naming what was
	 * being attempted. A consumer that cannot tolerate the throw should not be
	 * claiming to write as the system.
	 *
	 * @param string   $what      What is being written, for the refusal.
	 * @param callable $operation The trusted operation.
	 *
	 * @return mixed Whatever the callable returns.
	 *
	 * @throws SystemContextUnavailableException When elevation is not available.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public static function assertSystem(string $what, callable $operation) {
		// 🔴 THE ELEVATION IS VERIFIED, NOT ASSUMED. An earlier draft of this
		// method checked `class_exists(self::class)` and threw when it was
		// false — which cannot happen, because a class that does not exist
		// cannot run its own static method. That guard was dead on the day it
		// was written, and a dead guard is worse than none: it reads as a check
		// and a later edit deletes it with every test still green.
		//
		// What CAN go wrong is the elevation failing to take effect: a refactor
		// that stops `run()` incrementing, a nested scope decrementing early,
		// or somebody replacing the depth counter with something the permission
		// layer no longer consults. So this asserts the scope is live AT THE
		// MOMENT THE OPERATION RUNS, which is the only moment it matters.
		$elevated = false;

		$result = self::run(
			operation: static function () use ($operation, &$elevated) {
				// Checked on BOTH sides of the operation. Before, because an
				// elevation that never applied is the ordinary failure. After,
				// because one that stopped applying part-way is the dangerous
				// one: the write has already happened, and checking only up
				// front would call it elevated.
				$entered = self::isActive();
				$value = $operation();
				$elevated = ($entered === true && self::isActive() === true);

				return $value;
			}
		);

		if ($elevated === false) {
			throw new SystemContextUnavailableException(
				message: sprintf(
					'"%s" was declared as a system write, but the system-operation scope was not in effect '
					.'while it ran. The write has already happened as the acting principal rather than as '
					.'the system, so whatever it recorded names the wrong actor. This is a defect in the '
					.'elevation itself, not in the caller.',
					$what
				)
			);
		}

		return $result;
	}//end assertSystem()

	/**
	 * Whether a system-operation scope is currently active.
	 *
	 * @return bool True when executing inside run().
	 */
	public static function isActive(): bool {
		return self::$depth > 0;
	}//end isActive()
}//end class
