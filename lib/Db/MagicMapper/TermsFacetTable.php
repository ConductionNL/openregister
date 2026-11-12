<?php

/**
 * TermsFacetTable
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The magic table a terms facet is counted over, with the search filters,
 * before the facet's column is named.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
final class TermsFacetTable {

	/**
	 * Constructor.
	 *
	 * @param string   $tableName The magic table, without the table prefix
	 * @param array    $baseQuery The search filters, without the facet configuration
	 * @param Register $register  The register the table belongs to
	 * @param Schema   $schema    The schema the table belongs to
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(
		public readonly string $tableName,
		public readonly array $baseQuery,
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * The terms facet of this table on a metadata column.
	 *
	 * @param string $field The metadata column.
	 *
	 * @return TableTermsFacet The facet on that column.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function forMetadataField(string $field): TableTermsFacet {
		return new TableTermsFacet(table: $this, field: $field, isMetadata: true);
	}//end forMetadataField()

	/**
	 * The terms facet of this table on a property column.
	 *
	 * @param string $field The property column.
	 *
	 * @return TableTermsFacet The facet on that column.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function forPropertyField(string $field): TableTermsFacet {
		return new TableTermsFacet(table: $this, field: $field, isMetadata: false);
	}//end forPropertyField()
}//end class
