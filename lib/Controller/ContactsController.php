<?php

/**
 * ContactsController
 *
 * REST controller for contact relation operations on OpenRegister objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ContactsControllerServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ContactMatcherInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\PersonLinkChange;
use OCA\OpenRegister\Service\PersonLinkRef;
use OCA\OpenRegister\Service\PersonLinkTarget;
use OCA\OpenRegister\Service\ContactPlacement;
use OCA\OpenRegister\Service\ContactServiceInterface;
use OCA\OpenRegister\Service\PersonLinkServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * ContactsController handles contact relation operations for objects.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class ContactsController extends Controller {

	/**
	 * Contact service.
	 *
	 * @var ContactServiceInterface
	 */
	private readonly ContactServiceInterface $contactService;

	/**
	 * People on objects: the write surface for user and contact links.
	 *
	 * @var PersonLinkServiceInterface
	 */
	private readonly PersonLinkServiceInterface $personLinks;

	/**
	 * Object service.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * The controller's injected services.
	 *
	 * @var ContactsControllerServices
	 */
	private readonly ContactsControllerServices $services;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request
	 * @param ContactsControllerServices $services The contacts controller services.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		ContactsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->services = $services;

		$this->contactService = $services->domain->contactService;
		$this->personLinks = $services->platform->personLinks;
		$this->objectService = $services->domain->objectService;
	}//end __construct()

	/**
	 * List all contacts for a specific object.
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->listing(ref: $ref, handler: fn (ObjectEntity $object): JSONResponse => $this->contactsOf(object: $object));
	}//end index()

	/**
	 * Link or create a contact for an object.
	 *
	 * If addressbookId and contactUri are provided, links an existing contact.
	 * If fullName or displayName is provided, creates a new contact and links
	 * it (back-compat path for callers that don't use `/contacts/new`).
	 *
	 * Tier-2 — both the link and create paths thread the object's
	 * register + schema ids into the link row so the consumer side can
	 * scope the picker by `schemaId` without extra round-trips.
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function create(string $register, string $schema, string $id): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->withWritableObject(ref: $ref, handler: fn (ObjectEntity $object): JSONResponse => $this->linkedContact(object: $object));
	}//end create()

	/**
	 * Create a new contact (only) and link it to an object.
	 *
	 * Tier-2 dedicated route — surfaced to the `CnContactCreate` dialog
	 * so the consumer can hit a single unambiguous endpoint for the
	 * create-only flow. Accepts `displayName` (or `fullName`), `email`,
	 * `phone`, `org`, `role`. Rejects payloads carrying `contactUri`
	 * (link-existing) with a 400 — those callers must use the bare
	 * POST endpoint instead.
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-16
	 */
	public function createNew(string $register, string $schema, string $id): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->withWritableObject(ref: $ref, handler: fn (ObjectEntity $object): JSONResponse => $this->newContact(object: $object));
	}//end createNew()

	/**
	 * Run a handler on a validated object, mapping a missing object and a
	 * thrown service exception (its HTTP code, else 400) to their responses.
	 *
	 * Every per-object write shares this frame, so the 404 for an unknown
	 * object and the status of a service exception are decided once.
	 *
	 * @param ObjectRef $ref The register, schema and object id of the route.
	 * @param callable(ObjectEntity): JSONResponse $handler What to do with the object.
	 *
	 * @return JSONResponse The handler's response, or the error's.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	private function withObject(ObjectRef $ref, callable $handler): JSONResponse {
		try {
			return $this->onObject(ref: $ref, handler: $handler);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->errorResponse(exception: $e);
		}
	}//end withObject()

	/**
	 * Run a read on a validated object, as withObject() does, but answer an
	 * exception without an HTTP code with 500.
	 *
	 * @param ObjectRef $ref The register, schema and object id of the route.
	 * @param callable(ObjectEntity): JSONResponse $handler What to do with the object.
	 *
	 * @return JSONResponse The handler's response, or the error's.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	private function listing(ObjectRef $ref, callable $handler): JSONResponse {
		try {
			return $this->onObject(ref: $ref, handler: $handler);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->errorResponse(exception: $e, fallbackStatus: 500);
		}
	}//end listing()

	/**
	 * Run a handler on the object of a route: 404 when it resolves to nothing.
	 *
	 * @param ObjectRef $ref The register, schema and object id of the route.
	 * @param callable(ObjectEntity): JSONResponse $handler What to do with the object.
	 *
	 * @return JSONResponse The handler's response, or the 404.
	 *
	 * @throws DoesNotExistException When no such object exists.
	 * @throws Exception             When loading the object or the handler fails.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	private function onObject(ObjectRef $ref, callable $handler): JSONResponse {
		$object = $this->validateObject(ref: $ref);
		if ($object === null) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		}

		return $handler($object);
	}//end onObject()

	/**
	 * Run a handler on a validated object the caller may update.
	 *
	 * Changing an object's contacts changes the object, so it needs the same
	 * `update` right as editing the object; a caller who may only read it gets 403.
	 *
	 * @param ObjectRef $ref The register, schema and object id of the route.
	 * @param callable(ObjectEntity): JSONResponse $handler What to do with the object.
	 *
	 * @return JSONResponse The handler's response, or the error's.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	private function withWritableObject(ObjectRef $ref, callable $handler): JSONResponse {
		return $this->withObject(
			ref: $ref,
			handler: function (ObjectEntity $object) use ($handler): JSONResponse {
				$schemaEntity = $this->objectService->getCurrentSchemaEntity();
				if ($schemaEntity === null
					|| $this->services->platform->permissions->hasPermission(
						schema: $schemaEntity,
						action: 'update',
						objectOwner: $object->getOwner(),
						object: $object
					) === false
				) {
					return new JSONResponse(['error' => 'You may not change this object, so you cannot change its contacts.'], 403);
				}

				return $handler($object);
			}
		);
	}//end withWritableObject()

	/**
	 * The people linked to an object, grouped as the link service lists them.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The listing.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	private function contactsOf(ObjectEntity $object): JSONResponse {
		return new JSONResponse(
			$this->personLinks->listForObject(objectUuid: $object->getUuid(), schemaId: $this->resolveSchemaId(object: $object))
		);
	}//end contactsOf()

	/**
	 * Link the person a payload names, or create the new contact it names and link that.
	 *
	 * A payload naming a person (userId, or addressbookId plus contactUri)
	 * links that person; one naming only a new contact (fullName or
	 * displayName) creates it first. Naming neither, the link service
	 * answers the 400.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The link or the created contact, 201.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	private function linkedContact(ObjectEntity $object): JSONResponse {
		$data = $this->request->getParams();
		$schemaId = $this->resolveSchemaId(object: $object);
		if ($this->namesPerson(data: $data) === false && $this->namesNewContact(data: $data) === true) {
			return $this->createdContact(object: $object, data: $data);
		}

		$link = $this->personLinks->linkPerson(
			target: new PersonLinkTarget(objectUuid: $object->getUuid(), registerId: (int)$object->getRegister(), schemaId: $schemaId),
			payload: $data
		);

		return new JSONResponse($link, 201);
	}//end linkedContact()

	/**
	 * Create the contact a create-only payload describes and link it;
	 * a payload naming an existing contact, or no display name, is a 400.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The link, 201, or the 400.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	private function newContact(ObjectEntity $object): JSONResponse {
		$data = $this->request->getParams();
		if (empty($data['contactUri']) === false) {
			return new JSONResponse(['error' => 'Use POST /contacts to link an existing contact'], 400);
		}

		if (trim((string)($data['displayName'] ?? ($data['fullName'] ?? ''))) === '') {
			return new JSONResponse(['error' => 'displayName is required'], 400);
		}

		return $this->createdContact(object: $object, data: $data);
	}//end newContact()

	/**
	 * Create a contact from a payload and link it to the object, with the object's register and schema ids.
	 *
	 * @param ObjectEntity         $object The object.
	 * @param array<string, mixed> $data   The payload.
	 *
	 * @return JSONResponse The created contact's link, 201.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	private function createdContact(ObjectEntity $object, array $data): JSONResponse {
		$placement = new ContactPlacement(
			objectUuid: $object->getUuid(),
			registerId: (int)$object->getRegister(),
			schemaId: $this->resolveSchemaId(object: $object),
			role: ($data['role'] ?? null)
		);
		$created   = $this->contactService->createAndLinkContact(placement: $placement, data: $data);

		return new JSONResponse($created, 201);
	}//end createdContact()

	/**
	 * Change the editable fields (role, validity, note) of a person's link to the object.
	 *
	 * @param ObjectEntity $object     The object.
	 * @param string       $contactUid The contact UID.
	 *
	 * @return JSONResponse The updated link.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	private function updatedLink(ObjectEntity $object, string $contactUid): JSONResponse {
		$data = $this->request->getParams();

		return new JSONResponse(
			$this->personLinks->changeLink(
				change: new PersonLinkChange(
					ref: new PersonLinkRef(objectUuid: $object->getUuid(), contactUid: $contactUid, role: $this->optionalParam(name: 'currentRole')),
					schemaId: $this->resolveSchemaId(object: $object),
					changes: array_intersect_key($data, array_flip(['role', 'validFrom', 'validUntil', 'note']))
				)
			)
		);
	}//end updatedLink()

	/**
	 * Remove a person's links to the object: a numeric id is a legacy link
	 * id limited to this object's links, anything else a contact uid whose
	 * links (one role or all) are removed.
	 *
	 * @param ObjectEntity $object     The object.
	 * @param string       $contactUid The contact UID, or a numeric link id.
	 *
	 * @return JSONResponse Success, with the number removed for a contact uid.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	private function removedLink(ObjectEntity $object, string $contactUid): JSONResponse {
		// An object without a uuid owns no links, and a null uuid would lift the object check.
		$objectUuid = $object->getUuid();
		if ($objectUuid === null || $objectUuid === '') {
			return new JSONResponse(['error' => 'Contact link not found'], 404);
		}

		if (ctype_digit($contactUid) === true) {
			$this->contactService->unlinkContact(linkId: (int)$contactUid, objectUuid: $objectUuid);

			return new JSONResponse(['success' => true]);
		}

		$removed = $this->personLinks->removeLinks(
			ref: new PersonLinkRef(objectUuid: $objectUuid, contactUid: $contactUid, role: $this->optionalParam(name: 'role'))
		);

		return new JSONResponse(['success' => true, 'removed' => $removed]);
	}//end removedLink()

	/**
	 * Whether a payload names a person to link: a user, or a contact in an address book.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return bool True when it names one.
	 */
	private function namesPerson(array $data): bool {
		if (empty($data['userId']) === false) {
			return true;
		}

		return (empty($data['addressbookId']) === false && empty($data['contactUri']) === false);
	}//end namesPerson()

	/**
	 * Whether a payload names a contact to create.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return bool True when it carries a name.
	 */
	private function namesNewContact(array $data): bool {
		return (empty($data['fullName']) === false || empty($data['displayName']) === false);
	}//end namesNewContact()

	/**
	 * A non-empty string request parameter, or null.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string|null The value.
	 */
	private function optionalParam(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end optionalParam()

	/**
	 * A service exception as a response: its code when it is an HTTP status, the fallback otherwise.
	 *
	 * @param Exception $exception The exception.
	 * @param int $fallbackStatus The status for a code that is not an HTTP one.
	 *
	 * @return JSONResponse The response.
	 */
	private function errorResponse(Exception $exception, int $fallbackStatus = 400): JSONResponse {
		$code = (int)$exception->getCode();
		if (in_array($code, [400, 401, 403, 404, 409, 422], true) === true) {
			return new JSONResponse(['error' => $exception->getMessage()], $code);
		}

		return new JSONResponse(['error' => $exception->getMessage()], $fallbackStatus);
	}//end errorResponse()

	/**
	 * Resolve the object's schema id as an int (or null).
	 *
	 * `ObjectEntity::getSchema()` returns the schema id as a string;
	 * the link table accepts a nullable int.
	 *
	 * @param ObjectEntity $object The object entity.
	 *
	 * @return int|null
	 */
	private function resolveSchemaId(ObjectEntity $object): ?int {
		$schema = $object->getSchema();
		if ($schema === null || $schema === '') {
			return null;
		}

		// Non-numeric schema slugs map to null here — the link row keeps
		// the register id only, and the consumer side falls back to
		// resolving the schema from the URL.
		if (is_numeric($schema) === false) {
			return null;
		}

		return (int)$schema;
	}//end resolveSchemaId()

	/**
	 * Update a contact link (role change).
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 * @param string $contactUid The contact UID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Route-bound; method 501 pending role updates.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function update(string $register, string $schema, string $id, string $contactUid): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->withWritableObject(ref: $ref, handler: fn (ObjectEntity $object): JSONResponse => $this->updatedLink(object: $object, contactUid: $contactUid));
	}//end update()

	/**
	 * Remove a contact link.
	 *
	 * `{contactUid}` may be either the vCard UID *or* a numeric link id
	 * — the route requirement is `[^/]+` and consumers historically
	 * passed both shapes. The service resolves the contact-uid form via
	 * the (objectUuid, contactUid) composite index, falling back to the
	 * id-based path when the param looks numeric. Both code paths
	 * tolerate a missing underlying vCard per Phase F-3.
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 * @param string $contactUid The contact UID (or numeric link id).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function destroy(string $register, string $schema, string $id, string $contactUid): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->withWritableObject(ref: $ref, handler: fn (ObjectEntity $object): JSONResponse => $this->removedLink(object: $object, contactUid: $contactUid));
	}//end destroy()

	/**
	 * Find all objects linked to a contact.
	 *
	 * @param string $contactUid The contact UID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Guarded downstream: ContactService::getObjectsForContact scopes
	 *   the result to the caller's own addressbooks (cardDavBackend->getAddressBooksForUser
	 *   for the session principal) and returns [] for an anonymous session, so a caller only
	 *   ever sees links for contacts in addressbooks they own.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function objects(string $contactUid): JSONResponse {
		try {
			$results = $this->contactService->getObjectsForContact($contactUid);

			return new JSONResponse(['results' => $results, 'total' => count($results)]);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end objects()

	/**
	 * Validate that the object exists.
	 *
	 * @param ObjectRef $ref The register, schema and object id
	 *
	 * @return ObjectEntity|null
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(ObjectRef $ref): ?ObjectEntity {
		$this->objectService->setRegister($ref->register);
		$this->objectService->setSchema($ref->schema);
		$this->objectService->setObject($ref->id);

		return $this->objectService->getObject();
	}//end validateObject()

	/**
	 * Match contacts against OpenRegister objects by email, name, or organization.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt No per-object resource: free-text matching over caller-supplied
	 *   email/name/organization strings against schemas that opt into linkedTypes:["contact"];
	 *   takes no caller-supplied object id.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function match(): JSONResponse {
		$email = $this->request->getParam('email', '');
		$name = $this->request->getParam('name', '');
		$organization = $this->request->getParam('organization', '');

		if (empty($email) === true && empty($name) === true) {
			return new JSONResponse(
				['error' => $this->services->platform->l10n->t('At least email or name must be provided'), 'matches' => [], 'total' => 0],
				400
			);
		}

		try {
			$nameValue = null;
			$organizationValue = null;
			if (empty($name) === false) {
				$nameValue = (string)$name;
			}

			if (empty($organization) === false) {
				$organizationValue = (string)$organization;
			}

			$matches = $this->services->domain->matchingService->matchContact(
				(string)$email,
				$nameValue,
				$organizationValue
			);
			$enrichedMatches = $this->enrichMatches(matches: $matches);

			return new JSONResponse(['matches' => $enrichedMatches, 'total' => count($enrichedMatches)]);
		} catch (\Exception $e) {
			$this->services->platform->logger->error('[ContactsAPI] Match failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);

			return new JSONResponse(
				['error' => $this->services->platform->l10n->t('Internal server error'), 'matches' => [], 'total' => 0],
				500
			);
		}//end try
	}//end match()

	/**
	 * Enrich matches with deep link URLs and icons.
	 *
	 * @param array $matches The raw matches
	 *
	 * @return array Enriched matches
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	private function enrichMatches(array $matches): array {
		return array_map(
			function (array $match): array {
				$registerId = (int)($match['register']['id'] ?? 0);
				$schemaId = (int)($match['schema']['id'] ?? 0);
				$match['url'] = $this->services->domain->deepLinkRegistry->resolveUrl($registerId, $schemaId, $match);
				$match['icon'] = $this->services->domain->deepLinkRegistry->resolveIcon($registerId, $schemaId);

				return $match;
			},
			$matches
		);
	}//end enrichMatches()
}//end class
