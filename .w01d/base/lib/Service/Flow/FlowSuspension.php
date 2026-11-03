<?php

/**
 * Thrown by a step that wants the run paused rather than finished.
 *
 * A Wait step and a sub-flow step both need the same thing: stop here, keep
 * everything, come back later. Neither is a failure and neither is a
 * completion, so neither fits the existing `onError` policies or the terminal
 * statuses.
 *
 * An exception rather than a return value on purpose. A node returns ITEMS; if
 * "please suspend" were smuggled into that return, every node author would
 * have to know about a magic item shape, and a node that forgot would silently
 * continue. Throwing makes suspension impossible to ignore and keeps the item
 * contract exactly what it says it is.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use RuntimeException;

/**
 * Signals that the current run should suspend.
 */
class FlowSuspension extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param DateTime|null $resumeAt When the run becomes eligible to resume;
	 *                                null means it waits for an external
	 *                                signal (a child run, a webhook) instead
	 *                                of a clock.
	 * @param string $reason Why it suspended, for the run log.
	 */
	public function __construct(
		private readonly ?DateTime $resumeAt = null,
		string $reason = 'suspended',
	) {
		parent::__construct(message: $reason);

	}//end __construct()

	/**
	 * When this run may resume.
	 *
	 * @return DateTime|null The resume time, or null when waiting on a signal.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	public function getResumeAt(): ?DateTime {
		return $this->resumeAt;
	}//end getResumeAt()
}//end class
