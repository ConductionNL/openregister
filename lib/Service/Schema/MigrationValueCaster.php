<?php

/**
 * MigrationValueCaster — the `cast` transform of a schema migration plan.
 *
 * Casts one value to `string`, `integer`, `number`, `boolean` or `date`
 * (ISO-8601), or refuses with a message naming the value, so the planner
 * can fail the object without touching its data.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use DateTime;
use RuntimeException;

/**
 * Pure value caster for the `cast` migration transform.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
class MigrationValueCaster {

	/**
	 * The supported cast target types.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = ['string', 'integer', 'number', 'boolean', 'date'];

	/**
	 * Words accepted as booleans by the `boolean` cast (after trim + lowercase).
	 *
	 * @var array<int|string, bool>
	 */
	private const BOOLEAN_WORDS = [
		'true' => true,
		'1' => true,
		'yes' => true,
		'on' => true,
		'false' => false,
		'0' => false,
		'no' => false,
		'off' => false,
	];

	/**
	 * Cast a value to the target type.
	 *
	 * @param mixed       $value  The value.
	 * @param string      $toType Target type, one of TYPES.
	 * @param string|null $format Optional date format.
	 *
	 * @return mixed The cast value.
	 *
	 * @throws RuntimeException When uncastable or the type is unsupported.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function cast(mixed $value, string $toType, ?string $format): mixed {
		if ($toType === 'date') {
			return $this->castDate(value: $value, format: $format);
		}

		if ($toType === 'boolean') {
			return $this->castBoolean(value: $value);
		}

		return $this->castScalar(value: $value, toType: $toType);
	}//end cast()

	/**
	 * Cast to a string, integer or number.
	 *
	 * @param mixed  $value  The value.
	 * @param string $toType Target type.
	 *
	 * @return string|int|float The cast value.
	 *
	 * @throws RuntimeException When uncastable or the type is unsupported.
	 */
	private function castScalar(mixed $value, string $toType): string|int|float {
		if ($toType === 'string') {
			return $this->castString(value: $value);
		}

		if ($toType === 'integer' || $toType === 'number') {
			return $this->castNumeric(value: $value, toType: $toType);
		}

		throw new RuntimeException(sprintf('Unsupported cast target type "%s".', $toType));
	}//end castScalar()

	/**
	 * Cast a scalar to a string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string.
	 *
	 * @throws RuntimeException When the value is not scalar.
	 */
	private function castString(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		throw new RuntimeException('Cannot cast non-scalar value to string.');
	}//end castString()

	/**
	 * Cast a boolean or numeric value to an integer or a float.
	 *
	 * @param mixed  $value  The value.
	 * @param string $toType `integer` or `number`.
	 *
	 * @return int|float The number.
	 *
	 * @throws RuntimeException When the value is neither boolean nor numeric.
	 */
	private function castNumeric(mixed $value, string $toType): int|float {
		if (is_bool($value) === false && is_numeric($value) === false) {
			throw new RuntimeException(sprintf('Cannot cast "%s" to %s.', $this->describe(value: $value), $toType));
		}

		if ($toType === 'integer') {
			return (int)$value;
		}

		return (float)$value;
	}//end castNumeric()

	/**
	 * Cast a value to a boolean using a strict allow-list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool The boolean.
	 *
	 * @throws RuntimeException When the value is not recognisably boolean.
	 */
	private function castBoolean(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		$word = $this->booleanWord(value: $value);
		if ($word === null) {
			throw new RuntimeException(sprintf('Cannot cast "%s" to boolean.', $this->describe(value: $value)));
		}

		return $word;
	}//end castBoolean()

	/**
	 * The boolean an integer 0/1 or an accepted word stands for.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool|null The boolean, or null when the value stands for none.
	 */
	private function booleanWord(mixed $value): ?bool {
		if ($value === 0 || $value === 1) {
			return $value === 1;
		}

		if (is_string($value) === false) {
			return null;
		}

		return (self::BOOLEAN_WORDS[strtolower(trim($value))] ?? null);
	}//end booleanWord()

	/**
	 * Cast a value to an ISO-8601 date string.
	 *
	 * @param mixed       $value  The value.
	 * @param string|null $format Optional input format.
	 *
	 * @return string The ISO-8601 date string.
	 *
	 * @throws RuntimeException When the value cannot be parsed as a date.
	 */
	private function castDate(mixed $value, ?string $format): string {
		if (is_string($value) === false && is_numeric($value) === false) {
			throw new RuntimeException('Cannot cast non-string value to date.');
		}

		if ($format !== null && $format !== '') {
			return $this->castDateWithFormat(value: (string)$value, format: $format);
		}

		return $this->castDateFreeForm(value: (string)$value);
	}//end castDate()

	/**
	 * Parse a date in the given format.
	 *
	 * @param string $value  The value.
	 * @param string $format The input format.
	 *
	 * @return string The ISO-8601 date string.
	 *
	 * @throws RuntimeException When the value does not match the format.
	 */
	private function castDateWithFormat(string $value, string $format): string {
		$dateTime = date_create_from_format($format, $value);
		if ($dateTime === false) {
			throw new RuntimeException(sprintf('Value "%s" does not match date format "%s".', $value, $format));
		}

		return $dateTime->format(DateTime::ATOM);
	}//end castDateWithFormat()

	/**
	 * Parse a date in any format strtotime() understands.
	 *
	 * @param string $value The value.
	 *
	 * @return string The ISO-8601 date string (UTC).
	 *
	 * @throws RuntimeException When the value is not a parseable date.
	 */
	private function castDateFreeForm(string $value): string {
		$timestamp = strtotime($value);
		if ($timestamp === false) {
			throw new RuntimeException(sprintf('Value "%s" is not a parseable date.', $value));
		}

		return (new DateTime('@' . $timestamp))->format(DateTime::ATOM);
	}//end castDateFreeForm()

	/**
	 * Describe a value for an error message.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string A short description.
	 */
	private function describe(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return gettype($value);
	}//end describe()
}//end class
