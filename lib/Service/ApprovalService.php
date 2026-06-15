<?php

/**
 * OpenRegister ApprovalService
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-01-approval-workflow/tasks.md#task-4
 * @spec openspec/changes/retrofit-2026-05-01-approval-workflow/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalChainMapper;
use OCA\OpenRegister\Db\ApprovalStep;
use OCA\OpenRegister\Db\ApprovalStepMapper;
use OCA\OpenRegister\Db\WorkflowExecutionMapper;
use OCA\OpenRegister\Event\ApprovalStepApprovedEvent;
use OCA\OpenRegister\Event\ApprovalStepCompletedEvent;
use OCA\OpenRegister\Event\ApprovalStepInitiatedEvent;
use OCA\OpenRegister\Event\ApprovalStepRejectedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Service for managing multi-step approval chains.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ApprovalService
{
    /**
     * Constructor for ApprovalService.
     *
     * @param ApprovalChainMapper     $chainMapper     Chain mapper
     * @param ApprovalStepMapper      $stepMapper      Step mapper
     * @param WorkflowExecutionMapper $executionMapper Execution history mapper
     * @param IGroupManager           $groupManager    Group manager for role checks
     * @param LoggerInterface         $logger          Logger
     * @param IEventDispatcher        $eventDispatcher Event dispatcher for approval step events
     */
    public function __construct(
        private readonly ApprovalChainMapper $chainMapper,
        private readonly ApprovalStepMapper $stepMapper,
        private readonly WorkflowExecutionMapper $executionMapper,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
        private readonly IEventDispatcher $eventDispatcher
    ) {
    }//end __construct()

    /**
     * Initialize approval steps for an object entering a chain.
     *
     * Creates ApprovalStep entities for each step in the chain: step 1 as
     * 'pending', all others as 'waiting'.
     *
     * @param ApprovalChain $chain      The approval chain
     * @param string        $objectUuid The object's UUID
     *
     * @return array<int, ApprovalStep> Created steps
     *
     * @spec openspec/changes/retrofit-2026-05-01-approval-workflow/tasks.md#task-4
     * @spec openspec/changes/add-approval-step-events/tasks.md#task-3
     */
    public function initializeChain(ApprovalChain $chain, string $objectUuid): array
    {
        $steps        = $chain->getStepsArray();
        $createdSteps = [];

        foreach ($steps as $index => $stepDef) {
            $status = 'waiting';
            if ($index === 0) {
                $status = 'pending';
            }

            $step = $this->stepMapper->createFromArray(
                    [
                        'chainId'    => $chain->getId(),
                        'objectUuid' => $objectUuid,
                        'stepOrder'  => ($stepDef['order'] ?? ($index + 1)),
                        'role'       => ($stepDef['role'] ?? ''),
                        'status'     => $status,
                    ]
                    );

            $createdSteps[] = $step;

            // Dispatch initiated event for the first step (now `pending`).
            if ($index === 0) {
                $this->eventDispatcher->dispatchTyped(
                    new ApprovalStepInitiatedEvent(chain: $chain, step: $step, objectUuid: $objectUuid)
                );
            }
        }//end foreach

        return $createdSteps;
    }//end initializeChain()

    /**
     * Approve a pending approval step.
     *
     * Returns an array with the updated step and any next step info.
     *
     * @param int    $stepId  Step ID
     * @param string $userId  Current user ID
     * @param string $comment Approval comment
     *
     * @return array{step: ApprovalStep, nextStep: ApprovalStep|null, statusOnApprove: string}
     *
     * @throws Exception If user is not authorised or step is not pending
     *
     * @spec openspec/changes/retrofit-2026-05-01-approval-workflow/tasks.md#task-5
     * @spec openspec/changes/add-approval-step-events/tasks.md#task-4
     */
    public function approveStep(int $stepId, string $userId, string $comment=''): array
    {
        $step = $this->stepMapper->find($stepId);

        if ($step->getStatus() !== 'pending') {
            throw new Exception('Step is not in pending status');
        }

        // Verify role membership.
        $this->verifyRole(userId: $userId, role: $step->getRole());

        // Update the step.
        $step->setStatus('approved');
        $step->setDecidedBy($userId);
        $step->setComment($comment);
        $step->setDecidedAt(new DateTime());
        $this->stepMapper->update($step);

        // Load the chain to get step definitions.
        $chain      = $this->chainMapper->find($step->getChainId());
        $chainSteps = $chain->getStepsArray();

        // Find the current step definition for statusOnApprove.
        $statusOnApprove = 'approved';
        foreach ($chainSteps as $def) {
            if (($def['order'] ?? 0) === $step->getStepOrder()) {
                $statusOnApprove = ($def['statusOnApprove'] ?? 'approved');
                break;
            }
        }

        // Advance the next step to 'pending'.
        $nextStep = null;
        $allSteps = $this->stepMapper->findByChainAndObject($chain->getId(), $step->getObjectUuid());
        foreach ($allSteps as $candidate) {
            if ($candidate->getStepOrder() > $step->getStepOrder() && $candidate->getStatus() === 'waiting') {
                $candidate->setStatus('pending');
                $this->stepMapper->update($candidate);
                $nextStep = $candidate;
                break;
            }
        }

        // Persist execution history.
        $this->persistApprovalExecution(chain: $chain, step: $step, status: 'approved');

        // Dispatch the approved event.
        $this->eventDispatcher->dispatchTyped(
            new ApprovalStepApprovedEvent(
                chain: $chain,
                step: $step,
                userId: $userId,
                statusOnApprove: $statusOnApprove,
                nextStep: $nextStep
            )
        );

        // Dispatch follow-up: either initiate the next step or complete the chain.
        if ($nextStep !== null) {
            $this->eventDispatcher->dispatchTyped(
                new ApprovalStepInitiatedEvent(
                    chain: $chain,
                    step: $nextStep,
                    objectUuid: $step->getObjectUuid()
                )
            );
        } else {
            $this->eventDispatcher->dispatchTyped(
                new ApprovalStepCompletedEvent(
                    chain: $chain,
                    finalStep: $step,
                    userId: $userId,
                    statusOnApprove: $statusOnApprove
                )
            );
        }

        return [
            'step'            => $step,
            'nextStep'        => $nextStep,
            'statusOnApprove' => $statusOnApprove,
            'chain'           => $chain,
        ];
    }//end approveStep()

    /**
     * Reject a pending approval step.
     *
     * @param int    $stepId  Step ID
     * @param string $userId  Current user ID
     * @param string $comment Rejection comment
     *
     * @return array{step: ApprovalStep, statusOnReject: string}
     *
     * @throws Exception If user is not authorised or step is not pending
     *
     * @spec openspec/changes/retrofit-2026-05-01-approval-workflow/tasks.md#task-5
     * @spec openspec/changes/add-approval-step-events/tasks.md#task-5
     */
    public function rejectStep(int $stepId, string $userId, string $comment=''): array
    {
        $step = $this->stepMapper->find($stepId);

        if ($step->getStatus() !== 'pending') {
            throw new Exception('Step is not in pending status');
        }

        // Verify role membership.
        $this->verifyRole(userId: $userId, role: $step->getRole());

        // Update the step.
        $step->setStatus('rejected');
        $step->setDecidedBy($userId);
        $step->setComment($comment);
        $step->setDecidedAt(new DateTime());
        $this->stepMapper->update($step);

        // Load the chain to get step definitions.
        $chain      = $this->chainMapper->find($step->getChainId());
        $chainSteps = $chain->getStepsArray();

        // Find the current step definition for statusOnReject.
        $statusOnReject = 'rejected';
        foreach ($chainSteps as $def) {
            if (($def['order'] ?? 0) === $step->getStepOrder()) {
                $statusOnReject = ($def['statusOnReject'] ?? 'rejected');
                break;
            }
        }

        // Persist execution history.
        $this->persistApprovalExecution(chain: $chain, step: $step, status: 'rejected');

        // Dispatch the rejected event — chain is terminated; no next-step event.
        $this->eventDispatcher->dispatchTyped(
            new ApprovalStepRejectedEvent(
                chain: $chain,
                step: $step,
                userId: $userId,
                statusOnReject: $statusOnReject
            )
        );

        return [
            'step'           => $step,
            'statusOnReject' => $statusOnReject,
            'chain'          => $chain,
        ];
    }//end rejectStep()

    /**
     * Verify that a user is a member of the required group/role.
     *
     * @param string $userId User ID
     * @param string $role   Required role (Nextcloud group ID)
     *
     * @return void
     *
     * @throws Exception If user is not in the required group
     *
     * @spec openspec/changes/retrofit-2026-05-01-approval-workflow/tasks.md#task-5
     */
    private function verifyRole(string $userId, string $role): void
    {
        if ($this->groupManager->isInGroup($userId, $role) === false) {
            throw new Exception('You are not authorised for this approval step');
        }
    }//end verifyRole()

    /**
     * Persist an approval action to the execution history.
     *
     * @param ApprovalChain $chain  The approval chain
     * @param ApprovalStep  $step   The approval step
     * @param string        $status The approval status
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-01-approval-workflow/tasks.md#task-5
     */
    private function persistApprovalExecution(
        ApprovalChain $chain,
        ApprovalStep $step,
        string $status
    ): void {
        try {
            $this->executionMapper->createFromArray(
                    [
                        'hookId'     => 'approval-chain-'.$chain->getId(),
                        'eventType'  => 'approval',
                        'objectUuid' => $step->getObjectUuid(),
                        'schemaId'   => $chain->getSchemaId(),
                        'engine'     => 'approval',
                        'workflowId' => 'chain-'.$chain->getId().'-step-'.$step->getStepOrder(),
                        'mode'       => 'sync',
                        'status'     => $status,
                        'durationMs' => 0,
                        'metadata'   => json_encode(
                        [
                            'chainName' => $chain->getName(),
                            'stepOrder' => $step->getStepOrder(),
                            'role'      => $step->getRole(),
                            'decidedBy' => $step->getDecidedBy(),
                            'comment'   => $step->getComment(),
                        ]
                        ),
                        'executedAt' => new DateTime(),
                    ]
                    );
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[ApprovalService] Failed to persist approval execution',
                context: ['chainId' => $chain->getId(), 'stepId' => $step->getId(), 'error' => $e->getMessage()]
            );
        }//end try
    }//end persistApprovalExecution()
}//end class
