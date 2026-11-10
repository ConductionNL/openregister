<?php

/**
 * OpenRegister CacheSettingsController services
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

use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of CacheSettingsController, wired by DI autowiring.
 *
 * @spec openspec/specs/production-observability/spec.md
 */
final class CacheSettingsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param AppSettingsInterface $settingsService Settings service.
	 * @param LoggerInterface $logger Logger.
	 * @param IAppConfig $appConfig App configuration.
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function __construct(
		public readonly AppSettingsInterface $settingsService,
		public readonly LoggerInterface $logger,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
