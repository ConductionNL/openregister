<?php

/**
 * OpenRegister ApprovalStepCompletedEvent
 *
 * Dispatched when the LAST step of an approval chain is approved — i.e. the
 * chain has reached its terminal `approved` state. This is the signal
 * downstream apps (docudesk signing, decidesk decisions) use to advance
 * their own object's final status.
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
 * Event dispatched when an approval chain completes (final step approved).
 *
 * Fired ONCE per chain per object — at the moment the last step transitions
 * to `approved` and there is no next step. A separate
 * `ApprovalStepApprovedEvent` is also fired for that final step; downstream
 * apps that only care about full-chain completion should listen here.
 */
class ApprovalStepCompletedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param ApprovalChain $chain The approval chain that completed.
	 * @param ApprovalStep $finalStep The last step (approved) of the chain.
	 * @param string $userId ID of the user who approved the final step.
	 * @param string $statusOnApprove The configured `statusOnApprove` from
	 *                                the final step's chain definition —
	 *                                i.e. the terminal status of the chain.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ApprovalChain $chain,
		private readonly ApprovalStep $finalStep,
		private readonly string $userId,
		private readonly string $statusOnApprove,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the approval chain.
	 *
	 * @return ApprovalChain The approval chain configuration.
	 */
	public function getChain(): ApprovalChain {
		return $this->chain;
	}//end getChain()

	/**
	 * Get the final step that closed the chain.
	 *
	 * @return ApprovalStep The final approved step.
	 */
	public function getFinalStep(): ApprovalStep {
		return $this->finalStep;
	}//end getFinalStep()

	/**
	 * Get the ID of the user who closed the chain.
	 *
	 * @return string Nextcloud user ID.
	 */
	public function getUserId(): string {
		return $this->userId;
	}//end getUserId()

	/**
	 * Get the terminal status the parent object should adopt.
	 *
	 * @return string Status string from the final step's `statusOnApprove`.
	 */
	public function getStatusOnApprove(): string {
		return $this->statusOnApprove;
	}//end getStatusOnApprove()

	/**
	 * Convenience: the object UUID whose chain is now complete.
	 *
	 * @return string Object UUID (empty string if the step has none yet).
	 */
	public function getObjectUuid(): string {
		return $this->finalStep->getObjectUuid() ?? '';
	}//end getObjectUuid()
}//end class
