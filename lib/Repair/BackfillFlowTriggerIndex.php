<?php

/**
 * BackfillFlowTriggerIndex — derive every flow's trigger rows from its nodes.
 *
 * The engine matches object events against a derived index rather than against
 * the flow rows' four trigger columns, because a flow may carry SEVERAL trigger
 * nodes and four columns hold exactly one trigger between them. This step
 * populates that index for flows that already exist.
 *
 * IT NEVER CHANGES WHICH EVENTS A FLOW FIRES ON. A flow that derives no trigger
 * rows keeps matching through its columns — `FlowLocator` falls back for
 * exactly the flows this step could not represent. So the worst case is that a
 * flow stays on the old path, never that it stops firing.
 *
 * Two kinds of flow cannot be converted, and BOTH are reported by name rather
 * than passed over:
 *
 *   - one whose columns name a trigger that no node declares — it has not been
 *     re-authored on the canvas yet
 *   - one scoped to ANY register and ANY schema, which `TriggerObjectNode`
 *     deliberately refuses to express, since an object trigger names exactly
 *     one event, register and schema
 *
 * Reporting matters more than converting here: a backfill that silently did
 * what it could would leave an operator believing the cutover was complete.
 *
 * RE-RUNNING THIS STEP IS ALSO THE ROW-VOCABULARY REPAIR. The index used to
 * store whatever a trigger node's config held — a numeric register/schema id
 * from the builder, a slug from an imported declaration — while the fired
 * subject arrived as ids, so only rows that happened to hold ids ever matched.
 * `FlowTriggerIndex` now writes SLUGS canonically and the listener fires
 * slugs, so this step's rebuild (post-migration, every upgrade) rewrites every
 * existing row into the slug vocabulary: flows imported before the fix start
 * firing without anyone re-saving them, and a row hand-inserted with ids is
 * replaced by its reproducible slug form.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-the-cutover-from-trigger-columns-to-trigger-nodes-must-be-proven-per-flow
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Populates the derived flow trigger index.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-the-cutover-from-trigger-columns-to-trigger-nodes-must-be-proven-per-flow
 */
class BackfillFlowTriggerIndex implements IRepairStep {
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
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-the-cutover-from-trigger-columns-to-trigger-nodes-must-be-proven-per-flow
	 */
	public function getName(): string {
		return 'Derive the flow trigger index from each flow\'s trigger nodes';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * Never throws. An upgrade that aborted because a trigger could not be
	 * derived would be a far worse outcome than a flow remaining on the column
	 * fallback, which still fires.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-the-cutover-from-trigger-columns-to-trigger-nodes-must-be-proven-per-flow
	 */
	public function run(IOutput $output): void {
		try {
			$mapper = $this->container->get(FlowMapper::class);
			$index = $this->container->get(FlowTriggerIndex::class);
			// 🔴 PAGED. `findAllFlows()` defaults to a limit of 100, so this
			// derived only the first page's trigger rows and reported success —
			// every flow past 100 was left unsubscribed, which on the trigger
			// path is silent: the flow simply never fires.
			$flows = [];
			$page = 500;
			$offset = 0;
			while (true) {
				$batch = $mapper->findAllFlows(limit: $page, offset: $offset);
				$flows = array_merge($flows, $batch);
				if (count($batch) < $page) {
					break;
				}

				$offset += $page;
			}
		} catch (Throwable $e) {
			$output->info('Flow trigger index backfill skipped: ' . $e->getMessage());
			return;
		}

		if ($flows === []) {
			$output->info('Flow trigger index: no flows to derive.');
			return;
		}

		try {
			$report = $index->rebuild(flows: $flows);
		} catch (Throwable $e) {
			$output->warning('Flow trigger index backfill failed: ' . $e->getMessage());
			$this->logger->warning(
				message: '[BackfillFlowTriggerIndex] ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		$output->info(
			sprintf(
				'Flow trigger index: %d of %d flows derived %d trigger rows from their nodes.',
				$report['indexed'],
				count($flows),
				$report['rows']
			)
		);

		if ($report['unconverted'] === []) {
			return;
		}

		$output->info(
			sprintf(
				'%d flow(s) still match through their trigger COLUMNS and need re-authoring on the canvas:',
				count($report['unconverted'])
			)
		);

		foreach ($report['unconverted'] as $entry) {
			$line = sprintf(
				'  %s — %s (event=%s register=%s schema=%s)',
				$entry['uuid'],
				$entry['reason'],
				(string)($entry['columns']['event'] ?? '?'),
				(string)($entry['columns']['register'] ?? '?'),
				(string)($entry['columns']['schema'] ?? '?')
			);

			$output->info($line);
			$this->logger->warning(
				message: '[BackfillFlowTriggerIndex]' . $line,
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $entry['uuid']]
			);
		}

	}//end run()
}//end class
