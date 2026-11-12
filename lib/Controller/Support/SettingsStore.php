<?php

/**
 * OpenRegister settings store
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
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;

/**
 * Services of the settings API.
 *
 * @spec openspec/specs/production-observability/spec.md
 */
final class SettingsStore {
	/**
	 * Bundle the services.
	 *
	 * @param AppSettingsInterface $settingsService The settings service.
	 * @param IAppConfig           $config          The app configuration.
	 * @param IDBConnection        $db              The database connection.
	 * @param ContainerInterface   $container       The container.
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function __construct(
		public readonly AppSettingsInterface $settingsService,
		public readonly IAppConfig $config,
		public readonly IDBConnection $db,
		public readonly ContainerInterface $container,
	) {
	}//end __construct()
}//end class
