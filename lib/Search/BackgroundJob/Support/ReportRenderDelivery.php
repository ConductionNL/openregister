<?php

/**
 * OpenRegister report render delivery
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Search\BackgroundJob\Support
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

namespace OCA\OpenRegister\Search\BackgroundJob\Support;

use OCA\OpenRegister\Search\Service\Reporting\ReportRenderService;
use OCP\Files\IRootFolder;

/**
 * What renders a dashboard and where the result is delivered.
 *
 * @spec openspec/specs/rapportage-bi-export/spec.md
 */
final class ReportRenderDelivery {
	/**
	 * Bundle the services.
	 *
	 * @param ReportRenderService $renderService Render composer.
	 * @param IRootFolder         $rootFolder    Files root.
	 *
	 * @spec openspec/specs/rapportage-bi-export/spec.md
	 */
	public function __construct(
		public readonly ReportRenderService $renderService,
		public readonly IRootFolder $rootFolder,
	) {
	}//end __construct()
}//end class
