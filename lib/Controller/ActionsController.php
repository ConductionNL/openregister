<?php

/**
 * OpenRegister Actions Controller
 *
 * Controller for handling action management operations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-01-actions/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ActionLogMapper;
use OCA\OpenRegister\Db\ActionMapper;
use OCA\OpenRegister\Service\ActionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * ActionsController handles action CRUD and utility operations
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ActionsController extends Controller
{

    /**
     * Action mapper
     *
     * @var ActionMapper
     */
    private ActionMapper $actionMapper;

    /**
     * Action service
     *
     * @var ActionService
     */
    private ActionService $actionService;

    /**
     * Action log mapper
     *
     * @var ActionLogMapper
     */
    private ActionLogMapper $actionLogMapper;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param string          $appName         Application name
     * @param IRequest        $request         HTTP request
     * @param ActionMapper    $actionMapper    Action mapper
     * @param ActionLogMapper $actionLogMapper Action log mapper
     * @param ActionService   $actionService   Action service
     * @param LoggerInterface $logger          Logger
     * @param IUserSession    $userSession     Active session for caller identity.
     * @param IGroupManager   $groupManager    Group manager for admin gating.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ActionMapper $actionMapper,
        ActionLogMapper $actionLogMapper,
        ActionService $actionService,
        LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->actionMapper    = $actionMapper;
        $this->actionLogMapper = $actionLogMapper;
        $this->actionService   = $actionService;
        $this->logger          = $logger;
    }//end __construct()

    /**
     * Gate Actions mutations to admin group members.
     *
     * SECURITY: Actions persist as workflow hooks that fire on every
     * matching object lifecycle event. A non-admin who briefly auth-es
     * could otherwise register an attacker-chosen workflow that
     * survives password reset, session revocation, and even the source
     * account being disabled (the action row carries no owner check on
     * execution). Every write surface (`create`/`update`/`patch`/
     * `destroy`/`test`/`migrateFromHooks`) is admin-only at the
     * framework level (the methods carry no `@NoAdminRequired`); this
     * helper stays as defence-in-depth so a future refactor that
     * silently re-adds `@NoAdminRequired` does not open the surface.
     *
     * @return JSONResponse|null 403 response when not admin, null when allowed.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'Authentication required'],
                statusCode: 401
            );
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                data: ['error' => 'Forbidden: Actions management is admin-only'],
                statusCode: 403
            );
        }

        return null;

    }//end requireAdmin()

    /**
     * List all actions with pagination and filtering
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-01-actions/tasks.md#task-1
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        try {
            $params = $this->request->getParams();

            $limit  = isset($params['_limit']) === true ? (int) $params['_limit'] : null;
            $offset = isset($params['_offset']) === true ? (int) $params['_offset'] : null;

            if (isset($params['_page']) === true && $limit !== null) {
                $offset = ((int) $params['_page'] - 1) * $limit;
            }

            // Build filters from known filterable fields.
            $filters          = [];
            $filterableFields = ['status', 'event_type', 'engine', 'enabled', 'mode'];
            foreach ($filterableFields as $field) {
                if (isset($params[$field]) === true) {
                    $filters[$field] = $params[$field];
                }
            }

            // Search support.
            $search = $params['_search'] ?? null;

            $actions = $this->actionMapper->findAll(
                limit: $limit,
                offset: $offset,
                filters: $filters
            );

            // Apply search filter in PHP if provided.
            if ($search !== null && $search !== '') {
                $searchLower = strtolower($search);
                $actions     = array_values(
                    array_filter(
                        $actions,
                        function ($action) use ($searchLower) {
                            return str_contains(strtolower($action->getName()), $searchLower)
                                || str_contains(strtolower($action->getSlug() ?? ''), $searchLower);
                        }
                    )
                );
            }

            // Get total count.
            $allActions = $this->actionMapper->findAll(filters: $filters);
            if ($search !== null && $search !== '') {
                $searchLower = strtolower($search);
                $allActions  = array_filter(
                    $allActions,
                    function ($action) use ($searchLower) {
                        return str_contains(strtolower($action->getName()), $searchLower)
                            || str_contains(strtolower($action->getSlug() ?? ''), $searchLower);
                    }
                );
            }

            $total = count($allActions);

            $actionsArr = array_map(
                function ($action) {
                    return $action->jsonSerialize();
                },
                $actions
            );

            return new JSONResponse(
                data: [
                    'results' => array_values($actionsArr),
                    'total'   => $total,
                ],
                statusCode: 200
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ActionsController] Error listing actions: '.$e->getMessage()
            );

            return new JSONResponse(
                data: ['error' => 'Failed to list actions'],
                statusCode: 500
            );
        }//end try
    }//end index()

    /**
     * Get a single action
     *
     * @param int $id Action ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-01-actions/tasks.md#task-1
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): JSONResponse
    {
        try {
            $action = $this->actionMapper->find($id);

            return new JSONResponse(data: $action);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Action not found'],
                statusCode: 404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to retrieve action'],
                statusCode: 500
            );
        }
    }//end show()

    /**
     * Create a new action
     *
     * Admin-only at the framework level (no @NoAdminRequired). Body
     * `requireAdmin()` stays as defence-in-depth.
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-01-actions/tasks.md#task-1
     */
    #[NoCSRFRequired]
    public function create(): JSONResponse
    {
        if (($denial = $this->requireAdmin()) !== null) {
            return $denial;
        }

        try {
            $data = $this->request->getParams();

            // Remove internal parameters.
            foreach (array_keys($data) as $key) {
                if (str_starts_with($key, '_') === true) {
                    unset($data[$key]);
                }
            }

            unset($data['id'], $data['organisation']);

            $action = $this->actionService->createAction($data);

            return new JSONResponse(data: $action, statusCode: 201);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 400
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ActionsController] Error creating action: '.$e->getMessage()
            );

            return new JSONResponse(
                data: ['error' => 'Failed to create action: '.$e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end create()

    /**
     * Update an action (full replacement)
     *
     * Admin-only at the framework level (no @NoAdminRequired). Body
     * `requireAdmin()` stays as defence-in-depth.
     *
     * @param int $id Action ID
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-01-actions/tasks.md#task-1
     */
    #[NoCSRFRequired]
    public function update(int $id): JSONResponse
    {
        if (($denial = $this->requireAdmin()) !== null) {
            return $denial;
        }

        try {
            $data = $this->request->getParams();

            foreach (array_keys($data) as $key) {
                if (str_starts_with($key, '_') === true) {
                    unset($data[$key]);
                }
            }

            unset($data['organisation']);

            $action = $this->actionService->updateAction($id, $data);

            return new JSONResponse(data: $action);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Action not found'],
                statusCode: 404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to update action: '.$e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end update()

    /**
     * Partial update an action
     *
     * Admin-only at the framework level (no @NoAdminRequired); update()
     * also runs requireAdmin() as defence-in-depth.
     *
     * @param int $id Action ID
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-01-actions/tasks.md#task-1
     */
    #[NoCSRFRequired]
    public function patch(int $id): JSONResponse
    {
        // RequireAdmin() runs inside update() — no need to duplicate here.
        return $this->update(id: $id);
    }//end patch()

    /**
     * Soft-delete an action
     *
     * Admin-only at the framework level (no @NoAdminRequired). Body
     * `requireAdmin()` stays as defence-in-depth.
     *
     * @param int $id Action ID
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-01-actions/tasks.md#task-1
     */
    #[NoCSRFRequired]
    public function destroy(int $id): JSONResponse
    {
        if (($denial = $this->requireAdmin()) !== null) {
            return $denial;
        }

        try {
            $action = $this->actionService->deleteAction($id);

            return new JSONResponse(data: $action);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Action not found'],
                statusCode: 404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to delete action'],
                statusCode: 500
            );
        }
    }//end destroy()

    /**
     * Test action with dry-run simulation
     *
     * Admin-only at the framework level (no @NoAdminRequired). Body
     * `requireAdmin()` stays as defence-in-depth.
     *
     * @param int $id Action ID
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     */
    #[NoCSRFRequired]
    public function test(int $id): JSONResponse
    {
        if (($denial = $this->requireAdmin()) !== null) {
            return $denial;
        }

        try {
            $data = $this->request->getParams();

            foreach (array_keys($data) as $key) {
                if (str_starts_with($key, '_') === true) {
                    unset($data[$key]);
                }
            }

            $result = $this->actionService->testAction($id, $data);

            return new JSONResponse(data: $result);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Action not found'],
                statusCode: 404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to test action: '.$e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end test()

    /**
     * Get action execution logs
     *
     * @param int $id Action ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function logs(int $id): JSONResponse
    {
        try {
            $params = $this->request->getParams();
            $limit  = isset($params['_limit']) === true ? (int) $params['_limit'] : 25;
            $offset = isset($params['_offset']) === true ? (int) $params['_offset'] : 0;

            $logs = $this->actionLogMapper->findByActionId(
                actionId: $id,
                limit: $limit,
                offset: $offset
            );

            $stats = $this->actionLogMapper->getStatsByActionId($id);

            $logsArr = array_map(
                function ($log) {
                    return $log->jsonSerialize();
                },
                $logs
            );

            return new JSONResponse(
                data: [
                    'results'    => $logsArr,
                    'total'      => $stats['total'],
                    'statistics' => $stats,
                ]
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to retrieve action logs'],
                statusCode: 500
            );
        }//end try
    }//end logs()

    /**
     * Migrate inline hooks from a schema to Action entities
     *
     * Admin-only at the framework level (no @NoAdminRequired). Body
     * `requireAdmin()` stays as defence-in-depth.
     *
     * @param int $schemaId Schema ID
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     */
    #[NoCSRFRequired]
    public function migrateFromHooks(int $schemaId): JSONResponse
    {
        if (($denial = $this->requireAdmin()) !== null) {
            return $denial;
        }

        try {
            $report = $this->actionService->migrateFromHooks($schemaId);

            return new JSONResponse(data: $report);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Schema not found'],
                statusCode: 404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: ['error' => 'Migration failed: '.$e->getMessage()],
                statusCode: 500
            );
        }
    }//end migrateFromHooks()
}//end class
