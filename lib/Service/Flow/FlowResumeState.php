<?php

/**
 * Where a node was when the run stopped.
 *
 * `FlowToken` carries values that belong to the RUN and `FlowStateHandle`
 * values that belong to the FLOW. Neither answers the question a node that
 * suspends mid-work has to answer when it wakes up: *how far did I get?*
 *
 * Until now the only thing a resumed node learned was `$context['resuming']`,
 * a boolean. That is enough for a Wait — the wait is over by construction, so
 * there is nothing to remember. It is not enough for anything that suspends
 * while it still has work left: a synchronisation parked on a rate limit needs
 * its page and its shard, an approval step needs which request it raised. Left
 * without somewhere to put that, such a node restarts from the beginning, and a
 * resume that restarts is not a resume — it is a retry wearing its name.
 *
 * The state is keyed BY NODE, and a node never sees another node's slot: the
 * dispatcher hands each node a {@see FlowNodeResumeState} scoped to it. That is
 * not tidiness. A flow may hold two synchronisation nodes, and one flat bag
 * would have them silently overwrite each other's page numbers — the kind of
 * defect that only appears on the second node, under load, long after the
 * mechanism was declared working.
 *
 * Scoping is a VIEW rather than a mode set on this object, because dispatch
 * nests: a Loop node dispatches its body through a dispatcher of its own while
 * the outer node is still on the stack. A single mutable "current node" would
 * be corrupted by exactly that, and would be corrupted quietly.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-must-be-able-to-resume-from-where-it-stopped
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use JsonSerializable;

/**
 * Per-node progress, persisted across a suspension.
 */
final class FlowResumeState implements JsonSerializable {

	/**
	 * The context key the state is reachable at.
	 *
	 * @var string
	 */
	public const CONTEXT_KEY = 'resumeState';

	/**
	 * Progress slots, keyed by node id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $byNode = [];

	/**
	 * Build the state over stored slots.
	 *
	 * @param array<string, array<string, mixed>> $byNode The stored slots.
	 */
	public function __construct(array $byNode = []) {
		$this->byNode = $byNode;

	}//end __construct()

	/**
	 * A view of one node's slot.
	 *
	 * @param string $nodeId The node's id in the flow document.
	 *
	 * @return FlowNodeResumeState The scoped handle handed to that node.
	 */
	public function forNode(string $nodeId): FlowNodeResumeState {
		return new FlowNodeResumeState(parent: $this, nodeId: $nodeId);
	}//end forNode()

	/**
	 * Read a node's slot.
	 *
	 * @param string $nodeId The node's id.
	 *
	 * @return array<string, mixed> The stored values, empty when it has none.
	 */
	public function read(string $nodeId): array {
		return ($this->byNode[$nodeId] ?? []);
	}//end read()

	/**
	 * Replace a node's slot.
	 *
	 * @param string $nodeId The node's id.
	 * @param array<string, mixed> $values The values to hold.
	 *
	 * @return void
	 */
	public function write(string $nodeId, array $values): void {
		if ($values === []) {
			$this->forget(nodeId: $nodeId);
			return;
		}

		$this->byNode[$nodeId] = $values;

	}//end write()

	/**
	 * Drop a node's slot.
	 *
	 * Called by the dispatcher when a node returns normally. Progress is only
	 * ever meaningful BETWEEN a suspension and the resume that follows it, so a
	 * node that finished has nothing left to remember — and leaving the slot
	 * behind would hand stale progress to the next pass through the same node,
	 * which is precisely what happens inside a loop.
	 *
	 * @param string $nodeId The node's id.
	 *
	 * @return void
	 */
	public function forget(string $nodeId): void {
		unset($this->byNode[$nodeId]);

	}//end forget()

	/**
	 * Whether any node holds progress.
	 *
	 * @return boolean True when at least one slot is occupied.
	 */
	public function isEmpty(): bool {
		return ($this->byNode === []);
	}//end isEmpty()

	/**
	 * Every slot.
	 *
	 * @return array<string, array<string, mixed>> The slots, keyed by node id.
	 */
	public function all(): array {
		return $this->byNode;
	}//end all()

	/**
	 * Build the state from whatever a stored context happens to hold.
	 *
	 * Total, for the same reason {@see FlowToken::fromArray()} is: a run
	 * persisted before this existed holds nothing, a corrupted column holds a
	 * scalar, and a run handed straight back holds an object already. A run
	 * must not fail over any of those.
	 *
	 * @param mixed $stored The stored value, of any shape.
	 *
	 * @return self The state.
	 */
	public static function fromArray(mixed $stored): self {
		if ($stored instanceof self === true) {
			return $stored;
		}

		if (is_array($stored) === false) {
			return new self();
		}

		$byNode = [];
		foreach ($stored as $nodeId => $values) {
			// A JSON round trip turns a list into integer keys, which are not
			// node ids; and a slot that is not a value bag cannot be one.
			if (is_string($nodeId) === false || is_array($values) === false) {
				continue;
			}

			$byNode[$nodeId] = $values;
		}

		return new self($byNode);
	}//end fromArray()

	/**
	 * The storable form, or null when there is nothing worth storing.
	 *
	 * Only a SUSPENDED run has anywhere to continue from. A terminal one does
	 * not, so keeping its slots would put a stale cursor in front of anyone
	 * reading the run to find out what happened — and the dispatcher has already
	 * cleared every node that returned, so anything still held belongs to a node
	 * the run never came back to.
	 *
	 * Lives here rather than in the run service because it is a question about
	 * this value, not about persistence: the state knows when it is worth
	 * keeping.
	 *
	 * @param boolean $suspended Whether the walk ended suspended.
	 *
	 * @return array<string, array<string, mixed>>|null The slots, or null to drop them.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-must-be-able-to-resume-from-where-it-stopped
	 */
	public function storableWhen(bool $suspended): ?array {
		if ($suspended === false || $this->byNode === []) {
			return null;
		}

		return $this->byNode;
	}//end storableWhen()

	/**
	 * The storable form.
	 *
	 * @return array<string, array<string, mixed>> The slots.
	 */
	public function jsonSerialize(): array {
		return $this->byNode;
	}//end jsonSerialize()
}//end class
