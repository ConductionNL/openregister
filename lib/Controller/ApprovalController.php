<?php

/**
 * OpenRegister ApprovalController
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
 * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-1
 * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-2
 * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-3
 * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\ApprovalChainMapper;
use OCA\OpenRegister\Db\ApprovalStepMapper;
use OCA\OpenRegister\Service\ApprovalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for approval chain CRUD and step approve/reject.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ApprovalController extends Controller
{
    /**
     * Constructor for ApprovalController.
     *
     * @param string              $appName         App name
     * @param IRequest            $request         Request
     * @param ApprovalChainMapper $chainMapper     Chain mapper
     * @param ApprovalStepMapper  $stepMapper      Step mapper
     * @param ApprovalService     $approvalService Approval service
     * @param IUserSession        $userSession     User session
     * @param LoggerInterface     $logger          Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ApprovalChainMapper $chainMapper,
        private readonly ApprovalStepMapper $stepMapper,
        private readonly ApprovalService $approvalService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List all approval chains.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-1
     */
    public function index(): JSONResponse
    {
        $chains = $this->chainMapper->findAll();

        return new JSONResponse(
            array_map(fn ($c) => $c->jsonSerialize(), $chains)
        );
    }//end index()

    /**
     * Get a single approval chain.
     *
     * @param int $id Chain ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-1
     */
    public function show(int $id): JSONResponse
    {
        try {
            $chain = $this->chainMapper->find($id);

            return new JSONResponse($chain->jsonSerialize());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Approval chain not found'], 404);
        }
    }//end show()

    /**
     * Create a new approval chain.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-1
     */
    public function create(): JSONResponse
    {
        $data = $this->request->getParams();

        try {
            $chain = $this->chainMapper->createFromArray($data);

            return new JSONResponse($chain->jsonSerialize(), 201);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end create()

    /**
     * Update an approval chain.
     *
     * @param int $id Chain ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-1
     */
    public function update(int $id): JSONResponse
    {
        try {
            $data  = $this->request->getParams();
            $chain = $this->chainMapper->updateFromArray($id, $data);

            return new JSONResponse($chain->jsonSerialize());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Approval chain not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end update()

    /**
     * Delete an approval chain.
     *
     * @param int $id Chain ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-1
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $chain = $this->chainMapper->find($id);
            $this->chainMapper->delete($chain);

            return new JSONResponse($chain->jsonSerialize());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Approval chain not found'], 404);
        }
    }//end destroy()

    /**
     * List objects in an approval chain with their progress.
     *
     * @param int $id Chain ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-2
     */
    public function objects(int $id): JSONResponse
    {
        try {
            $this->chainMapper->find($id);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Approval chain not found'], 404);
        }

        $steps = $this->stepMapper->findByChain($id);

        // Group steps by object UUID.
        $objectProgress = [];
        foreach ($steps as $step) {
            $uuid = $step->getObjectUuid();
            if (isset($objectProgress[$uuid]) === false) {
                $objectProgress[$uuid] = [
                    'objectUuid' => $uuid,
                    'steps'      => [],
                    'approved'   => 0,
                    'total'      => 0,
                ];
            }

            $objectProgress[$uuid]['steps'][] = $step->jsonSerialize();
            $objectProgress[$uuid]['total']++;
            if ($step->getStatus() === 'approved') {
                $objectProgress[$uuid]['approved']++;
            }
        }

        return new JSONResponse(array_values($objectProgress));
    }//end objects()

    /**
     * List approval steps with optional filters.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-3
     */
    public function steps(): JSONResponse
    {
        $filters = [];

        $status = $this->request->getParam('status');
        if ($status !== null) {
            $filters['status'] = $status;
        }

        $role = $this->request->getParam('role');
        if ($role !== null) {
            $filters['role'] = $role;
        }

        $chainId = $this->request->getParam('chainId');
        if ($chainId !== null) {
            $filters['chainId'] = (int) $chainId;
        }

        $objectUuid = $this->request->getParam('objectUuid');
        if ($objectUuid !== null) {
            $filters['objectUuid'] = $objectUuid;
        }

        $steps = $this->stepMapper->findAllFiltered($filters);

        return new JSONResponse(
            array_map(fn ($step) => $step->jsonSerialize(), $steps)
        );
    }//end steps()

    /**
     * Approve a pending approval step.
     *
     * @param int $id Step ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-5
     */
    public function approve(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $comment = (string) ($this->request->getParam('comment', ''));

        try {
            $result = $this->approvalService->approveStep($id, $user->getUID(), $comment);
            $step   = $result['step'];

            $response = $step->jsonSerialize();
            if ($result['nextStep'] !== null) {
                $response['nextStep'] = $result['nextStep']->jsonSerialize();
            }

            return new JSONResponse($response);
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'not authorised') === true) {
                return new JSONResponse(['error' => $e->getMessage()], 403);
            }

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end approve()

    /**
     * Reject a pending approval step.
     *
     * @param int $id Step ID
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-approval-workflow-2026-05-01/tasks.md#task-5
     */
    public function reject(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $comment = (string) ($this->request->getParam('comment', ''));

        try {
            $result = $this->approvalService->rejectStep($id, $user->getUID(), $comment);
            $step   = $result['step'];

            return new JSONResponse($step->jsonSerialize());
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'not authorised') === true) {
                return new JSONResponse(['error' => $e->getMessage()], 403);
            }

            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end reject()
}//end class
