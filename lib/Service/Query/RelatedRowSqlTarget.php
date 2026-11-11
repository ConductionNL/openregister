<?php

/**
 * Where a related-row subquery reads from and joins to, and in which SQL dialect.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

/**
 * The engine, the related table, the outer row's alias and the storage shape of the related rows.
 *
 * Shared by every clause of one query; what differs per clause (the inner
 * alias, its access predicate, its placeholder prefix) is a
 * `RelatedRowInnerRow`.
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */
final readonly class RelatedRowSqlTarget {

	/**
	 * Bundle the target.
	 *
	 * @param string $engine     The database engine (`RelatedRowExistsClause::ENGINE_*`).
	 * @param string $table      The related table as SQL names it, `*PREFIX*`-qualified.
	 * @param string $outerAlias The alias of the outer object row.
	 * @param string $storage    Whether the related rows are JSON in the objects table or columns in a magic table.
	 */
	public function __construct(
		public string $engine,
		public string $table,
		public string $outerAlias,
		public string $storage = RelatedRowExistsClause::STORAGE_JSON,
	) {
	}//end __construct()
}//end class
