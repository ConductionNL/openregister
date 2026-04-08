<?php

/**
 * OpenRegister Archival Controller
 *
 * Provides API endpoints for the archival and destruction workflow:
 * selection list CRUD, retention metadata management, and destruction
 * list generation/approval.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\DestructionList;
use OCA\OpenRegister\Db\DestructionListMapper;
use OCA\OpenRegister\Db\SelectionList;
use OCA\OpenRegister\Db\SelectionListMapper;
use OCA\OpenRegister\Service\ArchivalService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for archival and destruction workflow endpoints.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Controller requires multiple dependencies
 */
class ArchivalController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                $appName               App name
     * @param IRequest              $request               Request object
     * @param ArchivalService       $archivalService       Archival service
     * @param SelectionListMapper   $selectionListMapper   Selection list mapper
     * @param DestructionListMapper $destructionListMapper Destruction list mapper
     * @param ObjectService         $objectService         Object service
     * @param IUserSession          $userSession           User session
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ArchivalService $archivalService,
        private readonly SelectionListMapper $selectionListMapper,
        private readonly DestructionListMapper $destructionListMapper,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    // ==================================================================================
    // SELECTION LIST ENDPOINTS
    // ==================================================================================

    /**
     * List all selection list entries.
     *
     * @return JSONResponse
     */
    public function listSelectionLists(): JSONResponse
    {
        try {
            $lists = $this->selectionListMapper->findAll();

            return new JSONResponse(
                ['results' => $lists, 'total' => count($lists)],
                Http::STATUS_OK
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end listSelectionLists()

    /**
     * Get a single selection list entry.
     *
     * @param string $id The UUID of the selection list entry
     *
     * @return JSONResponse
     */
    public function getSelectionList(string $id): JSONResponse
    {
        try {
            $list = $this->selectionListMapper->findByUuid($id);

            return new JSONResponse($list, Http::STATUS_OK);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Selection list not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }//end getSelectionList()

    /**
     * Create a new selection list entry.
     *
     * @return JSONResponse
     */
    public function createSelectionList(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            $entity = new SelectionList();
            $entity->hydrate($data);

            // Validate required fields.
            if ($entity->getCategory() === null || $entity->getCategory() === '') {
                return new JSONResponse(
                    ['error' => 'Category is required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Validate action.
            if ($entity->getAction() !== null
                && in_array($entity->getAction(), SelectionList::VALID_ACTIONS, true) === false
            ) {
                return new JSONResponse(
                    ['error' => 'Action must be one of: '.implode(', ', SelectionList::VALID_ACTIONS)],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $created = $this->selectionListMapper->createEntry($entity);

            return new JSONResponse($created, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end createSelectionList()

    /**
     * Update an existing selection list entry.
     *
     * @param string $id The UUID of the selection list entry
     *
     * @return JSONResponse
     */
    public function updateSelectionList(string $id): JSONResponse
    {
        try {
            $entity = $this->selectionListMapper->findByUuid($id);
            $data   = $this->request->getParams();

            $entity->hydrate($data);

            $updated = $this->selectionListMapper->updateEntry($entity);

            return new JSONResponse($updated, Http::STATUS_OK);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Selection list not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end updateSelectionList()

    /**
     * Delete a selection list entry.
     *
     * @param string $id The UUID of the selection list entry
     *
     * @return JSONResponse
     */
    public function deleteSelectionList(string $id): JSONResponse
    {
        try {
            $entity = $this->selectionListMapper->findByUuid($id);
            $this->selectionListMapper->delete($entity);

            return new JSONResponse([], Http::STATUS_NO_CONTENT);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Selection list not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }//end deleteSelectionList()

    // ==================================================================================
    // RETENTION METADATA ENDPOINTS
    // ==================================================================================

    /**
     * Get retention metadata for an object.
     *
     * @param string $id The UUID of the object
     *
     * @return JSONResponse
     */
    public function getRetention(string $id): JSONResponse
    {
        try {
            $object = $this->objectService->find($id);

            return new JSONResponse(
                ['retention' => $object->getRetention() ?? []],
                Http::STATUS_OK
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Object not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }//end getRetention()

    /**
     * Set retention metadata on an object.
     *
     * @param string $id The UUID of the object
     *
     * @return JSONResponse
     */
    public function setRetention(string $id): JSONResponse
    {
        try {
            $object    = $this->objectService->find($id);
            $retention = $this->request->getParams();

            // Remove framework params that are not retention data.
            unset($retention['id'], $retention['_route']);

            $updated = $this->archivalService->setRetentionMetadata($object, $retention);

            // Save the updated object.
            $this->objectService->saveObject(
                $updated->getRegister(),
                $updated->getSchema(),
                $updated
            );

            return new JSONResponse(
                ['retention' => $updated->getRetention()],
                Http::STATUS_OK
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Object not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end setRetention()

    // ==================================================================================
    // DESTRUCTION LIST ENDPOINTS
    // ==================================================================================

    /**
     * List all destruction lists.
     *
     * @return JSONResponse
     */
    public function listDestructionLists(): JSONResponse
    {
        try {
            $status = $this->request->getParam('status');

            $lists = $status !== null ? $this->destructionListMapper->findByStatus($status) : $this->destructionListMapper->findAll();

            return new JSONResponse(
                ['results' => $lists, 'total' => count($lists)],
                Http::STATUS_OK
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end listDestructionLists()

    /**
     * Get a single destruction list.
     *
     * @param string $id The UUID of the destruction list
     *
     * @return JSONResponse
     */
    public function getDestructionList(string $id): JSONResponse
    {
        try {
            $list = $this->destructionListMapper->findByUuid($id);

            return new JSONResponse($list, Http::STATUS_OK);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Destruction list not found'],
                Http::STATUS_NOT_FOUND
            );
        }
    }//end getDestructionList()

    /**
     * Generate a new destruction list from objects due for destruction.
     *
     * @return JSONResponse
     */
    public function generateDestructionList(): JSONResponse
    {
        try {
            $list = $this->archivalService->generateDestructionList();

            if ($list === null) {
                return new JSONResponse(
                    ['message' => 'No objects due for destruction'],
                    Http::STATUS_OK
                );
            }

            return new JSONResponse($list, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end generateDestructionList()

    /**
     * Approve a destruction list and destroy all objects in it.
     *
     * @param string $id The UUID of the destruction list
     *
     * @return JSONResponse
     */
    public function approveDestructionList(string $id): JSONResponse
    {
        try {
            $list = $this->destructionListMapper->findByUuid($id);
            $user = $this->userSession->getUser();

            if ($user === null) {
                return new JSONResponse(
                    ['error' => 'Authentication required'],
                    Http::STATUS_UNAUTHORIZED
                );
            }

            $result = $this->archivalService->approveDestructionList($list, $user->getUID());

            return new JSONResponse(
                [
                    'destroyed' => $result['destroyed'],
                    'errors'    => $result['errors'],
                    'list'      => $result['list'],
                ],
                Http::STATUS_OK
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Destruction list not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end approveDestructionList()

    /**
     * Reject (remove) specific objects from a destruction list.
     *
     * @param string $id The UUID of the destruction list
     *
     * @return JSONResponse
     */
    public function rejectFromDestructionList(string $id): JSONResponse
    {
        try {
            $list = $this->destructionListMapper->findByUuid($id);

            $objectUuids = $this->request->getParam('objects', []);
            if (is_array($objectUuids) === false || count($objectUuids) === 0) {
                return new JSONResponse(
                    ['error' => 'objects array is required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $updated = $this->archivalService->rejectFromDestructionList($list, $objectUuids);

            return new JSONResponse($updated, Http::STATUS_OK);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Destruction list not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end rejectFromDestructionList()
}//end class
