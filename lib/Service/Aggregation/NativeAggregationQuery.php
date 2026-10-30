<?php

/**
 * NativeAggregationQuery
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * The table, dialect and WHERE clause of one native aggregation statement.
 *
 * Predicates are ANDed in the order they are added; their bindings follow
 * the same order.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class NativeAggregationQuery {

	/**
	 * The WHERE predicates, in order.
	 *
	 * @var array<int, string>
	 */
	private array $parts = [];

	/**
	 * The bindings of the WHERE predicates, in placeholder order.
	 *
	 * @var array<int, mixed>
	 */
	private array $bindings = [];

	/**
	 * Constructor.
	 *
	 * @param string $platform  The platform short name: postgres, mysql or sqlite
	 * @param string $quote     The identifier quote character of the platform
	 * @param string $tableName The magic table name, without the `oc_` prefix
	 * @param string $metric    The aggregate metric
	 */
	public function __construct(
		public readonly string $platform,
		public readonly string $quote,
		public readonly string $tableName,
		public readonly string $metric,
	) {
	}//end __construct()

	/**
	 * Whether the statement runs on Postgres.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function isPostgres(): bool {
		return $this->platform === 'postgres';
	}//end isPostgres()

	/**
	 * A column name wrapped in the platform's identifier quotes.
	 *
	 * @param string $column The column name, already sanitised
	 *
	 * @return string
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function quoted(string $column): string {
		return $this->quote.$column.$this->quote;
	}//end quoted()

	/**
	 * The quoted, `oc_`-prefixed table name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function table(): string {
		return $this->quoted(column: 'oc_'.$this->tableName);
	}//end table()

	/**
	 * AND a predicate into the WHERE clause.
	 *
	 * @param string            $predicate The SQL predicate
	 * @param array<int, mixed> $bindings  The values of its placeholders
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function where(string $predicate, array $bindings = []): void {
		$this->parts[] = $predicate;
		foreach ($bindings as $binding) {
			$this->bindings[] = $binding;
		}
	}//end where()

	/**
	 * The WHERE clause, its predicates joined with AND.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function whereSql(): string {
		return implode(' AND ', $this->parts);
	}//end whereSql()

	/**
	 * The bindings of the WHERE clause, in placeholder order.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function bindings(): array {
		return $this->bindings;
	}//end bindings()
}//end class
