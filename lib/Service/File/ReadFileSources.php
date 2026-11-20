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
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFileRecordsInterface;
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
	 * @param ObjectFileRecordsInterface|null $fileMapper   Optional OR-side file records for category-based filtering.
	 */
	public function __construct(
		public IRootFolder $rootFolder,
		public FolderManagementHandler $folderMgmtHandler,
		public MagicObjectFinderInterface $objectMapper,
		public ?ObjectFileRecordsInterface $fileMapper = null,
	) {
	}//end __construct()
}//end class
