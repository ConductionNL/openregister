<?php

/**
 * OpenRegister ApprovalStepApprovedEvent
 *
 * Dispatched after a `pending` approval step has been successfully approved,
 * persisted, and the chain has been advanced (if a next waiting step exists).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-approval-step-events/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalStep;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an approval step is approved.
 *
 * Carries the step, chain, the user who approved, the `statusOnApprove`
 * resolved from the chain definition, and the next step (if any). Downstream
 * apps (docudesk signing) use this to advance their own workflow state.
 */
class ApprovalStepApprovedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param ApprovalChain     $chain           The approval chain.
     * @param ApprovalStep      $step            The step that was approved.
     * @param string            $userId          ID of the user who approved.
     * @param string            $statusOnApprove The configured `statusOnApprove`
     *                                           from the chain step definition.
     * @param ApprovalStep|null $nextStep        The next step now in `pending`, or
     *                                           null if this was the final step.
     *
     * @return void
     */
    public function __construct(
        private readonly ApprovalChain $chain,
        private readonly ApprovalStep $step,
        private readonly string $userId,
        private readonly string $statusOnApprove,
        private readonly ?ApprovalStep $nextStep
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the approval chain.
     *
     * @return ApprovalChain The approval chain configuration.
     */
    public function getChain(): ApprovalChain
    {
        return $this->chain;
    }//end getChain()

    /**
     * Get the approved step.
     *
     * @return ApprovalStep The step that was approved.
     */
    public function getStep(): ApprovalStep
    {
        return $this->step;
    }//end getStep()

    /**
     * Get the ID of the approving user.
     *
     * @return string Nextcloud user ID.
     */
    public function getUserId(): string
    {
        return $this->userId;
    }//end getUserId()

    /**
     * Get the configured status the parent object should adopt on approval.
     *
     * @return string Status string from the chain step's `statusOnApprove`.
     */
    public function getStatusOnApprove(): string
    {
        return $this->statusOnApprove;
    }//end getStatusOnApprove()

    /**
     * Get the next step now in `pending`, if any.
     *
     * @return ApprovalStep|null The next step or null if the chain has ended.
     */
    public function getNextStep(): ?ApprovalStep
    {
        return $this->nextStep;
    }//end getNextStep()

    /**
     * Convenience: is this the final step of the chain?
     *
     * @return bool True when no next step is pending.
     */
    public function isFinalStep(): bool
    {
        return $this->nextStep === null;
    }//end isFinalStep()

    /**
     * Convenience: the object UUID being approved.
     *
     * @return string Object UUID (empty string if the step has none yet).
     */
    public function getObjectUuid(): string
    {
        return $this->step->getObjectUuid() ?? '';
    }//end getObjectUuid()
}//end class
