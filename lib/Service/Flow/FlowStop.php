<?php

/**
 * Thrown by a step that wants the run to end here, deliberately.
 *
 * The counterpart to {@see FlowSuspension}: where suspension means "pause and
 * come back", this means "stop, on purpose, now". It is the mechanism behind a
 * Stop step — n8n's "Stop And Error" — and is caught by the engine before the
 * generic `Throwable` handler so it is never mistaken for a step failure and
 * never subject to an `onError` policy. The author asked the run to end; it
 * ends.
 *
 * `isError` chooses the outcome. A plain stop is a clean `stopped`; an error
 * stop is `failed` with the message, for the case where reaching this node
 * means something genuinely went wrong and downstream systems should treat it
 * as a failure rather than a normal completion.
 *
 * An exception rather than a return value, for the same reason as suspension: a
 * node returns ITEMS, and smuggling "end the run" into that return would force
 * every node author to know a magic item shape, with a forgetful node silently
 * carrying on.
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
 * @spec openspec/changes/or-flow-logic/specs/flow-logic/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use RuntimeException;

/**
 * Signals that the current run should end here.
 */
class FlowStop extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $reason Why the run stopped, for the run log.
	 * @param boolean $isError Whether this is a failure (`failed`) rather
	 *                         than a clean stop (`stopped`).
	 * @param string|null $checkId The oversight check that vetoed the hop, when
	 *                             the stop came from a gate rather than from a
	 *                             Stop step. Structured rather than folded into
	 *                             the reason so "which gate closed" stays a
	 *                             query instead of a substring search.
	 */
	public function __construct(
		string $reason = 'stopped',
		private readonly bool $isError = false,
		private readonly ?string $checkId = null,
	) {
		parent::__construct(message: $reason);

	}//end __construct()

	/**
	 * The oversight check that vetoed the hop, when a gate raised this stop.
	 *
	 * @return string|null The check id, or null for an author-requested stop.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function checkId(): ?string {
		return $this->checkId;
	}//end checkId()

	/**
	 * Whether the run should end as `failed` rather than `stopped`.
	 *
	 * @return boolean True for an error stop.
	 *
	 * @spec openspec/changes/or-flow-logic/specs/flow-logic/spec.md
	 */
	public function isError(): bool {
		return $this->isError;
	}//end isError()
}//end class
