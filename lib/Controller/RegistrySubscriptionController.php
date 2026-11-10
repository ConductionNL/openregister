<?php

/**
 * OpenRegister RegistrySubscriptionController
 *
 * Lets a user with `update` on an object request or end a registry
 * subscription (`registry-subscriptions`, finding B22). Its own controller,
 * deliberately: `ObjectsController` already carries a large surface, and
 * this endpoint's only dependency beyond the ordinary object lookup is
 * {@see RegistrySubscriptionLifecycleInterface}.
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

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\RegistrySubscriptionControllerServices;
use OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionLifecycleInterface;
use OCA\OpenRegister\Service\WriteCause;
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
	 * Resolves the target object.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Resolves the object's schema.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * The per-object `update` guard.
	 *
	 * @var PermissionCheckInterface
	 */
	private readonly PermissionCheckInterface $permissionHandler;

	/**
	 * The session user.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Owns the subscription lifecycle.
	 *
	 * @var RegistrySubscriptionLifecycleInterface
	 */
	private readonly RegistrySubscriptionLifecycleInterface $subscriptions;

	/**
	 * Consumed by HandlesExceptionsTrait for server-side 500 logging.
	 *
	 * @var ?LoggerInterface
	 */
	private readonly ?LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param RegistrySubscriptionControllerServices $services The registry subscription controller services.
	 */
	public function __construct(
		IRequest $request,
		RegistrySubscriptionControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->objectService = $services->domain->objectService;
		$this->schemaMapper = $services->domain->schemaMapper;
		$this->permissionHandler = $services->domain->permissionHandler;
		$this->userSession = $services->platform->userSession;
		$this->subscriptions = $services->domain->subscriptions;
		$this->logger = $services->platform->logger;
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
		return $this->subscribeOn(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
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
		return $this->unsubscribeOn(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end unsubscribe()

	/**
	 * Request a subscription on the named record, once the caller may update it.
	 *
	 * @param ObjectRef $ref The record the route names.
	 *
	 * @return JSONResponse The persisted subscription state, or an error.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	private function subscribeOn(ObjectRef $ref): JSONResponse {
		try {
			[$object, $resolvedSchema] = $this->resolveObjectAndGuardUpdate(ref: $ref);

			$row = $this->subscriptions->requestSubscription(object: $object, schema: $resolvedSchema);

			$this->logger?->info(
				'[OpenRegister.RegistrySubscriptionController] Subscription requested for object '
				. (string)$object->getUuid() . ' on registry ' . (string)$row->getRegistry()
			);

			return new JSONResponse(data: $row->toSelfMirror());
		} catch (\Throwable $e) {
			return $this->handleApiException(e: $e, context: 'registry-subscription-request');
		}
	}//end subscribeOn()

	/**
	 * End the subscription on the named record, once the caller may update it.
	 *
	 * @param ObjectRef $ref The record the route names.
	 *
	 * @return JSONResponse The persisted subscription state, or an error.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	private function unsubscribeOn(ObjectRef $ref): JSONResponse {
		try {
			[$object] = $this->resolveObjectAndGuardUpdate(ref: $ref);

			$row = $this->subscriptions->endSubscription(object: $object);

			$this->logger?->info(
				'[OpenRegister.RegistrySubscriptionController] Subscription ended for object '
				. (string)$object->getUuid() . ' on registry ' . (string)$row->getRegistry()
			);

			return new JSONResponse(data: $row->toSelfMirror());
		} catch (\Throwable $e) {
			return $this->handleApiException(e: $e, context: 'registry-subscription-end');
		}
	}//end unsubscribeOn()

	/**
	 * Resolve the target object and its schema, then refuse (throw) unless
	 * the calling user has `update` on THIS specific object — the per-object
	 * IDOR guard both endpoints in this controller need.
	 *
	 * @param ObjectRef $ref The record the route names.
	 *
	 * @return array{0: ObjectEntity, 1: Schema} The resolved object and schema.
	 *
	 * @throws NotAuthorizedException When the caller lacks `update` on the object.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function resolveObjectAndGuardUpdate(ObjectRef $ref): array {
		$object = WriteCause::asLookup(fn () => $this->objectService->find(id: $ref->id, register: $ref->register, schema: $ref->schema, _render: false));
		$resolvedSchema = $this->schemaMapper->find(id: (string)$object->getSchema());

		if ($this->mayUpdate(schema: $resolvedSchema, object: $object) === false) {
			throw new NotAuthorizedException(message: 'You do not have permission to update this object.');
		}

		return [$object, $resolvedSchema];
	}//end resolveObjectAndGuardUpdate()

	/**
	 * Whether the caller holds `update` on the object.
	 *
	 * @param Schema       $schema The object's schema.
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool The permission handler's verdict.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	private function mayUpdate(Schema $schema, ObjectEntity $object): bool {
		$user = $this->userSession->getUser();

		return $this->permissionHandler->hasPermission(
			ask: (new PermissionAsk(schema: $schema, action: 'update'))
				->askedBy(userId: $user?->getUID(), objectOwner: $object->getOwner())
				->about(object: $object, register: null)
		);
	}//end mayUpdate()
}//end class
