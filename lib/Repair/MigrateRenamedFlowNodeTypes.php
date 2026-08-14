<?php

/**
 * MigrateRenamedFlowNodeTypes — rewrite stored node types to their current names.
 *
 * `FlowNodeRegistry` resolves two old node ids through an alias table:
 * `openregister.loop` → `openregister.batch` and `openregister.stop` →
 * `openregister.end`. Its docblock says the alias is removed one release after
 * the rename — and nothing has ever rewritten the stored definitions, so the
 * day it goes, every flow still carrying an old name stops resolving.
 *
 * Measured on the development instance before this step existed: 13 nodes
 * across 19 flows still named `openregister.stop`. They work, silently, on the
 * alias. That is the shape of a deadline nobody can see: the definitions look
 * fine, the runs succeed, and the breakage arrives with a release that touches
 * none of them.
 *
 * The map is READ FROM the registry rather than repeated here, so dropping a
 * pair there stops this step rewriting it in the same commit — the retirement
 * order is correct by construction rather than by memory.
 *
 * SAFE TO RUN REPEATEDLY. A flow with no old names is not written at all, so a
 * second run touches nothing and no flow's `updated` timestamp moves for a
 * no-op.
 *
 * NEVER THROWS. An upgrade that aborted over a node-type rewrite would be a
 * far worse outcome than a flow staying on an alias that still resolves today.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites renamed flow node types in stored definitions.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class MigrateRenamedFlowNodeTypes implements IRepairStep {

	/**
	 * How many flows to read per page.
	 *
	 * @var integer
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * Resolved lazily from the container for the same reason the other flow
	 * repair steps are: this runs during install and upgrade, when the flow
	 * table may not exist yet, and a constructor-injected mapper would make
	 * that a fatal rather than a skip.
	 *
	 * @param ContainerInterface $container The app container.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Rewrite renamed flow node types in stored flow definitions';
	}//end getName()

	/**
	 * Rewrite every stored node type that the registry only resolves by alias.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) renamedTypes() is a compiled-in
	 * catalogue of old-to-current type ids, not collaborator state. Injecting the
	 * registry here would make a repair step that runs before the container is
	 * fully warm depend on a service it does not otherwise need.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function run(IOutput $output): void {
		$renamed = FlowNodeRegistry::renamedTypes();
		if ($renamed === []) {
			$output->info('OpenRegister: no flow node types are aliased — nothing to rewrite.');
			return;
		}

		try {
			$mapper = $this->container->get(FlowMapper::class);
		} catch (Throwable $e) {
			$this->logger->info(
				'[MigrateRenamedFlowNodeTypes] flow storage unavailable, skipping: ' . $e->getMessage()
			);
			return;
		}

		$flowsSeen = 0;
		$flowsWritten = 0;
		$nodesRewritten = 0;
		$offset = 0;

		while (true) {
			try {
				$flows = $mapper->findAllFlows(limit: self::PAGE_SIZE, offset: $offset);
			} catch (Throwable $e) {
				$output->warning('OpenRegister: could not read flows to rewrite node types: ' . $e->getMessage());
				$this->logger->warning('[MigrateRenamedFlowNodeTypes] read failed: ' . $e->getMessage());
				return;
			}

			if ($flows === []) {
				break;
			}

			foreach ($flows as $flow) {
				$flowsSeen++;

				$changed = $this->rewriteFlow(
					mapper: $mapper,
					flow: $flow,
					renamed: $renamed,
					output: $output
				);

				if ($changed === 0) {
					continue;
				}

				$flowsWritten++;
				$nodesRewritten += $changed;
			}//end foreach

			if (count($flows) < self::PAGE_SIZE) {
				break;
			}

			$offset += self::PAGE_SIZE;
		}//end while

		$this->report(
			output: $output,
			flowsSeen: $flowsSeen,
			flowsWritten: $flowsWritten,
			nodesRewritten: $nodesRewritten,
			renamed: $renamed
		);

	}//end run()

	/**
	 * Rewrite one flow's renamed node types, saving only if something changed.
	 *
	 * @param FlowMapper $mapper Persists the flow.
	 * @param mixed $flow The flow entity.
	 * @param array $renamed Old type id => current type id.
	 * @param IOutput $output Migration output.
	 *
	 * @return integer How many nodes were rewritten AND saved; 0 if none, or if
	 *                 the save failed.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function rewriteFlow(FlowMapper $mapper, mixed $flow, array $renamed, IOutput $output): int {
		$nodes = ($flow->getNodes() ?? []);
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

		// Only write a flow that actually carried an old name. A blanket save
		// would move every flow's `updated` timestamp for a no-op, which is the
		// kind of churn that makes a later "what changed and when" impossible to
		// answer.
		if ($changed === 0) {
			return 0;
		}

		$flow->setNodes($nodes);

		try {
			$mapper->update($flow);
		} catch (Throwable $e) {
			$output->warning(
				sprintf(
					'OpenRegister: could not rewrite node types on flow "%s": %s',
					(string)$flow->getUuid(),
					$e->getMessage()
				)
			);

			return 0;
		}

		return $changed;
	}//end rewriteFlow()

	/**
	 * Say what the pass did, naming the renames it applied.
	 *
	 * @param IOutput $output Migration output.
	 * @param integer $flowsSeen Flows inspected.
	 * @param integer $flowsWritten Flows saved.
	 * @param integer $nodesRewritten Nodes rewritten.
	 * @param array $renamed Old type id => current type id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function report(
		IOutput $output,
		int $flowsSeen,
		int $flowsWritten,
		int $nodesRewritten,
		array $renamed,
	): void {
		if ($nodesRewritten === 0) {
			$output->info(
				sprintf('OpenRegister: %d flow(s) checked, no renamed node types stored.', $flowsSeen)
			);

			return;
		}

		$pairs = implode(
			', ',
			array_map(
				static fn (string $old, string $new): string => $old . ' → ' . $new,
				array_keys($renamed),
				array_values($renamed)
			)
		);

		$output->info(
			sprintf(
				'OpenRegister: rewrote %d node(s) across %d flow(s) of %d to their current type ids (%s).',
				$nodesRewritten,
				$flowsWritten,
				$flowsSeen,
				$pairs
			)
		);

	}//end report()
}//end class
