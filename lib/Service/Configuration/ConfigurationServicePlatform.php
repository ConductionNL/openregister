<?php

/**
 * The platform services of the configuration service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
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

namespace OCA\OpenRegister\Service\Configuration;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The app manager, container, app config and logger the configuration service works with.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationServicePlatform {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IAppManager        $appManager App manager for checking installed apps
	 * @param ContainerInterface $container  Container for lazy service loading
	 * @param IAppConfig         $appConfig  App config for configuration metadata
	 * @param LoggerInterface    $logger     Logger for error tracking
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly ContainerInterface $container,
		public readonly IAppConfig $appConfig,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
