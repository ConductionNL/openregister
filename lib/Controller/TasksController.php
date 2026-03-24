<?php

/**
 * TasksController
 *
 * REST controller for CalDAV task operations on OpenRegister objects.
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
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * TasksController handles task operations for objects in registers.
 *
 * Provides REST API endpoints for managing CalDAV tasks (VTODOs)
 * associated with OpenRegister objects.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class TasksController extends Controller
{

    /**
     * Task service for CalDAV VTODO operations.
     *
     * @var TaskService
     */
    private readonly TaskService $taskService;

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
     * @param TaskService   $taskService   Task service for VTODO operations
     * @param ObjectService $objectService Object service for object validation
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        TaskService $taskService,
        ObjectService $objectService
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->taskService   = $taskService;
        $this->objectService = $objectService;
    }//end __construct()

    /**
     * List all tasks linked to a specific object.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object
     *
     * @return JSONResponse JSON response with tasks list
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

            $tasks = $this->taskService->getTasksForObject($object->getUuid());

            return new JSONResponse(data: ['results' => $tasks, 'total' => count($tasks)]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            // No VTODO calendar = no tasks; return empty for listing.
            if (str_contains($e->getMessage(), 'No VTODO-supporting calendar')) {
                return new JSONResponse(data: ['results' => [], 'total' => 0]);
            }

            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end index()

    /**
     * Create a new task linked to a specific object.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object
     *
     * @return JSONResponse JSON response with the created task
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
            if (empty($data['summary']) === true) {
                return new JSONResponse(
                    data: ['error' => 'Task summary is required'],
                    statusCode: 400
                );
            }

            $task = $this->taskService->createTask(
                registerId: (int) $object->getRegister(),
                schemaId: (int) $object->getSchema(),
                objectUuid: $object->getUuid(),
                objectTitle: $object->getName() ?? $object->getUuid(),
                data: $data
            );

            return new JSONResponse(data: $task, statusCode: 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try
    }//end create()

    /**
     * Update an existing task.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object
     * @param string $taskId   The URI of the task to update
     *
     * @return JSONResponse JSON response with the updated task
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(
        string $register,
        string $schema,
        string $id,
        string $taskId
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

            // The taskId from the URL is the task URI. We need the calendarId too.
            $calendarId = $data['calendarId'] ?? null;
            if ($calendarId === null) {
                // Try to find the task in the user's calendar to get the calendarId.
                $tasks = $this->taskService->getTasksForObject($object->getUuid());
                foreach ($tasks as $existingTask) {
                    if ($existingTask['id'] === $taskId) {
                        $calendarId = $existingTask['calendarId'];
                        break;
                    }
                }
            }

            if ($calendarId === null) {
                return new JSONResponse(
                    data: ['error' => 'Task not found'],
                    statusCode: 404
                );
            }

            $task = $this->taskService->updateTask($calendarId, $taskId, $data);

            return new JSONResponse(data: $task);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try
    }//end update()

    /**
     * Delete a task.
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object
     * @param string $taskId   The URI of the task to delete
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
        string $taskId
    ): JSONResponse {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(
                    data: ['error' => 'Object not found'],
                    statusCode: 404
                );
            }

            // Find the task to get its calendarId.
            $tasks      = $this->taskService->getTasksForObject($object->getUuid());
            $calendarId = null;
            foreach ($tasks as $existingTask) {
                if ($existingTask['id'] === $taskId) {
                    $calendarId = $existingTask['calendarId'];
                    break;
                }
            }

            if ($calendarId === null) {
                return new JSONResponse(
                    data: ['error' => 'Task not found'],
                    statusCode: 404
                );
            }

            $this->taskService->deleteTask($calendarId, $taskId);

            return new JSONResponse(data: ['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
        }//end try
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
