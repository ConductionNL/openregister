<?php

/**
 * One node's view of {@see FlowResumeState}.
 *
 * A node asks "how far did I get?" and answers "this far" without ever naming
 * itself: the dispatcher builds this scoped to the node it is about to call, so
 * a node cannot read or overwrite another's progress even by accident. That
 * matters most for the case a flat bag would break silently — two
 * synchronisation nodes in one flow, each parked on its own page.
 *
 * Writes go straight through to the parent rather than being buffered here.
 * A buffered copy would need flushing, and a node that suspends does so by
 * THROWING — there is no return path on which to flush, so anything unflushed
 * would be lost at exactly the moment it was needed.
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

/**
 * A read/write handle on one node's resume slot.
 */
final class FlowNodeResumeState {

	/**
	 * The context key the scoped handle is reachable at.
	 *
	 * Distinct from {@see FlowResumeState::CONTEXT_KEY}: that one holds every
	 * node's slot and is what gets persisted, this one is the single-node view
	 * a node actually uses.
	 *
	 * @var string
	 */
	public const CONTEXT_KEY = 'resume';

	/**
	 * Constructor.
	 *
	 * @param FlowResumeState $parent The state holding every node's slot.
	 * @param string $nodeId The node this view is scoped to.
	 */
	public function __construct(
		private readonly FlowResumeState $parent,
		private readonly string $nodeId,
	) {

	}//end __construct()

	/**
	 * Whether this node has progress stored from an earlier pass.
	 *
	 * The question a resumed node should ask INSTEAD of `$context['resuming']`.
	 * That flag says the RUN resumed, which is true for every node in the graph
	 * once anything has suspended; this says THIS node has somewhere to
	 * continue from, which is the thing worth branching on.
	 *
	 * @return boolean True when a slot is held.
	 */
	public function isResuming(): bool {
		return ($this->parent->read(nodeId: $this->nodeId) !== []);
	}//end isResuming()

	/**
	 * Read a value.
	 *
	 * @param string $key The value's key.
	 * @param mixed $default Returned when the key is not held.
	 *
	 * @return mixed The held value, or the default.
	 */
	public function get(string $key, mixed $default = null): mixed {
		$values = $this->parent->read(nodeId: $this->nodeId);

		return ($values[$key] ?? $default);
	}//end get()

	/**
	 * Whether a key is held.
	 *
	 * @param string $key The value's key.
	 *
	 * @return boolean Whether the key is held.
	 */
	public function has(string $key): bool {
		return array_key_exists($key, $this->parent->read(nodeId: $this->nodeId));
	}//end has()

	/**
	 * Write a value.
	 *
	 * @param string $key The value's key.
	 * @param mixed $value The value to hold. Must survive a JSON round trip —
	 *                     the slot is persisted into the run's context column,
	 *                     so an object handed in here comes back as an array.
	 *
	 * @return void
	 */
	public function set(string $key, mixed $value): void {
		$values = $this->parent->read(nodeId: $this->nodeId);
		$values[$key] = $value;
		$this->parent->write(nodeId: $this->nodeId, values: $values);

	}//end set()

	/**
	 * Write several values at once.
	 *
	 * @param array<string, mixed> $values The values to merge in.
	 *
	 * @return void
	 */
	public function merge(array $values): void {
		$this->parent->write(
			nodeId: $this->nodeId,
			values: array_merge($this->parent->read(nodeId: $this->nodeId), $values)
		);

	}//end merge()

	/**
	 * Everything this node holds.
	 *
	 * @return array<string, mixed> The stored values.
	 */
	public function all(): array {
		return $this->parent->read(nodeId: $this->nodeId);
	}//end all()

	/**
	 * Drop this node's progress.
	 *
	 * A node rarely needs to call this: the dispatcher clears the slot whenever
	 * a node returns normally, so finishing is enough. It is here for a node
	 * that abandons its stored position while still intending to suspend —
	 * restarting a crawl whose cursor the source has invalidated, say.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->parent->forget(nodeId: $this->nodeId);

	}//end clear()
}//end class
