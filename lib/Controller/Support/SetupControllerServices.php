<?php

/**
 * OpenRegister SetupController services
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

use OCA\OpenRegister\Service\DemoDataServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of SetupController, wired by DI autowiring.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
final class SetupControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param IAppConfig               $appConfig       Records the demo-data decision.
	 * @param LoggerInterface          $logger          Records a failed import.
	 * @param DemoDataServiceInterface $demoDataService Imports the shipped demo dataset.
	 */
	public function __construct(
		public readonly IAppConfig $appConfig,
		public readonly LoggerInterface $logger,
		public readonly DemoDataServiceInterface $demoDataService,
	) {
	}//end __construct()
}//end class
