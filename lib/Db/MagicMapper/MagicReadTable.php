<?php

/**
 * MagicReadTable
 *
 * The magic table one read is filtered for: its schema, register and table name.
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

use OCA\OpenRegister\Db\Schema;

/**
 * The magic table one read is filtered for. The schema carries the access
 * rules, the register and schema ids name the table for shared master data,
 * and the table name lets the RBAC and draft predicates leave out a metadata
 * column the table could not be given. Register and table name may be null.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class MagicReadTable
{

	/**
	 * Constructor.
	 *
	 * @param Schema      $schema     The schema of the table.
	 * @param int|null    $registerId The register the table belongs to.
	 * @param string|null $tableName  The bare magic table name.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly ?int $registerId = null,
		public readonly ?string $tableName = null,
	) {
	}//end __construct()

	/**
	 * The register+schema pair the organisation scope is decided for.
	 *
	 * @return OrganisationScopeTable The pair.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function organisationScopeTable(): OrganisationScopeTable {
		return new OrganisationScopeTable(registerId: $this->registerId, schemaId: $this->schema->getId());
	}//end organisationScopeTable()
}//end class
