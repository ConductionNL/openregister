<?php

/**
 * ContactsController
 *
 * REST controller for contact relation operations on OpenRegister objects.
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
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
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
     * If fullName is provided, creates a new contact and links it.
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $data = $this->request->getParams();

            if (empty($data['addressbookId']) === false && empty($data['contactUri']) === false) {
                // Link existing contact.
                $link = $this->contactService->linkContact(
                    $object->getUuid(),
                    (int) $object->getRegister(),
                    (int) $data['addressbookId'],
                    $data['contactUri'],
                    $data['role'] ?? null
                );
            } else if (empty($data['fullName']) === false) {
                // Create new contact.
                $link = $this->contactService->createAndLinkContact(
                    $object->getUuid(),
                    (int) $object->getRegister(),
                    $data
                );
            } else {
                return new JSONResponse(
                    ['error' => 'Either addressbookId+contactUri or fullName is required'],
                    400
                );
            }//end if

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
     */
    public function update(string $register, string $schema, string $id, string $contactUid): JSONResponse
    {
        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
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
     * @param string $register   The register slug
     * @param string $schema     The schema slug
     * @param string $id         The object ID
     * @param string $contactUid The contact UID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(string $register, string $schema, string $id, string $contactUid): JSONResponse
    {
        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->contactService->unlinkContact($object->getUuid(), $contactUid);

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
            $matches         = $this->matchingService->matchContact(
                (string) $email,
                empty($name) === false ? (string) $name : null,
                empty($organization) === false ? (string) $organization : null
            );
            $enrichedMatches = $this->enrichMatches(matches: $matches);

            return new JSONResponse(['matches' => $enrichedMatches, 'total' => count($enrichedMatches)]);
        } catch (\Exception $e) {
            $this->logger->error('[ContactsAPI] Match failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);

            return new JSONResponse(['error' => $this->l10n->t('Internal server error'), 'matches' => [], 'total' => 0], 500);
        }
    }//end match()

    /**
     * Enrich matches with deep link URLs and icons.
     *
     * @param array $matches The raw matches
     *
     * @return array Enriched matches
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
