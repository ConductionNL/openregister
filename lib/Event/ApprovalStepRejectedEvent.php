<?php

/**
 * OpenRegister ApprovalStepRejectedEvent
 *
 * Dispatched after a `pending` approval step has been rejected and persisted.
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
 * Event dispatched when an approval step is rejected.
 *
 * A rejection terminates the chain (no next step is advanced). Downstream
 * apps can subscribe to roll back state, notify the requester, or trigger
 * a `rejected` notification.
 */
class ApprovalStepRejectedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param ApprovalChain $chain The approval chain.
	 * @param ApprovalStep $step The step that was rejected.
	 * @param string $userId ID of the user who rejected.
	 * @param string $statusOnReject The configured `statusOnReject` from
	 *                               the chain step definition.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ApprovalChain $chain,
		private readonly ApprovalStep $step,
		private readonly string $userId,
		private readonly string $statusOnReject,
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
	 * Get the rejected step.
	 *
	 * @return ApprovalStep The step that was rejected.
	 */
	public function getStep(): ApprovalStep {
		return $this->step;
	}//end getStep()

	/**
	 * Get the ID of the rejecting user.
	 *
	 * @return string Nextcloud user ID.
	 */
	public function getUserId(): string {
		return $this->userId;
	}//end getUserId()

	/**
	 * Get the configured status the parent object should adopt on rejection.
	 *
	 * @return string Status string from the chain step's `statusOnReject`.
	 */
	public function getStatusOnReject(): string {
		return $this->statusOnReject;
	}//end getStatusOnReject()

	/**
	 * Convenience: the object UUID being rejected.
	 *
	 * @return string Object UUID (empty string if the step has none yet).
	 */
	public function getObjectUuid(): string {
		return $this->step->getObjectUuid() ?? '';
	}//end getObjectUuid()
}//end class
