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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipServiceInterface;

/**
 * The services of the object ownership API.
 *
 * @spec openspec/specs/object-ownership/spec.md
 */
final class ObjectOwnershipControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface          $objectService  Resolves a record through the RBAC boundary.
	 * @param RegisterMapperInterface         $registerMapper Resolves the register entity.
	 * @param SchemaMapperInterface           $schemaMapper   Resolves the schema entity.
	 * @param ObjectOwnershipServiceInterface $ownership      Performs the checked writes.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly ObjectOwnershipServiceInterface $ownership,
	) {
	}//end __construct()
}//end class
