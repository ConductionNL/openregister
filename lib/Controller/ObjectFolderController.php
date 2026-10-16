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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectFileAccessDeniedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\File\ObjectFolderBrowser;
use OCA\OpenRegister\Service\File\ObjectFolderResponder;
use OCA\OpenRegister\Service\File\ObjectFolderUploads;
use OCA\OpenRegister\Service\Object\FileWriteGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * The object's folder through OpenRegister, not through WebDAV.
 *
 * An object's files live in the `openregister` account's home, out of every
 * person's Files app, so a reader has no WebDAV path to them. These endpoints
 * are that path: every call checks the object first, as the signed-in person,
 * exactly as the object file endpoints do. A listing needs read on the object
 * and answers 404 without it; a change needs update and answers 403 to a reader,
 * and 409 when the object is frozen or archived (REQ-OAS-007).
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
	 * @param ObjectFolderBrowser   $browser          The folder tree of one object.
	 * @param ObjectFolderUploads   $uploads          Stores a batch of uploads.
	 * @param ObjectFolderResponder $responder        Turns outcomes and failures into responses.
	 * @param FileWriteGuard        $fileWriteGuard   Refuses a change to the files of a frozen or archived object.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectFileAccess $objectFileAccess,
		private readonly ObjectFolderBrowser $browser,
		private readonly ObjectFolderUploads $uploads,
		private readonly ObjectFolderResponder $responder,
		private readonly FileWriteGuard $fileWriteGuard,
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
			return $this->responder->failure(e: $e);
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
			return $this->responder->failure(e: $e);
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
				return $this->responder->noUpload();
			}

			return $this->responder->uploaded(
				outcome: $this->uploads->uploadAll(object: $object, path: $path, uploads: $uploads),
				total: count($uploads)
			);
		} catch (Throwable $e) {
			return $this->responder->failure(e: $e);
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
			return $this->responder->failure(e: $e);
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
			return $this->responder->failure(e: $e);
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
	 * @throws ObjectStateWriteException        When the object is frozen or archived (answered 409, REQ-OAS-007).
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
	 */
	private function ensureChangeable(string $register, string $schema, string $id): ObjectEntity {
		$object = $this->objectFileAccess->changeable(register: $register, schema: $schema, id: $id);

		// Asked after the access rule, so a caller without access still gets
		// 404 or 403 and learns nothing of the state; the same order the files
		// API uses (FilesController::ensureObjectAccess()).
		$this->fileWriteGuard->assertWritable(object: $object);

		return $object;
	}//end ensureChangeable()

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
}//end class
