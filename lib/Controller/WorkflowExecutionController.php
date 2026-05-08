<?php

/**
 * OpenRegister WorkflowExecutionController
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-83
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\WorkflowExecutionMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for workflow execution history API.
 *
 * @psalm-suppress UnusedClass
 */
class WorkflowExecutionController extends Controller
{
    /**
     * Constructor for WorkflowExecutionController.
     *
     * @param string                  $appName         App name
     * @param IRequest                $request         Request
     * @param WorkflowExecutionMapper $executionMapper Execution mapper
     * @param LoggerInterface         $logger          Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly WorkflowExecutionMapper $executionMapper,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List workflow executions with filters and pagination.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-83
     */
    public function index(): JSONResponse
    {
        $filters = [];

        $objectUuid = $this->request->getParam('objectUuid');
        if ($objectUuid !== null) {
            $filters['objectUuid'] = $objectUuid;
        }

        $schemaId = $this->request->getParam('schemaId');
        if ($schemaId !== null) {
            $filters['schemaId'] = (int) $schemaId;
        }

        $hookId = $this->request->getParam('hookId');
        if ($hookId !== null) {
            $filters['hookId'] = $hookId;
        }

        $status = $this->request->getParam('status');
        if ($status !== null) {
            $filters['status'] = $status;
        }

        $engine = $this->request->getParam('engine');
        if ($engine !== null) {
            $filters['engine'] = $engine;
        }

        $since = $this->request->getParam('since');
        if ($since !== null) {
            $filters['since'] = $since;
        }

        $limit  = (int) ($this->request->getParam('limit', '50'));
        $offset = (int) ($this->request->getParam('offset', '0'));

        $limit  = min(max($limit, 1), 500);
        $offset = max($offset, 0);

        $results = $this->executionMapper->findAll($filters, $limit, $offset);
        $total   = $this->executionMapper->countAll($filters);

        return new JSONResponse(
                [
                    'results' => array_map(fn ($e) => $e->jsonSerialize(), $results),
                    'total'   => $total,
                    'limit'   => $limit,
                    'offset'  => $offset,
                ]
                );
    }//end index()

    /**
     * Get a single execution detail.
     *
     * @param int $id Execution ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-83
     */
    public function show(int $id): JSONResponse
    {
        try {
            $execution = $this->executionMapper->find($id);

            return new JSONResponse($execution->jsonSerialize());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Execution not found'], 404);
        }
    }//end show()

    /**
     * Delete an execution record (admin only).
     *
     * @param int $id Execution ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-83
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $execution = $this->executionMapper->find($id);
            $this->executionMapper->delete($execution);

            return new JSONResponse(['message' => 'Execution deleted']);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Execution not found'], 404);
        }
    }//end destroy()
}//end class
