<?php

/**
 * The action nodes a flow document declares, read and VALIDATED.
 *
 * Every node needs a unique id and a type, and a node that conditions its
 * exits needs an else. Each refusal names the node, because each one guards
 * a failure that would otherwise be silent: a merged duplicate, a step that
 * does nothing, or a token with nowhere to go.
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
 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use InvalidArgumentException;

/**
 * Validates the `nodes` of a flow document for {@see FlowDefinitionBuilder}.
 */
final class FlowNodeDeclarations {

	/**
	 * Collect the declared action nodes, keyed by id.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return array<string, array> Node id => node, in declaration order.
	 *
	 * @throws InvalidArgumentException When a node has no usable id, or ids collide.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function extract(array $flow): array {
		$nodes = [];
		foreach (($flow['nodes'] ?? []) as $index => $node) {
			$id = self::nodeId(node: $node, index: $index);

			// A duplicate id would silently merge two nodes into one transition,
			// so the flow would run but not be the flow the user drew.
			if (array_key_exists($id, $nodes) === true) {
				throw new InvalidArgumentException(message: sprintf('Flow declares node id "%s" more than once.', $id));
			}

			self::assertTyped(node: $node, id: $id);
			$this->assertExitsCanAlwaysBeTaken(node: $node, id: $id);

			$nodes[$id] = $node;
		}

		return $nodes;
	}//end extract()

	/**
	 * The id of a declared node.
	 *
	 * @param mixed      $node  The declared node.
	 * @param int|string $index Its position, for the message.
	 *
	 * @return string The trimmed, non-empty id.
	 *
	 * @throws InvalidArgumentException When the node is not an object or has no id.
	 */
	private static function nodeId(mixed $node, int|string $index): string {
		if (is_array($node) === false) {
			throw new InvalidArgumentException(message: sprintf('Flow node at index %d is not an object.', $index));
		}

		$id = trim((string)($node['id'] ?? ''));
		if ($id === '') {
			throw new InvalidArgumentException(message: sprintf('Flow node at index %d has no id.', $index));
		}

		return $id;
	}//end nodeId()

	/**
	 * A node IS the step. One with no type resolves to nothing, runs, and
	 * reports COMPLETED having done nothing — the exact silent success this
	 * engine refuses to produce.
	 *
	 * @param array  $node The node.
	 * @param string $id   Its id, for the message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the node declares no type.
	 */
	private static function assertTyped(array $node, string $id): void {
		if (trim((string)($node['type'] ?? '')) === '') {
			throw new InvalidArgumentException(
				message: sprintf(
					'Flow node "%s" declares no "type". A node is the action that runs, so a node without a '
					. 'type is a step that does nothing while reporting success.',
					$id
				)
			);
		}
	}//end assertTyped()

	/**
	 * A branching node must always have somewhere to put its token.
	 *
	 * A token is unique and exclusive: a node with several exits takes exactly
	 * one per firing. If every exit is conditioned and none of them holds, the
	 * token has nowhere to go — and that does not raise anything. The run just
	 * stops, reporting no failure, which is indistinguishable from a flow that
	 * finished its work.
	 *
	 * So a node that conditions ANY exit must also declare an else: an exit with
	 * no condition. One is enough; a second would never be reachable, since the
	 * first unconditioned exit always matches.
	 *
	 * Refused at build time rather than warned about, for the same reason the
	 * engine refuses a step with no type — the failure it prevents is silent,
	 * and a silent stop in a scheduled flow is found weeks later by noticing
	 * work that never happened.
	 *
	 * No declared exits at all means the node does not branch, so every output
	 * place is taken and nothing can be stranded. Deliberately NOT gated on
	 * having two or more exits: a single conditioned exit strands the token
	 * just as completely when its condition does not hold — there is simply no
	 * sibling to notice.
	 *
	 * @param array $node The node.
	 * @param string $id Its id, for the message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a branching node has no else.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function assertExitsCanAlwaysBeTaken(array $node, string $id): void {
		$exits = ($node['exits'] ?? []);
		if (is_array($exits) === false || $exits === [] || self::hasElse(exits: $exits) === true) {
			return;
		}

		throw new InvalidArgumentException(
			message: sprintf(
				'Flow node "%s" conditions all %d of its exits and declares no else. A token is exclusive and '
				. 'must always leave through exactly one exit, so if no condition holds the token has nowhere to '
				. 'go — and the run stops without reporting anything, which is indistinguishable from a flow that '
				. 'finished. Add an exit with no condition.',
				$id,
				count($exits)
			)
		);
	}//end assertExitsCanAlwaysBeTaken()

	/**
	 * Whether any exit is unconditioned: the else. Entries that are not objects are skipped.
	 *
	 * @param array $exits The declared exits.
	 *
	 * @return boolean True when an else exists.
	 */
	private static function hasElse(array $exits): bool {
		foreach (array_filter($exits, 'is_array') as $exit) {
			$condition = ($exit['condition'] ?? null);
			if (is_array($condition) === false || $condition === []) {
				return true;
			}
		}

		return false;
	}//end hasElse()
}//end class
