<?php

/**
 * One pass of the renamed node type rewrite over the stored flows.
 *
 * Holds, for the one run that builds it, where the rewrite is saved and
 * reported and what it has counted so far, so the per-flow step and the
 * summary do not pass the same four values between them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCP\Migration\IOutput;
use Throwable;

/**
 * Rewrites one flow at a time and reports the totals at the end.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class FlowNodeTypeRewrite {

	/**
	 * Flows inspected.
	 *
	 * @var integer
	 */
	private int $flowsSeen = 0;

	/**
	 * Flows saved.
	 *
	 * @var integer
	 */
	private int $flowsWritten = 0;

	/**
	 * Nodes rewritten and saved.
	 *
	 * @var integer
	 */
	private int $nodesRewritten = 0;

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface $mapper Persists the flow.
	 * @param IOutput             $output Migration output.
	 */
	public function __construct(
		private readonly FlowMapperInterface $mapper,
		private readonly IOutput $output,
	) {

	}//end __construct()

	/**
	 * Rewrite one flow's renamed node types, saving only if something changed.
	 *
	 * A failed save is warned about and not counted.
	 *
	 * @param mixed $flow The flow entity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function rewriteFlow(mixed $flow): void {
		$this->flowsSeen++;

		$nodes = ($flow->getNodes() ?? []);
		$changed = $this->rewriteNodes(nodes: $nodes);

		// Only write a flow that actually carried an old name. A blanket save
		// would move every flow's `updated` timestamp for a no-op, which is the
		// kind of churn that makes a later "what changed and when" impossible to
		// answer.
		if ($changed === 0) {
			return;
		}

		$flow->setNodes($nodes);

		if ($this->save(flow: $flow) === false) {
			return;
		}

		$this->flowsWritten++;
		$this->nodesRewritten += $changed;

	}//end rewriteFlow()

	/**
	 * Save a rewritten flow, or warn that it could not be saved.
	 *
	 * @param mixed $flow The flow entity, its nodes rewritten.
	 *
	 * @return bool True when the flow was saved.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function save(mixed $flow): bool {
		try {
			$this->mapper->update($flow);
		} catch (Throwable $e) {
			$this->output->warning(
				sprintf(
					'OpenRegister: could not rewrite node types on flow "%s": %s',
					(string)$flow->getUuid(),
					$e->getMessage()
				)
			);

			return false;
		}

		return true;

	}//end save()

	/**
	 * Say what the pass did, naming the renames it applied.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function report(): void {
		if ($this->nodesRewritten === 0) {
			$this->output->info(
				sprintf('OpenRegister: %d flow(s) checked, no renamed node types stored.', $this->flowsSeen)
			);

			return;
		}

		$this->output->info($this->rewroteLine());

	}//end report()

	/**
	 * The report line of a pass that rewrote nodes.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function rewroteLine(): string {
		return sprintf(
			'OpenRegister: rewrote %d node(s) across %d flow(s) of %d to their current type ids (%s).',
			$this->nodesRewritten,
			$this->flowsWritten,
			$this->flowsSeen,
			self::renamedPairs()
		);

	}//end rewroteLine()

	/**
	 * The renames this pass applies, as `old → new` pairs.
	 *
	 * @return string The pairs, comma-separated.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private static function renamedPairs(): string {
		$renamed = FlowNodeRegistryInterface::RENAMED_TYPES;

		return implode(
			', ',
			array_map(
				static fn (string $old, string $new): string => $old . ' → ' . $new,
				array_keys($renamed),
				array_values($renamed)
			)
		);

	}//end renamedPairs()

	/**
	 * Rewrite the renamed node types in place.
	 *
	 * @param array $nodes The flow's nodes, rewritten in place.
	 *
	 * @return integer How many nodes were rewritten.
	 */
	private function rewriteNodes(array &$nodes): int {
		$changed = 0;

		foreach ($nodes as $index => $node) {
			$currentType = self::currentType(node: $node);
			if ($currentType === null) {
				continue;
			}

			$nodes[$index]['type'] = $currentType;
			$changed++;
		}

		return $changed;

	}//end rewriteNodes()

	/**
	 * The current type id of a node stored under a renamed one.
	 *
	 * @param mixed $node One stored node.
	 *
	 * @return string|null The current type id, or null when the node is malformed or not renamed.
	 */
	private static function currentType(mixed $node): ?string {
		if (is_array($node) === false) {
			return null;
		}

		$renamed = FlowNodeRegistryInterface::RENAMED_TYPES;
		$type = (string)($node['type'] ?? '');
		if ($type === '' || isset($renamed[$type]) === false) {
			return null;
		}

		return $renamed[$type];

	}//end currentType()
}//end class
