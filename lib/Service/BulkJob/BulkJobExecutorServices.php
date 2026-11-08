<?php

/**
 * OpenRegister BulkJobExecutorServices
 *
 * The selection, action, access and actor collaborators of the bulk job executor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCP\IUserManager;

/**
 * The selection, action, access and actor collaborators of the bulk job executor.
 *
 * Groups collaborators of {@see BulkJobExecutor} so its constructor stays small;
 * the container autowires it.
 */
final class BulkJobExecutorServices {

	/**
	 * Bundle the collaborators.
	 *
	 * @param BulkSelectionResolver      $resolver          Selection resolution.
	 * @param BulkActionRegistry         $registry          The catalogue of registered actions.
	 * @param PermissionCheckInterface $permissionHandler Per-object access checks.
	 * @param IUserManager               $userManager       Actor lookup.
	 */
	public function __construct(
		public readonly BulkSelectionResolver $resolver,
		public readonly BulkActionRegistry $registry,
		public readonly PermissionCheckInterface $permissionHandler,
		public readonly IUserManager $userManager,
	) {
	}//end __construct()
}//end class
