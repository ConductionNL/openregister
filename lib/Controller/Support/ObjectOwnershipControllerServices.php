<?php

/**
 * OpenRegister object ownership controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;

/**
 * The services of the object ownership API.
 *
 * @spec openspec/specs/object-ownership/spec.md
 */
final class ObjectOwnershipControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectService          $objectService  Resolves a record through the RBAC boundary.
	 * @param RegisterMapper         $registerMapper Resolves the register entity.
	 * @param SchemaMapper           $schemaMapper   Resolves the schema entity.
	 * @param ObjectOwnershipService $ownership      Performs the checked writes.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function __construct(
		public readonly ObjectService $objectService,
		public readonly RegisterMapper $registerMapper,
		public readonly SchemaMapper $schemaMapper,
		public readonly ObjectOwnershipService $ownership,
	) {
	}//end __construct()
}//end class
