<?php

/**
 * FacetColumnFilter
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use OCA\OpenRegister\Support\FilterParams;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;

/**
 * The conditions a facet query without a search handler puts on one column.
 *
 * Used only by the fallback facet query; with a search handler wired, the
 * handler's filtered query is used instead.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
final class FacetColumnFilter {

	/**
	 * Constructor.
	 *
	 * @param IQueryBuilder $queryBuilder The query the conditions are added to
	 * @param string        $columnName   The column, already sanitized
	 */
	public function __construct(
		private readonly IQueryBuilder $queryBuilder,
		private readonly string $columnName,
	) {
	}//end __construct()

	/**
	 * Require the column to equal the value, or one of the values of a list.
	 *
	 * @param mixed $value The value or values.
	 *
	 * @return void
	 */
	public function equals(mixed $value): void {
		$queryBuilder = $this->queryBuilder;
		if (is_array($value) === true) {
			$queryBuilder->andWhere(
				$queryBuilder->expr()->in(
					$this->columnName,
					$queryBuilder->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)
				)
			);
			return;
		}

		$queryBuilder->andWhere(
			$queryBuilder->expr()->eq($this->columnName, $queryBuilder->createNamedParameter($value))
		);
	}//end equals()

	/**
	 * Filter the column as a schema property of the given type.
	 *
	 * An array property must contain every value; any other property is
	 * compared by value, a PHP boolean in its string form (boolean-filter-values).
	 *
	 * @param mixed  $value        The filter value.
	 * @param string $propertyType The property's type.
	 *
	 * @return void
	 */
	public function matchesProperty(mixed $value, string $propertyType): void {
		if ($propertyType === 'array') {
			// Handle JSON array field filtering using containment operator.
			$this->containsAll(value: $value);
			return;
		}

		$this->equals(value: FilterParams::comparableValue(value: $value, propertyType: $propertyType));
	}//end matchesProperty()

	/**
	 * Require the JSON array column to contain every value (PostgreSQL @>).
	 *
	 * @param mixed $value The filter value (string or array).
	 *
	 * @return void
	 */
	public function containsAll(mixed $value): void {
		// Normalize value to array.
		$values = [$value];
		if (is_array($value) === true) {
			$values = $value;
		}

		// Use AND logic: JSON array must contain ALL specified values.
		$columnCast = $this->queryBuilder->createFunction("{$this->columnName}::jsonb");
		foreach ($values as $v) {
			$paramName = $this->queryBuilder->createNamedParameter(json_encode([$v]));
			$this->queryBuilder->andWhere("{$columnCast} @> {$paramName}");
		}
	}//end containsAll()

	/**
	 * The case-insensitive substring condition of a search on the column.
	 *
	 * Uses LIKE on LOWER() for MariaDB/MySQL and ILIKE for PostgreSQL. The LIKE
	 * parameter is created on every platform.
	 *
	 * @param string           $pattern  The search pattern, with its % wildcards.
	 * @param AbstractPlatform $platform The database platform.
	 *
	 * @return string|IQueryFunction The condition.
	 */
	public function searchCondition(string $pattern, AbstractPlatform $platform): string|IQueryFunction {
		$queryBuilder = $this->queryBuilder;
		$column       = $this->columnName;
		$condition    = $queryBuilder->expr()->like(
			$queryBuilder->createFunction("LOWER($column)"),
			$queryBuilder->createNamedParameter(strtolower($pattern))
		);
		if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform === true) {
			$condition = $queryBuilder->createFunction(
				"LOWER($column) ILIKE LOWER(" . $queryBuilder->createNamedParameter($pattern) . ')'
			);
		}

		return $condition;
	}//end searchCondition()
}//end class
