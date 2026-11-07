<?php

/**
 * OpenRegister edepot settings platform
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

use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCP\IAppConfig;

/**
 * Services of the edepot settings API.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
 */
final class EdepotSettingsPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param ConnectionReporter $connectionReporter Reports connection test outcomes to integriq's connection registry.
	 * @param IAppConfig         $appConfig          The app configuration.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function __construct(
		public readonly ConnectionReporter $connectionReporter,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
