<?php

/**
 * TableTermsFacet
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
 * One terms facet counted over a single magic table.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
final class TableTermsFacet {

	/**
	 * Constructor.
	 *
	 * @param string   $tableName  The magic table, without the table prefix
	 * @param string   $field      The column the facet counts
	 * @param array    $baseQuery  The search filters, without the facet configuration
	 * @param bool     $isMetadata Whether the column is a metadata column
	 * @param Register $register   The register the table belongs to
	 * @param Schema   $schema     The schema the table belongs to
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(
		public readonly string $tableName,
		public readonly string $field,
		public readonly array $baseQuery,
		public readonly bool $isMetadata,
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * The same table facet on a metadata column.
	 *
	 * @param string $field The metadata column.
	 *
	 * @return self The facet on that column.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function forMetadataField(string $field): self {
		return new self(
			tableName: $this->tableName,
			field: $field,
			baseQuery: $this->baseQuery,
			isMetadata: true,
			register: $this->register,
			schema: $this->schema
		);
	}//end forMetadataField()

	/**
	 * The same table facet on a property column.
	 *
	 * @param string $field The property column.
	 *
	 * @return self The facet on that column.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function forPropertyField(string $field): self {
		return new self(
			tableName: $this->tableName,
			field: $field,
			baseQuery: $this->baseQuery,
			isMetadata: false,
			register: $this->register,
			schema: $this->schema
		);
	}//end forPropertyField()
}//end class
