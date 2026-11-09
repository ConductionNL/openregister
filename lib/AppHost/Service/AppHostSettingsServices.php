<?php

/**
 * OpenRegister AppHost — settings service platform bundle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
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

namespace OCA\OpenRegister\AppHost\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The platform services an AppHost settings service reads a leaf app's
 * configuration through: its config store, the app manager, the leaf's
 * container and the logger.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.1
 */
final class AppHostSettingsServices {
	/**
	 * Bundle the services.
	 *
	 * @param IAppConfig         $appConfig  App config store.
	 * @param IAppManager        $appManager App manager (OR availability).
	 * @param ContainerInterface $container  DI container (lazy OR service resolution).
	 * @param LoggerInterface    $logger     PSR logger.
	 */
	public function __construct(
		public readonly IAppConfig $appConfig,
		public readonly IAppManager $appManager,
		public readonly ContainerInterface $container,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
