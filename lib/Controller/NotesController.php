<?php

/**
 * NotesController
 *
 * REST controller for note operations on OpenRegister objects.
 * Follows the FilesController pattern for sub-resource endpoints.
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
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * NotesController handles note operations for objects in registers.
 *
 * Provides REST API endpoints for managing notes (comments)
 * associated with OpenRegister objects.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class NotesController extends Controller
{

    /**
     * Note service for comment operations.
     *
     * @var NoteService
     */
    private readonly NoteService $noteService;

    /**
     * Object service for object validation.
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * Constructor.
     *
     * @param string        $appName       Application name
     * @param IRequest      $request       HTTP request object
     * @param NoteService   $noteService   Note service for comment operations
     * @param ObjectService $objectService Object service for object validation
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        NoteService $noteService,
        ObjectService $objectService
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->noteService   = $noteService;
        $this->objectService = $objectService;
    }//end __construct()

    /**
     * List all notes for a specific object.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object
     *
     * @return JSONResponse JSON response with notes list
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => 'Object not found'],
                    statusCode: 404
                );
            }

            $params = $this->request->getParams();
            $limit  = (int) ($params['limit'] ?? 50);
            $offset = (int) ($params['offset'] ?? 0);

            $notes = $this->noteService->getNotesForObject($object->getUuid(), $limit, $offset);

            return new JSONResponse(data: ['results' => $notes, 'total' => count($notes)]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
    }//end index()

    /**
     * Create a new note on a specific object.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object
     *
     * @return JSONResponse JSON response with the created note
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(
        string $register,
        string $schema,
        string $id
    ): JSONResponse {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => 'Object not found'],
                    statusCode: 404
                );
            }

            $data = $this->request->getParams();

            // Validate required fields.
            if (empty($data['message']) === true) {
                return new JSONResponse(
                    data: ['error' => 'Note message is required'],
                    statusCode: 400
                );
            }

            $note = $this->noteService->createNote($object->getUuid(), $data['message']);

            return new JSONResponse(data: $note, statusCode: 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try
    }//end create()

    /**
     * Delete a note.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object
     * @param string $noteId   The ID of the note to delete
     *
     * @return JSONResponse JSON response confirming deletion
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(
        string $register,
        string $schema,
        string $id,
        string $noteId
    ): JSONResponse {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => 'Object not found'],
                    statusCode: 404
                );
            }

            $this->noteService->deleteNote((int) $noteId);

            return new JSONResponse(data: ['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }
    }//end destroy()

    /**
     * Validate that the object exists and return it.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The object ID
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null The object or null
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
