<?php

/**
 * OpenRegister FlowRuleDescriptors
 *
 * Reads the flows a schema's objects trigger into inventory descriptors.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Db\FlowTriggerMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCA\OpenRegister\Service\Flow\FlowTriggerDerivation;

/**
 * One descriptor per flow, read from the derived trigger index and the published graph.
 *
 * One descriptor per flow, not per trigger row: a flow subscribed to both
 * create and update is one rule an administrator switches on or off, and
 * the events it listens for belong on that one entry.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class FlowRuleDescriptors {

	/**
	 * Reads a trigger node's scope.
	 *
	 * @var RuleScope
	 */
	private readonly RuleScope $scopes;

	/**
	 * Constructor.
	 *
	 * @param FlowTriggerMapperInterface $triggers  The derived flow trigger index.
	 * @param FlowPublishedGraph|null    $published Reads a flow's published graph, where its trigger node's scope lives.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FlowTriggerMapperInterface $triggers,
		private readonly ?FlowPublishedGraph $published,
	) {
		$this->scopes = new RuleScope();
	}//end __construct()

	/**
	 * The flows this schema's objects trigger.
	 *
	 * @param string $slug The schema's slug.
	 *
	 * @return array<int, RuleDescriptor> The descriptors.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function describe(string $slug): array {
		$descriptors = [];
		foreach ($this->byFlow(slug: $slug) as $flow) {
			$descriptors[] = $this->descriptor(flow: $flow);
		}

		return $descriptors;
	}//end describe()

	/**
	 * The trigger rows of a schema, grouped per flow.
	 *
	 * @param string $slug The schema's slug.
	 *
	 * @return array<string, array{uuid: string, schema: string, events: array<int, string>, enabled: bool}> The flows, in first-seen order.
	 */
	private function byFlow(string $slug): array {
		$byFlow = [];
		foreach ($this->triggers->findBySchema(schemaSlug: $slug) as $row) {
			$uuid = $row['flow_uuid'];
			$flow = ($byFlow[$uuid] ?? ['uuid' => (string)$uuid, 'schema' => $slug, 'events' => [], 'enabled' => false]);
			$flow['events'][] = $row['event'];
			// A flow is on when ANY of its trigger rows is: the row carries the
			// flow's own enabled flag, denormalised, and a flow with one
			// disabled row and one enabled row still runs.
			$flow['enabled'] = ($flow['enabled'] === true || $row['enabled'] === true);
			$byFlow[$uuid] = $flow;
		}

		return $byFlow;
	}//end byFlow()

	/**
	 * One flow as a rule.
	 *
	 * @param array{uuid: string, schema: string, events: array<int, string>, enabled: bool} $flow The flow.
	 *
	 * @return RuleDescriptor The descriptor.
	 */
	private function descriptor(array $flow): RuleDescriptor {
		$events = array_values(array_unique($flow['events']));
		sort($events);

		return new RuleDescriptor(
			kind: RuleVocabulary::KIND_FLOW,
			schemaSlug: $flow['schema'],
			key: $flow['uuid'],
			label: $flow['uuid'],
			source: ('openregister_flow_triggers.' . $flow['uuid']),
			enabled: $flow['enabled'],
			actions: [RuleVocabulary::ACTION_RUN_FLOW],
			condition: ['events' => $events],
			scope: $this->scopeOf(flowUuid: $flow['uuid'], schemaSlug: $flow['schema'])
		);
	}//end descriptor()

	/**
	 * The scope of a flow, read from its object trigger node for this schema.
	 *
	 * The published graph is the one that runs, so it is the one asked.
	 *
	 * @param string $flowUuid   The flow's uuid.
	 * @param string $schemaSlug The schema the inventory lists.
	 *
	 * @return array{property: string, value: string}|null The scope, or null when the trigger node declares none.
	 */
	private function scopeOf(string $flowUuid, string $schemaSlug): ?array {
		$graph = $this->published?->graphOf(flowId: $flowUuid);
		if (is_array($graph) === false) {
			return null;
		}

		foreach ((array)($graph['nodes'] ?? []) as $node) {
			$scope = $this->nodeScope(node: $node, schemaSlug: $schemaSlug);
			if ($scope !== null) {
				return $scope;
			}
		}

		return null;
	}//end scopeOf()

	/**
	 * The scope one node declares for this schema, when it is an object trigger node.
	 *
	 * A node that names no schema applies to every schema the flow triggers on.
	 *
	 * @param mixed  $node       The graph node.
	 * @param string $schemaSlug The schema the inventory lists.
	 *
	 * @return array{property: string, value: string}|null The scope, or null.
	 */
	private function nodeScope(mixed $node, string $schemaSlug): ?array {
		if (is_array($node) === false || ($node['type'] ?? null) !== FlowTriggerDerivation::OBJECT_TRIGGER) {
			return null;
		}

		$config = (array)($node['config'] ?? []);
		$schema = (string)($config['schema'] ?? '');
		if ($schema !== $schemaSlug && $schema !== '') {
			return null;
		}

		return $this->scopes->fromDeclaration(declaration: $config);
	}//end nodeScope()
}//end class
