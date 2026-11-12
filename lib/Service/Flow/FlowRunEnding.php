<?php

/**
 * FlowRunEnding
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;

/**
 * A pass's last word on a run: whose pass ends, whether work stays enabled,
 * the run-level terminal that overrides the projection, and when it ended.
 *
 * Built by {@see FlowStreamWalk::finalize()}; the moment is shared by every
 * stream a forced terminal ends and by the run row itself.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
 */
final class FlowRunEnding {

	/**
	 * Name the ending.
	 *
	 * @param string      $owner          The pass token whose claims are released.
	 * @param bool        $enabled        Whether any transition is enabled on the committed marking.
	 * @param string|null $forcedTerminal A run-level terminal outcome (stop, dead-letter, failure), or null.
	 * @param DateTime    $endedAt        When the pass ended.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	public function __construct(
		public readonly string $owner,
		public readonly bool $enabled,
		public readonly ?string $forcedTerminal,
		public readonly DateTime $endedAt,
	) {
	}//end __construct()

	/**
	 * What the ending gives back for the status projection: a run told to
	 * end has no enabled work left to honour.
	 *
	 * @return FlowStreamRelease
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	public function release(): FlowStreamRelease {
		return new FlowStreamRelease(claimedPlaces: [], owner: $this->owner, enabled: ($this->enabled === true && $this->forcedTerminal === null));
	}//end release()
}//end class
