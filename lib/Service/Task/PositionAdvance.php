<?php

/**
 * A task sequence moving from one approved position to the next.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;

/**
 * The position that just completed approvingly and the one it enables.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */
final class PositionAdvance {
	/**
	 * The advance.
	 *
	 * @param Task $completed The position that just completed approvingly.
	 * @param Task $next The position it enables.
	 */
	public function __construct(
		public readonly Task $completed,
		public readonly Task $next,
	) {
	}//end __construct()

	/**
	 * The audit reason of the advance.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function reason(): string {
		return sprintf(
			'Position %d approved; position %d is now enabled.',
			(int)$this->completed->getSequencePosition(),
			(int)$this->next->getSequencePosition()
		);
	}//end reason()
}//end class
