<?php

/**
 * OpenRegister migration mapping
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * What a migration resolves the addresses and maps the data with.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MigrationMapping {
	/**
	 * Bundle the services.
	 *
	 * @param UtilityHandler          $utilityHandler   Handler for utility operations.
	 * @param DataManipulationHandler $dataManipHandler Handler for data manipulation.
	 * @param SaveObjectRelationScannerInterface     $relationScanner  Finds the relations in the mapped data.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly UtilityHandler $utilityHandler,
		public readonly DataManipulationHandler $dataManipHandler,
		public readonly SaveObjectRelationScannerInterface $relationScanner,
	) {
	}//end __construct()
}//end class
