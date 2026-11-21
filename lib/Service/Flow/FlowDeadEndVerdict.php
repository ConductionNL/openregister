<?php

/**
 * Writes a dead-end verdict onto the flow it is about.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;

/**
 * Writes the outcome of a dead-end check onto the flow's own status.
 *
 * The flow's status is the surface an author looks at: a refusal is written
 * there, and a repaired flow stops reporting the error it no longer has.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class FlowDeadEndVerdict {

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface $mapper Stores the flow.
	 * @param Flow       $flow   The flow judged.
	 */
	public function __construct(
		private readonly FlowMapperInterface $mapper,
		private readonly Flow $flow,
	) {
	}//end __construct()

	/**
	 * Record the verdict: refused when there are dead ends, else cleared.
	 *
	 * @param array<int, string> $deadEnds The steps a token cannot leave.
	 *
	 * @return FlowDeadEnd|null The refusal, or null when the flow is sound.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function record(array $deadEnds): ?FlowDeadEnd {
		if ($deadEnds === []) {
			$this->clearStaleRefusal();
			return null;
		}

		$refusal = new FlowDeadEnd(nodeIds: $deadEnds);
		$this->flow->setStatus(Flow::STATUS_ERROR);
		$this->flow->setStatusMessage($refusal->getMessage());
		$this->mapper->update($this->flow);

		return $refusal;
	}//end record()

	/**
	 * Clear an error status left by an earlier refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function clearStaleRefusal(): void {
		if ($this->flow->getStatus() !== Flow::STATUS_ERROR) {
			return;
		}

		$this->flow->setStatus(Flow::STATUS_OK);
		$this->flow->setStatusMessage(null);
		$this->mapper->update($this->flow);
	}//end clearStaleRefusal()
}//end class
