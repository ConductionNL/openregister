<?php

/**
 * OpenRegister app platform services
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

use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * App configuration, the app manager and the DI container.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class AppPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IAppConfig         $config     The app configuration.
	 * @param IAppManager        $appManager The app manager.
	 * @param ContainerInterface $container  The DI container.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly IAppConfig $config,
		public readonly IAppManager $appManager,
		public readonly ContainerInterface $container,
	) {
	}//end __construct()
}//end class
