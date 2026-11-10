<?php

/**
 * DbalColumnCriterion: the `{op: value}` criteria of one aggregation filter column.
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

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Adds the operator criteria on one quoted column to the query being built,
 * as bound parameters. Used by {@see DbalAggregator}.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class DbalColumnCriterion {

	/**
	 * Constructor.
	 *
	 * @param QueryBuilder $qb     The query being built (mutated).
	 * @param string       $quoted The quoted column.
	 */
	public function __construct(
		private readonly QueryBuilder $qb,
		private readonly string $quoted,
	) {
	}//end __construct()

	/**
	 * Add one `{op: value}` criterion per operator; unknown operators are ignored.
	 *
	 * @param array<string, mixed> $criterion Operator => operand.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function apply(array $criterion): void {
		foreach ($criterion as $op => $onValue) {
			if ((string)$op === 'in' || (string)$op === 'notIn') {
				$this->inList(operator: (string)$op, operand: $onValue);
				continue;
			}

			$this->compare(operator: (string)$op, operand: $onValue);
		}
	}//end apply()

	/**
	 * Add a `gt` / `gte` / `lt` / `lte` / `ne` comparison; any other operator adds nothing.
	 *
	 * @param string $operator The operator.
	 * @param mixed  $operand  The value to compare with.
	 *
	 * @return void
	 */
	private function compare(string $operator, mixed $operand): void {
		$sqlOn = match ($operator) {
			'gt' => '>',
			'gte' => '>=',
			'lt' => '<',
			'lte' => '<=',
			'ne' => '<>',
			default => null,
		};
		if ($sqlOn !== null) {
			$this->qb->andWhere($this->quoted . ' ' . $sqlOn . ' ' . $this->qb->createNamedParameter($operand));
		}
	}//end compare()

	/**
	 * Add an `in` / `notIn` criterion. An empty list matches nothing for
	 * `in` and excludes nothing for `notIn`.
	 *
	 * @param string $operator `in` or `notIn`.
	 * @param mixed  $operand  The list of values.
	 *
	 * @return void
	 */
	private function inList(string $operator, mixed $operand): void {
		$list = [];
		if (is_array($operand) === true) {
			$list = array_values($operand);
		}

		$qb = $this->qb;
		if ($list === []) {
			$qb->andWhere(match ($operator) { 'in' => '1 = 0', default => '1 = 1' });
			return;
		}

		$params  = array_map(static fn (mixed $item): string => $qb->createNamedParameter($item), $list);
		$keyword = match ($operator) { 'in' => ' IN (', default => ' NOT IN (' };
		$qb->andWhere($this->quoted . $keyword . implode(', ', $params) . ')');
	}//end inList()
}//end class
