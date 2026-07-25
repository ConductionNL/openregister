<?php

/**
 * Run history and retry — the execution-tooling surface over flow runs.
 *
 * A run is persisted with everything a person needs to work with it: its
 * status, its per-step log, the items it carried, its error. This controller
 * exposes that — list runs (filter by flow and status), inspect one, retry a
 * finished one, and requeue a dead-lettered one. It is the answer to "what did
 * my flow do, and can I run it again".
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-tooling/specs/flow-tooling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * REST surface for inspecting and retrying flow runs.
 */
class FlowRunController extends Controller
{
    /**
     * Constructor.
     *
     * @param string         $appName The app id.
     * @param IRequest       $request The request.
     * @param FlowRunMapper  $mapper  Reads runs.
     * @param FlowRunService $runner  Retries and requeues.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly FlowRunMapper $mapper,
        private readonly FlowRunService $runner
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * List runs, newest first, optionally filtered by flow and status.
     *
     * @return JSONResponse The runs plus the paging window.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/or-flow-tooling/specs/flow-tooling/spec.md
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $flowId = $this->request->getParam('flowId');
        $status = $this->request->getParam('status');
        $limit  = min(200, max(1, (int) $this->request->getParam('limit', 50)));
        $offset = max(0, (int) $this->request->getParam('offset', 0));

        $flowFilter = null;
        if ($flowId !== null) {
            $flowFilter = (string) $flowId;
        }

        $statusFilter = null;
        if ($status !== null) {
            $statusFilter = (string) $status;
        }

        $runs = $this->mapper->findAllRuns(
            flowId: $flowFilter,
            status: $statusFilter,
            limit: $limit,
            offset: $offset
        );

        return new JSONResponse(
            [
                'results' => array_map(static fn (FlowRun $r): array => $r->jsonSerialize(), $runs),
                'limit'   => $limit,
                'offset'  => $offset,
            ]
        );

    }//end index()

    /**
     * One run in full — its log, items, context and error.
     *
     * @param string $uuid The run uuid.
     *
     * @return JSONResponse The run, or 404.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/or-flow-tooling/specs/flow-tooling/spec.md
     */
    #[NoAdminRequired]
    public function show(string $uuid): JSONResponse
    {
        try {
            $run = $this->mapper->findByUuid($uuid);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'No such run'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($run->jsonSerialize());

    }//end show()

    /**
     * Retry a finished run: queue a fresh one, leave the original untouched.
     *
     * @param string $uuid The run uuid.
     *
     * @return JSONResponse The new run, or a 4xx when the source cannot be retried.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/or-flow-tooling/specs/flow-tooling/spec.md
     */
    #[NoAdminRequired]
    public function retry(string $uuid): JSONResponse
    {
        try {
            $run = $this->mapper->findByUuid($uuid);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'No such run'], Http::STATUS_NOT_FOUND);
        }

        $new = $this->runner->retry($run);
        if ($new === null) {
            // The source is not terminal — queued, running, or suspended. Retry
            // is for finished runs; the others are already progressing.
            return new JSONResponse(
                ['error' => 'Only a finished run can be retried; this one is '.$run->getStatus().'.'],
                Http::STATUS_CONFLICT
            );
        }

        return new JSONResponse($new->jsonSerialize(), Http::STATUS_CREATED);

    }//end retry()
}//end class
