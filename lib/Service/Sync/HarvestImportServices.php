<?php

/**
 * OpenRegister Harvest Import Services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\MappingService;

/**
 * What the import stage of the harvest pipeline works with: mapping, persistence and conflict resolution.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final readonly class HarvestImportServices {

	/**
	 * Bundle the collaborators.
	 *
	 * @param MappingMapperInterface    $mappingMapper    Resolves the configured Mapping entity.
	 * @param MappingService            $mappingService   Applies Twig field transformation.
	 * @param ObjectOperationsInterface $objectService    Validates + persists target objects.
	 * @param SyncConflictResolver      $conflictResolver Decides source/local conflicts.
	 */
	public function __construct(
		public MappingMapperInterface $mappingMapper,
		public MappingService $mappingService,
		public ObjectOperationsInterface $objectService,
		public SyncConflictResolver $conflictResolver,
	) {
	}//end __construct()
}//end class
