<?php

/**
 * Object watchers controller — follow an object, and see who else does.
 *
 * WHY THESE ENDPOINTS EXIST AT ALL. A watcher is per-user, per-object state
 * that must NOT be written through the object: storing it on the object would
 * put a subscription in the object's audit trail and cut a new version every
 * time somebody followed or unfollowed it. So following needs its own entry
 * point, which is this, and the rows live in `openregister_watchers`.
 *
 * THE IDOR GUARD. Every method resolves the object through `ObjectService`
 * first, which applies register RBAC and multitenancy. An object the caller
 * cannot READ resolves to null and the request is refused with 404, never 403 —
 * existence is not leaked, which is the same guard the sharing and integrations
 * endpoints use. Only after that does `WatcherService` decide the rest: `update`
 * to read the watcher list, `manage` to change somebody else's subscription.
 * Both decisions live in the service, so this controller cannot disagree with
 * the render layer or the notification dispatcher about who may see what.
 *
 * This is NOT a pass-through to ObjectService: every method calls the watcher
 * primitive, which no other route reaches.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Follow an object, stop following it, and read the audience.
 */
class ObjectWatchersController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param ObjectService $objectService Resolves an object through the RBAC boundary.
	 * @param WatcherService $watchers The subscription primitive.
	 * @param IUserManager $userManager Verifies a named uid before it is subscribed.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly WatcherService $watchers,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Follow an object.
	 *
	 * Idempotent: following an object you already follow returns the same
	 * subscription and changes nothing.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The subscription, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	#[NoAdminRequired]
	public function watch(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		try {
			$watcher = $this->watchers->watch(object: $object, register: $register, schema: $schema);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'watch');
		}

		return new JSONResponse($watcher->jsonSerialize());
	}//end watch()

	/**
	 * Stop following an object.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse Empty on success, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	#[NoAdminRequired]
	public function unwatch(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		try {
			$this->watchers->unwatch(object: $object);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'unwatch');
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end unwatch()

	/**
	 * List an object's watchers, for a caller who may edit it.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The watchers with the time each subscribed, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	#[NoAdminRequired]
	public function index(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		try {
			$rows = $this->watchers->listWatchers(object: $object);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'index');
		}

		$results = [];
		foreach ($rows as $row) {
			$results[] = $row->jsonSerialize();
		}

		return new JSONResponse(['results' => $results, 'total' => count($results)]);
	}//end index()

	/**
	 * Subscribe another user, for a caller who may manage the object.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 * @param string $userId The user to subscribe.
	 *
	 * @return JSONResponse The subscription, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	#[NoAdminRequired]
	public function add(string $register, string $schema, string $id, string $userId): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		// The uid arrives in the URL, so it is caller-controlled. Subscribing a
		// name nothing answers to would put a row in the table that no dispatch
		// can ever resolve, and the list would show a person who does not exist.
		if ($this->userManager->userExists($userId) === false) {
			return new JSONResponse(['message' => 'Unknown user'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$watcher = $this->watchers->addWatcher(
				object: $object,
				userId: $userId,
				register: $register,
				schema: $schema
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'add');
		}

		return new JSONResponse($watcher->jsonSerialize());
	}//end add()

	/**
	 * Remove a user's subscription.
	 *
	 * A watcher removing themselves needs nothing beyond being that watcher;
	 * removing anybody else needs `manage`.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 * @param string $userId The user whose subscription is removed.
	 *
	 * @return JSONResponse Empty on success, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	#[NoAdminRequired]
	public function remove(string $register, string $schema, string $id, string $userId): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		try {
			$this->watchers->removeWatcher(object: $object, userId: $userId);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'remove');
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end remove()

	/**
	 * Resolve the target object through the RBAC boundary.
	 *
	 * An object the caller cannot READ is refused with 404 rather than 403, so
	 * these endpoints cannot be used to probe which object ids exist, and a user
	 * without read can never write a watcher row.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return ObjectEntity|JSONResponse The object, or the response to return.
	 */
	private function resolveObject(string $register, string $schema, string $id): ObjectEntity|JSONResponse {
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

		try {
			$object = $this->objectService->getObject();
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		if (($object instanceof ObjectEntity) === false) {
			return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
		}

		return $object;
	}//end resolveObject()

	/**
	 * Log an unexpected failure and return a generic error.
	 *
	 * @param \Throwable $exception The failure.
	 * @param string $context Short label for the log line.
	 *
	 * @return JSONResponse A generic 500.
	 */
	private function unexpected(\Throwable $exception, string $context): JSONResponse {
		$this->logger->error(
			message: '[ObjectWatchersController] ' . $context . ': ' . $exception->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $exception]
		);

		return new JSONResponse(
			['message' => 'Could not complete the watcher request'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end unexpected()
}//end class
