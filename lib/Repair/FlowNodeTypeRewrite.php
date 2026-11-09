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

			return;
		}

		$this->flowsWritten++;
		$this->nodesRewritten += $changed;

	}//end rewriteFlow()

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

		$renamed = FlowNodeRegistryInterface::RENAMED_TYPES;
		$pairs = implode(
			', ',
			array_map(
				static fn (string $old, string $new): string => $old . ' → ' . $new,
				array_keys($renamed),
				array_values($renamed)
			)
		);

		$this->output->info(
			sprintf(
				'OpenRegister: rewrote %d node(s) across %d flow(s) of %d to their current type ids (%s).',
				$this->nodesRewritten,
				$this->flowsWritten,
				$this->flowsSeen,
				$pairs
			)
		);

	}//end report()

	/**
	 * Rewrite the renamed node types in place.
	 *
	 * @param array $nodes The flow's nodes, rewritten in place.
	 *
	 * @return integer How many nodes were rewritten.
	 */
	private function rewriteNodes(array &$nodes): int {
		$renamed = FlowNodeRegistryInterface::RENAMED_TYPES;
		$changed = 0;

		foreach ($nodes as $index => $node) {
			if (is_array($node) === false) {
				continue;
			}

			$type = (string)($node['type'] ?? '');
			if ($type === '' || isset($renamed[$type]) === false) {
				continue;
			}

			$nodes[$index]['type'] = $renamed[$type];
			$changed++;
		}

		return $changed;

	}//end rewriteNodes()
}//end class
