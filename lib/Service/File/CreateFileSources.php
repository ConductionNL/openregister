<?php

/**
 * The places a file create writes to.
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

use OCA\OpenRegister\Db\MagicMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * The object folders and objects a file create writes to, and the logger.
 */
final readonly class CreateFileSources {
	/**
	 * Bundle the services.
	 *
	 * @param FolderManagementHandler $folderMgmtHandler  Folder management handler.
	 * @param MagicMapperInterface    $objectEntityMapper Object entity mapper.
	 * @param LoggerInterface         $logger             Logger for logging operations.
	 */
	public function __construct(
		public FolderManagementHandler $folderMgmtHandler,
		public MagicMapperInterface $objectEntityMapper,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
