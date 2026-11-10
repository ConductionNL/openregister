<?php

/**
 * The services of the file sidebar API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Files\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller\Support;

use OCA\OpenRegister\Files\Service\FileSidebarService;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The sidebar reader, the root folder a caller's file is opened from, the caller and the logger.
 */
final readonly class FileSidebarControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FileSidebarService $fileSidebarService File sidebar service.
	 * @param IRootFolder        $rootFolder         Root folder for per-user file access checks.
	 * @param IUserSession       $userSession        The caller whose file access is checked.
	 * @param LoggerInterface    $logger             Logger.
	 */
	public function __construct(
		public FileSidebarService $fileSidebarService,
		public IRootFolder $rootFolder,
		public IUserSession $userSession,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
