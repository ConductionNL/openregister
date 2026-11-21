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

use OCA\OpenRegister\Kernel\Contracts\TaskSequenceInterface;
use OCP\EventDispatcher\Event;

/**
 * An event about a task sequence: the sequence as persisted.
 *
 * The base carries the Kernel sequence contract, so it names no area above
 * Kernel. Each concrete event keeps its own `getSequence(): TaskSequence`,
 * so what its listeners read does not change.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
 */
abstract class AbstractTaskSequenceEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param TaskSequenceInterface $sequence The sequence as persisted.
	 */
	public function __construct(private readonly TaskSequenceInterface $sequence) {
		parent::__construct();
	}//end __construct()

	/**
	 * The sequence the event is about.
	 *
	 * @return TaskSequenceInterface The sequence as persisted.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-the-four-approval-events-are-replaced-by-a-named-complete-mapping
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-a-completion-that-neither-approves-nor-rejects-ends-the-sequence-as-undecided
	 */
	public function getSequence(): TaskSequenceInterface {
		return $this->sequence;
	}//end getSequence()
}//end class
