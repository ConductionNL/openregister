<?php

/**
 * SearchLeafScope
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

use OCA\OpenRegister\Db\Schema;

/**
 * What every term of one `_search` is matched against: the schema's columns,
 * the platform and the fuzzy flag.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
final class SearchLeafScope {

	/**
	 * Whether `_fuzzy=true` was requested and pg_trgm is present.
	 *
	 * @var boolean
	 */
	public bool $fuzzyEnabled = false;

	/**
	 * Connection for value quoting on the UNION path; null on the QueryBuilder path.
	 *
	 * @var object|null
	 */
	public ?object $connection = null;

	/**
	 * The columns the table has, or null for every column.
	 *
	 * @var array<int, string>|null
	 */
	public ?array $existingColumns = null;

	/**
	 * Constructor.
	 *
	 * @param Schema $schema     Schema for determining searchable columns
	 * @param bool   $isPostgres Whether the active platform is PostgreSQL
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly bool $isPostgres,
	) {
	}//end __construct()

	/**
	 * One term with the SQL it has always produced.
	 *
	 * @param string $pattern The LIKE pattern, unquoted
	 * @param string $literal The literal text, for the fuzzy arm
	 *
	 * @return SearchLeaf The term in this scope.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function term(string $pattern, string $literal): SearchLeaf {
		return new SearchLeaf(scope: $this, pattern: $pattern, literal: $literal, nullSafe: false);
	}//end term()

	/**
	 * One leaf of a parsed term, with every column wrapped in COALESCE so NOT can negate it.
	 *
	 * @param string $pattern The LIKE pattern, unquoted
	 * @param string $literal The literal text, for the fuzzy arm
	 *
	 * @return SearchLeaf The term in this scope.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function nullSafeTerm(string $pattern, string $literal): SearchLeaf {
		return new SearchLeaf(scope: $this, pattern: $pattern, literal: $literal, nullSafe: true);
	}//end nullSafeTerm()

	/**
	 * Whether a column takes part in this search.
	 *
	 * In UNION contexts only the columns that actually exist in the table are searched.
	 *
	 * @param string $columnName The sanitized column name
	 *
	 * @return bool True when the column is searched.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function hasColumn(string $columnName): bool {
		return $this->existingColumns === null || in_array($columnName, $this->existingColumns, true) === true;
	}//end hasColumn()
}//end class
