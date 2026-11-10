<?php

/**
 * The platform services of the file service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
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

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Files\Service\File\ShareUrlBuilderInterface;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The logger, root folder, share link builder and user session the file service works with.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileServicePlatform {
	/**
	 * Bundle the collaborators.
	 *
	 * @param LoggerInterface          $logger      Logger
	 * @param IRootFolder              $rootFolder  Root folder
	 * @param ShareUrlBuilderInterface $shareUrls   Share link builder
	 * @param IUserSession             $userSession User session
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly IRootFolder $rootFolder,
		public readonly ShareUrlBuilderInterface $shareUrls,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
