<?php

/**
 * OpenRegister register-descriptor controller services
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

use OCA\OpenRegister\Service\RegisterDescriptorServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The services of RegisterDescriptorController, bundled so its constructor stays small.
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */
final class RegisterDescriptorControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterDescriptorServiceInterface $descriptors  The inventory and importer.
	 * @param IUserSession                       $userSession  Resolves the caller.
	 * @param IGroupManager                      $groupManager Answers whether they are an administrator.
	 */
	public function __construct(
		public readonly RegisterDescriptorServiceInterface $descriptors,
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
