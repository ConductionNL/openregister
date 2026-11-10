<?php

/**
 * OpenRegister operations-maintenance controller services
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

use OCA\OpenRegister\Service\Operations\MaintenanceModeServiceInterface;
use OCA\OpenRegister\Service\Operations\SupportBundleServiceInterface;

/**
 * The services of OperationsMaintenanceController, bundled so its constructor stays small.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
 */
final class OperationsMaintenanceControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param MaintenanceModeServiceInterface $maintenance Maintenance mode.
	 * @param SupportBundleServiceInterface   $bundle      The support bundle and the instance facts.
	 */
	public function __construct(
		public readonly MaintenanceModeServiceInterface $maintenance,
		public readonly SupportBundleServiceInterface $bundle,
	) {
	}//end __construct()
}//end class
