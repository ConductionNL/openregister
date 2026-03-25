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
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

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
     * Constructor.
     *
     * @param string         $appName        Application name
     * @param IRequest       $request        HTTP request
     * @param ContactService $contactService Contact service
     * @param ObjectService  $objectService  Object service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ContactService $contactService,
        ObjectService $objectService
    ) {
        parent::__construct($appName, $request);

        $this->contactService = $contactService;
        $this->objectService  = $objectService;
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
            $object = $this->validateObject($register, $schema, $id);
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
            $object = $this->validateObject($register, $schema, $id);
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
            }

            return new JSONResponse($link->jsonSerialize(), 201);
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
     * @param string $register  The register slug
     * @param string $schema    The schema slug
     * @param string $id        The object ID
     * @param string $contactId The contact link ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(string $register, string $schema, string $id, string $contactId): JSONResponse
    {
        try {
            $object = $this->validateObject($register, $schema, $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $data = $this->request->getParams();

            if (empty($data['role']) === true) {
                return new JSONResponse(['error' => 'role is required'], 400);
            }

            $link = $this->contactService->updateRole((int) $contactId, $data['role']);

            return new JSONResponse($link->jsonSerialize());
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
     * @param string $register  The register slug
     * @param string $schema    The schema slug
     * @param string $id        The object ID
     * @param string $contactId The contact link ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(string $register, string $schema, string $id, string $contactId): JSONResponse
    {
        try {
            $object = $this->validateObject($register, $schema, $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->contactService->unlinkContact((int) $contactId);

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
}//end class
