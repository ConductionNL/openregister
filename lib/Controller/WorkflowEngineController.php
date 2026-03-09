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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for workflow engine CRUD and health checks.
 *
 * @psalm-suppress UnusedClass
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
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly WorkflowEngineRegistry $registry,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List all registered engines.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
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
            return new JSONResponse(['error' => 'Engine not found'], 404);
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
            return new JSONResponse(['error' => 'Engine not found'], 404);
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
            return new JSONResponse(['error' => 'Engine not found'], 404);
        }
    }//end destroy()

    /**
     * Run a health check on an engine.
     *
     * @param int $id Engine ID
     *
     * @return JSONResponse
     */
    public function health(int $id): JSONResponse
    {
        try {
            $result = $this->registry->healthCheck($id);

            return new JSONResponse($result);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Engine not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end health()

    /**
     * List auto-discovered engine types from installed ExApps.
     *
     * @return JSONResponse
     */
    public function available(): JSONResponse
    {
        $engines = $this->registry->discoverEngines();

        return new JSONResponse($engines);
    }//end available()
}//end class
