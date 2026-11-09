<?php

/**
 * MomentShift: a relative date modifier that refuses PHP's silent `false`.
 *
 * The SLA arithmetic and the working-day roll both move instants by a
 * relative modifier; this is the one place that turns an unparseable
 * modifier into a refusal instead of a `false` that reads as a date.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Applies a relative modifier to an instant.
 *
 * @spec openspec/specs/flow-business-timers/spec.md
 */
final class MomentShift {

	/**
	 * Apply a relative modifier, refusing PHP's silent `false`.
	 *
	 * @param DateTimeImmutable $moment   The instant.
	 * @param string            $modifier A relative modifier such as `+3 days`.
	 *
	 * @return DateTimeImmutable The shifted instant.
	 *
	 * @throws FlowTimerValidationException When the modifier is unparseable.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public static function by(DateTimeImmutable $moment, string $modifier): DateTimeImmutable {
		$shifted = $moment->modify($modifier);
		if ($shifted === false) {
			throw new FlowTimerValidationException(message: sprintf("Date modifier '%s' is not parseable.", $modifier));
		}

		return $shifted;
	}//end by()
}//end class
