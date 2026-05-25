<?php

/**
 * DeckLinksController — Tier-2 REST controller for Deck card links.
 *
 * Augments the Tier-1 {@see DeckController} with explicit link/create
 * endpoints and a board/stack discovery surface so the picker UX can
 * drive the multi-step modal without leaking Deck internals.
 *
 * Endpoints:
 *   - GET  /api/objects/{register}/{schema}/{id}/deck            — list
 *   - POST /api/objects/{register}/{schema}/{id}/deck            — link existing card
 *   - POST /api/objects/{register}/{schema}/{id}/deck/new        — create + link
 *   - DELETE /api/objects/{register}/{schema}/{id}/deck/{cardId} — unlink
 *   - GET  /api/integrations/deck/boards                         — list boards
 *   - GET  /api/integrations/deck/boards/{boardId}/stacks        — list stacks
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\DeckLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Deck links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class DeckLinksController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName         App id.
     * @param IRequest        $request         HTTP request.
     * @param DeckLinkService $deckLinkService Backing service.
     * @param ObjectService   $objectService   OR object resolver.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DeckLinkService $deckLinkService,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List linked Deck cards for an object.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->deckLinkService->isDeckAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $results = $this->deckLinkService->getLinkedCards($object->getUuid());

            return new JSONResponse(['results' => $results, 'total' => count($results)]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end index()

    /**
     * Link an existing Deck card.
     *
     * Body: `{ cardId: int }`.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function link(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->deckLinkService->isDeckAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $cardId = (int) $this->request->getParam('cardId', 0);
            if ($cardId === 0) {
                return new JSONResponse(['error' => 'cardId is required'], 400);
            }

            $link = $this->deckLinkService->linkCard(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $cardId
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end link()

    /**
     * Create a new Deck card and link it.
     *
     * Body: `{ boardId, stackId, title, description?, duedate? }`.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Composed of independent
     *     guard clauses (Deck-availability, object resolve, three required-
     *     field checks, two optional-field reads, two HTTP error mappings).
     *     Each branch carries a distinct surface contract — splitting would
     *     scatter the request-handling intent across helper methods.
     */
    public function createNew(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->deckLinkService->isDeckAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $boardId = (int) $this->request->getParam('boardId', 0);
            $stackId = (int) $this->request->getParam('stackId', 0);
            $title   = (string) $this->request->getParam('title', '');

            if ($boardId === 0 || $stackId === 0 || $title === '') {
                return new JSONResponse(
                    ['error' => 'boardId, stackId and title are required'],
                    400
                );
            }

            $description = $this->request->getParam('description');
            $duedate     = $this->request->getParam('duedate');

            $link = $this->deckLinkService->createAndLinkCard(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $boardId,
                $stackId,
                $title,
                $description === null ? null : (string) $description,
                $duedate === null ? null : (string) $duedate
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end createNew()

    /**
     * Unlink a card.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     * @param string $cardId   Deck card id (numeric).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(string $register, string $schema, string $id, string $cardId): JSONResponse
    {
        if ($this->deckLinkService->isDeckAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->deckLinkService->unlinkCard($object->getUuid(), (int) $cardId);

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end destroy()

    /**
     * List Deck boards visible to the current user.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function boards(): JSONResponse
    {
        if ($this->deckLinkService->isDeckAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        $boards = $this->deckLinkService->getAvailableBoards();
        return new JSONResponse(['results' => $boards, 'total' => count($boards)]);
    }//end boards()

    /**
     * List stacks for a board.
     *
     * @param string $boardId Deck board id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function stacks(string $boardId): JSONResponse
    {
        if ($this->deckLinkService->isDeckAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        $stacks = $this->deckLinkService->getStacksForBoard((int) $boardId);
        return new JSONResponse(['results' => $stacks, 'total' => count($stacks)]);
    }//end stacks()

    /**
     * Resolve an OR object from register/schema/id.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return ObjectEntity|null
     */
    private function validateObject(string $register, string $schema, string $id): ?ObjectEntity
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        return $this->objectService->getObject();
    }//end validateObject()

    /**
     * Map a service-layer Exception to a JSONResponse.
     *
     * Exception codes carry HTTP intent:
     *   - 409 → conflict
     *   - 404 → not found
     *   - 503 → service unavailable
     *   - everything else → 400 bad request
     *
     * @param Exception $exception Source exception.
     *
     * @return JSONResponse
     */
    private function mapException(Exception $exception): JSONResponse
    {
        $code = $exception->getCode();
        if ($code === 409) {
            return new JSONResponse(['error' => $exception->getMessage()], 409);
        }

        if ($code === 404) {
            return new JSONResponse(['error' => $exception->getMessage()], 404);
        }

        if ($code === 503) {
            return new JSONResponse(['error' => $exception->getMessage()], 503);
        }

        return new JSONResponse(['error' => $exception->getMessage()], 400);
    }//end mapException()
}//end class
