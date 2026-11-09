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
	 * The magic table, without the table prefix.
	 *
	 * @var string
	 */
	public readonly string $tableName;

	/**
	 * The search filters, without the facet configuration.
	 *
	 * @var array
	 */
	public readonly array $baseQuery;

	/**
	 * The register the table belongs to.
	 *
	 * @var Register
	 */
	public readonly Register $register;

	/**
	 * The schema the table belongs to.
	 *
	 * @var Schema
	 */
	public readonly Schema $schema;

	/**
	 * Constructor.
	 *
	 * Built by TermsFacetTable::forMetadataField() and ::forPropertyField().
	 *
	 * @param TermsFacetTable $table      The table and its filters
	 * @param string          $field      The column the facet counts
	 * @param bool            $isMetadata Whether the column is a metadata column
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(
		TermsFacetTable $table,
		public readonly string $field,
		public readonly bool $isMetadata,
	) {
		$this->tableName = $table->tableName;
		$this->baseQuery = $table->baseQuery;
		$this->register  = $table->register;
		$this->schema    = $table->schema;
	}//end __construct()
}//end class
