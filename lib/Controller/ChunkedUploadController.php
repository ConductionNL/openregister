<?php

/**
 * ChunkedUploadController: the upload-in-parts API of an object's files.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-a-large-file-uploads-in-parts-with-stated-boundaries-req-lfh-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectFileAccessDeniedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Files\Controller\Support\ChunkedUploadControllerServices;
use OCA\OpenRegister\Files\Controller\Support\FileActionRefusal;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Kernel\Contracts\UploadSettingsReaderInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Open an upload session on an object, send its parts, read what arrived and complete it.
 *
 * Every endpoint first asks the object rule, as a file upload does: update on
 * the object (404 when the caller may not read it, 403 when they may read but
 * not update). A session is then usable only by the user who opened it, on the
 * object it was opened for; anyone else is answered 404.
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-a-large-file-uploads-in-parts-with-stated-boundaries-req-lfh-002
 */
class ChunkedUploadController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request  The request.
	 * @param ChunkedUploadControllerServices $services The upload sessions, the object rule, the session and the body reader.
	 */
	public function __construct(
		IRequest $request,
		private readonly ChunkedUploadControllerServices $services,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Open an upload session for a declared name, size and SHA-256.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object id or uuid.
	 *
	 * @return JSONResponse 201 with uploadId, partSize, partCount, parts and expiresAt.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-2
	 */
	#[NoAdminRequired]
	public function open(string $register, string $schema, string $id): JSONResponse {
		try {
			$object = $this->ensureObjectAccess(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
			$plan = $this->services->uploads->open(object: $object, userId: $this->userId(), declared: $this->request->getParams());

			return new JSONResponse(data: $plan, statusCode: 201);
		} catch (Exception $e) {
			return $this->failed(e: $e);
		}
	}//end open()

	/**
	 * Store part n of a session; the body is the part's raw bytes.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object id or uuid.
	 * @param string $uploadId The upload id.
	 * @param int    $n        The part number, from 1.
	 *
	 * @return JSONResponse The session status after the part.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-2
	 */
	#[NoAdminRequired]
	public function part(string $register, string $schema, string $id, string $uploadId, int $n): JSONResponse {
		try {
			$object  = $this->ensureObjectAccess(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
			$session = $this->services->uploads->sessionFor(uploadId: $uploadId, userId: $this->userId(), object: $object);
			$bytes   = $this->services->body->read(maxBytes: max(UploadSettingsReaderInterface::MAX_PART_SIZE, $session->getPartSize()));

			return new JSONResponse(data: $this->services->uploads->storePart(session: $session, n: $n, bytes: $bytes));
		} catch (Exception $e) {
			return $this->failed(e: $e);
		}
	}//end part()

	/**
	 * What a session has received, what is missing and whether it is complete.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object id or uuid.
	 * @param string $uploadId The upload id.
	 *
	 * @return JSONResponse The session status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-2
	 */
	#[NoAdminRequired]
	public function status(string $register, string $schema, string $id, string $uploadId): JSONResponse {
		try {
			$object  = $this->ensureObjectAccess(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
			$session = $this->services->uploads->sessionFor(uploadId: $uploadId, userId: $this->userId(), object: $object);

			return new JSONResponse(data: $this->services->uploads->status(session: $session));
		} catch (Exception $e) {
			return $this->failed(e: $e);
		}
	}//end status()

	/**
	 * Assemble, verify and create the file; 409 naming what is missing or does not match.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object id or uuid.
	 * @param string $uploadId The upload id.
	 *
	 * @return JSONResponse 201 with the file, as the file API formats it.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-3-1
	 */
	#[NoAdminRequired]
	public function complete(string $register, string $schema, string $id, string $uploadId): JSONResponse {
		try {
			$object  = $this->ensureObjectAccess(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
			$session = $this->services->uploads->sessionFor(uploadId: $uploadId, userId: $this->userId(), object: $object);
			$file    = $this->services->uploads->complete(session: $session, object: $object);

			return new JSONResponse(data: $this->services->fileReader->formatFile($file), statusCode: 201);
		} catch (Exception $e) {
			return $this->failed(e: $e);
		}
	}//end complete()

	/**
	 * The object, once the object rule lets the caller add files to it and it is writable.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return ObjectEntity
	 *
	 * @throws ObjectFileAccessDeniedException With 404 or 403 when the rule refuses.
	 * @throws ObjectStateWriteException       When the object is frozen or archived.
	 */
	private function ensureObjectAccess(ObjectRef $ref): ObjectEntity {
		$object = $this->services->access->changeable(ref: $ref, verb: ObjectFileAccess::ADD_FILES);
		$this->services->writeGuard->assertWritable(object: $object);

		return $object;
	}//end ensureObjectAccess()

	/**
	 * The signed-in user's id; the routes need a login, so no user is refused.
	 *
	 * @return string
	 *
	 * @throws NotAuthorizedException When nobody is signed in.
	 */
	private function userId(): string {
		$user = $this->services->userSession->getUser();
		if ($user === null) {
			throw new NotAuthorizedException(message: 'Authentication is required for this object action');
		}

		return $user->getUID();
	}//end userId()

	/**
	 * The answer for what stopped an action.
	 *
	 * @param Exception $e What stopped it.
	 *
	 * @return JSONResponse
	 */
	private function failed(Exception $e): JSONResponse {
		if ($e instanceof ObjectFileAccessDeniedException === true) {
			if ($e->getHttpStatus() === ObjectFileAccessDeniedException::NOT_READABLE) {
				return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
			}

			return new JSONResponse(data: ['error' => 'You do not have access to this object'], statusCode: 403);
		}

		if ($e instanceof NotAuthorizedException === true) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 401);
		}

		if ($e instanceof ObjectStateWriteException === true) {
			return new JSONResponse(data: $e->toResponseBody(), statusCode: ObjectStateWriteException::HTTP_STATUS);
		}

		if ($e instanceof FileActionRefusal === true) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getCode());
		}

		// A refused upload check or a failed write: no file was created and the parts are kept.
		$this->services->logger->warning(
			message: '[ChunkedUploadController] Upload action refused',
			context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
		);

		return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
	}//end failed()
}//end class
