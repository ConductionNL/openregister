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
 * @spec openspec/changes/object-ownership-and-handover/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
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
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param ObjectService $objectService Resolves a record through the RBAC boundary.
	 * @param RegisterMapper $registerMapper Resolves the register entity.
	 * @param SchemaMapper $schemaMapper Resolves the schema entity.
	 * @param ObjectOwnershipService $ownership Performs the checked writes.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ObjectOwnershipService $ownership,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
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
	 * @spec openspec/changes/object-ownership-and-handover/specs/object-ownership/spec.md
	 */
	#[NoAdminRequired]
	public function show(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$block = ($object->getAuthorization() ?? []);

		return new JSONResponse(
			[
				'owner' => $object->getOwner(),
				'ownerGroup' => ($block[ObjectScopeResolver::OWNER_GROUP_KEY] ?? null),
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
	 * @spec openspec/changes/object-ownership-and-handover/specs/object-ownership/spec.md
	 */
	#[NoAdminRequired]
	public function claim(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		try {
			$outcome = $this->ownership->claim(
				register: $this->registerMapper->find($this->objectService->getRegister()),
				schema: $this->schemaMapper->find($this->objectService->getSchema()),
				object: $object,
				cascade: $this->cascadeRequested()
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'claim');
		}

		return new JSONResponse($outcome);
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
	 * @spec openspec/changes/object-ownership-and-handover/specs/object-ownership/spec.md
	 */
	#[NoAdminRequired]
	public function assign(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$owner = $this->request->getParam('owner');
		if (is_string($owner) === false || $owner === '') {
			return new JSONResponse(['message' => 'An owner is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$outcome = $this->ownership->assign(
				register: $this->registerMapper->find($this->objectService->getRegister()),
				schema: $this->schemaMapper->find($this->objectService->getSchema()),
				object: $object,
				newOwner: $owner,
				cascade: $this->cascadeRequested()
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'assign');
		}

		return new JSONResponse($outcome);
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
	 * @spec openspec/changes/object-ownership-and-handover/specs/object-ownership/spec.md
	 */
	#[NoAdminRequired]
	public function setOwnerGroup(string $register, string $schema, string $id): JSONResponse {
		$object = $this->resolveObject(register: $register, schema: $schema, id: $id);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$group = $this->request->getParam('ownerGroup');
		if ($group !== null && is_string($group) === false) {
			return new JSONResponse(['message' => 'An owner group must be a group id'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$block = $this->ownership->setOwnerGroup(
				register: $this->registerMapper->find($this->objectService->getRegister()),
				schema: $this->schemaMapper->find($this->objectService->getSchema()),
				object: $object,
				group: $group
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'setOwnerGroup');
		}

		return new JSONResponse(
			['ownerGroup' => ($block[ObjectScopeResolver::OWNER_GROUP_KEY] ?? null)]
		);
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
	 * @spec openspec/changes/object-ownership-and-handover/specs/object-ownership/spec.md
	 */
	#[NoAdminRequired]
	public function reassign(): JSONResponse {
		$owner = $this->request->getParam('owner');
		if (is_string($owner) === false || $owner === '') {
			return new JSONResponse(['message' => 'An owner is required'], Http::STATUS_BAD_REQUEST);
		}

		$objects = $this->request->getParam('objects');
		if (is_array($objects) === false || $objects === []) {
			return new JSONResponse(['message' => 'Name at least one record to reassign'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$outcome = $this->ownership->reassignMany(
				identifiers: array_values($objects),
				newOwner: $owner,
				cascade: $this->cascadeRequested()
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return $this->unexpected(exception: $e, context: 'reassign');
		}

		return new JSONResponse($outcome);
	}//end reassign()

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

	/**
	 * Resolve the target record through the RBAC boundary.
	 *
	 * A record the caller cannot READ is refused with 404 rather than 403, so
	 * this endpoint cannot be used to probe which record ids exist.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Record uuid.
	 *
	 * @return ObjectEntity|JSONResponse The record, or the response to return.
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
	 * The message is never echoed to the client: it can carry storage details.
	 *
	 * @param \Throwable $exception The failure.
	 * @param string $context Short label for the log line.
	 *
	 * @return JSONResponse A generic 500.
	 */
	private function unexpected(\Throwable $exception, string $context): JSONResponse {
		$this->logger->error(
			message: '[ObjectOwnershipController] ' . $context . ': ' . $exception->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $exception]
		);

		return new JSONResponse(
			['message' => 'Could not complete the ownership request'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end unexpected()

}//end class
