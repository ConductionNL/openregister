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

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ObjectSharingControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectSharingEndpoint;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\Rbac\GrantRequest;
use OCA\OpenRegister\Service\Rbac\GrantTerms;
use OCA\OpenRegister\Service\Rbac\LinkInvitation;
use OCA\OpenRegister\Service\Rbac\LinkProtection;
use OCA\OpenRegister\Service\Rbac\ScopeChange;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

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
	 * @param IRequest                        $request  Request.
	 * @param ObjectSharingControllerServices $services The object sharing controller services.
	 */
	public function __construct(
		IRequest $request,
		ObjectSharingControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->services = $services;
		$failure = 'Could not complete the sharing request';
		$this->endpoint = new ObjectSharingEndpoint(objectService: $services->objectService, logger: $services->logger, file: __FILE__, failure: $failure);
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
		return $this->scopeOn(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
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
		return $this->setScopeOn(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
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
		return $this->sharesOn(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
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
		return $this->createShareOn(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
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
		return $this->createLinkOn(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
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
		return $this->inviteByEmailOn(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end inviteByEmail()

	/**
	 * Read one object's effective scope.
	 *
	 * @param ObjectRef $ref The record the route names.
	 *
	 * @return JSONResponse The scope.
	 */
	private function scopeOn(ObjectRef $ref): JSONResponse {
		$object = $this->resolved(ref: $ref);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$block = ($object->getAuthorization() ?? []);
		$scope = null;
		if (is_array($block) === true) {
			$scope = ($block['scope'] ?? null);
		}

		return new JSONResponse(['scope' => $scope]);
	}//end scopeOn()

	/**
	 * Set one object's scope.
	 *
	 * @param ObjectRef $ref The record the route names.
	 *
	 * @return JSONResponse The stored block, or an error.
	 */
	private function setScopeOn(ObjectRef $ref): JSONResponse {
		$object = $this->resolved(ref: $ref);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$scope = $this->request->getParam('scope');
		if (is_string($scope) === false) {
			return new JSONResponse(['message' => 'A scope is required'], Http::STATUS_BAD_REQUEST);
		}

		$store = fn (): array => ['scope' => ($this->storeScope(object: $object, scope: $scope)['scope'] ?? null)];
		return $this->endpoint->answerValidated(operation: $store, context: 'setScope');
	}//end setScopeOn()

	/**
	 * List the grants on one object.
	 *
	 * @param ObjectRef $ref The record the route names.
	 *
	 * @return JSONResponse The grants, or an error.
	 */
	private function sharesOn(ObjectRef $ref): JSONResponse {
		$object = $this->resolved(ref: $ref);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$list = fn (): array => ['results' => $this->services->sharing->listGrants(object: $object)];
		return $this->endpoint->answer(operation: $list, context: 'shares');
	}//end sharesOn()

	/**
	 * Grant one principal access to one object.
	 *
	 * @param ObjectRef $ref The record the route names.
	 *
	 * @return JSONResponse The created grant, or an error.
	 */
	private function createShareOn(ObjectRef $ref): JSONResponse {
		$object = $this->resolved(ref: $ref);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$type = (string)($this->request->getParam('type') ?? 'user');
		$shareWith = (string)($this->request->getParam('shareWith') ?? '');
		$permissions = $this->requestedPermissions();

		$grantRequest = new GrantRequest(type: $type, shareWith: $shareWith, terms: new GrantTerms(permissions: $permissions));
		$grant        = fn (): array => $this->services->sharing->grantWithTerms(object: $object, request: $grantRequest);
		return $this->endpoint->answerCreated(operation: $grant, context: 'createShare');
	}//end createShareOn()

	/**
	 * Create a tokenised link to an object.
	 *
	 * @param ObjectRef $ref The record the route names.
	 *
	 * @return JSONResponse The created link, or an error.
	 */
	private function createLinkOn(ObjectRef $ref): JSONResponse {
		$object = $this->resolved(ref: $ref);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$link = fn (): array => $this->linkTo(object: $object);
		return $this->endpoint->answerCreated(operation: $link, context: 'createLink');
	}//end createLinkOn()

	/**
	 * Invite an email address to an object.
	 *
	 * @param ObjectRef $ref The record the route names.
	 *
	 * @return JSONResponse The created invitation, or an error.
	 */
	private function inviteByEmailOn(ObjectRef $ref): JSONResponse {
		$object = $this->resolved(ref: $ref);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$invite = fn (): array => $this->invitationTo(object: $object);
		return $this->endpoint->answerCreated(operation: $invite, context: 'inviteByEmail');
	}//end inviteByEmailOn()

	/**
	 * Revoke one grant.
	 *
	 * @param ObjectRef $ref The record the route names.
	 * @param string $shareId The grant to revoke.
	 *
	 * @return JSONResponse Empty on success, or an error.
	 */
	private function destroyShareOn(ObjectRef $ref, string $shareId): JSONResponse {
		$object = $this->resolved(ref: $ref);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$revoke = function () use ($object, $shareId): array {
			$this->services->sharing->revoke(object: $object, shareId: $shareId);
			return [];
		};
		return $this->endpoint->answerRemoved(operation: $revoke, context: 'destroyShare');
	}//end destroyShareOn()

	/**
	 * Resolve the record a route names through the RBAC boundary.
	 *
	 * @param ObjectRef $ref The record the route names.
	 *
	 * @return ObjectEntity|JSONResponse The object, or the response to return.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	private function resolved(ObjectRef $ref): ObjectEntity|JSONResponse {
		return $this->endpoint->resolve(ref: $ref);
	}//end resolved()

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
		return $this->destroyShareOn(ref: new ObjectRef(register: $register, schema: $schema, id: $id), shareId: $shareId);
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
		$table = new RegisterSchema(
			register: $this->services->catalog->registers()->find($this->services->objectService->getRegister()),
			schema: $this->services->catalog->schemas()->find($this->services->objectService->getSchema())
		);

		return $this->services->sharing->setScope(table: $table, change: new ScopeChange(object: $object, scope: $scope));
	}//end storeScope()

	/**
	 * Create the link the request describes.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array The created link.
	 */
	private function linkTo(ObjectEntity $object): array {
		return $this->services->sharing->createLink(object: $object, invitation: $this->invitation(recipient: null));
	}//end linkTo()

	/**
	 * Send the invitation the request describes.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array The created invitation.
	 */
	private function invitationTo(ObjectEntity $object): array {
		$email = (string)($this->request->getParam('email') ?? '');

		return $this->services->sharing->inviteByEmail(object: $object, invitation: $this->invitation(recipient: $email));
	}//end invitationTo()

	/**
	 * The link or invitation the request describes: its permissions (READ unless asked otherwise), password and expiry.
	 *
	 * @param string|null $recipient The address to invite, or null for a link.
	 *
	 * @return LinkInvitation The invitation.
	 */
	private function invitation(?string $recipient): LinkInvitation {
		$protection = new LinkProtection(password: $this->optionalParam(name: 'password'), expiration: $this->optionalParam(name: 'expiration'));

		return new LinkInvitation(recipient: $recipient, permissions: $this->requestedPermissions(), protection: $protection);
	}//end invitation()
}//end class
