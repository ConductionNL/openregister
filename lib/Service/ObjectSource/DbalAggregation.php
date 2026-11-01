<?php

/**
 * DbalAggregation — what one DbalObjectSourceProvider::aggregate() call computes:
 * the metric, its field, the filter map and the allowlisted columns.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * The metric, field, filter and column allowlist of one aggregation.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class DbalAggregation {

	/**
	 * Constructor.
	 *
	 * @param string               $metric  count/sum/avg/min/max.
	 * @param string|null          $field   The metric field (required for sum/avg/min/max).
	 * @param array<int|string, mixed> $filter The filter map; keys may be ints.
	 * @param array<int, string>   $columns The allowlisted scalar columns.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function __construct(
		public readonly string $metric,
		public readonly ?string $field,
		public readonly array $filter,
		public readonly array $columns,
	) {
	}//end __construct()

	/**
	 * Whether a value metric (sum/avg/min/max) lacks a real, allowlisted column to operate on.
	 *
	 * @return bool True when the aggregation cannot run.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function lacksValueField(): bool {
		return $this->metric !== 'count'
			&& ($this->field === null || $this->field === '' || in_array($this->field, $this->columns, true) === false);
	}//end lacksValueField()
}//end class
