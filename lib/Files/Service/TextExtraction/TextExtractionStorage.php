<?php

/**
 * TextExtractionStorage: the stores TextExtractionService reads files from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;

/**
 * The stores TextExtractionService reads files from.
 */
final class TextExtractionStorage {
	/**
	 * Bundle the services.
	 *
	 * @param FileMapperInterface $fileMapper Mapper for Nextcloud files
	 * @param IRootFolder         $rootFolder Nextcloud root folder
	 * @param IDBConnection       $db         Database connection
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $db is a well-known PHP idiom for a database connection parameter.
	 */
	public function __construct(
		public readonly FileMapperInterface $fileMapper,
		public readonly IRootFolder $rootFolder,
		public readonly IDBConnection $db,
	) {
	}//end __construct()
}//end class
