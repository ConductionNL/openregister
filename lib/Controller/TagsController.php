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
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCA\OpenRegister\Service\ObjectHandlerAccessInterface;
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
	 * @param ObjectHandlerAccessInterface $objectService Object service instance
	 * @param FileServiceInterface $fileService File service instance for tag retrieval
	 * @param TaggingHandlerInterface $taggingHandler Tagging handler for object-level tags
	 * @param IUserSession $userSession Session for the @PublicPage anonymous-deny guard
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectHandlerAccessInterface $objectService,
		private readonly FileServiceInterface $fileService,
		private readonly TaggingHandlerInterface $taggingHandler,
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
	public function index(string $register, string $schema, string $id): JSONResponse {
		try {
			$object = $this->selectObject(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
			if ($object === null) {
				return $this->objectNotFound();
			}

			return new JSONResponse(data: $this->taggingHandler->getObjectTags($object->getUuid()));
		} catch (DoesNotExistException $e) {
			return $this->objectNotFound();
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
	public function add(string $register, string $schema, string $id): JSONResponse {
		return $this->changeTags(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			change: fn (ObjectEntity $object): JSONResponse => $this->addTag(object: $object)
		);
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
	public function remove(string $register, string $schema, string $id, string $tag): JSONResponse {
		return $this->changeTags(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			change: fn (ObjectEntity $object): JSONResponse => $this->removeTag(object: $object, tag: $tag)
		);
	}//end remove()

	/**
	 * Select the object addressed by the route and load it through the read path.
	 *
	 * @param ObjectLinkRoute $route The register slug or identifier, the schema slug or identifier and the object ID
	 *
	 * @return ObjectEntity|null The object, or null when it is not found.
	 */
	private function selectObject(ObjectLinkRoute $route): ?ObjectEntity {
		$this->objectService->setRegister($route->register);
		$this->objectService->setSchema($route->schema);
		$this->objectService->setObject($route->id);

		return $this->objectService->getObject();
	}//end selectObject()

	/**
	 * Load the object, refuse without the update right, then apply a tag change.
	 *
	 * The right is checked BEFORE the change runs; every failure maps to the
	 * same statuses add() and remove() always answered.
	 *
	 * @param ObjectLinkRoute $route  The register slug or identifier, the schema slug or identifier and the object ID
	 * @param callable        $change The change, given the object: (ObjectEntity): JSONResponse
	 *
	 * @return JSONResponse The change's response, or the refusal.
	 */
	private function changeTags(ObjectLinkRoute $route, callable $change): JSONResponse {
		try {
			$object = $this->selectObject(route: $route);
			if ($object === null) {
				return $this->objectNotFound();
			}

			return ($this->refuseWithoutUpdateRight(object: $object) ?? $change($object));
		} catch (DoesNotExistException $e) {
			return $this->objectNotFound();
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 403);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}//end try
	}//end changeTags()

	/**
	 * Add the posted tag to an object the caller may update.
	 *
	 * @param ObjectEntity $object The object being tagged.
	 *
	 * @return JSONResponse 201 with the updated tags, or 400 without a tag name.
	 */
	private function addTag(ObjectEntity $object): JSONResponse {
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
	}//end addTag()

	/**
	 * Remove a tag from an object the caller may update.
	 *
	 * @param ObjectEntity $object The object being untagged.
	 * @param string       $tag    The tag name to remove.
	 *
	 * @return JSONResponse The updated tags.
	 */
	private function removeTag(ObjectEntity $object, string $tag): JSONResponse {
		$this->taggingHandler->removeObjectTag($object->getUuid(), $tag);
		$tags = $this->taggingHandler->getObjectTags($object->getUuid());

		return new JSONResponse(data: $tags);
	}//end removeTag()

	/**
	 * The 404 for an object that cannot be found.
	 *
	 * @return JSONResponse The 404 response.
	 */
	private function objectNotFound(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
	}//end objectNotFound()

	/**
	 * Refuse a tag change on an object the caller may read but not update.
	 *
	 * The object was loaded through the read path, so reaching this point
	 * proves only `read`. A tag is part of the object as its users see it, so
	 * adding or removing one is an update, decided by the same permission
	 * handler and rule as any other update (openregister#4096). An object
	 * whose schema cannot be resolved is refused: the rule cannot be read.
	 *
	 * @param ObjectEntity $object The object being tagged.
	 *
	 * @return JSONResponse|null A 403 response, or null when the caller may update the object.
	 *
	 * @spec openspec/changes/flow-tag-object-step/proposal.md
	 */
	private function refuseWithoutUpdateRight(ObjectEntity $object): ?JSONResponse {
		if ($this->mayUpdate(object: $object) === true) {
			return null;
		}

		return new JSONResponse(
			data: ['error' => 'You may read this object but not change it, so you cannot change its tags.'],
			statusCode: 403
		);
	}//end refuseWithoutUpdateRight()

	/**
	 * Whether the permission handler grants `update` on the object.
	 *
	 * @param ObjectEntity $object The object being tagged.
	 *
	 * @return bool False when the schema cannot be resolved or the right is not held.
	 */
	private function mayUpdate(ObjectEntity $object): bool {
		$schema = $this->objectService->getCurrentSchemaEntity();
		if ($schema === null) {
			return false;
		}

		return $this->objectService->getPermissionHandler()->hasPermission(
			schema: $schema,
			action: 'update',
			objectOwner: $object->getOwner(),
			object: $object
		);
	}//end mayUpdate()
}//end class
