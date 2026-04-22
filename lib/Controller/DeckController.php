<?php

/**
 * DeckController
 *
 * REST controller for Deck card relation operations on OpenRegister objects.
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
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * DeckController handles Deck card relation operations for objects.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class DeckController extends Controller
{

    /**
     * Deck card service.
     *
     * @var DeckCardService
     */
    private readonly DeckCardService $deckCardService;

    /**
     * Object service.
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * Constructor.
     *
     * @param string          $appName         Application name
     * @param IRequest        $request         HTTP request
     * @param DeckCardService $deckCardService Deck card service
     * @param ObjectService   $objectService   Object service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        DeckCardService $deckCardService,
        ObjectService $objectService
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->deckCardService = $deckCardService;
        $this->objectService   = $objectService;
    }//end __construct()

    /**
     * List all Deck cards for a specific object.
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
        if ($this->deckCardService->isDeckAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $result = $this->deckCardService->getCardsForObject($object->getUuid());

            return new JSONResponse($result);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end index()

    /**
     * Create or link a Deck card to an object.
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
        if ($this->deckCardService->isDeckAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $data = $this->request->getParams();

            $link = $this->deckCardService->linkOrCreateCard(
                $object->getUuid(),
                (int) $object->getRegister(),
                $data
            );

            return new JSONResponse($link, 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 409) {
                return new JSONResponse(['error' => $e->getMessage()], 409);
            }

            if ($code === 404) {
                return new JSONResponse(['error' => $e->getMessage()], 404);
            }

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end create()

    /**
     * Remove a Deck card link from an object.
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     * @param string $deckRef  The deck reference
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(string $register, string $schema, string $id, string $deckRef): JSONResponse
    {
        if ($this->deckCardService->isDeckAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->deckCardService->unlinkCard($object->getUuid(), $deckRef);

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
     * Find all objects linked to cards on a board.
     *
     * @param string $boardId The board ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function objects(string $boardId): JSONResponse
    {
        if ($this->deckCardService->isDeckAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $results = $this->deckCardService->getObjectsForBoard((int) $boardId);

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
