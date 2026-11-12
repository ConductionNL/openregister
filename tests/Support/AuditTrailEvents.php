<?php

/**
 * Adapt positional object-event stubs to AuditTrailMapper::recordObjectEvent().
 *
 * Several suites stub the audit trail with a callback, or an argument list,
 * that reads one object event as `(object, action, context, actorId,
 * actorName, ipAddress)`. These helpers hand such a callback the same values
 * from the ObjectAuditEvent that recordObjectEvent() receives, and match an
 * event against such an argument list.
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
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsEqual;

/**
 * Builds recordObjectEvent() stubs and matchers from positional ones.
 */
final class AuditTrailEvents {
	/**
	 * The event's values in the order of the former positional signature.
	 *
	 * @param ObjectAuditEvent $event The recorded event.
	 *
	 * @return array<int, mixed> Object, action, context, actor id, actor name and address.
	 */
	public static function values(ObjectAuditEvent $event): array {
		return [
			$event->object,
			$event->action,
			$event->context,
			$event->options->actor->id,
			$event->options->actor->name,
			$event->options->ipAddress,
		];
	}//end values()

	/**
	 * A recordObjectEvent() callback that calls $inner with the event's positional values.
	 *
	 * @param callable $inner The positional callback.
	 *
	 * @return Closure The recordObjectEvent() callback.
	 */
	public static function positional(callable $inner): Closure {
		return static fn (ObjectAuditEvent $event): mixed => $inner(...self::values(event: $event));
	}//end positional()

	/**
	 * A constraint that holds when the event's leading values match $expected, as `with()` would match them.
	 *
	 * @param array<int, mixed> $expected Values or constraints, in positional order.
	 *
	 * @return Callback The constraint for `with()`.
	 */
	public static function matching(array $expected): Callback {
		return new Callback(static function (mixed $event) use ($expected): bool {
			if ($event instanceof ObjectAuditEvent === false) {
				return false;
			}

			$values = self::values(event: $event);
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
