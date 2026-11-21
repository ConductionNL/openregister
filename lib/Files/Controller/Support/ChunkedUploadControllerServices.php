<?php

/**
 * The service bundle of the upload-in-parts API.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Files\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-2-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller\Support;

use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\File\ChunkedUploadService;
use OCA\OpenRegister\Service\FileReaderInterface;
use OCA\OpenRegister\Service\Object\FileWriteGuardInterface;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Bundles what ChunkedUploadController needs.
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-2-2
 */
final class ChunkedUploadControllerServices {

	/**
	 * Bundle the services.
	 *
	 * @param ChunkedUploadService    $uploads      The upload sessions.
	 * @param ObjectFileAccess        $access       The object rule: update on the object to add files.
	 * @param FileWriteGuardInterface $writeGuard   Refuses file writes on a frozen or archived object.
	 * @param IUserSession            $userSession  The signed-in caller.
	 * @param FileReaderInterface     $fileReader   Formats the created file as the file API does.
	 * @param RawRequestBody          $body         The raw body of a part.
	 * @param LoggerInterface         $logger       Logger.
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-2
	 */
	public function __construct(
		public readonly ChunkedUploadService $uploads,
		public readonly ObjectFileAccess $access,
		public readonly FileWriteGuardInterface $writeGuard,
		public readonly IUserSession $userSession,
		public readonly FileReaderInterface $fileReader,
		public readonly RawRequestBody $body,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
