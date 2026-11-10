<?php

/**
 * OpenRegister UnaryTestValue
 *
 * The runtime value a DMN input cell is tested against, with its declared type.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * An already-coerced runtime value and the type it was coerced to.
 *
 * Every branch of {@see UnaryTestEvaluator} compares this value with an
 * operand coerced to the same type; the comparison lives here so the type
 * that decides how equality works travels with the value.
 *
 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
 */
final class UnaryTestValue {

	/**
	 * Constructor.
	 *
	 * @param mixed  $value The runtime value, already coerced for $type.
	 * @param string $type  The declared type.
	 */
	public function __construct(
		public readonly mixed $value,
		public readonly string $type,
	) {
	}//end __construct()

	/**
	 * Type-aware equality with an operand coerced to the same type.
	 *
	 * Numbers and dates compare within 1e-9; everything else compares strictly.
	 *
	 * @param mixed $coerced The coerced operand.
	 *
	 * @return bool True when equal.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	public function equals(mixed $coerced): bool {
		if ($this->type === 'number' || $this->type === 'date') {
			return (abs(((float)$this->value) - ((float)$coerced)) < 1.0e-9);
		}

		return ($this->value === $coerced);
	}//end equals()

	/**
	 * Compare this value with a coerced operand.
	 *
	 * @param string $operator One of `< > <= >= = !=`.
	 * @param mixed  $coerced  The coerced operand.
	 *
	 * @return bool The comparison's answer.
	 *
	 * @throws DecisionEvaluationException `invalid_expression` on an operator outside the grammar.
	 *
	 * @spec openspec/changes/shared-decision-table-evaluator/specs/shared-decision-tables/spec.md
	 */
	public function compare(string $operator, mixed $coerced): bool {
		return match ($operator) {
			'<' => ($this->value < $coerced),
			'<=' => ($this->value <= $coerced),
			'>' => ($this->value > $coerced),
			'>=' => ($this->value >= $coerced),
			'=' => $this->equals(coerced: $coerced),
			'!=' => ($this->equals(coerced: $coerced) === false),
			default => throw new DecisionEvaluationException(
				errorCode: 'invalid_expression',
				details: ['reason' => 'unknown_operator', 'operator' => $operator],
			),
		};
	}//end compare()
}//end class
