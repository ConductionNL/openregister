<?php

/**
 * OpenRegister ScheduledWorkflowController
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-17
     */
    public function index(): JSONResponse
    {
        $workflows = $this->workflowMapper->findAll();

        return new JSONResponse(
            array_map(fn ($workflow) => $workflow->jsonSerialize(), $workflows)
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
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-17
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
     * @auth admin-only A scheduled workflow is instance-wide: ScheduledWorkflow carries no owner
     *       column, and the TimedJob that runs it runs for the whole instance, not for the caller.
     *       There is therefore no per-object guard to write here — nothing scopes a row to a user —
     *       so admin is the only posture that is not a privilege escalation. Adding
     *       #[NoAdminRequired] would let any authenticated user schedule work on the instance.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-17
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
     * @auth admin-only ScheduledWorkflow has no owner column, so "the caller's own workflow" is not
     *       a thing that can be expressed, let alone guarded. Rescheduling or re-pointing a job
     *       changes instance-wide behaviour for every user, so admin is the correct posture.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-17
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
     * @auth admin-only Deletes an instance-wide scheduled job by id. ScheduledWorkflow has no owner
     *       column, so a per-object guard has nothing to compare the caller against; under
     *       #[NoAdminRequired] this would be a direct object reference any user could delete.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-17
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
