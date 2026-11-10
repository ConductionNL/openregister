<?php

/**
 * OpenRegister connection reporting for settings controllers
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

use OCA\OpenRegister\Service\Connection\ConnectionReporterInterface;
use OCP\IAppConfig;

/**
 * App config plus the connection reporter: what a settings controller that tests a connection needs.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
 */
final class ConnectionReporting {
	/**
	 * Bundle the services.
	 *
	 * @param ConnectionReporterInterface $connectionReporter Reports connection test outcomes to integriq's connection registry.
	 * @param IAppConfig         $appConfig          The app configuration.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function __construct(
		public readonly ConnectionReporterInterface $connectionReporter,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
