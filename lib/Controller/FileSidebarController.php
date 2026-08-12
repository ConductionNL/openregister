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

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\FileSidebarService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
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
	 * Constructor.
	 *
	 * @param string $appName Application name.
	 * @param IRequest $request HTTP request.
	 * @param FileSidebarService $fileSidebarService File sidebar service.
	 * @param LoggerInterface $logger Logger.
	 * @param IRootFolder $rootFolder Root folder for per-user file access checks.
	 * @param IUserSession $userSession Active user session for caller identity.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FileSidebarService $fileSidebarService,
		private readonly LoggerInterface $logger,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
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
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		return empty($userFolder->getById($fileId)) === false;
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
			return new JSONResponse(
				data: ['success' => false, 'error' => 'File not found or access denied'],
				statusCode: 404
			);
		}

		try {
			$objects = $this->fileSidebarService->getObjectsForFile($fileId);

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => $objects,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'[FileSidebarController] Error fetching objects for file ' . $fileId . ': ' . $e->getMessage()
			);

			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Failed to retrieve objects for file.',
				],
				statusCode: 500
			);
		}//end try
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
			return new JSONResponse(
				data: ['success' => false, 'error' => 'File not found or access denied'],
				statusCode: 404
			);
		}

		try {
			$status = $this->fileSidebarService->getExtractionStatus($fileId);

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => $status,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'[FileSidebarController] Error fetching extraction status for file ' . $fileId . ': ' . $e->getMessage()
			);

			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Failed to retrieve extraction status.',
				],
				statusCode: 500
			);
		}//end try
	}//end getExtractionStatus()
}//end class
