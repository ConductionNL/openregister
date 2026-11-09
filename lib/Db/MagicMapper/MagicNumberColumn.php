<?php

/**
 * MagicNumberColumn: the DECIMAL column a schema `number` property gets, and
 * whether a value fits it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Exception\ValidationException;

/**
 * Size a `number` property's DECIMAL column and check a value against it.
 *
 * The scale is the number of decimals of the property's `multipleOf`, or 6
 * without one; the column always holds 14 digits before the point. A value
 * the column cannot hold is refused, because the database would round it.
 *
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
 */
final class MagicNumberColumn {

	public const INTEGER_DIGITS = 14;

	public const DEFAULT_SCALE = 6;

	/**
	 * The largest scale MySQL/MariaDB allow for DECIMAL.
	 */
	private const MAX_SCALE = 30;

	/**
	 * The largest precision MySQL/MariaDB allow for DECIMAL.
	 */
	private const MAX_PRECISION = 65;

	/**
	 * The column size for a `number` property.
	 *
	 * @param array $propertyConfig The schema property.
	 *
	 * @return array{precision: int, scale: int} The DECIMAL precision and scale.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function size(array $propertyConfig): array {
		$scale = $this->scaleOf(multipleOf: $propertyConfig['multipleOf'] ?? null);

		return [
			'precision' => min(self::INTEGER_DIGITS + $scale, self::MAX_PRECISION),
			'scale' => $scale,
		];
	}//end size()

	/**
	 * The scale a `multipleOf` asks for: its decimals, or the default without a usable one.
	 *
	 * @param mixed $multipleOf The property's `multipleOf`, or null.
	 *
	 * @return int The scale.
	 */
	private function scaleOf(mixed $multipleOf): int {
		if ($this->isNumberLike(value: $multipleOf) === false) {
			return self::DEFAULT_SCALE;
		}

		$digits = $this->digits(value: $multipleOf);
		if ($digits === null || ((float)$multipleOf > 0) === false) {
			return self::DEFAULT_SCALE;
		}

		return min($digits['decimals'], self::MAX_SCALE);
	}//end scaleOf()

	/**
	 * Whether a value is an int, a float or a string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for an int, float or string.
	 */
	private function isNumberLike(mixed $value): bool {
		return is_int($value) === true || is_float($value) === true || is_string($value) === true;
	}//end isNumberLike()

	/**
	 * Why a value does not fit a DECIMAL(precision, scale) column, or null when it does.
	 *
	 * A float is compared with its rounding to the scale within float precision,
	 * so binary noise (0.1 + 0.2) is not refused but a real extra decimal is.
	 * A non-numeric value is left to the validator and the database.
	 *
	 * @param mixed $value     The value to store.
	 * @param int   $precision The column's precision.
	 * @param int   $scale     The column's scale.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-the-column-cannot-hold-is-refused-not-rounded-req-mtc-002
	 */
	public function refusal(mixed $value, int $precision, int $scale): ?string {
		$size = ['precision' => $precision, 'scale' => $scale];
		if (is_float($value) === true) {
			return $this->floatRefusal(value: $value, size: $size);
		}

		if ($this->isIntOrNumericString(value: $value) === false) {
			return null;
		}

		return $this->digitsRefusal(digits: $this->digits(value: $value), size: $size);
	}//end refusal()

	/**
	 * Whether a value is an int or a numeric string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for an int or a numeric string.
	 */
	private function isIntOrNumericString(mixed $value): bool {
		return is_int($value) === true || (is_string($value) === true && is_numeric($value) === true);
	}//end isIntOrNumericString()

	/**
	 * Why counted digits do not fit the column, or null when they do (or could not be counted).
	 *
	 * @param array{integer: int, decimals: int}|null $digits The value's digit counts.
	 * @param array{precision: int, scale: int}       $size   The column size.
	 *
	 * @return string|null The reason, or null.
	 */
	private function digitsRefusal(array|null $digits, array $size): string|null {
		if ($digits === null) {
			return null;
		}

		if ($digits['decimals'] > $size['scale']) {
			return sprintf('has more than %d decimals', $size['scale']);
		}

		$integerDigits = $size['precision'] - $size['scale'];
		if ($digits['integer'] > $integerDigits) {
			return sprintf('has more than %d digits before the point', $integerDigits);
		}

		return null;
	}//end digitsRefusal()

	/**
	 * Why a float does not fit, comparing it with its rounding within float precision.
	 *
	 * @param float                             $value The value.
	 * @param array{precision: int, scale: int} $size  The column size.
	 *
	 * @return string|null The reason, or null.
	 */
	private function floatRefusal(float $value, array $size): ?string {
		if (is_finite($value) === false) {
			return 'is not a finite number';
		}

		$scale = $size['scale'];
		$integerDigits = $size['precision'] - $scale;
		$rounded = round($value, $scale);
		if (abs($value - $rounded) > abs($value) * 4 * PHP_FLOAT_EPSILON) {
			return sprintf('has more than %d decimals', $scale);
		}

		if (abs($rounded) >= 10 ** $integerDigits) {
			return sprintf('has more than %d digits before the point', $integerDigits);
		}

		return null;
	}//end floatRefusal()

	/**
	 * Refuse a row holding a number its column would round.
	 *
	 * @param array                                           $row         The prepared row, keyed by column name.
	 * @param array<string, array{precision: int, scale: int}> $columnSizes The table's number columns, keyed by lower-cased name.
	 *
	 * @throws ValidationException When a column cannot hold its value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-the-column-cannot-hold-is-refused-not-rounded-req-mtc-002
	 */
	public function assertRowFits(array $row, array $columnSizes): void {
		foreach ($row as $columnName => $value) {
			$size = $columnSizes[strtolower((string)$columnName)] ?? null;
			if ($size === null || $value === null) {
				continue;
			}

			$reason = $this->refusal(value: $value, precision: $size['precision'], scale: $size['scale']);
			if ($reason !== null) {
				$message = sprintf("The value of '%s' %s and cannot be stored without rounding.", $columnName, $reason);
				throw new ValidationException(message: $message);
			}
		}
	}//end assertRowFits()

	/**
	 * The size an existing column must be widened to, or null when it already holds the required size.
	 *
	 * Never narrower than the live column, so widening cannot change a stored value.
	 *
	 * @param array $currentColumn The live column (type, precision, scale).
	 * @param int   $precision     The required precision.
	 * @param int   $scale         The required scale.
	 *
	 * @return array{precision: int, scale: int}|null The widened size, or null.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-number-column-holds-every-value-its-property-accepts-req-mtc-001
	 */
	public function widening(array $currentColumn, int $precision, int $scale): ?array {
		$live = $this->decimalSize(column: $currentColumn);
		if ($live === null) {
			return null;
		}

		// Neither part shrinks, so an unchanged total means both parts are unchanged.
		$newScale = max($live['scale'], $scale);
		$newIntegerDigits = max($live['precision'] - $live['scale'], $precision - $scale);
		if ($newIntegerDigits + $newScale === $live['precision']) {
			return null;
		}

		return [
			'precision' => min($newIntegerDigits + $newScale, self::MAX_PRECISION),
			'scale' => min($newScale, self::MAX_SCALE),
		];
	}//end widening()

	/**
	 * The size of a live DECIMAL/NUMERIC column, or null for another type or an unknown size.
	 *
	 * @param array $column The live column (type, precision, scale).
	 *
	 * @return array{precision: int, scale: int}|null The size, or null.
	 */
	private function decimalSize(array $column): ?array {
		$type = strtolower((string)($column['type'] ?? ''));
		if (in_array($type, ['decimal', 'numeric'], true) === false) {
			return null;
		}

		if (isset($column['precision'], $column['scale']) === false) {
			return null;
		}

		return ['precision' => (int)$column['precision'], 'scale' => (int)$column['scale']];
	}//end decimalSize()

	/**
	 * Count the digits before and after the point of an int or a numeric string.
	 *
	 * Leading and trailing zeros do not count; an exponent moves the point.
	 *
	 * @param int|float|string $value The value.
	 *
	 * @return array{integer: int, decimals: int}|null The counts, or null when the value is not a plain number.
	 */
	private function digits(int|float|string $value): ?array {
		$parts = $this->numberParts(value: $value);
		if ($parts === null) {
			return null;
		}

		$all = $parts['all'];
		$point = strlen($parts['whole']) + $parts['exponent'];
		$leadingZeros = strlen($all) - strlen(ltrim($all, '0'));

		return [
			'integer' => max(0, $point - $leadingZeros),
			'decimals' => max(0, strlen(rtrim($all, '0')) - $point),
		];
	}//end digits()

	/**
	 * Split an int, float or numeric string into its whole digits, all digits and exponent.
	 *
	 * @param int|float|string $value The value.
	 *
	 * @return array{whole: string, all: string, exponent: int}|null The parts, or null when the value is not a plain number.
	 */
	private function numberParts(int|float|string $value): ?array {
		if (is_float($value) === true) {
			$value = var_export($value, true);
		}

		$matches = [];
		if (preg_match('/^[+-]?(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', trim((string)$value), $matches) !== 1) {
			return null;
		}

		$all = $matches[1].($matches[2] ?? '');
		if ($all === '') {
			return null;
		}

		return ['whole' => $matches[1], 'all' => $all, 'exponent' => (int)($matches[3] ?? 0)];
	}//end numberParts()
}//end class
