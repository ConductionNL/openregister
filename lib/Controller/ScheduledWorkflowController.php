<?php

/**
 * OpenRegister ScheduledWorkflowController
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

use OCA\OpenRegister\Db\ScheduledWorkflowMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for scheduled workflow CRUD.
 *
 * @psalm-suppress UnusedClass
 */
class ScheduledWorkflowController extends Controller
{
    /**
     * Constructor for ScheduledWorkflowController.
     *
     * @param string                  $appName        App name
     * @param IRequest                $request        Request
     * @param ScheduledWorkflowMapper $workflowMapper Scheduled workflow mapper
     * @param LoggerInterface         $logger         Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ScheduledWorkflowMapper $workflowMapper,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List all scheduled workflows.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        $workflows = $this->workflowMapper->findAll();

        return new JSONResponse(
            array_map(fn ($w) => $w->jsonSerialize(), $workflows)
        );
    }//end index()

    /**
     * Get a single scheduled workflow.
     *
     * @param int $id Scheduled workflow ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function show(int $id): JSONResponse
    {
        try {
            $workflow = $this->workflowMapper->find($id);

            return new JSONResponse($workflow->jsonSerialize());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Scheduled workflow not found'], 404);
        }
    }//end show()

    /**
     * Create a new scheduled workflow.
     *
     * @return JSONResponse
     */
    public function create(): JSONResponse
    {
        $data = $this->request->getParams();

        // Encode payload if it is an array.
        if (isset($data['payload']) === true && is_array($data['payload']) === true) {
            $data['payload'] = json_encode($data['payload']);
        }

        // Map 'interval' to 'intervalSec' for convenience.
        if (isset($data['interval']) === true && isset($data['intervalSec']) === false) {
            $data['intervalSec'] = (int) $data['interval'];
        }

        try {
            $workflow = $this->workflowMapper->createFromArray($data);

            return new JSONResponse($workflow->jsonSerialize(), 201);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end create()

    /**
     * Update a scheduled workflow.
     *
     * @param int $id Scheduled workflow ID
     *
     * @return JSONResponse
     */
    public function update(int $id): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            if (isset($data['payload']) === true && is_array($data['payload']) === true) {
                $data['payload'] = json_encode($data['payload']);
            }

            if (isset($data['interval']) === true && isset($data['intervalSec']) === false) {
                $data['intervalSec'] = (int) $data['interval'];
            }

            $workflow = $this->workflowMapper->updateFromArray($id, $data);

            return new JSONResponse($workflow->jsonSerialize());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Scheduled workflow not found'], 404);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end update()

    /**
     * Delete a scheduled workflow.
     *
     * @param int $id Scheduled workflow ID
     *
     * @return JSONResponse
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $workflow = $this->workflowMapper->find($id);
            $this->workflowMapper->delete($workflow);

            return new JSONResponse($workflow->jsonSerialize());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Scheduled workflow not found'], 404);
        }
    }//end destroy()
}//end class
