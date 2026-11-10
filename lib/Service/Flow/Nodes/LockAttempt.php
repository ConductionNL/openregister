<?php

/**
 * One attempt of a lock step at one target, under the step's wait budget.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;

/**
 * One attempt of a {@see LockObjectNode} at one target: the object, the slot
 * the retry count lives in, and the absolute deadline the waiting stops at.
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
 */
final class LockAttempt {

	/**
	 * Name the attempt.
	 *
	 * @param FlowNodeResumeState $resume   This node's resume slot.
	 * @param DateTime            $deadline The absolute wait deadline.
	 * @param string              $uuid     The object to lock.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function __construct(
		public readonly FlowNodeResumeState $resume,
		public readonly DateTime $deadline,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
