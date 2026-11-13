<?php

/**
 * Conversions the timer classes share.
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
 * @spec openspec/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use Throwable;

/**
 * Turns configured and stored values into the shapes timers use.
 */
final class FlowTimerValues {

	/**
	 * The clock instant as an immutable.
	 *
	 * @param DateTimeInterface|null $now The injected clock, or null for the real one.
	 *
	 * @return DateTimeImmutable Now.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function instant(?DateTimeInterface $now): DateTimeImmutable {
		if ($now === null) {
			return new DateTimeImmutable();
		}

		return DateTimeImmutable::createFromInterface($now);
	}//end instant()

	/**
	 * A mutable copy, as the entities store.
	 *
	 * @param DateTimeInterface $value The instant.
	 *
	 * @return DateTime The copy.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function mutable(DateTimeInterface $value): DateTime {
		return (new DateTime('@' . $value->format('U.u')))->setTimezone($value->getTimezone());
	}//end mutable()

	/**
	 * A mutable copy or null.
	 *
	 * @param DateTimeInterface|null $value The instant.
	 *
	 * @return DateTime|null The copy.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function mutableOrNull(?DateTimeInterface $value): ?DateTime {
		if ($value === null) {
			return null;
		}

		return $this->mutable(value: $value);
	}//end mutableOrNull()

	/**
	 * A parsed date or null.
	 *
	 * @param mixed $value An ISO string, a DateTimeInterface, or null.
	 * @param string $field The field name, for the message.
	 *
	 * @return DateTimeImmutable|null The instant.
	 *
	 * @throws FlowTimerValidationException On an unparseable value.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function dateOrNull(mixed $value, string $field): ?DateTimeImmutable {
		if (in_array($value, [null, ''], true) === true) {
			return null;
		}

		if ($value instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($value);
		}

		if (is_string($value) === false) {
			throw new FlowTimerValidationException(
				message: sprintf('%s must be a date string or a DateTime; got %s.', $field, get_debug_type($value))
			);
		}

		return $this->parsedDate(value: $value, field: $field);
	}//end dateOrNull()

	/**
	 * Parse a date string.
	 *
	 * @param string $value The text.
	 * @param string $field The field name, for the message.
	 *
	 * @return DateTimeImmutable The instant.
	 *
	 * @throws FlowTimerValidationException On an unparseable value.
	 */
	private function parsedDate(string $value, string $field): DateTimeImmutable {
		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			throw new FlowTimerValidationException(message: sprintf("%s '%s' is not a parseable date.", $field, $value));
		}
	}//end parsedDate()

	/**
	 * A non-empty string or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end stringOrNull()

	/**
	 * An array or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string, mixed>|null The array.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function arrayOrNull(mixed $value): ?array {
		if (is_array($value) === false || $value === []) {
			return null;
		}

		return $value;
	}//end arrayOrNull()
}//end class
