<?php

/**
 * Tries a flow against an object without effect.
 *
 * The flow's graph is walked by the real engine, so branches are decided
 * exactly as in a run, with a {@see PreviewStepDispatcher} in place of the
 * dispatcher that performs steps. The marking lives on an unsaved run record,
 * no run row is queued, and the object is read with the caller's own access
 * and without a read audit entry: nothing is stored and nothing is sent.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Walks a flow against an object and reports what each step would do.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class FlowDryRunService {
	/**
	 * Constructor.
	 *
	 * @param FlowService      $flows    Finds the flow within the caller's organisation.
	 * @param FlowLocator      $locator  Builds the flow document the engine walks.
	 * @param ObjectService    $objects  Reads the object with the caller's access.
	 * @param FlowEngine       $engine   The engine runs walk.
	 * @param FlowNodeRegistry $registry Resolves each step's node.
	 */
	public function __construct(
		private readonly FlowService $flows,
		private readonly FlowLocator $locator,
		private readonly ObjectService $objects,
		private readonly FlowEngine $engine,
		private readonly FlowNodeRegistry $registry,
	) {
	}//end __construct()

	/**
	 * The steps a flow would take for an object, and what each would do.
	 *
	 * @param string $flowId   The flow uuid.
	 * @param string $register The object's register.
	 * @param string $schema   The object's schema.
	 * @param string $objectId The object's uuid.
	 *
	 * @return array{status: string, error?: string, steps: array<int, array<string, mixed>>}
	 *
	 * @throws DoesNotExistException When the flow or the object is out of the caller's reach.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	public function dryRun(string $flowId, string $register, string $schema, string $objectId): array {
		// Throws for a flow outside the caller's organisation.
		$this->flows->find(uuid: $flowId);

		$document = $this->locator->resolveFlow(flowId: $flowId);
		if ($document === null) {
			throw new DoesNotExistException('No such flow');
		}

		// A lookup, not a person opening the object: no audit-trail row, so the
		// object does not land in anyone's recent list.
		$object = $this->objects->find(
			id: $objectId,
			_extend: [],
			files: false,
			register: $register,
			schema: $schema,
			_rbac: true,
			_multitenancy: true,
			_render: true,
			_audit: false
		);
		if ($object instanceof ObjectEntity === false) {
			throw new DoesNotExistException('No such object');
		}

		$dispatcher = new PreviewStepDispatcher(registry: $this->registry);
		$result = $this->engine->run(
			flow: $document,
			store: new FlowRunMarkingStore(run: new FlowRun()),
			subject: $object,
			dispatcher: $dispatcher,
			context: ['dryRun' => true]
		);

		$labels = [];
		foreach (($document['nodes'] ?? []) as $node) {
			if (is_array($node) === true) {
				$labels[(string)($node['id'] ?? '')] = (string)($node['label'] ?? '');
			}
		}

		$previews = $dispatcher->previews();
		$steps = [];
		foreach (($result['log'] ?? []) as $entry) {
			$nodeId = (string)($entry['transition'] ?? '');
			$steps[] = [
				'nodeId' => $nodeId,
				'type' => (string)($entry['type'] ?? ''),
				'label' => ($labels[$nodeId] ?? ''),
				'status' => (string)($entry['status'] ?? ''),
				'itemsIn' => (int)($entry['itemsIn'] ?? 0),
				'itemsOut' => (int)($entry['itemsOut'] ?? 0),
				'preview' => ($previews[$nodeId] ?? null),
			];
		}

		$answer = ['status' => (string)($result['status'] ?? ''), 'steps' => $steps];
		if (isset($result['error']) === true) {
			$answer['error'] = (string)$result['error'];
		}

		return $answer;
	}//end dryRun()
}//end class
