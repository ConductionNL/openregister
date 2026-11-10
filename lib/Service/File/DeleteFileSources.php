<?php

/**
 * The places a file delete looks in.
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

use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * The root folder and the file reader a delete finds its file with, and the logger.
 */
final readonly class DeleteFileSources {
	/**
	 * Bundle the services.
	 *
	 * @param IRootFolder     $rootFolder      Root folder for file operations.
	 * @param ReadFileHandler $readFileHandler Read file handler.
	 * @param LoggerInterface $logger          Logger for logging operations.
	 */
	public function __construct(
		public IRootFolder $rootFolder,
		public ReadFileHandler $readFileHandler,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
