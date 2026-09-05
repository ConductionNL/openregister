<?php

/**
 * Keeps the indexed trigger set derived from a flow's trigger nodes.
 *
 * The nodes are what an author edits; the index is what an event matches
 * against. This is the only thing that writes the index, and it writes it from
 * one source — `FlowTriggerDerivation`. A second writer would mean a
 * subscription that no node explains.
 *
 * WHY A FLOW CAN BE ABSENT FROM THE INDEX
 * ---------------------------------------
 * A flow contributes rows only for the triggers it can express as NODES. Two
 * kinds of flow contribute none:
 *
 *   - one that has not been converted yet (every flow, before the backfill)
 *   - one whose subscription has NO node form at all — an "any register, any
 *     schema" object trigger, which `TriggerObjectNode` deliberately refuses
 *
 * Both keep firing through the old columns, because `FlowLocator` falls back
 * for flows absent from the index. That fallback is what makes this cutover
 * safe to ship: no flow changes which events it fires on. It is also the
 * deprecation surface — a flow still on the fallback is a flow someone has to
 * re-author.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the derived trigger index.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
 */
class FlowTriggerIndex {
	/**
	 * Constructor.
	 *
	 * @param FlowTriggerMapper $mapper The indexed trigger set.
	 * @param FlowTriggerDerivation $derivation Nodes to triggers.
	 * @param FlowTriggerSlugs $slugs Normalises a derived triple to the slugs matching runs on.
	 * @param LoggerInterface $logger Diagnostics.
	 * @param FlowPublishedGraph|null $published Resolves the published graph.
	 */
	public function __construct(
		private readonly FlowTriggerMapper $mapper,
		private readonly FlowTriggerDerivation $derivation,
		private readonly FlowTriggerSlugs $slugs,
		private readonly LoggerInterface $logger,
		private readonly ?FlowPublishedGraph $published = null,
	) {

	}//end __construct()

	/**
	 * Rebuild one flow's rows from its nodes.
	 *
	 * Never raises: this runs on the flow SAVE path, and a failure to index
	 * must not stop an author saving their work. It is logged as a warning and
	 * the flow keeps whatever rows it had — including none, which puts it on
	 * the column fallback rather than silently unsubscribing it.
	 *
	 * @param Flow $flow The flow to index.
	 *
	 * @return int The number of trigger rows written.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
	 */
	public function reindex(Flow $flow): int {
		$uuid = trim((string)$flow->getUuid());
		if ($uuid === '') {
			return 0;
		}

		try {
			// 🔴 THE ROWS COME FROM THE PUBLISHED VERSION, NEVER THE HEAD. While
			// a draft is open the head holds nodes that must NOT be subscribed,
			// and omits the published ones that must stay subscribed — deriving
			// from it is both rules backwards. Putting the resolution here
			// rather than at each caller is what stops the two from drifting:
			// the flow save path, the publish transaction and the upgrade
			// back-fill all reach the index through this one method.
			$triggers = $this->derivation->objectTriggersOf(flow: $this->publishedFace(flow: $flow));

			// A flow that derives NO object triggers must not be left with
			// stale rows from a previous save — deleting the flow's last
			// trigger node has to actually unsubscribe it.
			return $this->mapper->replaceFor(
				flowUuid: $uuid,
				triggers: $this->canonical(triggers: $triggers),
				enabled: ($flow->getEnabled() === true)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowTriggerIndex] Could not index flow "' . $uuid . '": ' . $e->getMessage()
					. '. The flow keeps its previous trigger rows and, if it has none, its columns still decide.',
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $uuid]
			);

			return 0;
		}//end try

	}//end reindex()

	/**
	 * The derived triggers, each register and schema resolved to its SLUG.
	 *
	 * A trigger node's config holds whatever its authoring surface put there —
	 * an imported declaration writes slugs, the builder may write the numeric
	 * id its select handed it. The MATCHING surface speaks slugs (the column
	 * is `schema_slug`, and the fired subject is resolved to slugs the same
	 * way), so the rows are normalised HERE, at the only writer, rather than
	 * asking every matcher to try both vocabularies forever. Two nodes that
	 * name the same triple through different identifiers collapse to one row.
	 *
	 * @param array<int, array{event: string, register: string, schema: string}> $triggers The derived triggers.
	 *
	 * @return array<int, array{event: string, register: string, schema: string}> The slug-keyed triggers.
	 *
	 * @spec openspec/changes/flow-trigger-canonical-slugs/specs/flow-engine/spec.md
	 */
	private function canonical(array $triggers): array {
		$rows = [];
		foreach ($triggers as $trigger) {
			$row = [
				'event' => (string)$trigger['event'],
				'register' => $this->slugs->registerSlug(identifier: (string)$trigger['register']),
				'schema' => $this->slugs->schemaSlug(identifier: (string)$trigger['schema']),
			];

			$rows[$row['event'] . '|' . $row['register'] . '|' . $row['schema']] = $row;
		}

		return array_values($rows);
	}//end canonical()

	/**
	 * The flow as its PUBLISHED version sees it.
	 *
	 * Returns a detached carrier holding the published graph, or a carrier
	 * with NO nodes when the flow has no published version — which makes
	 * `objectTriggersOf()` derive nothing and `replaceFor()` clear the flow's
	 * rows. That is the correct reading of "a draft must not back a run": an
	 * unpublished flow is subscribed to nothing at all.
	 *
	 * `enabled` always comes from the LIVE flow, because switching a flow off
	 * must take effect at once and has nothing to do with which version is
	 * published.
	 *
	 * @param Flow $flow The stored flow.
	 *
	 * @return Flow The carrier to derive triggers from.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	private function publishedFace(Flow $flow): Flow {
		$carrier = new Flow();
		$carrier->setUuid((string)$flow->getUuid());
		$carrier->setEnabled($flow->getEnabled());
		$carrier->setNodes([]);
		$carrier->setEdges([]);

		if ($this->published === null) {
			return $carrier;
		}

		$graph = $this->published->graphOf(flowId: (string)$flow->getUuid());
		if ($graph === null) {
			return $carrier;
		}

		$carrier->setNodes((array)($graph['nodes'] ?? []));
		$carrier->setEdges((array)($graph['edges'] ?? []));

		return $carrier;

	}//end publishedFace()

	/**
	 * Drop a deleted flow's rows.
	 *
	 * @param string $flowUuid The flow's UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
	 */
	public function forget(string $flowUuid): void {
		try {
			$this->mapper->deleteFor(flowUuid: $flowUuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowTriggerIndex] Could not drop trigger rows for "' . $flowUuid . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowUuid]
			);
		}

	}//end forget()

	/**
	 * Rebuild the index for many flows, reporting what could not be converted.
	 *
	 * The report is the point. A backfill that silently converted what it could
	 * would leave an operator believing the cutover was complete while flows
	 * quietly remained on the old path — so every flow that keeps a column
	 * trigger with no node form is named.
	 *
	 * @param array<int, Flow> $flows The flows to index.
	 *
	 * @return array{indexed: int, rows: int, unconverted: array<int, array{uuid: string, reason: string, columns: array|null}>}
	 *                                                                                                                           What happened.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-the-cutover-from-trigger-columns-to-trigger-nodes-must-be-proven-per-flow
	 */
	public function rebuild(array $flows): array {
		$indexed = 0;
		$rows = 0;
		$unconverted = [];

		foreach ($flows as $flow) {
			$written = $this->reindex(flow: $flow);
			$rows += $written;

			if ($written > 0) {
				$indexed++;
				continue;
			}

			// No rows. Only a flow whose COLUMNS still name a trigger is a
			// problem: one with no trigger at all is simply unfinished, and
			// reporting it would bury the flows that matter.
			$columns = $this->derivation->columnTriggerOf(flow: $flow);
			if ($columns === null) {
				continue;
			}

			// Why a flow is unconverted matters, because the two reasons need
			// different actions. Only an OBJECT trigger is matched by register
			// and schema at all: a `manual` or `schedule` column carries empty
			// ones because they are meaningless for it, not because it is
			// unscoped. Reporting those as "no node can express this" would
			// name 15 flows as blocked when 11 of them convert by simply being
			// re-authored with a manual or schedule trigger node.
			$isObjectTrigger = str_starts_with($columns['event'], 'object.');

			$reason = 'not yet re-authored on the canvas: add a trigger node for "' . $columns['event'] . '"';
			if ($isObjectTrigger === true
				&& ($columns['register'] === '*' || $columns['schema'] === '*')
			) {
				$reason = 'BLOCKED: an object trigger scoped to any register/schema, which a trigger node '
					. 'deliberately cannot express — it needs one trigger node per register/schema pair';
			}

			$unconverted[] = [
				'uuid' => (string)$flow->getUuid(),
				'reason' => $reason,
				'columns' => $columns,
			];
		}//end foreach

		return [
			'indexed' => $indexed,
			'rows' => $rows,
			'unconverted' => $unconverted,
		];

	}//end rebuild()
}//end class
