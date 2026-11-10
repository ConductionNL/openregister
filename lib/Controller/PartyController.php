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
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\PartyControllerServices;
use OCA\OpenRegister\Controller\Support\PartyObjectAccess;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuardInterface;
use OCA\OpenRegister\Service\Party\PartyRoleServiceInterface;
use OCA\OpenRegister\Service\Party\PartySearchInterface;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use OCP\AppFramework\Controller;
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
	 * Resolves the object a party sits on and asks the update right of a write.
	 *
	 * @var PartyObjectAccess
	 */
	private readonly PartyObjectAccess $objectAccess;

	/**
	 * The parties on an object.
	 *
	 * @var PartyRoleServiceInterface
	 */
	private readonly PartyRoleServiceInterface $roles;

	/**
	 * The party records.
	 *
	 * @var PartyServiceInterface
	 */
	private readonly PartyServiceInterface $parties;

	/**
	 * The declared effects.
	 *
	 * @var PartyIndicatorGuardInterface
	 */
	private readonly PartyIndicatorGuardInterface $indicators;

	/**
	 * The capped party query.
	 *
	 * @var PartySearchInterface
	 */
	private readonly PartySearchInterface $search;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param PartyControllerServices $services The party controller services.
	 */
	public function __construct(
		IRequest $request,
		PartyControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->objectAccess = new PartyObjectAccess(objects: $services->domain->objects, permissions: $services->search->permissions);
		$this->roles = $services->domain->roles;
		$this->parties = $services->domain->parties;
		$this->indicators = $services->domain->indicators;
		$this->search = $services->search->search;
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
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->indexForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * index() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse The listing.
	 */
	private function indexForObject(ObjectRef $ref): JSONResponse {
		return $this->objectAccess->read(
			target: [(string)$ref->register, (string)$ref->schema, $ref->id],
			handler: fn (ObjectEntity $object): JSONResponse => new JSONResponse(
				$this->roles->listForObject(objectUuid: (string)$object->getUuid(), schemaId: $object->getSchema())
			)
		);
	}//end indexForObject()

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
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function create(string $register, string $schema, string $id): JSONResponse {
		return $this->createForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end create()

	/**
	 * create() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse The link as stored, or the refusal naming the kind.
	 */
	private function createForObject(ObjectRef $ref): JSONResponse {
		return $this->objectAccess->write(
			target: [(string)$ref->register, (string)$ref->schema, $ref->id],
			handler: fn (ObjectEntity $object): JSONResponse => new JSONResponse($this->addParty(object: $object)->jsonSerialize(), 201)
		);
	}//end createForObject()

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
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function replacePrimary(string $register, string $schema, string $id): JSONResponse {
		return $this->replacePrimaryForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end replacePrimary()

	/**
	 * replacePrimary() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse The new primary link.
	 */
	private function replacePrimaryForObject(ObjectRef $ref): JSONResponse {
		return $this->objectAccess->write(
			target: [(string)$ref->register, (string)$ref->schema, $ref->id],
			handler: fn (ObjectEntity $object): JSONResponse => $this->replacePrimaryOf(object: $object)
		);
	}//end replacePrimaryForObject()

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
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function destroy(string $register, string $schema, string $id, string $partyUuid): JSONResponse {
		return $this->destroyForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id), partyUuid: $partyUuid);
	}//end destroy()

	/**
	 * destroy() for the object the route names.
	 *
	 * @param ObjectRef $ref       The object the route names.
	 * @param string    $partyUuid The party.
	 *
	 * @return JSONResponse How many roles went.
	 */
	private function destroyForObject(ObjectRef $ref, string $partyUuid): JSONResponse {
		return $this->objectAccess->write(
			target: [(string)$ref->register, (string)$ref->schema, $ref->id],
			handler: fn (ObjectEntity $object): JSONResponse => new JSONResponse(
				['removed' => $this->removeParty(object: $object, partyUuid: $partyUuid)]
			)
		);
	}//end destroyForObject()

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
	 * @throws Exception Never past the controller: a failure below becomes the JSON error response.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Reads only what the acting user may already read:
	 *   the party record goes through ObjectService (RBAC, tenant scoped), and
	 *   an unreadable party answers 404 rather than its link rows.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function show(string $partyUuid): JSONResponse {
		try {
			return $this->describe(partyUuid: $partyUuid);
		} catch (Exception $e) {
			return $this->objectAccess->errorResponse(exception: $e);
		}
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
	 * @spec openspec/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function search(): JSONResponse {
		try {
			return new JSONResponse(
				$this->search->search(
					query: (string)$this->request->getParam('q', ''),
					schemaId: self::textOrNull(value: $this->request->getParam('schema', ''))
				)
			);
		} catch (Exception $e) {
			return $this->objectAccess->errorResponse(exception: $e);
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
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
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

		return new JSONResponse($this->addressed(party: $party));
	}//end resolve()

	/**
	 * Give a party a role on an object, from the request payload.
	 *
	 * @param ObjectEntity $object The object, its update right already asked.
	 *
	 * @return ContactLink The link as stored.
	 */
	private function addParty(ObjectEntity $object): ContactLink {
		return $this->roles->addParty(
			objectUuid: (string)$object->getUuid(),
			registerId: (int)($object->getRegister() ?? 0),
			schemaId: $object->getSchema(),
			payload: $this->request->getParams()
		);
	}//end addParty()

	/**
	 * Replace the primary party of an object, from the request.
	 *
	 * @param ObjectEntity $object The object, its update right already asked.
	 *
	 * @return JSONResponse The new primary link, or 400 without a party.
	 */
	private function replacePrimaryOf(ObjectEntity $object): JSONResponse {
		$partyUuid = trim((string)$this->request->getParam('partyUuid', ''));
		if ($partyUuid === '') {
			return new JSONResponse(['error' => 'partyUuid is required'], 400);
		}

		$role = self::textOrNull(value: $this->request->getParam('role', ''));
		$link = $this->roles->replacePrimaryParty(object: $object, partyUuid: $partyUuid, role: $role);

		return new JSONResponse($link->jsonSerialize());
	}//end replacePrimaryOf()

	/**
	 * Take a party off an object: every role, or the one the request names.
	 *
	 * @param ObjectEntity $object    The object, its update right already asked.
	 * @param string       $partyUuid The party.
	 *
	 * @return int How many roles went.
	 */
	private function removeParty(ObjectEntity $object, string $partyUuid): int {
		return $this->roles->removeParty(
			objectUuid: (string)$object->getUuid(),
			partyUuid: $partyUuid,
			role: self::textOrNull(value: $this->request->getParam('role', ''))
		);
	}//end removeParty()

	/**
	 * A party's kind, addresses, indicators and objects, or the refusal.
	 *
	 * @param string $partyUuid The party.
	 *
	 * @return JSONResponse The party, 404 when unreadable, 400 when not a party.
	 */
	private function describe(string $partyUuid): JSONResponse {
		$party = $this->parties->find(partyUuid: $partyUuid);
		if ($party === null) {
			return new JSONResponse(['error' => 'Party not found'], 404);
		}

		$definition = $this->parties->definitionFor(party: $party);
		if ($definition === null) {
			return new JSONResponse(['error' => 'Object "' . $partyUuid . '" is not a party'], 400);
		}

		$body = $this->described(party: $party, definition: $definition);
		$body['objects'] = $this->indicators->objectsOfParty(partyUuid: $partyUuid);

		return new JSONResponse($body);
	}//end describe()

	/**
	 * The body of a party read.
	 *
	 * The caller adds the objects of the party, keyed on the party as asked for.
	 *
	 * @param ObjectEntity    $party      The party record.
	 * @param PartyDefinition $definition Its party definition.
	 *
	 * @return array<string, mixed> The body.
	 */
	private function described(ObjectEntity $party, PartyDefinition $definition): array {
		return [
			'uuid' => $party->getUuid(),
			'kind' => $definition->kind(),
			'addresses' => $this->parties->addresses(party: $party, definition: $definition),
			'indicators' => $this->parties->indicators(party: $party, definition: $definition),
		];
	}//end described()

	/**
	 * The body of an address resolution.
	 *
	 * @param ObjectEntity $party The party holding the address.
	 *
	 * @return array<string, mixed> The body.
	 */
	private function addressed(ObjectEntity $party): array {
		return [
			'uuid' => $party->getUuid(),
			'register' => $party->getRegister(),
			'schema' => $party->getSchema(),
			'addresses' => $this->parties->addresses(party: $party),
		];
	}//end addressed()

	/**
	 * A trimmed request parameter, or null when it carries nothing.
	 *
	 * @param mixed $value The raw parameter.
	 *
	 * @return string|null The text.
	 */
	private static function textOrNull(mixed $value): ?string {
		$text = trim((string)($value ?? ''));
		if ($text === '') {
			return null;
		}

		return $text;
	}//end textOrNull()
}//end class
