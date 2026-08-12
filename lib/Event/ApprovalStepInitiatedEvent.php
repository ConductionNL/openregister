<?php

/**
 * OpenRegister ApprovalStepInitiatedEvent
 *
 * Dispatched the moment an approval step transitions to the `pending` state and
 * is awaiting a decision. Fires both when a chain is initialised (step 1 → pending)
 * and when a prior step is approved and the next waiting step is advanced.
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
 * Event dispatched when an approval step is initiated (becomes `pending`).
 *
 * Downstream apps (docudesk signing workflow, decidesk multi-step decisions,
 * any approval-driven leaf app) subscribe to this event to notify the
 * authorised role group, queue reminders, or kick off integrations.
 */
class ApprovalStepInitiatedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param ApprovalChain $chain The approval chain the step belongs to.
	 * @param ApprovalStep $step The step that has just transitioned to `pending`.
	 * @param string $objectUuid UUID of the object being approved.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ApprovalChain $chain,
		private readonly ApprovalStep $step,
		private readonly string $objectUuid,
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
	 * Get the approval step that has been initiated.
	 *
	 * @return ApprovalStep The step now in `pending`.
	 */
	public function getStep(): ApprovalStep {
		return $this->step;
	}//end getStep()

	/**
	 * Get the UUID of the object whose chain is progressing.
	 *
	 * @return string Object UUID.
	 */
	public function getObjectUuid(): string {
		return $this->objectUuid;
	}//end getObjectUuid()
}//end class
