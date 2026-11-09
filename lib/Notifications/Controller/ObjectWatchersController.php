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
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Controller\Support\ObjectWatchersControllerServices;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
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
	 * Resolves an object through the RBAC boundary.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * The subscription primitive.
	 *
	 * @var WatcherServiceInterface
	 */
	private readonly WatcherServiceInterface $watchers;

	/**
	 * Verifies a named uid before it is subscribed.
	 *
	 * @var IUserManager
	 */
	private readonly IUserManager $userManager;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param ObjectWatchersControllerServices $services The controller's services.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ObjectWatchersControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);

		$this->objectService = $services->objectService;
		$this->watchers = $services->watchers;
		$this->userManager = $services->userManager;
		$this->logger = $services->logger;
	}//end __construct()

	/**
	 * Follow an object.
	 *
	 * Idempotent: following an object you already follow returns the same
	 * subscription and changes nothing, except the notification switch when
	 * the body names one: `{"notify": false}` keeps following and stops the
	 * notifications, `{"notify": true}` turns them back on. Without it a new
	 * follow notifies and an existing one keeps its setting.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The subscription, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	#[NoAdminRequired]
	public function watch(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		return $this->watchObject(object: $object, scope: ['register' => $register, 'schema' => $schema]);
	}//end watch()

	/**
	 * Follow a resolved object for the caller.
	 *
	 * @param ObjectEntity          $object The object.
	 * @param array<string, string> $scope  The register and schema as the caller named them.
	 *
	 * @return JSONResponse The subscription, or an error.
	 */
	private function watchObject(ObjectEntity $object, array $scope): JSONResponse {
		try {
			$watcher = $this->watchers->watch(
				object: $object,
				register: $scope['register'],
				schema: $scope['schema'],
				notify: $this->notifyParam()
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'watch');
		}

		return new JSONResponse($watcher->jsonSerialize());
	}//end watchObject()

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
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	#[NoAdminRequired]
	public function unwatch(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		return $this->unwatchObject(object: $object);
	}//end unwatch()

	/**
	 * Stop following a resolved object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse Empty on success, or an error.
	 */
	private function unwatchObject(ObjectEntity $object): JSONResponse {
		try {
			$this->watchers->unwatch(object: $object);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'unwatch');
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end unwatchObject()

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
	 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	#[NoAdminRequired]
	public function index(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		return $this->watcherList(object: $object);
	}//end index()

	/**
	 * List a resolved object's watchers.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The watchers with the time each subscribed, or an error.
	 */
	private function watcherList(ObjectEntity $object): JSONResponse {
		try {
			$rows = $this->watchers->listWatchers(object: $object);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'index');
		}

		$results = [];
		foreach ($rows as $row) {
			// Who follows, and since when. Each follower's notification
			// switch is their own preference and stays out of the list.
			$result = $row->jsonSerialize();
			unset($result['notify']);
			$results[] = $result;
		}

		return new JSONResponse(['results' => $results, 'total' => count($results)]);
	}//end watcherList()

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
	 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	#[NoAdminRequired]
	public function add(string $register, string $schema, string $id, string $userId): JSONResponse {
		$object = $this->resolveObject(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		return $this->addTo(object: $object, watcher: ['userId' => $userId, 'register' => $register, 'schema' => $schema]);
	}//end add()

	/**
	 * Subscribe a known user to a resolved object.
	 *
	 * @param ObjectEntity          $object  The object.
	 * @param array<string, string> $watcher The user to subscribe, and the register and schema as named.
	 *
	 * @return JSONResponse The subscription, or an error.
	 */
	private function addTo(ObjectEntity $object, array $watcher): JSONResponse {
		// The uid arrives in the URL, so it is caller-controlled. Subscribing a
		// name nothing answers to would put a row in the table that no dispatch
		// can ever resolve, and the list would show a person who does not exist.
		if ($this->userManager->userExists($watcher['userId']) === false) {
			return new JSONResponse(['message' => 'Unknown user'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$added = $this->watchers->addWatcher(
				object: $object,
				userId: $watcher['userId'],
				register: $watcher['register'],
				schema: $watcher['schema']
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'add');
		}

		return new JSONResponse($added->jsonSerialize());
	}//end addTo()

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
	 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	#[NoAdminRequired]
	public function remove(string $register, string $schema, string $id, string $userId): JSONResponse {
		$object = $this->resolveObject(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		return $this->removeFrom(object: $object, userId: $userId);
	}//end remove()

	/**
	 * Remove a user's subscription from a resolved object.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $userId The user whose subscription is removed.
	 *
	 * @return JSONResponse Empty on success, or an error.
	 */
	private function removeFrom(ObjectEntity $object, string $userId): JSONResponse {
		try {
			$this->watchers->removeWatcher(object: $object, userId: $userId);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'remove');
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end removeFrom()

	/**
	 * The notification switch the caller asked for, or null when they named none.
	 *
	 * A value that is not a boolean spelling is treated as not named, so a
	 * typo keeps the current setting rather than silencing a follow.
	 *
	 * @return boolean|null The switch, or null.
	 */
	private function notifyParam(): ?bool {
		$raw = $this->request->getParam('notify');
		if ($raw === null || $raw === '') {
			return null;
		}

		return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
	}//end notifyParam()

	/**
	 * Resolve the target object through the RBAC boundary.
	 *
	 * An object the caller cannot READ is refused with 404 rather than 403, so
	 * these endpoints cannot be used to probe which object ids exist, and a user
	 * without read can never write a watcher row.
	 *
	 * @param array<string, string> $path The register slug or id, schema slug or id and object uuid.
	 *
	 * @return ObjectEntity|JSONResponse The object, or the response to return.
	 */
	private function resolveObject(array $path): ObjectEntity|JSONResponse {
		try {
			// The setObject() call already looks the object up, so an unknown id throws here.
			$this->objectService->setRegister($path['register']);
			$this->objectService->setSchema($path['schema']);
			$this->objectService->setObject($path['id']);
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
