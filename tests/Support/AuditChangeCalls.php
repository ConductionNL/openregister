<?php

/**
 * Adapt positional change stubs to AuditTrailMapper::recordChange().
 *
 * Several suites stub the audit trail with a callback, or an argument list,
 * that reads one change as `(old, new, action, cascadeContext)`. These helpers
 * hand such a callback the same values from the AuditedChange that
 * recordChange() receives, and match a change against such an argument list.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support;

use Closure;
use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * Builds recordChange() stubs and matchers from positional ones.
 */
final class AuditChangeCalls {
	/**
	 * The change's values in the order of the former positional signature.
	 *
	 * @param AuditedChange $change The recorded change.
	 *
	 * @return array<int, mixed> Old, new, action and cascade context.
	 */
	public static function values(AuditedChange $change): array {
		return [$change->old, $change->new, $change->action, $change->cascadeContext];
	}//end values()

	/**
	 * A recordChange() callback that calls $inner with the change's positional values.
	 *
	 * @param callable $inner The positional callback.
	 *
	 * @return Closure The recordChange() callback.
	 */
	public static function positional(callable $inner): Closure {
		return static fn (AuditedChange $change): mixed => $inner(...self::values(change: $change));
	}//end positional()

	/**
	 * A constraint that holds when the change's leading values match $expected, as `with()` would match them.
	 *
	 * @param array<int, mixed> $expected Values or constraints, in positional order.
	 *
	 * @return Callback The constraint for `with()`.
	 */
	public static function matching(array $expected): Callback {
		return new Callback(static function (mixed $change) use ($expected): bool {
			if ($change instanceof AuditedChange === false) {
				return false;
			}

			$values = self::values(change: $change);
			foreach ($expected as $index => $want) {
				$constraint = $want;
				if ($want instanceof Constraint === false) {
					$constraint = new IsEqual($want);
				}

				if ($constraint->evaluate($values[$index], '', true) !== true) {
					return false;
				}
			}

			return true;
		});
	}//end matching()
}//end class
