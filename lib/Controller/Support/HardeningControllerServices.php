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

use OCA\OpenRegister\Service\Hardening\ElevationService;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningReportService;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsService;

/**
 * The collaborators of HardeningController, wired by DI autowiring.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */
final class HardeningControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param HardeningReportService $reportService Builds the report.
	 * @param HardeningSettingsService $settingsService Applies a change, or refuses it.
	 * @param HardeningPolicy $policy Reads the floors in force.
	 * @param ElevationService $elevation Guards the administration writes with a fresh sign-in.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
	 */
	public function __construct(
		public readonly HardeningReportService $reportService,
		public readonly HardeningSettingsService $settingsService,
		public readonly HardeningPolicy $policy,
		public readonly ElevationService $elevation,
	) {
	}//end __construct()
}//end class
