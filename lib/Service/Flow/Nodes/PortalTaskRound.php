<?php

/**
 * PortalTaskRound
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
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

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * One ask of a portal-task step: the items and configuration it was asked
 * with, the node, the cycle, the matched party and, on a re-ask, the
 * previous task and the reason. Each wither returns a changed copy.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-re-ask-creates-a-new-task-carrying-a-mandatory-reason
 */
final class PortalTaskRound {

	/**
	 * The node raising the task.
	 *
	 * @var string
	 */
	private string $nodeId = '';

	/**
	 * This ask's cycle number, 1 for the first.
	 *
	 * @var integer
	 */
	private int $cycle = 1;

	/**
	 * The frozen party reference (`party:<ref>`), once matched.
	 *
	 * @var string
	 */
	private string $partyReference = '';

	/**
	 * The previous cycle's task, on a re-ask.
	 *
	 * @var string|null
	 */
	private ?string $previousTaskUuid = null;

	/**
	 * The re-ask reason, on a re-ask.
	 *
	 * @var string|null
	 */
	private ?string $reason = null;

	/**
	 * Constructor.
	 *
	 * @param array                $items  The input items; the first is the representative.
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function __construct(
		public readonly array $items,
		public readonly array $config,
	) {

	}//end __construct()

	/**
	 * A copy raised by the given node.
	 *
	 * @param string $nodeId The node.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function atNode(string $nodeId): self {
		$copy         = clone $this;
		$copy->nodeId = $nodeId;
		return $copy;
	}//end atNode()

	/**
	 * A copy that re-asks, in the given cycle, after the given task ended.
	 *
	 * @param int    $cycle            This ask's cycle number.
	 * @param string $previousTaskUuid The previous cycle's task.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-re-ask-creates-a-new-task-carrying-a-mandatory-reason
	 */
	public function inCycle(int $cycle, string $previousTaskUuid): self {
		$copy                   = clone $this;
		$copy->cycle            = $cycle;
		$copy->previousTaskUuid = $previousTaskUuid;
		return $copy;
	}//end inCycle()

	/**
	 * A copy carrying the re-ask reason.
	 *
	 * @param string $reason The reason.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-re-ask-creates-a-new-task-carrying-a-mandatory-reason
	 */
	public function because(string $reason): self {
		$copy         = clone $this;
		$copy->reason = $reason;
		return $copy;
	}//end because()

	/**
	 * A copy assigned to the matched party.
	 *
	 * @param string $partyReference The frozen party reference.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	public function withParty(string $partyReference): self {
		$copy                 = clone $this;
		$copy->partyReference = $partyReference;
		return $copy;
	}//end withParty()

	/**
	 * The node raising the task.
	 *
	 * @return string The node id.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function nodeId(): string {
		return $this->nodeId;
	}//end nodeId()

	/**
	 * This ask's cycle number.
	 *
	 * @return int The cycle.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-re-ask-creates-a-new-task-carrying-a-mandatory-reason
	 */
	public function cycle(): int {
		return $this->cycle;
	}//end cycle()

	/**
	 * The matched party.
	 *
	 * @return string The party reference.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	public function partyReference(): string {
		return $this->partyReference;
	}//end partyReference()

	/**
	 * The previous cycle's task.
	 *
	 * @return string|null The uuid, null on a first ask.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-re-ask-creates-a-new-task-carrying-a-mandatory-reason
	 */
	public function previousTaskUuid(): ?string {
		return $this->previousTaskUuid;
	}//end previousTaskUuid()

	/**
	 * The re-ask reason.
	 *
	 * @return string|null The reason, null on a first ask.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-re-ask-creates-a-new-task-carrying-a-mandatory-reason
	 */
	public function reason(): ?string {
		return $this->reason;
	}//end reason()
}//end class
