<?php

/**
 * OpenRegister object sharing controller services
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
use OCA\OpenRegister\Service\Rbac\ObjectSharingServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * The services of the object sharing API.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
final class ObjectSharingControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface       $objectService Resolves an object through the RBAC boundary.
	 * @param SchemaCatalogInterface          $catalog       Resolves the register and schema entities.
	 * @param ObjectSharingServiceInterface   $sharing       Performs the checked writes.
	 * @param LoggerInterface                 $logger        Records unexpected failures.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly SchemaCatalogInterface $catalog,
		public readonly ObjectSharingServiceInterface $sharing,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
