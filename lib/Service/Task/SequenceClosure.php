<?php

/**
 * Closing an approval sequence: with which status and outcome, and why.
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

use OCA\OpenRegister\Db\TaskSequence;

/**
 * Closing an approval sequence: the sequence, the status and outcome it closes as, and the reason its open tasks are told.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */
final readonly class SequenceClosure {

	/**
	 * @param TaskSequence $sequence The sequence to close.
	 * @param string       $status   The terminal status.
	 * @param string       $outcome  The outcome recorded on it.
	 * @param string       $reason   Why its still-open tasks are terminated as moot.
	 */
	public function __construct(
		public TaskSequence $sequence,
		public string $status,
		public string $outcome,
		public string $reason,
	) {
	}//end __construct()
}//end class
