<?php

/**
 * The platform services file versioning reads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use Psr\Container\ContainerInterface;

/**
 * The root folder, the app manager and the container the Files_Versions manager is resolved from.
 */
final readonly class FileVersionsPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IRootFolder        $rootFolder Root folder for file access.
	 * @param IAppManager        $appManager App manager to check if files_versions is enabled.
	 * @param ContainerInterface $container  App container the Files_Versions manager is resolved from.
	 */
	public function __construct(
		public IRootFolder $rootFolder,
		public IAppManager $appManager,
		public ContainerInterface $container,
	) {
	}//end __construct()
}//end class
