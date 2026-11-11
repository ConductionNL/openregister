<?php

/**
 * ObjectFilterRun
 *
 * One pass of the object-field filters over a magic-table query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The state the object-field filter helpers of MagicSearchHandler share while
 * they narrow one query on the QueryBuilder path: the filter pass, the
 * schema's declared properties and whether the platform is PostgreSQL
 * (ADR-011 Rule 3, run object). It never leaves MagicSearchHandler.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class ObjectFilterRun
{
	use MagicColumnNameTrait;

	/**
	 * Constructor.
	 *
	 * @param TableFilterRun       $table      The filter pass: query builder, query and table.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 * @param bool                 $isPostgres Whether the active platform is PostgreSQL.
	 */
	public function __construct(
		public readonly TableFilterRun $table,
		public readonly array $properties,
		public readonly bool $isPostgres,
	) {
	}//end __construct()

	/**
	 * The filter on the column of a declared property, or null when the schema does not declare it.
	 *
	 * @param int|string $field The property name.
	 *
	 * @return ObjectFieldFilter|null The field filter, or null for an undeclared property.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function fieldFilter(int|string $field): ?ObjectFieldFilter {
		if (($this->properties[$field] ?? null) === null) {
			return null;
		}

		return new ObjectFieldFilter(
			qb: $this->table->qb,
			columnName: $this->sanitizeColumnName(name: $field),
			propertyType: ($this->properties[$field]['type'] ?? 'string'),
			isPostgres: $this->isPostgres
		);
	}//end fieldFilter()

	/**
	 * The table-aliased column of an object property that a nested path starts from.
	 *
	 * @param string $root The object property.
	 *
	 * @return string The column reference (`t.<column>`).
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
	 */
	public function rootColumn(string $root): string {
		return 't.'.$this->sanitizeColumnName(name: $root);
	}//end rootColumn()
}//end class
