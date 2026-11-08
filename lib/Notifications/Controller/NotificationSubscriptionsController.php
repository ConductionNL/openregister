<?php

/**
 * NotificationSubscriptionsController.
 *
 * REST surface for per-user (register, schema) notification
 * subscriptions. Routes:
 *
 *   GET    /api/notification-subscriptions
 *          → list current user's subscriptions
 *
 *   POST   /api/notification-subscriptions
 *          body: { registerId?, schemaId? }
 *          → subscribe (idempotent)
 *
 *   DELETE /api/notification-subscriptions
 *          query: ?registerId=X&schemaId=Y (either may be omitted)
 *          → unsubscribe by tuple
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md "Users MUST be able to manage their notification preferences"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Notifications\Db\NotificationSubscriptionMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationRequestTrait;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationSubscriptionsControllerServices;

/**
 * DEPRECATED per-user (register, schema) notification subscription surface.
 *
 * Superseded by the override-only model: rules live in the schema annotation
 * `x-openregister-notifications` and per-user preferences are override-only
 * Nextcloud user-config values served by NotificationPreferencesController.
 * Existing rows are migrated by the MigrateNotificationSubscriptionsToUserConfig
 * repair step; this controller keeps responding during the deprecation window
 * and is scheduled for removal.
 *
 * @deprecated Use NotificationPreferencesController (override-only user-config).
 */
class NotificationSubscriptionsController extends Controller {
	use NotificationRequestTrait;

	/**
	 * Subscription mapper.
	 *
	 * @var NotificationSubscriptionMapper
	 */
	private readonly NotificationSubscriptionMapper $mapper;

	/**
	 * Register mapper for permission check.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper for permission check.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Constructor.
	 *
	 * @param IRequest                                    $request     Request.
	 * @param NotificationSubscriptionsControllerServices $services    The subscription stores.
	 * @param IUserSession                                $userSession Current-user session.
	 */
	public function __construct(
		IRequest $request,
		NotificationSubscriptionsControllerServices $services,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

		$this->mapper         = $services->mapper;
		$this->registerMapper = $services->registerMapper;
		$this->schemaMapper   = $services->schemaMapper;
	}//end __construct()

	/**
	 * List the current user's subscriptions.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md "Users MUST be able to manage their notification preferences"
	 */
	public function index(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		$rows = $this->mapper->findByUser(userId: $userId);
		$items = array_map(
			static fn ($r) => $r->jsonSerialize(),
			$rows
		);
		return new JSONResponse(data: ['results' => $items, 'total' => count($items)]);
	}//end index()

	/**
	 * Subscribe the current user to a (register, schema) tuple.
	 *
	 * Body: `{ registerId?: int, schemaId?: int }`. At least one MUST
	 * be present.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md "Users MUST be able to manage their notification preferences"
	 */
	public function create(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		$params = $this->request->getParams();
		$registerId = $this->coerceNullableInt(value: ($params['registerId'] ?? null));
		$schemaId = $this->coerceNullableInt(value: ($params['schemaId'] ?? null));
		$refusal = $this->refuseScope(registerId: $registerId, schemaId: $schemaId);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->subscribe(userId: $userId, registerId: $registerId, schemaId: $schemaId);
	}//end create()

	/**
	 * Refuse a register or schema the caller cannot reach.
	 *
	 * Both are looked up under multitenancy, so a register or schema of
	 * another tenant answers 404 exactly as an absent one does.
	 *
	 * @param int|null $registerId The register asked for, or null.
	 * @param int|null $schemaId   The schema asked for, or null.
	 *
	 * @return JSONResponse|null The 404, or null when both are reachable.
	 */
	private function refuseScope(?int $registerId, ?int $schemaId): ?JSONResponse {
		$register = $this->refuseUnreachable(
			id: $registerId,
			find: fn (int $id): mixed => $this->registerMapper->find($id, _multitenancy: true),
			error: 'Register not found or not accessible'
		);

		return ($register ?? $this->refuseUnreachable(
			id: $schemaId,
			find: fn (int $id): mixed => $this->schemaMapper->find($id, _multitenancy: true),
			error: 'Schema not found or not accessible'
		));
	}//end refuseScope()

	/**
	 * A 404 when an asked-for id cannot be found; null when none was asked for or it was found.
	 *
	 * @param int|null                $id    The id, or null when none was asked for.
	 * @param callable(int): mixed    $find  Looks the id up; throws when it is unreachable.
	 * @param string                  $error The error the 404 carries.
	 *
	 * @return JSONResponse|null The 404, or null.
	 */
	private function refuseUnreachable(?int $id, callable $find, string $error): ?JSONResponse {
		if ($id === null) {
			return null;
		}

		try {
			$find($id);
		} catch (\Throwable $e) {
			return new JSONResponse(data: ['error' => $error], statusCode: 404);
		}

		return null;
	}//end refuseUnreachable()

	/**
	 * Subscribe the caller, answering 422 when the mapper refuses the scope.
	 *
	 * @param string   $userId     The caller.
	 * @param int|null $registerId The register, or null.
	 * @param int|null $schemaId   The schema, or null.
	 *
	 * @return JSONResponse The subscription, or a 422.
	 */
	private function subscribe(string $userId, ?int $registerId, ?int $schemaId): JSONResponse {
		try {
			$entity = $this->mapper->subscribe(userId: $userId, registerId: $registerId, schemaId: $schemaId);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		}

		return new JSONResponse(data: $entity->jsonSerialize(), statusCode: 201);
	}//end subscribe()

	/**
	 * Unsubscribe the current user from a (register, schema) tuple.
	 *
	 * Query: `?registerId=X&schemaId=Y` (either may be omitted).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md "Users MUST be able to manage their notification preferences"
	 */
	public function destroy(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		$registerId = $this->coerceNullableInt(value: $this->request->getParam('registerId'));
		$schemaId = $this->coerceNullableInt(value: $this->request->getParam('schemaId'));

		$deleted = $this->mapper->unsubscribe(
			userId: $userId,
			registerId: $registerId,
			schemaId: $schemaId
		);

		return new JSONResponse(data: ['deleted' => $deleted]);
	}//end destroy()

	/**
	 * Coerce a request value to a nullable int. Empty string and null
	 * both become null; anything else cast to int.
	 *
	 * @param mixed $value Input.
	 *
	 * @return ?int
	 *
	 * @spec exclude Private helper: coerces a request value to a nullable int; the subscription endpoints are owned by notificatie-engine/tasks.md.
	 */
	private function coerceNullableInt(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end coerceNullableInt()
}//end class
