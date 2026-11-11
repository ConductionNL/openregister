<?php

/**
 * The Tables services the Tables object source provider reads through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * The guarded reader, the row mapper, the uuid derivation and the managed-schema lookup.
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */
final readonly class TablesProviderParts {

	/**
	 * Bundle the collaborators.
	 *
	 * @param TablesTableReader       $reader       Guarded gateway to Tables services.
	 * @param TablesColumnMapper      $columnMapper Column/row → property projection.
	 * @param TablesUuidDeriver       $uuidDeriver  Deterministic object-uuid derivation.
	 * @param TablesSchemaSyncService $syncService  Managed-schema lookup (relation targets).
	 */
	public function __construct(
		public TablesTableReader $reader,
		public TablesColumnMapper $columnMapper,
		public TablesUuidDeriver $uuidDeriver,
		public TablesSchemaSyncService $syncService,
	) {
	}//end __construct()
}//end class
