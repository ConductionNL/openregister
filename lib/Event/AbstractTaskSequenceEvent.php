<?php

/**
 * OpenRegister AbstractTaskSequenceEvent
 *
 * What the events about the end of a task sequence carry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\TaskSequence;
use OCP\EventDispatcher\Event;

/**
 * An event about a task sequence: the sequence as persisted.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
 */
abstract class AbstractTaskSequenceEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param TaskSequence $sequence The sequence as persisted.
	 */
	public function __construct(private readonly TaskSequence $sequence) {
		parent::__construct();
	}//end __construct()

	/**
	 * The sequence the event is about.
	 *
	 * @return TaskSequence The sequence as persisted.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-a-completion-that-neither-approves-nor-rejects-ends-the-sequence-as-undecided
	 */
	public function getSequence(): TaskSequence {
		return $this->sequence;
	}//end getSequence()
}//end class
