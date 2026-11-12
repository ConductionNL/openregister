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

namespace OCA\OpenRegister\Files\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Files\Controller\Support\ObjectFolderControllerServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ObjectFileAccessDeniedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Files\Service\File\ObjectFolderBrowser;
use OCA\OpenRegister\Files\Service\File\ObjectFolderPath;
use OCA\OpenRegister\Files\Service\File\ObjectFolderResponder;
use OCA\OpenRegister\Files\Service\File\ObjectFolderUploads;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Object\FileWriteGuardInterface;
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
	 * The object rule for file actions.
	 *
	 * @var ObjectFileAccess
	 */
	private readonly ObjectFileAccess $objectFileAccess;

	/**
	 * The folder tree of one object.
	 *
	 * @var ObjectFolderBrowser
	 */
	private readonly ObjectFolderBrowser $browser;

	/**
	 * Turns outcomes and failures into responses.
	 *
	 * @var ObjectFolderResponder
	 */
	private readonly ObjectFolderResponder $responder;

	/**
	 * The controller's injected services.
	 *
	 * @var ObjectFolderControllerServices
	 */
	private readonly ObjectFolderControllerServices $services;

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request          The request.
	 * @param ObjectFolderControllerServices $services The object folder controller services.
	 * @param FileWriteGuardInterface        $fileWriteGuard   Refuses a change to the files of a frozen or archived object.
	 */
	public function __construct(
		IRequest $request,
		ObjectFolderControllerServices $services,
		private readonly FileWriteGuardInterface $fileWriteGuard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->services = $services;
		$this->objectFileAccess = $services->objectFileAccess;
		$this->browser = $services->browser;
		$this->responder = $services->responder;
	}//end __construct()

	/**
	 * List the object folder, or one of its subfolders.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 *
	 * The `path` param names the folder, relative to the object folder; empty or absent for its root.
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
	public function index(string $register, string $schema, string $id): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);
		return $this->answer(work: function () use ($ref): JSONResponse {
			$path = $this->text(name: 'path');
			return $this->listing(object: $this->ensureReadable(ref: $ref), path: $path);
		});
	}//end index()

	/**
	 * The listing of a folder of an object the caller may read.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $path   The folder, relative to the object folder.
	 *
	 * @return JSONResponse The listing, with `canChange`.
	 */
	private function listing(ObjectEntity $object, string $path): JSONResponse {
		$listing = $this->browser->listFolder(object: $object, path: $path);
		$listing['canChange'] = $this->objectFileAccess->mayUpdate(object: $object);

		return new JSONResponse(data: $listing);
	}//end listing()

	/**
	 * Create a folder inside the object folder.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 *
	 * The `name` param is the new folder's name; `path` says where, relative to the object folder, empty for its root.
	 *
	 * @return JSONResponse The new folder (201); or 400, 403, 404, 409.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	#[NoAdminRequired]
	public function create(string $register, string $schema, string $id): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);
		return $this->answer(work: function () use ($ref): JSONResponse {
			$path = $this->text(name: 'path');
			$name = $this->text(name: 'name');
			return new JSONResponse(
				data: $this->browser->createFolder(object: $this->ensureChangeable(ref: $ref), path: $path, name: $name),
				statusCode: 201
			);
		});
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
	 *
	 * The `path` param names the target folder, relative to the object folder.
	 *
	 * @return JSONResponse 201 with `stored`; 207 with `stored` and `rejected`; 400, 403, 404; 409 when every file was a taken name.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	#[NoAdminRequired]
	public function upload(string $register, string $schema, string $id): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);
		return $this->answer(work: function () use ($ref): JSONResponse {
			$path = $this->text(name: 'path');
			return $this->uploadInto(object: $this->ensureChangeable(ref: $ref), path: $path);
		});
	}//end upload()

	/**
	 * Store the request's uploads in a folder of an object the caller may change.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $path   The target folder, relative to the object folder.
	 *
	 * @return JSONResponse The upload outcome, or the answer to no upload at all.
	 */
	private function uploadInto(ObjectEntity $object, string $path): JSONResponse {
		$uploads = $this->uploadedFiles();
		if ($uploads === []) {
			return $this->responder->noUpload();
		}

		return $this->responder->uploaded(
			outcome: $this->services->uploads->uploadAll(folder: new ObjectFolderPath(object: $object, path: $path), uploads: $uploads),
			total: count($uploads)
		);
	}//end uploadInto()

	/**
	 * Rename a file or folder inside the object folder.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object uuid or id.
	 * @param int    $nodeId   The file or folder.
	 *
	 * The `name` param is the new name.
	 *
	 * @return JSONResponse The renamed node; or 400, 403, 404, 409.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-folder-in-files-browser/specs/file-actions/spec.md#requirement-changing-an-objects-folder-and-its-subfolders-follows-the-objects-update-rule
	 */
	#[NoAdminRequired]
	public function rename(string $register, string $schema, string $id, int $nodeId): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);
		return $this->answer(work: function () use ($ref, $nodeId): JSONResponse {
			$name = $this->text(name: 'name');
			return new JSONResponse(
				data: $this->browser->rename(object: $this->ensureChangeable(ref: $ref), nodeId: $nodeId, name: $name)
			);
		});
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
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);
		return $this->answer(work: fn (): JSONResponse => $this->deleted(object: $this->ensureChangeable(ref: $ref), nodeId: $nodeId));
	}//end destroy()

	/**
	 * Delete a file or folder of an object the caller may change.
	 *
	 * @param ObjectEntity $object The object.
	 * @param int          $nodeId The file or folder.
	 *
	 * @return JSONResponse `{deleted: true}`.
	 */
	private function deleted(ObjectEntity $object, int $nodeId): JSONResponse {
		$this->browser->delete(object: $object, nodeId: $nodeId);

		return new JSONResponse(data: ['deleted' => true]);
	}//end deleted()

	/**
	 * Run one action, answering any failure the way every action here does.
	 *
	 * @param callable(): JSONResponse $work The action, access checks included.
	 *
	 * @return JSONResponse The action's answer, or the failure's.
	 */
	private function answer(callable $work): JSONResponse {
		try {
			return $work();
		} catch (Throwable $e) {
			return $this->responder->failure(e: $e);
		}
	}//end answer()

	/**
	 * A text param of the request, as the dispatcher would bind a `string $name = ''` argument.
	 *
	 * Read before the access check, so a malformed param is refused with 400 whatever the caller's access.
	 *
	 * @param string $name The param's name.
	 *
	 * @return string The value, or '' when absent or null.
	 *
	 * @throws InvalidArgumentException When the param is present but not a string (answered 400).
	 */
	private function text(string $name): string {
		$value = $this->request->getParam($name);
		if ($value === null) {
			return '';
		}

		if (is_string($value) === false) {
			$code = ObjectFolderBrowser::BAD_NAME;
			if ($name === 'path') {
				$code = ObjectFolderBrowser::BAD_PATH;
			}

			throw new InvalidArgumentException(message: 'The ' . $name . ' param must be a string', code: $code);
		}

		return $value;
	}//end text()

	/**
	 * The object, when the signed-in person may read it.
	 *
	 * @param ObjectRef $ref The object.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @throws ObjectFileAccessDeniedException With 404 when they may not read it.
	 */
	private function ensureReadable(ObjectRef $ref): ObjectEntity {
		return $this->objectFileAccess->readable(ref: $ref);
	}//end ensureReadable()

	/**
	 * The object, when the signed-in person may update it.
	 *
	 * @param ObjectRef $ref The object.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @throws ObjectFileAccessDeniedException With 404 without read, 403 with read but without update.
	 * @throws ObjectStateWriteException        When the object is frozen or archived (answered 409, REQ-OAS-007).
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
	 */
	private function ensureChangeable(ObjectRef $ref): ObjectEntity {
		$object = $this->objectFileAccess->changeable(ref: $ref);

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
