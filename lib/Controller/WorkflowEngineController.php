<?php

/**
 * OpenRegister WorkflowEngineController
 *
<<<<<<< HEAD
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
=======
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
<<<<<<< HEAD
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-85
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-89
=======
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-85
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-91
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-89
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
<<<<<<< HEAD
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
=======
use OCP\IRequest;
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
use Psr\Log\LoggerInterface;

/**
 * Controller for workflow engine CRUD, health checks, and test hooks.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
class WorkflowEngineController extends Controller
{
    /**
     * Constructor for WorkflowEngineController.
     *
<<<<<<< HEAD
     * @param string                 $appName      App name
     * @param IRequest               $request      Request
     * @param WorkflowEngineRegistry $registry     Engine registry
     * @param LoggerInterface        $logger       Logger
     * @param IL10N                  $l10n         Localization service
     * @param IUserSession           $userSession  User session for admin checks
     * @param IGroupManager          $groupManager Group manager for admin checks
=======
     * @param string                 $appName  App name
     * @param IRequest               $request  Request
     * @param WorkflowEngineRegistry $registry Engine registry
     * @param LoggerInterface        $logger   Logger
     * @param IL10N                  $l10n     Localization service
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly WorkflowEngineRegistry $registry,
        private readonly LoggerInterface $logger,
<<<<<<< HEAD
        private readonly IL10N $l10n,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager
=======
        private readonly IL10N $l10n
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
<<<<<<< HEAD
     * Check whether the currently authenticated user is a Nextcloud administrator.
     *
     * @return bool True if a user is signed in and belongs to the admin group.
     */
    private function isCurrentUserAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());
    }//end isCurrentUserAdmin()

    /**
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     * List all registered engines.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-91
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function index(): JSONResponse
    {
        $engines = $this->registry->getEngines();

        return new JSONResponse(
            array_map(fn ($engine) => $engine->jsonSerialize(), $engines)
        );
    }//end index()

    /**
     * Get a single engine.
     *
     * @param int $id Engine ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function show(int $id): JSONResponse
    {
        try {
            $engine = $this->registry->getEngine($id);

            return new JSONResponse($engine->jsonSerialize());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l10n->t('Engine not found')], 404);
        }
    }//end show()

    /**
     * Register a new engine.
     *
     * @param string      $name           Engine name
     * @param string      $engineType     Engine type (n8n, windmill)
     * @param string      $baseUrl        Base URL
     * @param string|null $authType       Auth type
     * @param array|null  $authConfig     Auth configuration
     * @param bool        $enabled        Whether enabled
     * @param int         $defaultTimeout Default timeout
     *
     * @return JSONResponse
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-91
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function create(
        string $name,
        string $engineType,
        string $baseUrl,
        ?string $authType='none',
        ?array $authConfig=null,
        bool $enabled=true,
        int $defaultTimeout=30
    ): JSONResponse {
<<<<<<< HEAD
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(['error' => 'Admin privileges required'], 403);
        }

=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $validTypes = ['n8n', 'windmill'];
        if (in_array(needle: $engineType, haystack: $validTypes, strict: true) === false) {
            return new JSONResponse(
                ['error' => "Invalid engine type '$engineType'. Must be one of: ".implode(', ', $validTypes)],
                400
            );
        }

        try {
            $engine = $this->registry->createEngine(
                    [
                        'name'           => $name,
                        'engineType'     => $engineType,
                        'baseUrl'        => $baseUrl,
                        'authType'       => $authType ?? 'none',
                        'authConfig'     => $authConfig,
                        'enabled'        => $enabled,
                        'defaultTimeout' => $defaultTimeout,
                    ]
                    );

            // Run initial health check.
            try {
                $this->registry->healthCheck($engine->getId());
                $engine = $this->registry->getEngine($engine->getId());
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[WorkflowEngineController] Initial health check failed',
                    context: ['engineId' => $engine->getId(), 'error' => $e->getMessage()]
                );
            }

            return new JSONResponse($engine->jsonSerialize(), 201);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end create()

    /**
     * Update an engine.
     *
     * @param int $id Engine ID
     *
     * @return JSONResponse
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
     */
    public function update(int $id): JSONResponse
    {
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(['error' => 'Admin privileges required'], 403);
        }

=======
     */
    public function update(int $id): JSONResponse
    {
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        try {
            $data   = $this->request->getParams();
            $engine = $this->registry->updateEngine($id, $data);

            return new JSONResponse($engine->jsonSerialize());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l10n->t('Engine not found')], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end update()

    /**
     * Delete an engine.
     *
     * @param int $id Engine ID
     *
     * @return JSONResponse
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
     */
    public function destroy(int $id): JSONResponse
    {
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(['error' => 'Admin privileges required'], 403);
        }

=======
     */
    public function destroy(int $id): JSONResponse
    {
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        try {
            $engine = $this->registry->deleteEngine($id);

            return new JSONResponse($engine->jsonSerialize());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l10n->t('Engine not found')], 404);
        }
    }//end destroy()

    /**
     * Run a health check on an engine.
     *
     * @param int $id Engine ID
     *
     * @return JSONResponse
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-85
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-85
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-91
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function health(int $id): JSONResponse
    {
        try {
            $result = $this->registry->healthCheck($id);

            return new JSONResponse($result);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l10n->t('Engine not found')], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end health()

    /**
     * List auto-discovered engine types from installed ExApps.
     *
     * @return JSONResponse
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-89
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-89
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function available(): JSONResponse
    {
        $engines = $this->registry->discoverEngines();

        return new JSONResponse($engines);
    }//end available()

    /**
     * Test a hook by executing a workflow with sample data (dry-run).
     *
     * No database writes occur. The response includes dryRun: true.
     *
     * @param int $id Engine ID
     *
     * @return JSONResponse
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function testHook(int $id): JSONResponse
    {
        $workflowId = $this->request->getParam('workflowId');
        $sampleData = $this->request->getParam('sampleData', []);
        $timeout    = (int) $this->request->getParam('timeout', 30);

        if (empty($workflowId) === true) {
            return new JSONResponse(['error' => 'workflowId is required'], 400);
        }

        if (is_array($sampleData) === false) {
            $sampleData = json_decode((string) $sampleData, true) ?? [];
        }

        try {
            $adapter = $this->registry->resolveAdapterById($id);
            $result  = $adapter->executeWorkflow(
                workflowId: $workflowId,
                data: $sampleData,
                timeout: $timeout
            );

            $response           = $result->toArray();
            $response['dryRun'] = true;

            return new JSONResponse($response);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l10n->t('Engine not found')], 404);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $lower   = strtolower($message);

            // Connectivity errors return 502.
            if (str_contains($lower, 'connection') === true
                || str_contains($lower, 'unreachable') === true
                || str_contains($lower, 'refused') === true
            ) {
                return new JSONResponse(
                        [
                            'status' => 'error',
                            'errors' => [['message' => $message]],
                            'dryRun' => true,
                        ],
                        502
                        );
            }

            // Workflow errors return 422.
            return new JSONResponse(
                    [
                        'status' => 'error',
                        'errors' => [['message' => $message]],
                        'dryRun' => true,
                    ],
                    422
                    );
        }//end try
    }//end testHook()
}//end class
