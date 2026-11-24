<?php

/**
 * A multipleOf check that compares decimals exactly.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Keywords\MultipleOfKeyword;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\ValidationContext;

/**
 * Opis's own check formats both numbers at 14 decimals, where 1234.56 reads
 * as 1234.55999999999995 and is refused as a multiple of 0.01. This one takes
 * the shortest decimal that round-trips each float (the value as written in
 * the JSON) and compares those as integers.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
final class ExactMultipleOfKeyword extends MultipleOfKeyword {
	/**
	 * The divisor.
	 *
	 * @var integer|float
	 */
	private int|float $divisor;

	/**
	 * Construct the keyword.
	 *
	 * @param integer|float $divisor The schema's multipleOf value.
	 */
	public function __construct(int|float $divisor) {
		parent::__construct(number: (float) $divisor);
		$this->divisor = $divisor;
	}//end __construct()

	/**
	 * Refuse a value that is not a whole multiple of the divisor.
	 *
	 * @param ValidationContext $context The validation context.
	 * @param Schema            $schema  The schema node.
	 *
	 * @return ValidationError|null The error, or null when the value is a multiple.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function validate(ValidationContext $context, Schema $schema): ?ValidationError {
		$value = $context->currentData();
		if (self::isMultipleOf(value: $value, divisor: $this->divisor) === true) {
			return null;
		}

		return $this->error(
			schema: $schema,
			context: $context,
			keyword: 'multipleOf',
			message: 'Number must be a multiple of {divisor}',
			args: ['divisor' => $this->divisor]
		);
	}//end validate()

	/**
	 * Whether a number is a whole multiple of a divisor, compared as exact decimals.
	 *
	 * Falls back to opis's approximation for a value with no plain decimal form, or
	 * one past 18 digits without bcmath.
	 *
	 * @param integer|float $value   The number checked.
	 * @param integer|float $divisor The divisor, greater than zero.
	 *
	 * @return boolean Whether the value is a multiple.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public static function isMultipleOf(int|float $value, int|float $divisor): bool {
		$number = self::decimal(value: $value);
		$step = self::decimal(value: $divisor);
		if ($number === null || $step === null) {
			return Helper::isMultipleOf(number: $value, divisor: $divisor);
		}

		$scale = max($number['scale'], $step['scale']);
		$numerator = $number['digits'].str_repeat('0', $scale - $number['scale']);
		$denominator = $step['digits'].str_repeat('0', $scale - $step['scale']);
		if ($denominator === '0') {
			return $numerator === '0';
		}

		if (strlen($numerator) <= 18 && strlen($denominator) <= 18) {
			return ((int) $numerator % (int) $denominator) === 0;
		}

		if (function_exists('bcmod') === true) {
			return bcmod($numerator, $denominator, 0) === '0';
		}

		return Helper::isMultipleOf(number: $value, divisor: $divisor);
	}//end isMultipleOf()

	/**
	 * The unsigned digits and decimal scale of a number's shortest round-trip form.
	 *
	 * @param integer|float $value The number.
	 *
	 * @return array{digits: string, scale: int}|null Null when the number has no such form.
	 */
	private static function decimal(int|float $value): ?array {
		if (is_float($value) === true && (is_finite($value) === false || ini_get('serialize_precision') !== '-1')) {
			return null;
		}

		$text = (string) json_encode(abs($value));
		if (preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $text, $parts) !== 1) {
			return null;
		}

		$fraction = ($parts[2] ?? '');
		$scale = strlen($fraction) - (int) ($parts[3] ?? 0);
		$digits = $parts[1].$fraction;
		if ($scale < 0) {
			$digits .= str_repeat('0', -$scale);
			$scale = 0;
		}

		$digits = ltrim($digits, '0');
		if ($digits === '') {
			$digits = '0';
		}

		return [
			'digits' => $digits,
			'scale' => $scale,
		];
	}//end decimal()
}//end class
