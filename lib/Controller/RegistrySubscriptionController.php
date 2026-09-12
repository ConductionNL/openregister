<?php

/**
 * OpenRegister RegistrySubscriptionController
 *
 * Lets a user with `update` on an object request or end a registry
 * subscription (`registry-subscriptions`, finding B22). Its own controller,
 * deliberately: `ObjectsController` already carries a large surface, and
 * this endpoint's only dependency beyond the ordinary object lookup is
 * {@see RegistrySubscriptionService}.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Request / end a registry subscription on one object.
 */
class RegistrySubscriptionController extends Controller {

	use HandlesExceptionsTrait;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param ObjectService $objectService Resolves the target object.
	 * @param SchemaMapper $schemaMapper Resolves the object's schema.
	 * @param PermissionHandler $permissionHandler The per-object `update` guard.
	 * @param IUserSession $userSession The session user.
	 * @param RegistrySubscriptionService $subscriptions Owns the subscription lifecycle.
	 * @param LoggerInterface|null $logger Consumed by HandlesExceptionsTrait for server-side 500 logging.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly SchemaMapper $schemaMapper,
		private readonly PermissionHandler $permissionHandler,
		private readonly IUserSession $userSession,
		private readonly RegistrySubscriptionService $subscriptions,
		private readonly ?LoggerInterface $logger = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Request a subscription on an object.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The object id or uuid.
	 *
	 * @return JSONResponse The persisted subscription state, or an error.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	#[NoAdminRequired]
	public function subscribe(string $register, string $schema, string $id): JSONResponse {
		try {
			[$object, $resolvedSchema] = $this->resolveObjectAndGuardUpdate(register: $register, schema: $schema, id: $id);

			$row = $this->subscriptions->requestSubscription(object: $object, schema: $resolvedSchema);

			$this->logger?->info(
				'[OpenRegister.RegistrySubscriptionController] Subscription requested for object '
				. (string)$object->getUuid() . ' on registry ' . (string)$row->getRegistry()
			);

			return new JSONResponse(data: $row->toSelfMirror());
		} catch (\Throwable $e) {
			return $this->handleApiException(e: $e, context: 'registry-subscription-request');
		}
	}//end subscribe()

	/**
	 * End a subscription on an object.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The object id or uuid.
	 *
	 * @return JSONResponse The persisted subscription state, or an error.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	#[NoAdminRequired]
	public function unsubscribe(string $register, string $schema, string $id): JSONResponse {
		try {
			[$object] = $this->resolveObjectAndGuardUpdate(register: $register, schema: $schema, id: $id);

			$row = $this->subscriptions->endSubscription(object: $object);

			$this->logger?->info(
				'[OpenRegister.RegistrySubscriptionController] Subscription ended for object '
				. (string)$object->getUuid() . ' on registry ' . (string)$row->getRegistry()
			);

			return new JSONResponse(data: $row->toSelfMirror());
		} catch (\Throwable $e) {
			return $this->handleApiException(e: $e, context: 'registry-subscription-end');
		}
	}//end unsubscribe()

	/**
	 * Resolve the target object and its schema, then refuse (throw) unless
	 * the calling user has `update` on THIS specific object — the per-object
	 * IDOR guard both endpoints in this controller need.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The object id or uuid.
	 *
	 * @return array{0: ObjectEntity, 1: Schema} The resolved object and schema.
	 *
	 * @throws NotAuthorizedException When the caller lacks `update` on the object.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	private function resolveObjectAndGuardUpdate(string $register, string $schema, string $id): array {
		$object = $this->objectService->find(id: $id, register: $register, schema: $schema, _render: false);
		$resolvedSchema = $this->schemaMapper->find(id: (string)$object->getSchema());

		$user = $this->userSession->getUser();
		$allowed = $this->permissionHandler->hasPermission(
			schema: $resolvedSchema,
			action: 'update',
			userId: $user?->getUID(),
			objectOwner: $object->getOwner(),
			object: $object,
		);
		if ($allowed === false) {
			throw new NotAuthorizedException(message: 'You do not have permission to update this object.');
		}

		return [$object, $resolvedSchema];
	}//end resolveObjectAndGuardUpdate()
}//end class
