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
 *
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-61
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
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
     * Get all tasks for the current user across all calendars.
     *
     * Returns all CalDAV VTODOs from the user's VTODO-supporting calendars,
     * optionally filtered by status or assignee.
     *
     * Authorization: this endpoint is anchored to the current session user.
     * TaskService::getAllUserTasks() resolves the calendar set from
     * IUserSession::getUser()->getUID() (principals/users/<uid>); the request
     * never controls which user's calendars are read. The optional `assignee`
     * request parameter is a free-text filter applied to each task's
     * description ATTENDEE field within the caller's own task list — it is
     * NOT an identity claim and cannot be used to read another user's tasks.
     * Per ADR-005 Rule 3, no per-object authorization anchor is needed beyond
     * the session-user binding already enforced in the service.
     *
     * @return JSONResponse JSON response with all user tasks
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function allUserTasks(): JSONResponse
    {
        try {
            $status   = $this->request->getParam('status');
            $limit    = min((int) ($this->request->getParam('_limit') ?? $this->request->getParam('limit') ?? 50), 200);
            $offset   = (int) ($this->request->getParam('_offset') ?? $this->request->getParam('offset') ?? 0);
            $assignee = $this->request->getParam('assignee');

            $result = $this->taskService->getAllUserTasks(
                status: $status,
                limit: $limit,
                offset: $offset,
                assignee: $assignee
            );

            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end allUserTasks()

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
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-61
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
        } catch (NoVtodoCalendarException $e) {
            // No VTODO calendar = no tasks; return empty for listing.
            return new JSONResponse(data: ['results' => [], 'total' => 0]);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }//end try
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
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-61
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
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-61
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
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-61
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
