<?php

/**
 * AggregateReportEnvelope — the reporting shape of an aggregation result.
 *
 * Turns the runner's `{groups: [{key|keys, value|values}]}` envelope into
 * the reporting API's `{results: [{<groupField>: ..., <metric>: ...}], total}`
 * rows, fills empty time buckets with zero, and applies the `having`
 * post-aggregation filter. It holds no state and reads nothing: the runner
 * has already applied the caller's access rules to the figures it gets.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

use InvalidArgumentException;

/**
 * Reshapes, gap-fills and post-filters an aggregation result for reporting.
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
 */
final class AggregateReportEnvelope {

	/**
	 * The `having` operators and what each compares.
	 *
	 * @var array<int, string>
	 */
	public const HAVING_OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte'];

	/**
	 * The report's metadata group fields and the column each reads.
	 *
	 * @var array<string, string>
	 */
	public const SELF_FIELDS = [
		'@self.created' => '_created',
		'@self.updated' => '_updated',
	];

	/**
	 * The column a report's group field reads: `@self.created` is `_created`.
	 *
	 * @param string $field The group field as the caller wrote it.
	 *
	 * @return string The column the runner groups on.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function column(string $field): string {
		return (self::SELF_FIELDS[$field] ?? $field);
	}//end column()

	/**
	 * The runner's groups as report rows, one column per group field and
	 * one per metric.
	 *
	 * A metric column is named by its metric (`sum`) when only one entry
	 * uses that metric, else by the runner's own key (`sum_bedrag`).
	 *
	 * @param array<int, array<string, mixed>>                 $groups      The runner's groups.
	 * @param array<int, string>                               $groupFields The group fields, as the caller wrote them.
	 * @param array<int, array{metric: string, field: mixed}> $metrics     The metric entries, primary first.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function rows(array $groups, array $groupFields, array $metrics): array {
		$names = self::metricNames(metrics: $metrics);
		$rows = [];
		foreach ($groups as $group) {
			$row = self::keyColumns(group: $group, groupFields: $groupFields);
			$values = [(string)reset($names) => ($group['value'] ?? null)];
			if (isset($group['values']) === true && is_array($group['values']) === true) {
				$values = $group['values'];
			}

			foreach ($values as $runnerKey => $value) {
				$row[($names[$runnerKey] ?? (string)$runnerKey)] = $value;
			}

			$rows[] = $row;
		}

		return $rows;
	}//end rows()

	/**
	 * The report's total: the sum of the count column when count is one of
	 * the metrics, else the number of rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows, before `having`.
	 *
	 * @return int|float The total.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function total(array $rows): int|float {
		$total = 0;
		$hasCount = false;
		foreach ($rows as $row) {
			if (array_key_exists(AggregationQuery::METRIC_COUNT, $row) === true) {
				$hasCount = true;
				if (is_numeric($row[AggregationQuery::METRIC_COUNT]) === true) {
					$total += ($row[AggregationQuery::METRIC_COUNT] + 0);
				}
			}
		}

		if ($hasCount === false) {
			return count($rows);
		}

		return $total;
	}//end total()

	/**
	 * Parse the `having` param: `having[count][gt]=10`, or `having[count]=10`
	 * for equality.
	 *
	 * @param mixed              $raw     The raw `having` param.
	 * @param array<int, string> $columns The metric columns the rows carry.
	 *
	 * @return array<string, array<string, float>> Column => operator => operand.
	 *
	 * @throws InvalidArgumentException When it names an unknown column or operator, or an operand is not a number.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function parseHaving(mixed $raw, array $columns): array {
		if ($raw === null || $raw === '' || $raw === []) {
			return [];
		}

		if (is_array($raw) === false) {
			throw new InvalidArgumentException('`having` MUST name a metric, e.g. having[count][gt]=10');
		}

		$having = [];
		foreach ($raw as $column => $condition) {
			if (in_array((string)$column, $columns, true) === false) {
				throw new InvalidArgumentException(
					sprintf('`having` names "%s", which is not one of the metrics (%s)', (string)$column, implode(', ', $columns))
				);
			}

			$having[(string)$column] = self::parseCondition(column: (string)$column, condition: $condition);
		}

		return $having;
	}//end parseHaving()

	/**
	 * One metric's `having` condition: an operator map, or a bare number for equality.
	 *
	 * @param string $column    The metric column.
	 * @param mixed  $condition The raw condition.
	 *
	 * @return array<string, float> Operator => operand.
	 *
	 * @throws InvalidArgumentException When an operator is unknown or an operand is not a number.
	 */
	private static function parseCondition(string $column, mixed $condition): array {
		if (is_array($condition) === false) {
			$condition = ['eq' => $condition];
		}

		$parsed = [];
		foreach ($condition as $operator => $operand) {
			if (in_array((string)$operator, self::HAVING_OPERATORS, true) === false) {
				throw new InvalidArgumentException(
					sprintf('`having` operator "%s" MUST be one of %s', (string)$operator, implode(', ', self::HAVING_OPERATORS))
				);
			}

			if (is_numeric($operand) === false) {
				throw new InvalidArgumentException(sprintf('`having[%s][%s]` MUST be a number', $column, (string)$operator));
			}

			$parsed[(string)$operator] = (float)$operand;
		}

		return $parsed;
	}//end parseCondition()

	/**
	 * The rows every `having` condition holds for; a null figure holds for none.
	 *
	 * @param array<int, array<string, mixed>>    $rows   The rows.
	 * @param array<string, array<string, float>> $having The parsed conditions.
	 *
	 * @return array<int, array<string, mixed>> The kept rows, in order.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function applyHaving(array $rows, array $having): array {
		if ($having === []) {
			return $rows;
		}

		$kept = [];
		foreach ($rows as $row) {
			if (self::holds(row: $row, having: $having) === true) {
				$kept[] = $row;
			}
		}

		return $kept;
	}//end applyHaving()

	/**
	 * The metric column names, keyed by the runner's key for each entry.
	 *
	 * @param array<int, array{metric: string, field: mixed}> $metrics The metric entries.
	 *
	 * @return array<string, string> Runner key => column name.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The runner's own key rule, read rather than copied.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function metricNames(array $metrics): array {
		$uses = array_count_values(array_map(static fn (array $entry): string => $entry['metric'], $metrics));
		$names = [];
		foreach ($metrics as $entry) {
			$field = null;
			if (is_string($entry['field'] ?? null) === true) {
				$field = $entry['field'];
			}

			$runnerKey = AggregationQuery::metricResponseKey(metric: $entry['metric'], field: $field);
			$names[$runnerKey] = $runnerKey;
			if ($uses[$entry['metric']] === 1) {
				$names[$runnerKey] = $entry['metric'];
			}
		}

		return $names;
	}//end metricNames()

	/**
	 * Whether every condition holds for one row.
	 *
	 * @param array<string, mixed>                $row    The row.
	 * @param array<string, array<string, float>> $having The conditions.
	 *
	 * @return bool
	 */
	private static function holds(array $row, array $having): bool {
		foreach ($having as $column => $conditions) {
			$figure = ($row[$column] ?? null);
			if (is_numeric($figure) === false) {
				return false;
			}

			$figure = (float)$figure;
			foreach ($conditions as $operator => $operand) {
				$holds = match ($operator) {
					'eq' => $figure === $operand,
					'ne' => $figure !== $operand,
					'gt' => $figure > $operand,
					'gte' => $figure >= $operand,
					'lt' => $figure < $operand,
					default => $figure <= $operand,
				};
				if ($holds === false) {
					return false;
				}
			}
		}

		return true;
	}//end holds()

	/**
	 * A group's key as report columns, named by the group fields.
	 *
	 * @param array<string, mixed> $group       The runner's group.
	 * @param array<int, string>   $groupFields The group fields as the caller wrote them.
	 *
	 * @return array<string, mixed>
	 */
	private static function keyColumns(array $group, array $groupFields): array {
		if (isset($group['keys']) === true && is_array($group['keys']) === true) {
			$columns = [];
			foreach ($groupFields as $field) {
				$columns[$field] = ($group['keys'][self::column(field: $field)] ?? ($group['keys'][$field] ?? null));
			}

			return $columns;
		}

		return [($groupFields[0] ?? 'key') => ($group['key'] ?? null)];
	}//end keyColumns()
}//end class
