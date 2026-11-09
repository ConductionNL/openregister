<?php

/**
 * The related schema, its table and the alias its rows carry in the subquery.
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

use OCA\OpenRegister\Db\Schema;

/**
 * What `RelatedRowAccess::predicate()` renders the access predicate for.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final readonly class RelatedRowSource {

	/**
	 * Bundle the source.
	 *
	 * @param Schema $schema    The related schema.
	 * @param string $tableName The bare related table, for the row predicate.
	 * @param string $alias     The related row's alias.
	 */
	public function __construct(
		public Schema $schema,
		public string $tableName,
		public string $alias,
	) {
	}//end __construct()
}//end class
