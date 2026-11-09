<?php

/**
 * OpenRegister ThresholdTrigger
 *
 * A threshold notification's trigger: which aggregation, which operator, which value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * The aggregation a threshold notification watches, and the condition its value must meet.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ThresholdTrigger {
	/**
	 * Name a trigger.
	 *
	 * @param string $aggregation The aggregation to run.
	 * @param string $op          Comparison operator (gt|gte|lt|lte|eq|ne).
	 * @param mixed  $threshold   The threshold value; only a numeric one can be met.
	 */
	public function __construct(
		public readonly string $aggregation,
		public readonly string $op,
		public readonly mixed $threshold,
	) {
	}//end __construct()

	/**
	 * Read a trigger configuration block.
	 *
	 * @param array<string, mixed> $trigger Trigger configuration block.
	 *
	 * @return self|null Null when the aggregation, the operator or the value is missing.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public static function fromConfig(array $trigger): ?self {
		$aggregation = (string)($trigger['aggregation'] ?? '');
		$op = (string)($trigger['op'] ?? '');
		$threshold = ($trigger['value'] ?? null);
		if ($aggregation === '' || $op === '' || $threshold === null) {
			return null;
		}

		return new self(aggregation: $aggregation, op: $op, threshold: $threshold);
	}//end fromConfig()

	/**
	 * Whether a computed value meets the condition.
	 *
	 * @param int|float $actual Numeric value computed from the aggregation.
	 *
	 * @return bool True when the comparison is satisfied, false otherwise (also for a non-numeric threshold).
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function isMetBy(int|float $actual): bool {
		if (is_numeric($this->threshold) === false) {
			return false;
		}

		$rhs = (float)$this->threshold;
		$lhs = (float)$actual;
		return match ($this->op) {
			'gt' => $lhs > $rhs,
			'gte' => $lhs >= $rhs,
			'lt' => $lhs < $rhs,
			'lte' => $lhs <= $rhs,
			'eq' => $lhs === $rhs,
			'ne' => $lhs !== $rhs,
			default => false,
		};
	}//end isMetBy()
}//end class
