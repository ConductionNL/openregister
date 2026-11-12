<?php

/**
 * The places a file read looks in.
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

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCP\Files\IRootFolder;

/**
 * The root folder, the object folders, the objects and the OR-side file metadata a file read looks in.
 */
final readonly class ReadFileSources {
	/**
	 * Bundle the services.
	 *
	 * @param IRootFolder                $rootFolder        Root folder for file operations.
	 * @param FolderManagementHandler    $folderMgmtHandler Folder management handler.
	 * @param MagicObjectFinderInterface $objectMapper      Object mapper for magic table operations.
	 * @param FileMapperInterface|null   $fileMapper        Optional OR-side metadata mapper for category-based filtering.
	 */
	public function __construct(
		public IRootFolder $rootFolder,
		public FolderManagementHandler $folderMgmtHandler,
		public MagicObjectFinderInterface $objectMapper,
		public ?FileMapperInterface $fileMapper = null,
	) {
	}//end __construct()
}//end class
