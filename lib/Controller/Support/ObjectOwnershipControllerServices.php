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

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * The services of the object ownership API.
 *
 * @spec openspec/specs/object-ownership/spec.md
 */
final class ObjectOwnershipControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface       $objectService Resolves an object through the RBAC boundary.
	 * @param SchemaCatalogInterface          $catalog       Resolves the register and schema entities.
	 * @param ObjectOwnershipServiceInterface $ownership     Performs the checked writes.
	 * @param LoggerInterface                 $logger        Records unexpected failures.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly SchemaCatalogInterface $catalog,
		public readonly ObjectOwnershipServiceInterface $ownership,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
