<?php

/**
 * The preconditions on every flow lifecycle transition, in one place.
 *
 * 🔴 A GUARD THAT IS DEFINED BUT NEVER CALLED IS THE SAME AS NO GUARD. This
 * class exists as one collaborator, with one caller per rule, precisely so
 * "is this checked?" is answerable by grep rather than by reading three
 * services. Every refusal is thrown, never returned as a boolean nobody has
 * to inspect, and every refusal is logged with the reason that caused it.
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
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowVersion;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a lifecycle transition is allowed, and refuses out loud.
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class FlowLifecycleGuard {
	/**
	 * Constructor.
	 *
	 * @param FlowRunMapper     $runs      Counts runs pinned to a version.
	 * @param FlowNodePreflight $preflight Inspects a graph for dead ends.
	 * @param LoggerInterface   $logger    Records every refusal and its reason.
	 */
	public function __construct(
		private readonly FlowRunMapper $runs,
		private readonly FlowNodePreflight $preflight,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Refuse a definition change against anything but a draft.
	 *
	 * @param string      $flowId The flow being written.
	 * @param string|null $state  The flow head's lifecycle status.
	 *
	 * @return void
	 *
	 * @throws FlowLifecycleRefused When the head is not a draft.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function refuseEditUnlessDraft(string $flowId, ?string $state): void {
		if ($state === null || $state === FlowVersion::STATUS_DRAFT) {
			return;
		}

		$this->refuse(
			reason: FlowLifecycleRefused::REASON_IMMUTABLE,
			flowId: $flowId,
			state: $state
		);

	}//end refuseEditUnlessDraft()

	/**
	 * Refuse to publish anything but a draft.
	 *
	 * @param string      $flowId The flow.
	 * @param string|null $state  The lifecycle status of the version being published.
	 *
	 * @return void
	 *
	 * @throws FlowLifecycleRefused When the version is not a draft.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function refusePublishUnlessDraft(string $flowId, ?string $state): void {
		if ($state === FlowVersion::STATUS_DRAFT) {
			return;
		}

		$this->refuse(
			reason: FlowLifecycleRefused::REASON_NOT_A_DRAFT,
			flowId: $flowId,
			state: $state
		);

	}//end refusePublishUnlessDraft()

	/**
	 * Refuse to deprecate anything but a published version.
	 *
	 * @param string      $flowId The flow.
	 * @param string|null $state  The lifecycle status of the version being deprecated.
	 *
	 * @return void
	 *
	 * @throws FlowLifecycleRefused When the version is not published.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function refuseDeprecateUnlessPublished(string $flowId, ?string $state): void {
		if ($state === FlowVersion::STATUS_PUBLISHED) {
			return;
		}

		$this->refuse(
			reason: FlowLifecycleRefused::REASON_NOT_PUBLISHED,
			flowId: $flowId,
			state: $state
		);

	}//end refuseDeprecateUnlessPublished()

	/**
	 * Refuse to publish a graph with a node a token cannot leave.
	 *
	 * 🔑 THE PREFLIGHT RUNS ON THE GRAPH BEING PUBLISHED, not on the flow's
	 * head. Judging the head would let a broken draft block the publication of
	 * a sound version, and — worse in the other direction — let a repaired
	 * draft mask a dead end in the graph actually going live.
	 *
	 * @param string               $flowId The flow.
	 * @param array<string, mixed> $graph  The nodes and edges being published.
	 *
	 * @return void
	 *
	 * @throws FlowLifecycleRefused When the graph has a dead end.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function refusePublishOnDeadEnd(string $flowId, array $graph): void {
		$findings = $this->preflight->inspect(
			flow: [
				'nodes' => (array)($graph['nodes'] ?? []),
				'edges' => (array)($graph['edges'] ?? []),
			]
		);

		$deadEnds = [];
		foreach (($findings['warnings'] ?? []) as $warning) {
			if (($warning['reason'] ?? '') === FlowNodePreflight::REASON_DEAD_END) {
				$deadEnds[] = (string)($warning['step'] ?? '');
			}
		}

		if ($deadEnds === []) {
			return;
		}

		$this->refuse(
			reason: FlowLifecycleRefused::REASON_DEAD_END,
			flowId: $flowId,
			state: FlowVersion::STATUS_DRAFT,
			detail: 'Offending steps: ' . implode(separator: ', ', array: $deadEnds) . '.'
		);

	}//end refusePublishOnDeadEnd()

	/**
	 * Refuse to remove a version a still-movable run is pinned to.
	 *
	 * @param string  $flowId  The flow.
	 * @param integer $version The version being removed.
	 *
	 * @return void
	 *
	 * @throws FlowLifecycleRefused When a non-terminal run is pinned to it.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function refuseRemoveWhilePinned(string $flowId, int $version): void {
		$pinned = $this->runs->countActivePinnedTo(flowUuid: $flowId, version: $version);
		if ($pinned === 0) {
			return;
		}

		$this->refuse(
			reason: FlowLifecycleRefused::REASON_VERSION_IN_USE,
			flowId: $flowId,
			state: null,
			detail: $pinned . ' run(s) are still pinned to version ' . $version . '.'
		);

	}//end refuseRemoveWhilePinned()

	/**
	 * Log the refusal, then throw it.
	 *
	 * Both, always. The throw is what stops the transition; the log is what
	 * lets an operator answer "why did publishing do nothing" without a
	 * debugger, which is the complaint every silent guard produces.
	 *
	 * @param string      $reason One of the FlowLifecycleRefused REASON_* constants.
	 * @param string      $flowId The flow.
	 * @param string|null $state  The state that caused it.
	 * @param string|null $detail Extra human detail.
	 *
	 * @return void
	 *
	 * @throws FlowLifecycleRefused Always.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	private function refuse(string $reason, string $flowId, ?string $state, ?string $detail = null): void {
		$refusal = new FlowLifecycleRefused(
			reason: $reason,
			flowId: $flowId,
			state: $state,
			detail: $detail
		);

		$this->logger->warning(
			message: '[FlowLifecycleGuard] Refused: ' . $refusal->getMessage(),
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'flow' => $flowId,
				'reason' => $reason,
				'state' => $state,
			]
		);

		throw $refusal;

	}//end refuse()
}//end class
