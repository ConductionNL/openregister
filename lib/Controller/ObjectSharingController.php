<?php

/**
 * Object sharing controller — set an object's scope, and grant or revoke it.
 *
 * The read side of this capability is enforced inside RBAC, on all four paths
 * ({@see \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver} and
 * {@see \OCA\OpenRegister\Service\Rbac\ObjectGrantResolver}). This is the write
 * side, and it is deliberately narrow.
 *
 * WHY THESE ENDPOINTS EXIST AT ALL. `_authorization` cannot be written through an
 * ordinary object save: non-admin writes have it stripped, and the object write
 * path omits the column so a routine update carries the stored value forward
 * rather than destroying it. Both of those are correct — per-object RBAC must not
 * be reachable through a data field. So changing an object's scope needs its own
 * owner-checked entry point, which is this.
 *
 * EVERY METHOD IS OWNER-OR-ADMIN. Two guards apply in order, and both are needed:
 *
 *  1. `guardObjectAccess()` resolves the object through `ObjectService`, which
 *     applies register RBAC and multitenancy. An object the caller cannot READ
 *     resolves to null and the request is refused with 404 — existence is not
 *     leaked (the IDOR guard the integrations endpoints already use).
 *  2. `ObjectSharingService` then requires the caller to be the OWNER or an
 *     administrator, using the same resolver the read side uses, so "who may
 *     change sharing" and "who is admitted unconditionally" cannot drift.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\ObjectSharingControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectSharingEndpoint;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Owner-checked endpoints for an object's scope and its grants.
 */
class ObjectSharingController extends Controller {
	/**
	 * The controller's injected services.
	 *
	 * @var ObjectSharingControllerServices
	 */
	private readonly ObjectSharingControllerServices $services;

	/**
	 * Resolves the target object through the RBAC boundary, runs the
	 * owner-checked writes and maps their refusals.
	 *
	 * @var ObjectSharingEndpoint
	 */
	private readonly ObjectSharingEndpoint $endpoint;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param ObjectSharingControllerServices $services The object sharing controller services.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ObjectSharingControllerServices $services,
		LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->services = $services;
		$failure = 'Could not complete the sharing request';
		$this->endpoint = new ObjectSharingEndpoint(objectService: $services->objectService, logger: $logger, file: __FILE__, failure: $failure);
	}//end __construct()

	/**
	 * Read one object's effective scope.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The scope.
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function scope(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$block = ($object->getAuthorization() ?? []);
		$scope = null;
		if (is_array($block) === true) {
			$scope = ($block['scope'] ?? null);
		}

		return new JSONResponse(['scope' => $scope]);
	}//end scope()

	/**
	 * Set one object's scope.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The stored block, or an error.
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function setScope(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$scope = $this->request->getParam('scope');
		if (is_string($scope) === false) {
			return new JSONResponse(['message' => 'A scope is required'], Http::STATUS_BAD_REQUEST);
		}

		$store = fn (): array => ['scope' => ($this->storeScope(object: $object, scope: $scope)['scope'] ?? null)];
		return $this->endpoint->answer(operation: $store, context: 'setScope', validates: true);
	}//end setScope()

	/**
	 * List the grants on one object.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The grants, or an error.
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function shares(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$list = fn (): array => ['results' => $this->services->sharing->listGrants(object: $object)];
		return $this->endpoint->answer(operation: $list, context: 'shares');
	}//end shares()

	/**
	 * Grant one principal access to one object.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The created grant, or an error.
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function createShare(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$type = (string)($this->request->getParam('type') ?? 'user');
		$shareWith = (string)($this->request->getParam('shareWith') ?? '');
		$permissions = $this->requestedPermissions();

		$grant = fn (): array => $this->services->sharing->grant(object: $object, type: $type, shareWith: $shareWith, permissions: $permissions);
		return $this->endpoint->answer(operation: $grant, context: 'createShare', validates: true, status: Http::STATUS_CREATED);
	}//end createShare()

	/**
	 * Create a tokenised link to an object.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The created link, or an error.
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function createLink(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$link = fn (): array => $this->linkTo(object: $object);
		return $this->endpoint->answer(operation: $link, context: 'createLink', validates: true, status: Http::STATUS_CREATED);
	}//end createLink()

	/**
	 * Invite an email address to an object.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 *
	 * @return JSONResponse The created invitation, or an error.
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function inviteByEmail(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$invite = fn (): array => $this->invitationTo(object: $object);
		return $this->endpoint->answer(operation: $invite, context: 'inviteByEmail', validates: true, status: Http::STATUS_CREATED);
	}//end inviteByEmail()

	/**
	 * The requested permission bitmask, defaulting to READ.
	 *
	 * Defaulting to read is the safe direction: a grant or link that silently
	 * carried write because the caller omitted a field would be the wrong surprise.
	 *
	 * @return integer The bitmask.
	 */
	private function requestedPermissions(): int {
		$requested = $this->request->getParam('permissions');
		if (is_numeric($requested) === true) {
			return (int)$requested;
		}

		return 1;
	}//end requestedPermissions()

	/**
	 * A request parameter, or null when it is absent or blank.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value.
	 */
	private function optionalParam(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end optionalParam()

	/**
	 * Revoke one grant.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object uuid.
	 * @param string $shareId The grant to revoke.
	 *
	 * @return JSONResponse Empty on success, or an error.
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function destroyShare(string $register, string $schema, string $id, string $shareId): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$revoke = function () use ($object, $shareId): array {
			$this->services->sharing->revoke(object: $object, shareId: $shareId);
			return [];
		};
		return $this->endpoint->answer(operation: $revoke, context: 'destroyShare', validates: true, status: Http::STATUS_NO_CONTENT);
	}//end destroyShare()

	/**
	 * Store a new scope on the object, in the register and schema the request resolved.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $scope  The requested scope.
	 *
	 * @return array The stored authorization block.
	 */
	private function storeScope(ObjectEntity $object, string $scope): array {
		return $this->services->sharing->setScope(
			register: $this->services->registerMapper->find($this->services->objectService->getRegister()),
			schema: $this->services->schemaMapper->find($this->services->objectService->getSchema()),
			object: $object,
			scope: $scope
		);
	}//end storeScope()

	/**
	 * Create the link the request describes.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array The created link.
	 */
	private function linkTo(ObjectEntity $object): array {
		return $this->services->sharing->createLink(
			object: $object,
			permissions: $this->requestedPermissions(),
			password: $this->optionalParam(name: 'password'),
			expiration: $this->optionalParam(name: 'expiration')
		);
	}//end linkTo()

	/**
	 * Send the invitation the request describes.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array The created invitation.
	 */
	private function invitationTo(ObjectEntity $object): array {
		return $this->services->sharing->inviteByEmail(
			object: $object,
			email: (string)($this->request->getParam('email') ?? ''),
			permissions: $this->requestedPermissions(),
			password: $this->optionalParam(name: 'password'),
			expiration: $this->optionalParam(name: 'expiration')
		);
	}//end invitationTo()
}//end class
