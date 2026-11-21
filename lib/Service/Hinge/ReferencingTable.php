<?php

/**
 * ReferencingTable — one register+schema table the reverse view reads, with its register and schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Hinge
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * A candidate table of the reverse view resolved to the register and schema it stores.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final readonly class ReferencingTable {

	/**
	 * Describe the table.
	 *
	 * @param array    $tableInfo The register+schema table descriptor.
	 * @param Register $register  The table's register.
	 * @param Schema   $schema    The table's schema.
	 */
	public function __construct(
		public array $tableInfo,
		public Register $register,
		public Schema $schema,
	) {
	}//end __construct()
}//end class
