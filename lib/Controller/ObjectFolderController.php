<?php

/**
 * ObjectFolderController
 *
 * Browse and change one object's folder, subfolders included, for the files browser.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-a-reader-can-browse-an-objects-folder-without-a-share-that-outlives-the-read-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectFileAccessDeniedException;
use OCA\OpenRegister\Exception\ObjectFolderConflictException;
use OCA\OpenRegister\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\File\ObjectFolderBrowser;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The object's folder through OpenRegister, not through WebDAV.
 *
 * An object's files live in the `openregister` account's home, out of every
 * person's Files app, so a reader has no WebDAV path to them. These endpoints
 * are that path: every call checks the object first, as the signed-in person,
 * exactly as the object file endpoints do. A listing needs read on the object
 * and answers 404 without it; a change needs update and answers 403 to a reader.
 * No Nextcloud share is created, because a share on an object folder is an
 * object grant that would outlive the rule that admitted the reader.
 *
 * Signed-in callers only: none of these is a public page, so Nextcloud refuses
 * an anonymous call before it gets here.
 *
 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-a-reader-can-browse-an-objects-folder-without-a-share-that-outlives-the-read-rule
 */
class ObjectFolderController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string              $appName          The app name.
	 * @param IRequest            $request          The request.
	 * @param ObjectFileAccess    $objectFileAccess The object rule for file actions.
	 * @param ObjectFolderBrowser $browser          The folder tree of one object.
	 * @param IL10N               $l10n             Translates the error messages.
	 * @param LoggerInterface     $logger           Logs an unexpected failure.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectFileAccess $objectFileAccess,
		private readonly ObjectFolderBrowser $browser,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List the object folder, or one of its subfolders.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 * @param string $path     The folder, relative to the object folder; empty for its root.
	 *
	 * @return JSONResponse The listing, with `canChange`; or 400, 404.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-listing-an-objects-folder-and-its-subfolders-follows-the-objects-read-rule
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $register, string $schema, string $id, string $path = ''): JSONResponse {
		try {
			$object = $this->ensureReadable(register: $register, schema: $schema, id: $id);
			$listing = $this->browser->listFolder(object: $object, path: $path);
			$listing['canChange'] = $this->objectFileAccess->mayUpdate(object: $object);

			return new JSONResponse(data: $listing);
		} catch (Throwable $e) {
			return $this->failure(e: $e);
		}
	}//end index()

	/**
	 * Create a folder inside the object folder.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 * @param string $name     The new folder's name.
	 * @param string $path     Where, relative to the object folder; empty for its root.
	 *
	 * @return JSONResponse The new folder (201); or 400, 403, 404, 409.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	#[NoAdminRequired]
	public function create(string $register, string $schema, string $id, string $name = '', string $path = ''): JSONResponse {
		try {
			$object = $this->ensureChangeable(register: $register, schema: $schema, id: $id);

			return new JSONResponse(
				data: $this->browser->createFolder(object: $object, path: $path, name: $name),
				statusCode: 201
			);
		} catch (Throwable $e) {
			return $this->failure(e: $e);
		}
	}//end create()

	/**
	 * Upload files into a folder of the object.
	 *
	 * Takes `files[]` (or a single `file`) as multipart form data. Each file is
	 * stored on its own: a file the pipeline refuses, or whose name is taken,
	 * is named in `rejected` and the rest are still stored.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 * @param string $path     The target folder, relative to the object folder.
	 *
	 * @return JSONResponse 201 with `stored`; 207 with `stored` and `rejected`; 400, 403, 404; 409 when every file was a taken name.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	#[NoAdminRequired]
	public function upload(string $register, string $schema, string $id, string $path = ''): JSONResponse {
		try {
			$object = $this->ensureChangeable(register: $register, schema: $schema, id: $id);
			$uploads = $this->uploadedFiles();
			if ($uploads === []) {
				return new JSONResponse(data: ['error' => $this->l10n->t('No file was uploaded')], statusCode: 400);
			}

			return $this->storeUploads(object: $object, path: $path, uploads: $uploads);
		} catch (Throwable $e) {
			return $this->failure(e: $e);
		}
	}//end upload()

	/**
	 * Rename a file or folder inside the object folder.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 * @param int    $nodeId   The file or folder.
	 * @param string $name     The new name.
	 *
	 * @return JSONResponse The renamed node; or 400, 403, 404, 409.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	#[NoAdminRequired]
	public function rename(string $register, string $schema, string $id, int $nodeId, string $name = ''): JSONResponse {
		try {
			$object = $this->ensureChangeable(register: $register, schema: $schema, id: $id);

			return new JSONResponse(data: $this->browser->rename(object: $object, nodeId: $nodeId, name: $name));
		} catch (Throwable $e) {
			return $this->failure(e: $e);
		}
	}//end rename()

	/**
	 * Delete a file or folder inside the object folder.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 * @param int    $nodeId   The file or folder.
	 *
	 * @return JSONResponse `{deleted: true}`; or 403, 404, 409.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	#[NoAdminRequired]
	public function destroy(string $register, string $schema, string $id, int $nodeId): JSONResponse {
		try {
			$object = $this->ensureChangeable(register: $register, schema: $schema, id: $id);
			$this->browser->delete(object: $object, nodeId: $nodeId);

			return new JSONResponse(data: ['deleted' => true]);
		} catch (Throwable $e) {
			return $this->failure(e: $e);
		}
	}//end destroy()

	/**
	 * The object, when the signed-in person may read it.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @throws ObjectFileAccessDeniedException With 404 when they may not read it.
	 */
	private function ensureReadable(string $register, string $schema, string $id): ObjectEntity {
		return $this->objectFileAccess->readable(register: $register, schema: $schema, id: $id);
	}//end ensureReadable()

	/**
	 * The object, when the signed-in person may update it.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @throws ObjectFileAccessDeniedException With 404 without read, 403 with read but without update.
	 */
	private function ensureChangeable(string $register, string $schema, string $id): ObjectEntity {
		return $this->objectFileAccess->changeable(register: $register, schema: $schema, id: $id);
	}//end ensureChangeable()

	/**
	 * Store each upload on its own and answer for the batch.
	 *
	 * @param ObjectEntity                                    $object  The object.
	 * @param string                                          $path    The target folder.
	 * @param list<array{name: string, tmp_name: string, error: int}> $uploads The uploads.
	 *
	 * @return JSONResponse 201, 207, 409 or 400.
	 */
	private function storeUploads(ObjectEntity $object, string $path, array $uploads): JSONResponse {
		$stored = [];
		$rejected = [];
		$conflicts = 0;
		foreach ($uploads as $upload) {
			try {
				$stored[] = $this->storeUpload(object: $object, path: $path, upload: $upload);
			} catch (ObjectFolderConflictException $e) {
				$conflicts++;
				$rejected[] = ['name' => $upload['name'], 'error' => $this->l10n->t('A file or folder with that name is already here')];
			} catch (InvalidArgumentException | NotFoundException | ObjectFileAccessDeniedException $e) {
				// The path itself is wrong: no file of this batch can land.
				throw $e;
			} catch (Throwable $e) {
				$rejected[] = ['name' => $upload['name'], 'error' => $e->getMessage()];
			}
		}

		if ($stored === []) {
			$status = 400;
			if ($conflicts === count($uploads)) {
				$status = 409;
			}

			return new JSONResponse(
				data: ['error' => $rejected[0]['error'], 'stored' => [], 'rejected' => $rejected],
				statusCode: $status
			);
		}

		$status = 201;
		if ($rejected !== []) {
			$status = 207;
		}

		return new JSONResponse(data: ['stored' => $stored, 'rejected' => $rejected], statusCode: $status);
	}//end storeUploads()

	/**
	 * Store one upload.
	 *
	 * @param ObjectEntity                                    $object The object.
	 * @param string                                          $path   The target folder.
	 * @param array{name: string, tmp_name: string, error: int} $upload The upload.
	 *
	 * @return array<string, mixed> The stored file, as a listing entry.
	 *
	 * @throws \Exception When the upload failed or the pipeline refused it.
	 */
	private function storeUpload(ObjectEntity $object, string $path, array $upload): array {
		if ($upload['error'] !== UPLOAD_ERR_OK || is_readable($upload['tmp_name']) === false) {
			throw new \Exception($this->l10n->t('The upload failed'));
		}

		// A stream, so the bytes are stored as sent: no data-URI or base64
		// guessing, which the string path of the pipeline does.
		$stream = fopen($upload['tmp_name'], 'r');
		if ($stream === false) {
			throw new \Exception($this->l10n->t('The upload failed'));
		}

		try {
			return $this->browser->upload(object: $object, path: $path, name: $upload['name'], content: $stream);
		} finally {
			if (is_resource($stream) === true) {
				fclose($stream);
			}
		}
	}//end storeUpload()

	/**
	 * The uploaded files, from `files[]` and `file`, one entry each.
	 *
	 * @return list<array{name: string, tmp_name: string, error: int}> The uploads.
	 */
	private function uploadedFiles(): array {
		$uploads = [];
		foreach (['files', 'file'] as $field) {
			$raw = $this->request->getUploadedFile($field);
			if (is_array($raw) === false || isset($raw['name']) === false) {
				continue;
			}

			$names = (array)$raw['name'];
			$tmpNames = (array)($raw['tmp_name'] ?? []);
			$errors = (array)($raw['error'] ?? []);
			foreach ($names as $index => $name) {
				$uploads[] = [
					'name' => (string)$name,
					'tmp_name' => (string)($tmpNames[$index] ?? ''),
					'error' => (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE),
				];
			}
		}

		return $uploads;
	}//end uploadedFiles()

	/**
	 * Answer a failed call.
	 *
	 * A person who may not read the object gets 404, the answer an object read
	 * gives, so a refusal never confirms the object exists; a reader who may not
	 * change it gets 403. A path that could leave the folder or a bad name is
	 * 400, a path or node not in the folder is 404, a taken name or a locked file
	 * is 409. Anything else is a 500 that names nothing internal.
	 *
	 * @param Throwable $e The failure.
	 *
	 * @return JSONResponse The error response.
	 */
	private function failure(Throwable $e): JSONResponse {
		if ($e instanceof ObjectFileAccessDeniedException === true) {
			return $this->accessDenied(e: $e);
		}

		if ($e instanceof InvalidArgumentException === true) {
			$message = $this->l10n->t('This name cannot be used');
			if ($e->getCode() === ObjectFolderBrowser::BAD_PATH) {
				$message = $this->l10n->t('The path is not a folder of this object');
			}

			return new JSONResponse(data: ['error' => $message], statusCode: 400);
		}

		if ($e instanceof NotFoundException === true) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not found')], statusCode: 404);
		}

		if ($e instanceof ObjectFolderConflictException === true) {
			$message = $this->l10n->t('A file or folder with that name is already here');
			if ($e->getCode() === ObjectFolderBrowser::LOCKED) {
				$message = $this->l10n->t('A file in this folder is locked by someone else');
			}

			return new JSONResponse(data: ['error' => $message], statusCode: 409);
		}

		// Logged in full here, answered without detail.
		$this->logger->error(
			message: '[ObjectFolderController] ' . $e->getMessage(),
			context: ['exception' => $e]
		);

		return new JSONResponse(data: ['error' => $this->l10n->t('Internal server error')], statusCode: 500);
	}//end failure()

	/**
	 * Answer a refused object.
	 *
	 * @param ObjectFileAccessDeniedException $e The refusal.
	 *
	 * @return JSONResponse 404 without read, 403 with read but without update.
	 */
	private function accessDenied(ObjectFileAccessDeniedException $e): JSONResponse {
		if ($e->getHttpStatus() === ObjectFileAccessDeniedException::NOT_READABLE) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Object not found')], statusCode: 404);
		}

		return new JSONResponse(
			data: ['error' => $this->l10n->t('You do not have access to this object')],
			statusCode: 403
		);
	}//end accessDenied()
}//end class
