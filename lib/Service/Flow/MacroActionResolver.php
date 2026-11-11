<?php

/**
 * Which flow a schema binds to a declared action, and where it leaves you.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Reads what a macro run needs from its flows and schemas: the stored flow a
 * schema declares by name, the changes a finished run made and the `next`
 * hint it ends with.
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */
class MacroActionResolver {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemas   Loads a schema by id or slug.
	 * @param FlowService           $flows     Reads a flow, for its nodes and `next` hint.
	 * @param FlowMapperInterface|null       $flowStore Finds a flow a schema declares by name.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly FlowService $flows,
		private readonly ?FlowMapperInterface $flowStore = null,
	) {
	}//end __construct()

	/**
	 * What a finished macro run changed, for its audit entry.
	 *
	 * @param FlowRun $run      The run.
	 * @param string  $flowUuid The flow it ran.
	 *
	 * @return array<int, array{step: string, kind: string}> The changes.
	 *
	 * @spec openspec/specs/declared-actions/spec.md#requirement-a-macro-runs-audit-entry-lists-the-changes-it-made
	 */
	public function changesOf(FlowRun $run, string $flowUuid): array {
		return (new MacroRunChanges())->fromLog(log: ($run->getLog() ?? []), nodes: $this->nodesOf(flowUuid: $flowUuid));
	}//end changesOf()

	/**
	 * The nodes of a flow, or none when it cannot be read.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return array<int, mixed> The nodes.
	 *
	 * @spec openspec/specs/declared-actions/spec.md#requirement-a-macro-runs-audit-entry-lists-the-changes-it-made
	 */
	public function nodesOf(string $flowUuid): array {
		try {
			return ($this->flows->find(uuid: $flowUuid)->getNodes() ?? []);
		} catch (\Throwable) {
			return [];
		}
	}//end nodesOf()

	/**
	 * The uuid of the stored flow a schema's `x-openregister-flows` declaration
	 * of this name became, or null when none is stored.
	 *
	 * @param Schema $schema The declaring schema.
	 * @param string $name   The declared flow's name.
	 *
	 * @return string|null The uuid.
	 *
	 * @spec openspec/specs/declared-actions/spec.md#requirement-a-macro-can-name-a-flow-its-schema-declares
	 */
	public function declaredFlowUuid(Schema $schema, string $name): ?string {
		if ($this->flowStore === null) {
			return null;
		}

		$flow = $this->flowStore->findDeclared(name: $name, schemaSlug: (string)$schema->getSlug());
		if ($flow === null || (string)$flow->getUuid() === '') {
			return null;
		}

		return (string)$flow->getUuid();
	}//end declaredFlowUuid()

	/**
	 * The EFFECTIVE `next` hint of a finished run.
	 *
	 * The end node the run reached wins when it declares a hint; an end node
	 * that declares nothing is silence, and the manual trigger's hint stands.
	 * The reached end node is the last end-node step in the run's log.
	 *
	 * @param string  $flowUuid The flow.
	 * @param FlowRun $run      The run.
	 *
	 * @return string One of FlowNextHint::HINTS.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/flow-engine/spec.md#requirement-a-manual-trigger-declares-where-the-person-goes-next
	 */
	public function nextForRun(string $flowUuid, FlowRun $run): string {
		try {
			$nodes = ($this->flows->find(uuid: $flowUuid)->getNodes() ?? []);
		} catch (\Throwable) {
			return FlowNextHint::STAY;
		}

		return FlowNextHint::effective(nodes: $nodes, endNode: $this->endNodeReached(nodes: $nodes, run: $run));
	}//end nextForRun()

	/**
	 * The end node the run reached: the node named by the last end-node step
	 * in its log, or null when there is none.
	 *
	 * @param array<int, mixed> $nodes The flow's nodes.
	 * @param FlowRun           $run   The run.
	 *
	 * @return array<string, mixed>|null The end node.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/flow-engine/spec.md#requirement-a-manual-trigger-declares-where-the-person-goes-next
	 */
	private function endNodeReached(array $nodes, FlowRun $run): ?array {
		$reached = $this->reachedEndId(run: $run);
		if ($reached === null) {
			return null;
		}

		return self::nodeWithId(nodes: $nodes, id: $reached);
	}//end endNodeReached()

	/**
	 * The first node with this id.
	 *
	 * @param array<int, mixed> $nodes The flow's nodes.
	 * @param string            $id    The id.
	 *
	 * @return array<string, mixed>|null The node, or null when none has the id.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/flow-engine/spec.md#requirement-a-manual-trigger-declares-where-the-person-goes-next
	 */
	private static function nodeWithId(array $nodes, string $id): ?array {
		foreach ($nodes as $node) {
			if (is_array($node) === true && (string)($node['id'] ?? '') === $id) {
				return $node;
			}
		}

		return null;
	}//end nodeWithId()

	/**
	 * The id of the end node in the last end-node step of the run's log.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return string|null The id, or null when the run reached no end node.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/flow-engine/spec.md#requirement-a-manual-trigger-declares-where-the-person-goes-next
	 */
	private function reachedEndId(FlowRun $run): ?string {
		$reached = null;
		foreach (($run->getLog() ?? []) as $entry) {
			if (is_array($entry) === true && (string)($entry['type'] ?? '') === FlowNextHint::END_NODE) {
				$reached = (string)($entry['transition'] ?? '');
			}
		}

		return $reached;
	}//end reachedEndId()

	/**
	 * Load a schema by id or slug.
	 *
	 * @param string $schema The schema identifier.
	 *
	 * @return Schema|null The schema.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	public function loadSchema(string $schema): ?Schema {
		try {
			return $this->schemas->find($schema, _multitenancy: false, _rbac: false);
		} catch (\Throwable) {
			return null;
		}
	}//end loadSchema()
}//end class
