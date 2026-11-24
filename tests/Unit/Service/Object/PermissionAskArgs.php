<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lets a PermissionHandler::hasPermission() mock state its expectations in the
 * positional order the question used to be asked in: schema, action, userId,
 * objectOwner, _rbac, object, register.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Closure;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsAnything;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * Adapts a positional hasPermission() expectation to hasPermission(PermissionAsk, _rbac).
 */
final class PermissionAskArgs {

	/**
	 * A mock callback that receives the question and hands the positional callback its parts.
	 *
	 * @param callable $callback The callback over (schema, action, userId, objectOwner, _rbac, object, register).
	 *
	 * @return Closure The callback over (PermissionAsk, _rbac).
	 */
	public static function spread(callable $callback): Closure {
		return static fn (PermissionAsk $ask, bool $_rbac = true): mixed => $callback(
			$ask->schema,
			$ask->action,
			$ask->userId,
			$ask->objectOwner,
			$_rbac,
			$ask->object,
			self::registerAsGiven(ask: $ask)
		);
	}//end spread()

	/**
	 * The with() constraints for a question stated positionally; positions not given match anything.
	 *
	 * @param mixed ...$expected Schema, action, userId, objectOwner, _rbac, object, register.
	 *
	 * @return array{0: Constraint, 1: Constraint} The constraints on the question and on _rbac.
	 */
	public static function with(mixed ...$expected): array {
		$rbac = new IsAnything();
		if (array_key_exists(4, $expected) === true) {
			$rbac = self::constraint(expected: $expected[4]);
		}

		$ask = new Callback(
			static function (mixed $ask) use ($expected): bool {
				if ($ask instanceof PermissionAsk === false) {
					return false;
				}

				$actual = [$ask->schema, $ask->action, $ask->userId, $ask->objectOwner, null, $ask->object, self::registerAsGiven(ask: $ask)];
				foreach ($expected as $position => $value) {
					if ($position !== 4 && self::constraint(expected: $value)->evaluate($actual[$position], '', true) !== true) {
						return false;
					}
				}

				return true;
			}
		);

		return [$ask, $rbac];
	}//end with()

	/**
	 * The register as a positional caller gave it: a question about an object
	 * reads the object's register and named none of its own.
	 *
	 * @param PermissionAsk $ask The question.
	 *
	 * @return string|null The register named for a question without an object.
	 */
	private static function registerAsGiven(PermissionAsk $ask): ?string {
		if ($ask->object !== null) {
			return null;
		}

		return $ask->register;
	}//end registerAsGiven()

	/**
	 * The constraint for one expected value: itself when it is one, else equality.
	 *
	 * @param mixed $expected The expected value or constraint.
	 *
	 * @return Constraint The constraint.
	 */
	private static function constraint(mixed $expected): Constraint {
		if ($expected instanceof Constraint) {
			return $expected;
		}

		return new IsEqual($expected);
	}//end constraint()
}//end class
