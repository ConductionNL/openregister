<?php

/**
 * OpenRegister HardeningController services
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

use OCA\OpenRegister\Service\Hardening\ElevationServiceInterface;
use OCA\OpenRegister\Service\Hardening\HardeningFloorsInterface;
use OCA\OpenRegister\Service\Hardening\HardeningReportServiceInterface;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsServiceInterface;

/**
 * The collaborators of HardeningController, wired by DI autowiring.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */
final class HardeningControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param HardeningReportServiceInterface $reportService Builds the report.
	 * @param HardeningSettingsServiceInterface $settingsService Applies a change, or refuses it.
	 * @param HardeningFloorsInterface $policy Reads the floors in force.
	 * @param ElevationServiceInterface $elevation Guards the administration writes with a fresh sign-in.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
	 */
	public function __construct(
		public readonly HardeningReportServiceInterface $reportService,
		public readonly HardeningSettingsServiceInterface $settingsService,
		public readonly HardeningFloorsInterface $policy,
		public readonly ElevationServiceInterface $elevation,
	) {
	}//end __construct()
}//end class
