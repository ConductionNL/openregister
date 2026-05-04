<?php

/**
 * OpenRegister WorkflowEngineController
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
 *
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-85
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-89
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
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
     * @param string                 $appName  App name
     * @param IRequest               $request  Request
     * @param WorkflowEngineRegistry $registry Engine registry
     * @param LoggerInterface        $logger   Logger
     * @param IL10N                  $l10n     Localization service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly WorkflowEngineRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly IL10N $l10n
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List all registered engines.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
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
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
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
     */
    public function update(int $id): JSONResponse
    {
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
     */
    public function destroy(int $id): JSONResponse
    {
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-85
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-91
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
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-89
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
