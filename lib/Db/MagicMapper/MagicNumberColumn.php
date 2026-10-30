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
		$scale = self::DEFAULT_SCALE;
		$multipleOf = $propertyConfig['multipleOf'] ?? null;
		if (is_int($multipleOf) === true || is_float($multipleOf) === true || is_string($multipleOf) === true) {
			$digits = $this->digits(value: $multipleOf);
			if ($digits !== null && (float)$multipleOf > 0) {
				$scale = min($digits['decimals'], self::MAX_SCALE);
			}
		}

		return [
			'precision' => min(self::INTEGER_DIGITS + $scale, self::MAX_PRECISION),
			'scale' => $scale,
		];
	}//end size()

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
		if (is_float($value) === true) {
			return $this->floatRefusal(value: $value, integerDigits: $precision - $scale, scale: $scale);
		}

		if (is_int($value) === false && (is_string($value) === false || is_numeric($value) === false)) {
			return null;
		}

		$digits = $this->digits(value: $value);
		if ($digits !== null && $digits['decimals'] > $scale) {
			return sprintf('has more than %d decimals', $scale);
		}

		if ($digits !== null && $digits['integer'] > $precision - $scale) {
			return sprintf('has more than %d digits before the point', $precision - $scale);
		}

		return null;
		}//end refusal()

	/**
	 * Why a float does not fit, comparing it with its rounding within float precision.
	 *
	 * @param float $value         The value.
	 * @param int   $integerDigits The column's digits before the point.
	 * @param int   $scale         The column's scale.
	 *
	 * @return string|null The reason, or null.
	 */
	private function floatRefusal(float $value, int $integerDigits, int $scale): ?string {
		if (is_finite($value) === false) {
			return 'is not a finite number';
		}

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
		$type = strtolower((string)($currentColumn['type'] ?? ''));
		if (in_array($type, ['decimal', 'numeric'], true) === false) {
			return null;
		}

		if (isset($currentColumn['precision'], $currentColumn['scale']) === false) {
			return null;
		}

		$currentScale = (int)$currentColumn['scale'];
		$currentIntegerDigits = (int)$currentColumn['precision'] - $currentScale;
		$newScale = max($currentScale, $scale);
		$newIntegerDigits = max($currentIntegerDigits, $precision - $scale);
		if ($newScale === $currentScale && $newIntegerDigits === $currentIntegerDigits) {
			return null;
		}

		return [
			'precision' => min($newIntegerDigits + $newScale, self::MAX_PRECISION),
			'scale' => min($newScale, self::MAX_SCALE),
		];
	}//end widening()

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
		if (is_float($value) === true) {
			$value = var_export($value, true);
		}

		$matches = [];
		if (preg_match('/^[+-]?(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', trim((string)$value), $matches) !== 1) {
			return null;
		}

		$whole = $matches[1];
		$all = $whole.($matches[2] ?? '');
		if ($all === '') {
			return null;
		}

		$point = strlen($whole) + (int)($matches[3] ?? 0);
		$leadingZeros = strlen($all) - strlen(ltrim($all, '0'));

		return [
			'integer' => max(0, $point - $leadingZeros),
			'decimals' => max(0, strlen(rtrim($all, '0')) - $point),
		];
	}//end digits()
}//end class
