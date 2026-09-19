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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PersonLinkService;
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
	 * @var ContactService
	 */
	private readonly ContactService $contactService;

	/**
	 * People on objects: the write surface for user and contact links.
	 *
	 * @var PersonLinkService
	 */
	private readonly PersonLinkService $personLinks;

	/**
	 * Object service.
	 *
	 * @var ObjectService
	 */
	private readonly ObjectService $objectService;

	/**
	 * Contact matching service.
	 *
	 * @var ContactMatchingService
	 */
	private readonly ContactMatchingService $matchingService;

	/**
	 * Deep link registry service.
	 *
	 * @var DeepLinkRegistryService
	 */
	private readonly DeepLinkRegistryService $deepLinkRegistry;

	/**
	 * Localization service.
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request
	 * @param ContactService $contactService Contact service
	 * @param ObjectService $objectService Object service
	 * @param ContactMatchingService $matchingService Contact matching service
	 * @param DeepLinkRegistryService $deepLinkRegistry Deep link registry
	 * @param IL10N $l10n Localization service
	 * @param LoggerInterface $logger Logger
	 * @param PersonLinkService $personLinks People on objects: user and contact links
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ContactService $contactService,
		ObjectService $objectService,
		ContactMatchingService $matchingService,
		DeepLinkRegistryService $deepLinkRegistry,
		IL10N $l10n,
		LoggerInterface $logger,
		PersonLinkService $personLinks,
	) {
		parent::__construct(appName: $appName, request: $request);

		$this->contactService = $contactService;
		$this->personLinks = $personLinks;
		$this->objectService = $objectService;
		$this->matchingService = $matchingService;
		$this->deepLinkRegistry = $deepLinkRegistry;
		$this->l10n = $l10n;
		$this->logger = $logger;
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
		return $this->withObject(
			register: $register,
			schema: $schema,
			id: $id,
			handler: function (ObjectEntity $object): JSONResponse {
				return new JSONResponse(
					$this->personLinks->listForObject(
						objectUuid: $object->getUuid(),
						schemaId: $this->resolveSchemaId(object: $object)
					)
				);
			},
			fallbackStatus: 500
		);
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
		return $this->withObject(
			register: $register,
			schema: $schema,
			id: $id,
			handler: function (ObjectEntity $object): JSONResponse {
				$data = $this->request->getParams();
				$schemaId = $this->resolveSchemaId(object: $object);
				// A payload naming a person (userId, or addressbookId plus
				// contactUri) links that person; one naming only a new contact
				// (fullName/displayName) creates it first. Naming neither, the
				// link service answers the 400.
				if ($this->namesPerson(data: $data) === false && $this->namesNewContact(data: $data) === true) {
					$created = $this->contactService->createAndLinkContact(
						$object->getUuid(),
						(int)$object->getRegister(),
						$data,
						$schemaId
					);

					return new JSONResponse($created, 201);
				}

				$link = $this->personLinks->link(
					objectUuid: $object->getUuid(),
					registerId: (int)$object->getRegister(),
					schemaId: $schemaId,
					payload: $data
				);

				return new JSONResponse($link, 201);
			}
		);
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
		return $this->withObject(
			register: $register,
			schema: $schema,
			id: $id,
			handler: function (ObjectEntity $object): JSONResponse {
				$data = $this->request->getParams();
				// Refuse "link" payloads here: `/contacts/new` is create-only.
				if (empty($data['contactUri']) === false) {
					return new JSONResponse(['error' => 'Use POST /contacts to link an existing contact'], 400);
				}

				if (trim((string)($data['displayName'] ?? ($data['fullName'] ?? ''))) === '') {
					return new JSONResponse(['error' => 'displayName is required'], 400);
				}

				$link = $this->contactService->createAndLinkContact(
					$object->getUuid(),
					(int)$object->getRegister(),
					$data,
					$this->resolveSchemaId(object: $object)
				);

				return new JSONResponse($link, 201);
			}
		);
	}//end createNew()

	/**
	 * Run a handler on a validated object, mapping a missing object and a
	 * thrown service exception to their responses.
	 *
	 * Every per-object route shares this frame, so the 404 for an unknown
	 * object and the status of a service exception are decided once.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 * @param callable(ObjectEntity): JSONResponse $handler What to do with the object.
	 * @param int $fallbackStatus The status for an exception carrying none.
	 *
	 * @return JSONResponse The handler's response, or the error's.
	 */
	private function withObject(string $register, string $schema, string $id, callable $handler, int $fallbackStatus = 400): JSONResponse {
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			return $handler($object);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->errorResponse(exception: $e, fallbackStatus: $fallbackStatus);
		}//end try
	}//end withObject()

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
		return $this->withObject(
			register: $register,
			schema: $schema,
			id: $id,
			handler: function (ObjectEntity $object) use ($contactUid): JSONResponse {
				$data = $this->request->getParams();

				return new JSONResponse(
					$this->personLinks->update(
						objectUuid: $object->getUuid(),
						contactUid: $contactUid,
						schemaId: $this->resolveSchemaId(object: $object),
						changes: array_intersect_key($data, array_flip(['role', 'validFrom', 'validUntil', 'note'])),
						currentRole: $this->optionalParam(name: 'currentRole')
					)
				);
			}
		);
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
		return $this->withObject(
			register: $register,
			schema: $schema,
			id: $id,
			handler: function (ObjectEntity $object) use ($contactUid): JSONResponse {
				// Numeric param: legacy link-id path.
				if (ctype_digit($contactUid) === true) {
					$this->contactService->unlinkContact((int)$contactUid);

					return new JSONResponse(['success' => true]);
				}

				// Non-numeric: the person's links on the object, one role or all.
				$removed = $this->personLinks->unlink(
					objectUuid: $object->getUuid(),
					contactUid: $contactUid,
					role: $this->optionalParam(name: 'role')
				);

				return new JSONResponse(['success' => true, 'removed' => $removed]);
			}
		);
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
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(
		string $register,
		string $schema,
		string $id,
	): ?\OCA\OpenRegister\Db\ObjectEntity {
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

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
				['error' => $this->l10n->t('At least email or name must be provided'), 'matches' => [], 'total' => 0],
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

			$matches = $this->matchingService->matchContact(
				(string)$email,
				$nameValue,
				$organizationValue
			);
			$enrichedMatches = $this->enrichMatches(matches: $matches);

			return new JSONResponse(['matches' => $enrichedMatches, 'total' => count($enrichedMatches)]);
		} catch (\Exception $e) {
			$this->logger->error('[ContactsAPI] Match failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);

			return new JSONResponse(
				['error' => $this->l10n->t('Internal server error'), 'matches' => [], 'total' => 0],
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
				$match['url'] = $this->deepLinkRegistry->resolveUrl($registerId, $schemaId, $match);
				$match['icon'] = $this->deepLinkRegistry->resolveIcon($registerId, $schemaId);

				return $match;
			},
			$matches
		);
	}//end enrichMatches()
}//end class
