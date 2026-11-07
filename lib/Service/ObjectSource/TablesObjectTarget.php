<?php

/**
 * Where the virtual objects of one Tables read belong.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema a Tables read serves, with its read scope.
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */
final class TablesObjectTarget {

	/**
	 * Constructor.
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The sourced schema.
	 * @param TablesReadScope $scope The read scope.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly TablesReadScope $scope,
	) {
	}//end __construct()
}//end class
