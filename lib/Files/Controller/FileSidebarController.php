<?php

/**
 * FileSidebarController
 *
 * Provides API endpoints for the Nextcloud Files sidebar tabs.
 * Returns OpenRegister object references and extraction metadata
 * for a given Nextcloud file ID.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCA\OpenRegister\Files\Controller\Support\FileSidebarControllerServices;
use OCA\OpenRegister\Files\Service\FileSidebarService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for Files sidebar tab API endpoints.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @psalm-suppress UnusedClass
 */
class FileSidebarController extends Controller {
	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Whether the caller can open a file.
	 *
	 * @var CallerFileAccess
	 */
	private readonly CallerFileAccess $fileAccess;

	/**
	 * File sidebar service.
	 *
	 * @var FileSidebarService
	 */
	private readonly FileSidebarService $fileSidebarService;

	/**
	 * Constructor.
	 *
	 * @param IRequest                                                $request       HTTP request.
	 * @param FileSidebarControllerServices                           $services      The sidebar service, the root folder, the session and the logger.
	 * @param \OCA\OpenRegister\Files\Service\File\FileReadScope|null $fileReadScope The object read rule for an object's file.
	 */
	public function __construct(
		IRequest $request,
		FileSidebarControllerServices $services,
		?\OCA\OpenRegister\Files\Service\File\FileReadScope $fileReadScope = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

		$this->fileSidebarService = $services->fileSidebarService;
		$this->logger = $services->logger;
		$this->fileAccess = new CallerFileAccess(
			fileReadScope: $fileReadScope,
			userSession: $services->userSession,
			rootFolder: $services->rootFolder
		);
	}//end __construct()

	/**
	 * Whether the current session user can access the given Nextcloud file.
	 *
	 * Resolves the file through the caller's own user folder so Nextcloud's
	 * share/permission ACLs apply. A file the user cannot access resolves to
	 * no node — preventing IDOR where a caller inspects object links or
	 * extraction metadata for arbitrary file IDs they do not own.
	 *
	 * @param int $fileId Nextcloud file ID.
	 *
	 * @return bool True when the file is reachable in the caller's user folder.
	 */
	private function hasFileAccess(int $fileId): bool {
		return $this->fileAccess->mayOpen(fileId: $fileId);
	}//end hasFileAccess()

	/**
	 * Get all OpenRegister objects that reference the given file.
	 *
	 * @param int $fileId The Nextcloud file ID.
	 *
	 * @return JSONResponse JSON response with objects array.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 */
	public function getObjectsForFile(int $fileId): JSONResponse {
		// IDOR guard: only reveal object links for files the caller can access.
		// 404 (not 403) so a non-owner cannot probe which file IDs exist.
		if ($this->hasFileAccess(fileId: $fileId) === false) {
			return $this->fileNotFound();
		}

		try {
			return $this->found(data: $this->fileSidebarService->getObjectsForFile($fileId));
		} catch (\Exception $e) {
			$this->logger->error(
				'[FileSidebarController] Error fetching objects for file ' . $fileId . ': ' . $e->getMessage()
			);

			return $this->failed(error: 'Failed to retrieve objects for file.');
		}
	}//end getObjectsForFile()

	/**
	 * Get the extraction status and metadata for the given file.
	 *
	 * @param int $fileId The Nextcloud file ID.
	 *
	 * @return JSONResponse JSON response with extraction data.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 */
	public function getExtractionStatus(int $fileId): JSONResponse {
		// IDOR guard: extraction status reveals chunk counts, entity types and
		// PII risk level. Only expose it for files the caller can access; 404
		// (not 403) so a non-owner cannot probe which file IDs exist.
		if ($this->hasFileAccess(fileId: $fileId) === false) {
			return $this->fileNotFound();
		}

		try {
			return $this->found(data: $this->fileSidebarService->getExtractionStatus($fileId));
		} catch (\Exception $e) {
			$this->logger->error(
				'[FileSidebarController] Error fetching extraction status for file ' . $fileId . ': ' . $e->getMessage()
			);

			return $this->failed(error: 'Failed to retrieve extraction status.');
		}
	}//end getExtractionStatus()

	/**
	 * The refusal of a file the caller cannot reach: 404, not 403, so a non-owner
	 * cannot probe which file IDs exist.
	 *
	 * @return JSONResponse
	 */
	private function fileNotFound(): JSONResponse {
		return new JSONResponse(
			data: ['success' => false, 'error' => 'File not found or access denied'],
			statusCode: 404
		);
	}//end fileNotFound()

	/**
	 * A successful answer carrying the sidebar data.
	 *
	 * @param mixed $data The sidebar data.
	 *
	 * @return JSONResponse
	 */
	private function found(mixed $data): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => true,
				'data' => $data,
			]
		);
	}//end found()

	/**
	 * A 500 answer with a fixed message; the cause is logged, not served.
	 *
	 * @param string $error The message.
	 *
	 * @return JSONResponse
	 */
	private function failed(string $error): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => false,
				'error' => $error,
			],
			statusCode: 500
		);
	}//end failed()
}//end class
