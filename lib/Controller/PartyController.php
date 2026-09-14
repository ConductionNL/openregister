<?php

/**
 * PartyController
 *
 * The parties on an object: who they are, in which role, for how long, and
 * which of them the object is filed against. Plus the two reads that hang
 * off a party rather than an object — every case it holds a role on, and a
 * capped search over the party registers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Party\PartyRoleService;
use OCA\OpenRegister\Service\Party\PartySearchService;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * REST surface for the party model.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One controller over the
 *   party model's four reads and three writes; splitting it per read would
 *   scatter one resource across four routes files.
 */
class PartyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName Application name.
	 * @param IRequest $request HTTP request.
	 * @param ObjectService $objects Object service, to resolve the object a party sits on.
	 * @param PartyRoleService $roles The parties on an object.
	 * @param PartyService $parties The party records.
	 * @param PartyIndicatorGuard $indicators The declared effects.
	 * @param PartySearchService $search The capped party query.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objects,
		private readonly PartyRoleService $roles,
		private readonly PartyService $parties,
		private readonly PartyIndicatorGuard $indicators,
		private readonly PartySearchService $search,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The parties on an object, grouped by role, with what the schema accepts.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 *
	 * @return JSONResponse The listing.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->withObject(
			register: $register,
			schema: $schema,
			id: $id,
			handler: fn (ObjectEntity $object): JSONResponse => new JSONResponse(
				$this->roles->listForObject(
					objectUuid: (string)$object->getUuid(),
					schemaId: $object->getSchema()
				)
			)
		);
	}//end index()

	/**
	 * Give a party a role on an object.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 *
	 * @return JSONResponse The link as stored, or the refusal naming the kind.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function create(string $register, string $schema, string $id): JSONResponse {
		return $this->withObject(
			register: $register,
			schema: $schema,
			id: $id,
			handler: function (ObjectEntity $object): JSONResponse {
				$link = $this->roles->addParty(
					objectUuid: (string)$object->getUuid(),
					registerId: (int)($object->getRegister() ?? 0),
					schemaId: $object->getSchema(),
					payload: $this->request->getParams()
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end create()

	/**
	 * Replace the party the object is filed against.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 *
	 * @return JSONResponse The new primary link.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function replacePrimary(string $register, string $schema, string $id): JSONResponse {
		return $this->withObject(
			register: $register,
			schema: $schema,
			id: $id,
			handler: function (ObjectEntity $object): JSONResponse {
				$partyUuid = trim((string)$this->request->getParam('partyUuid', ''));
				if ($partyUuid === '') {
					return new JSONResponse(['error' => 'partyUuid is required'], 400);
				}

				$role = trim((string)$this->request->getParam('role', ''));
				$link = $this->roles->replacePrimaryParty(
					object: $object,
					partyUuid: $partyUuid,
					role: ($role !== '' ? $role : null)
				);

				return new JSONResponse($link->jsonSerialize());
			}
		);
	}//end replacePrimary()

	/**
	 * Take a party off an object: every role, or the one named by `?role=`.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 * @param string $partyUuid The party.
	 *
	 * @return JSONResponse How many roles went.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function destroy(string $register, string $schema, string $id, string $partyUuid): JSONResponse {
		return $this->withObject(
			register: $register,
			schema: $schema,
			id: $id,
			handler: function (ObjectEntity $object) use ($partyUuid): JSONResponse {
				$role = trim((string)$this->request->getParam('role', ''));

				return new JSONResponse(
					[
						'removed' => $this->roles->removeParty(
							objectUuid: (string)$object->getUuid(),
							partyUuid: $partyUuid,
							role: ($role !== '' ? $role : null)
						),
					]
				);
			}
		);
	}//end destroy()

	/**
	 * Every object a party holds a role on, and the indicators it carries.
	 *
	 * The indicators are read here, from the party's side, which is what
	 * lets three cases of one party show the same indicator without any of
	 * the three being written.
	 *
	 * @param string $partyUuid The party.
	 *
	 * @return JSONResponse The party's roles and indicators.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Reads only what the acting user may already read:
	 *   the party record goes through ObjectService (RBAC, tenant scoped), and
	 *   an unreadable party answers 404 rather than its link rows.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function show(string $partyUuid): JSONResponse {
		$party = $this->parties->find(partyUuid: $partyUuid);
		if ($party === null) {
			return new JSONResponse(['error' => 'Party not found'], 404);
		}

		$definition = $this->parties->definitionFor(party: $party);
		if ($definition === null) {
			return new JSONResponse(['error' => 'Object "' . $partyUuid . '" is not a party'], 400);
		}

		return new JSONResponse(
			[
				'uuid' => $party->getUuid(),
				'kind' => $definition->kind(),
				'addresses' => $this->parties->addresses(party: $party, definition: $definition),
				'indicators' => $this->parties->indicators(party: $party, definition: $definition),
				'objects' => $this->indicators->objectsOfParty(partyUuid: $partyUuid),
			]
		);
	}//end show()

	/**
	 * Search the parties, refused over the administered cap.
	 *
	 * @return JSONResponse The matches, or a 403 naming the cap.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt A free-text query over the party schemas; takes no
	 *   object id. Every result goes through ObjectService, which is RBAC and
	 *   tenant scoped, and the cap is the proportionality control.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function search(): JSONResponse {
		try {
			$schema = trim((string)$this->request->getParam('schema', ''));

			return new JSONResponse(
				$this->search->search(
					query: (string)$this->request->getParam('q', ''),
					schemaId: ($schema !== '' ? $schema : null)
				)
			);
		} catch (Exception $e) {
			return $this->errorResponse(exception: $e);
		}
	}//end search()

	/**
	 * Resolve an inbound address to the party that holds it.
	 *
	 * @return JSONResponse The party, or 404 when no party holds the address.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Takes an address, not an object id, and answers
	 *   only with parties the acting user may read through ObjectService.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function resolve(): JSONResponse {
		$address = trim((string)$this->request->getParam('address', ''));
		if ($address === '') {
			return new JSONResponse(['error' => 'address is required'], 400);
		}

		$party = $this->parties->resolveByAddress(address: $address);
		if ($party === null) {
			return new JSONResponse(['error' => 'No party holds that address'], 404);
		}

		return new JSONResponse(
			[
				'uuid' => $party->getUuid(),
				'register' => $party->getRegister(),
				'schema' => $party->getSchema(),
				'addresses' => $this->parties->addresses(party: $party),
			]
		);
	}//end resolve()

	/**
	 * Run a handler against a resolved object, mapping the failures to status codes.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 * @param callable $handler What to do with the object.
	 *
	 * @return JSONResponse The handler's response, or the error.
	 */
	private function withObject(string $register, string $schema, string $id, callable $handler): JSONResponse {
		try {
			$this->objects->setRegister($register);
			$this->objects->setSchema($schema);
			$this->objects->setObject($id);
			$object = $this->objects->getObject();
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			return $handler($object);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->errorResponse(exception: $e);
		}//end try
	}//end withObject()

	/**
	 * An exception as a response, keeping the status it named.
	 *
	 * @param Exception $exception The exception.
	 *
	 * @return JSONResponse The error.
	 */
	private function errorResponse(Exception $exception): JSONResponse {
		$code = (int)$exception->getCode();
		if (in_array($code, [400, 401, 403, 404, 409, 422], true) === true) {
			return new JSONResponse(['error' => $exception->getMessage()], $code);
		}

		return new JSONResponse(['error' => $exception->getMessage()], 400);
	}//end errorResponse()
}//end class
