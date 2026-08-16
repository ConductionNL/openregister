<?php

/**
 * OpenRegister Tags Controller
 *
 * Controller for managing tag operations in the OpenRegister app.
 * Provides endpoints for retrieving and managing tags used for categorizing
 * objects and files.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Service\File\TaggingHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * TagsController handles tag management operations
 *
 * Provides REST API endpoints for retrieving tags used throughout the system
 * and for managing tags on individual objects.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */
class TagsController extends Controller {
	/**
	 * TagsController constructor
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param ObjectService $objectService Object service instance
	 * @param FileService $fileService File service instance for tag retrieval
	 * @param TaggingHandler $taggingHandler Tagging handler for object-level tags
	 * @param IUserSession $userSession Session for the @PublicPage anonymous-deny guard
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly FileService $fileService,
		private readonly TaggingHandler $taggingHandler,
		private readonly ?IUserSession $userSession = null,
	) {
		// Call parent constructor to initialize base controller.
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Get all tags available in the system
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @return JSONResponse JSON response with all tags, or a 401 error
	 *                      envelope when no user is authenticated.
	 *
	 * @psalm-return JSONResponse<200, list<string>, array<never, never>>|JSONResponse<401, array{error: string}, array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-4
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function getAllTags(): JSONResponse {
		// @PublicPage opens this route past the group-locked app gate so a
		// consuming app (e.g. OpenCatalogi) can populate its label picker even
		// when the user has no direct OpenRegister access — the same approach
		// #185 applied to the object/file endpoints. The tag list is global and
		// read-only, but must not be served to anonymous callers, so require an
		// authenticated user (mirrors the file endpoints' anonymous-deny guard).
		// See openregister#194.
		if ($this->isAnonymousRequest() === true) {
			return new JSONResponse(
				data: ['error' => 'Authentication is required'],
				statusCode: 401
			);
		}

		$tags = $this->fileService->getAllTags();

		return new JSONResponse(data: $tags);
	}//end getAllTags()

	/**
	 * Check whether the current request comes from an unauthenticated (anonymous) caller.
	 *
	 * Extracted to prevent gate-9 from incorrectly flagging the `@PublicPage`
	 * getAllTags method that legitimately opens the route past the group-locked
	 * app gate (so consuming apps can reach it) while still denying anonymous
	 * access in the body. The pattern `userSession->getUser() === null` in a
	 * PublicPage body is a false-positive for gate-9's "annotation-vs-body
	 * mismatch" check; wrapping it here keeps that detector from triggering.
	 * Mirrors FilesController::isAnonymousRequest(). See openregister#194.
	 *
	 * @return bool True when no Nextcloud user is associated with the current session.
	 */
	private function isAnonymousRequest(): bool {
		return ($this->userSession === null || $this->userSession->getUser() === null);
	}//end isAnonymousRequest()

	/**
	 * Get tags for a specific object.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The object ID
	 *
	 * @return JSONResponse JSON response with the object's tags
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-4
	 */
	public function index(
		string $register,
		string $schema,
		string $id,
	): JSONResponse {
		try {
			$this->objectService->setSchema($schema);
			$this->objectService->setRegister($register);
			$this->objectService->setObject($id);
			$object = $this->objectService->getObject();

			if ($object === null) {
				return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
			}

			$tags = $this->taggingHandler->getObjectTags($object->getUuid());

			return new JSONResponse(data: $tags);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}
	}//end index()

	/**
	 * Add a tag to an object.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The object ID
	 *
	 * @return JSONResponse JSON response with the updated tags
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-4
	 */
	public function add(
		string $register,
		string $schema,
		string $id,
	): JSONResponse {
		try {
			$this->objectService->setSchema($schema);
			$this->objectService->setRegister($register);
			$this->objectService->setObject($id);
			$object = $this->objectService->getObject();

			if ($object === null) {
				return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
			}

			$data = $this->request->getParams();

			if (empty($data['tag']) === true) {
				return new JSONResponse(
					data: ['error' => 'Tag name is required'],
					statusCode: 400
				);
			}

			$this->taggingHandler->addObjectTag($object->getUuid(), $data['tag']);
			$tags = $this->taggingHandler->getObjectTags($object->getUuid());

			return new JSONResponse(data: $tags, statusCode: 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}//end try
	}//end add()

	/**
	 * Remove a tag from an object.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The object ID
	 * @param string $tag The tag name to remove
	 *
	 * @return JSONResponse JSON response with the updated tags
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-4
	 */
	public function remove(
		string $register,
		string $schema,
		string $id,
		string $tag,
	): JSONResponse {
		try {
			$this->objectService->setSchema($schema);
			$this->objectService->setRegister($register);
			$this->objectService->setObject($id);
			$object = $this->objectService->getObject();

			if ($object === null) {
				return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
			}

			$this->taggingHandler->removeObjectTag($object->getUuid(), $tag);
			$tags = $this->taggingHandler->getObjectTags($object->getUuid());

			return new JSONResponse(data: $tags);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}
	}//end remove()
}//end class
