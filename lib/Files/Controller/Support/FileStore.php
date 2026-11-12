<?php

/**
 * OpenRegister file store services
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

namespace OCA\OpenRegister\Files\Controller\Support;

use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\Files\IRootFolder;

/**
 * The file and object services with the root folder and file mapper.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileStore {
	/**
	 * Bundle the services.
	 *
	 * @param FileServiceInterface   $fileService   The file service.
	 * @param ObjectOperationsInterface $objectService The object service.
	 * @param IRootFolder   $rootFolder    The root folder.
	 * @param ?FileMapperInterface   $fileMapper    The file mapper, null when absent.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly FileServiceInterface $fileService,
		public readonly ObjectOperationsInterface $objectService,
		public readonly IRootFolder $rootFolder,
		public readonly ?FileMapperInterface $fileMapper = null,
	) {
	}//end __construct()
}//end class
