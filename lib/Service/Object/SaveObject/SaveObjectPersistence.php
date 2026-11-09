<?php

/**
 * Where a single-object save stores, records and looks things up.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * Where a single-object save stores, records and looks things up.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveObjectPersistence {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SaveObjectMappers $mappers The object, schema and register mappers.
	 * @param SaveObjectRecords $records Audit trail, trail settings, archival metadata and activity stamp.
	 * @param SaveObjectRuntime $runtime Cache, object sources, flow-run context and container.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly SaveObjectMappers $mappers,
		public readonly SaveObjectRecords $records,
		public readonly SaveObjectRuntime $runtime,
	) {
	}//end __construct()
}//end class
