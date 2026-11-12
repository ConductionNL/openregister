<?php

/**
 * OpenRegister EmailLinksController services
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\EmailLinkServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;

/**
 * The collaborators of EmailLinksController, wired by DI autowiring.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
 */
final class EmailLinksControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param EmailLinkServiceInterface $emailLinkService Backing service.
	 * @param ObjectOperationsInterface $objectService OR object resolver.
	 * @param PermissionHandlerInterface $permissions Answers the object update right
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function __construct(
		public readonly EmailLinkServiceInterface $emailLinkService,
		public readonly ObjectOperationsInterface $objectService,
		public readonly PermissionHandlerInterface $permissions,
	) {
	}//end __construct()
}//end class
