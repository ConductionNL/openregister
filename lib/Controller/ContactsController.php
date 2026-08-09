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
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
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
class ContactsController extends Controller
{

    /**
     * Contact service.
     *
     * @var ContactService
     */
    private readonly ContactService $contactService;

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
     * @param string                  $appName          Application name
     * @param IRequest                $request          HTTP request
     * @param ContactService          $contactService   Contact service
     * @param ObjectService           $objectService    Object service
     * @param ContactMatchingService  $matchingService  Contact matching service
     * @param DeepLinkRegistryService $deepLinkRegistry Deep link registry
     * @param IL10N                   $l10n             Localization service
     * @param LoggerInterface         $logger           Logger
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
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->contactService   = $contactService;
        $this->objectService    = $objectService;
        $this->matchingService  = $matchingService;
        $this->deepLinkRegistry = $deepLinkRegistry;
        $this->l10n   = $l10n;
        $this->logger = $logger;
    }//end __construct()

    /**
     * List all contacts for a specific object.
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/contacts-actions/spec.md
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $result = $this->contactService->getContactsForObject($object->getUuid());

            return new JSONResponse($result);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
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
     * @param string $schema   The schema slug
     * @param string $id       The object ID
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
    public function create(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $data = $this->request->getParams();

            $hasLinkData = (empty($data['addressbookId']) === false && empty($data['contactUri']) === false);
            // Accept `displayName` (Tier-2 dialog field) alongside `fullName`.
            $hasCreateData = (empty($data['fullName']) === false || empty($data['displayName']) === false);

            if ($hasLinkData === false && $hasCreateData === false) {
                return new JSONResponse(
                    ['error' => 'Either addressbookId+contactUri or fullName/displayName is required'],
                    400
                );
            }

            $schemaId = $this->resolveSchemaId(object: $object);

            if ($hasLinkData === true) {
                // Link existing contact.
                $link = $this->contactService->linkContact(
                    $object->getUuid(),
                    (int) $object->getRegister(),
                    (int) $data['addressbookId'],
                    $data['contactUri'],
                    $data['role'] ?? null,
                    $schemaId
                );
            }

            if ($hasLinkData === false) {
                // Create new contact.
                $link = $this->contactService->createAndLinkContact(
                    $object->getUuid(),
                    (int) $object->getRegister(),
                    $data,
                    $schemaId
                );
            }

            return new JSONResponse($link, 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return new JSONResponse(['error' => $e->getMessage()], 404);
            }

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
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
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-16
     */
    public function createNew(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $data = $this->request->getParams();

            // Refuse "link" payloads here — `/contacts/new` is create-only.
            if (empty($data['contactUri']) === false) {
                return new JSONResponse(
                    ['error' => 'Use POST /contacts to link an existing contact'],
                    400
                );
            }

            $displayName = $data['displayName'] ?? $data['fullName'] ?? '';
            if ($displayName === '' || trim($displayName) === '') {
                return new JSONResponse(
                    ['error' => 'displayName is required'],
                    400
                );
            }

            $schemaId = $this->resolveSchemaId(object: $object);

            $link = $this->contactService->createAndLinkContact(
                $object->getUuid(),
                (int) $object->getRegister(),
                $data,
                $schemaId
            );

            return new JSONResponse($link, 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return new JSONResponse(['error' => $e->getMessage()], 404);
            }

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end createNew()

    /**
     * Resolve the object's schema id as an int (or null).
     *
     * `ObjectEntity::getSchema()` returns the schema id as a string;
     * the link table accepts a nullable int.
     *
     * @param \OCA\OpenRegister\Db\ObjectEntity $object The object entity.
     *
     * @return int|null
     */
    private function resolveSchemaId(\OCA\OpenRegister\Db\ObjectEntity $object): ?int
    {
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

        return (int) $schema;
    }//end resolveSchemaId()

    /**
     * Update a contact link (role change).
     *
     * @param string $register   The register slug
     * @param string $schema     The schema slug
     * @param string $id         The object ID
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
    public function update(string $register, string $schema, string $id, string $contactUid): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            // Role updates are not yet supported with the generic metadata column approach.
            // Unlink and relink with the new role as a workaround.
            return new JSONResponse(['error' => 'Role update not yet supported. Unlink and relink with the new role.'], 501);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return new JSONResponse(['error' => $e->getMessage()], 404);
            }

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
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
     * @param string $register   The register slug
     * @param string $schema     The schema slug
     * @param string $id         The object ID
     * @param string $contactUid The contact UID (or numeric link id).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/contacts-actions/spec.md
     */
    public function destroy(string $register, string $schema, string $id, string $contactUid): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            // Numeric param → legacy/link-id path.
            if (ctype_digit($contactUid) === true) {
                $this->contactService->unlinkContact((int) $contactUid);
                return new JSONResponse(['success' => true]);
            }

            // Non-numeric: resolve via the (objectUuid, contactUid) composite index.
            $this->contactService->unlinkContactByUid(
                objectUuid: $object->getUuid(),
                contactUid: $contactUid
            );

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return new JSONResponse(['error' => $e->getMessage()], 404);
            }

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
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
    public function objects(string $contactUid): JSONResponse
    {
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
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null
     *
     * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
     *         than caught: every call site already wraps this helper and translates it to a 404.
     *         Swallowing it here would collapse "no such object" into the same null this method
     *         returns for other reasons, which the caller could no longer tell apart.
     */
    private function validateObject(
        string $register,
        string $schema,
        string $id
    ): ?\OCA\OpenRegister\Db\ObjectEntity {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);
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
    public function match(): JSONResponse
    {
        $email        = $this->request->getParam('email', '');
        $name         = $this->request->getParam('name', '');
        $organization = $this->request->getParam('organization', '');

        if (empty($email) === true && empty($name) === true) {
            return new JSONResponse(
                ['error' => $this->l10n->t('At least email or name must be provided'), 'matches' => [], 'total' => 0],
                400
            );
        }

        try {
            $nameValue         = null;
            $organizationValue = null;
            if (empty($name) === false) {
                $nameValue = (string) $name;
            }

            if (empty($organization) === false) {
                $organizationValue = (string) $organization;
            }

            $matches         = $this->matchingService->matchContact(
                (string) $email,
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
    private function enrichMatches(array $matches): array
    {
        return array_map(
            function (array $match): array {
                $registerId    = (int) ($match['register']['id'] ?? 0);
                $schemaId      = (int) ($match['schema']['id'] ?? 0);
                $match['url']  = $this->deepLinkRegistry->resolveUrl($registerId, $schemaId, $match);
                $match['icon'] = $this->deepLinkRegistry->resolveIcon($registerId, $schemaId);

                return $match;
            },
            $matches
        );
    }//end enrichMatches()
}//end class
