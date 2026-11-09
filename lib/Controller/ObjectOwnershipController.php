<?php

/**
 * Object ownership controller — read who owns a record, take it, or reassign it.
 *
 * The write side of ownership, and deliberately narrow. The owner column is not
 * writable through an object save: the save path derives the owner from the
 * acting user and refuses a request that names a different one, because a caller
 * who can set the owner can grant itself edit rights. So every legitimate change
 * of owner comes through here, where it is checked, recorded and announced.
 *
 * WHY A PLAIN `Controller` AND NOT AN `OCSController`. Nextcloud's
 * `OCSMiddleware` turns a 403 thrown out of an OCS controller into an HTTP 200
 * carrying the refusal in its body, so a refusal reads as success to anything
 * that looks at the status — a browser, a client library, a monitor. The
 * refusals here are the point of the endpoint, so they must survive: a plain
 * `Controller` returning a `JSONResponse` keeps the status it was given.
 *
 * EXISTENCE IS NOT LEAKED. A record the caller cannot READ answers 404, never
 * 403, so these endpoints cannot be used to find out which record ids exist —
 * the same guard the sharing and integration endpoints use.
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
 * @spec openspec/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\ObjectOwnershipControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectSharingEndpoint;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolverInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Checked endpoints for a record's owner and its owning group.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A controller that resolves a
 * record through the RBAC boundary, its register, its schema and the ownership
 * service, and answers four statuses, names those types; the alternative is a
 * second controller that duplicates the resolve.
 */
class ObjectOwnershipController extends Controller {

	/**
	 * The controller's injected services.
	 *
	 * @var ObjectOwnershipControllerServices
	 */
	private readonly ObjectOwnershipControllerServices $services;

	/**
	 * Resolves the target record through the RBAC boundary, runs the checked
	 * writes and maps their refusals.
	 *
	 * @var ObjectSharingEndpoint
	 */
	private readonly ObjectSharingEndpoint $endpoint;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param ObjectOwnershipControllerServices $services The object ownership controller services.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ObjectOwnershipControllerServices $services,
		LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->services = $services;
		// The message is never echoed to the client: it can carry storage details.
		$failure = 'Could not complete the ownership request';
		$this->endpoint = new ObjectSharingEndpoint(objectService: $services->objectService, logger: $logger, file: __FILE__, failure: $failure);
	}//end __construct()

	/**
	 * Read who owns one record.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Record uuid.
	 *
	 * @return JSONResponse The owner and the owning group.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	#[NoAdminRequired]
	public function show(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$block = ($object->getAuthorization() ?? []);

		return new JSONResponse(
			[
				'owner' => $object->getOwner(),
				'ownerGroup' => ($block[ObjectScopeResolverInterface::OWNER_GROUP_KEY] ?? null),
			]
		);
	}//end show()

	/**
	 * Take ownership of one record, as the signed-in user.
	 *
	 * The body carries no owner. The new owner is the acting user, which is what
	 * makes ownership underivable from the request.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Record uuid.
	 *
	 * @return JSONResponse The outcome, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	#[NoAdminRequired]
	public function claim(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$claim = fn (): array => $this->services->ownership->claim(
			register: $this->register(),
			schema: $this->schema(),
			object: $object,
			cascade: $this->cascadeRequested()
		);
		return $this->endpoint->answer(operation: $claim, context: 'claim');
	}//end claim()

	/**
	 * Assign one record to a named owner. Administrators only.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Record uuid.
	 *
	 * @return JSONResponse The outcome, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	#[NoAdminRequired]
	public function assign(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$owner = $this->requestedOwner();
		if ($owner === null) {
			return $this->badRequest(message: 'An owner is required');
		}

		$assign = fn (): array => $this->assigned(object: $object, owner: $owner);
		return $this->endpoint->answer(operation: $assign, context: 'assign', validates: true);
	}//end assign()

	/**
	 * Name the group that owns one record, or take the owning group away.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Record uuid.
	 *
	 * @return JSONResponse The owning group, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	#[NoAdminRequired]
	public function setOwnerGroup(string $register, string $schema, string $id): JSONResponse {
		$object = $this->endpoint->resolve(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$group = $this->request->getParam('ownerGroup');
		if ($group !== null && is_string($group) === false) {
			return $this->badRequest(message: 'An owner group must be a group id');
		}

		$key   = ObjectScopeResolverInterface::OWNER_GROUP_KEY;
		$store = fn (): array => ['ownerGroup' => ($this->storeOwnerGroup(object: $object, group: $group)[$key] ?? null)];
		return $this->endpoint->answer(operation: $store, context: 'setOwnerGroup', validates: true);
	}//end setOwnerGroup()

	/**
	 * Reassign many records to one owner, in one action. Administrators only.
	 *
	 * The records are named by identifier rather than resolved through a
	 * register and schema, because a departure is rarely confined to one schema.
	 *
	 * @return JSONResponse What moved, what did not, and why.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	#[NoAdminRequired]
	public function reassign(): JSONResponse {
		$owner = $this->requestedOwner();
		if ($owner === null) {
			return $this->badRequest(message: 'An owner is required');
		}

		$objects = $this->request->getParam('objects');
		if (is_array($objects) === false || $objects === []) {
			return $this->badRequest(message: 'Name at least one record to reassign');
		}

		$reassign = fn (): array => $this->reassigned(objects: $objects, owner: $owner);
		return $this->endpoint->answer(operation: $reassign, context: 'reassign', validates: true);
	}//end reassign()

	/**
	 * The owner the request names, or null when it names none.
	 *
	 * @return string|null The owner.
	 */
	private function requestedOwner(): ?string {
		$owner = $this->request->getParam('owner');
		if (is_string($owner) === false || $owner === '') {
			return null;
		}

		return $owner;
	}//end requestedOwner()

	/**
	 * A 400 carrying the given message.
	 *
	 * @param string $message What the request lacks.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function badRequest(string $message): JSONResponse {
		return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
	}//end badRequest()

	/**
	 * Store the owning group on the record.
	 *
	 * @param ObjectEntity $object The record.
	 * @param string|null  $group  The group id, or null to take the owning group away.
	 *
	 * @return array The stored authorization block.
	 */
	private function storeOwnerGroup(ObjectEntity $object, ?string $group): array {
		return $this->services->ownership->setOwnerGroup(
			register: $this->register(),
			schema: $this->schema(),
			object: $object,
			group: $group
		);
	}//end storeOwnerGroup()

	/**
	 * Assign the record to the named owner.
	 *
	 * @param ObjectEntity $object The record.
	 * @param string       $owner  The new owner.
	 *
	 * @return array The outcome.
	 */
	private function assigned(ObjectEntity $object, string $owner): array {
		return $this->services->ownership->assign(
			register: $this->register(),
			schema: $this->schema(),
			object: $object,
			newOwner: $owner,
			cascade: $this->cascadeRequested()
		);
	}//end assigned()

	/**
	 * Reassign the named records to the named owner.
	 *
	 * @param array  $objects The records' identifiers.
	 * @param string $owner   The new owner.
	 *
	 * @return array What moved, what did not, and why.
	 */
	private function reassigned(array $objects, string $owner): array {
		return $this->services->ownership->reassignMany(
			identifiers: array_values($objects),
			newOwner: $owner,
			cascade: $this->cascadeRequested()
		);
	}//end reassigned()

	/**
	 * The register the request resolved.
	 *
	 * @return Register The register.
	 */
	private function register(): Register {
		return $this->services->registerMapper->find($this->services->objectService->getRegister());
	}//end register()

	/**
	 * The schema the request resolved.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		return $this->services->schemaMapper->find($this->services->objectService->getSchema());
	}//end schema()

	/**
	 * Whether the caller asked for the change to carry to child records.
	 *
	 * Off unless asked for. A handover that silently moved every related record
	 * would be a bigger action than the one the caller named.
	 *
	 * @return bool True when the request asked for the cascade.
	 */
	private function cascadeRequested(): bool {
		$cascade = $this->request->getParam('cascade');

		return ($cascade === true || $cascade === 'true' || $cascade === '1' || $cascade === 1);
	}//end cascadeRequested()
}//end class
