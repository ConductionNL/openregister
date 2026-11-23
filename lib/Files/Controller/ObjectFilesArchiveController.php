<?php

/**
 * ObjectFilesArchiveController
 *
 * Serves the download of all files of one object as one ZIP archive.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Files\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFilesArchiveInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The bulk download of an object's files: `GET /api/objects/{register}/{schema}/{id}/files/download`.
 *
 * Moved from ObjectsController::downloadFiles() unchanged: the object is looked up and its
 * access checked first, file storage builds the archive, and one audit entry records the
 * download without ever breaking it.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/file-actions/spec.md
 */
class ObjectFilesArchiveController extends Controller {
	/**
	 * Build the controller.
	 *
	 * @param IRequest                    $request The request.
	 * @param ObjectFilesArchiveInterface $archive Builds the archive and records the download.
	 * @param LoggerInterface             $logger  Logs an audit entry that could not be written.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectFilesArchiveInterface $archive,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Download all files of an object as a ZIP archive
	 *
	 * This method creates a ZIP file containing all files associated with a specific object
	 * and returns it as a downloadable file. The ZIP file includes all files stored in the
	 * object's folder with their original names.
	 *
	 * @param string                    $id            The identifier of the object to download files for
	 * @param string                    $register      The register (identifier or slug) to search within
	 * @param string                    $schema        The schema (identifier or slug) to search within
	 * @param ObjectOperationsInterface $objectService The object service for handling object operations
	 *
	 * @return DataDownloadResponse|JSONResponse Download response or error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function downloadFiles(
		string $id,
		string $register,
		string $schema,
		ObjectOperationsInterface $objectService,
	): JSONResponse|DataDownloadResponse {
		try {
			// Set the context for the object service.
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);

			// Get the object to ensure it exists and we have access.
			$object = WriteCause::asLookup(fn () => $objectService->find(id: $id));

			// Optional: get custom filename from query parameters.
			$customFilename = $this->request->getParam(key: 'filename');

			// Create the ZIP archive.
			$zipInfo = $this->archive->createZip(object: $object, zipName: $customFilename);

			$zipContent = self::takeZipContent(path: $zipInfo['path']);

			$this->auditBulkDownload(object: $object, zipInfo: $zipInfo);

			// Return the ZIP file as a download response.
			return new DataDownloadResponse(
				$zipContent,
				$zipInfo['filename'],
				$zipInfo['mimeType']
			);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (MultipleObjectsReturnedException $exception) {
			return new JSONResponse(
				data: [
					'message' => sprintf('Identifier "%s" matches more than one object in this register and schema', $id),
					'error' => 'ambiguous-identifier',
				],
				statusCode: Http::STATUS_CONFLICT
			);
		} catch (\Exception $exception) {
			return new JSONResponse(
				data: [
					'error' => 'Internal server error',
				],
				statusCode: 500
			);
		}//end try
	}//end downloadFiles()

	/**
	 * Read a temporary ZIP file and remove it.
	 *
	 * @param string $path The file path.
	 *
	 * @return string The content.
	 *
	 * @throws RuntimeException When the file cannot be read.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private static function takeZipContent(string $path): string {
		$zipContent = file_get_contents($path);
		if (file_exists($path) === true) {
			// NOSEMGREP: php.lang.security.unlink-use.unlink-use -- The path is the temporary zip this endpoint built itself.
			unlink($path);
		}

		if ($zipContent === false) {
			throw new RuntimeException('Failed to read ZIP file content');
		}

		return $zipContent;
	}//end takeZipContent()

	/**
	 * Audit a bulk download as ONE entry tied to the parent object.
	 *
	 * Best-effort: an audit-trail failure must not break the download.
	 *
	 * @param ObjectEntity $object  The object whose files were downloaded.
	 * @param array        $zipInfo The ZIP file's name and size.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function auditBulkDownload(ObjectEntity $object, array $zipInfo): void {
		try {
			$this->archive->recordDownload(object: $object, zipInfo: $zipInfo);
		} catch (\Throwable $auditError) {
			// The audit trail must never break the download response.
			$this->logger->warning(
				message: '[ObjectFilesArchiveController] Bulk download audit entry not written',
				context: [
					'zipName' => $zipInfo['filename'],
					'exception' => $auditError->getMessage(),
				]
			);
		}
	}//end auditBulkDownload()
}//end class
