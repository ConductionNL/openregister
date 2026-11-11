<?php

/**
 * Tries a flow against an object, or an example payload, without effect.
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
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Walks a flow against an object and reports what each step would do.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class FlowDryRunService implements FlowDryRunServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param FlowDryRunLookups   $lookups Finds the flow, builds its document and reads the object.
	 * @param FlowRunWalkServices $walk    The engine runs walk and the registry resolving each step's node.
	 */
	public function __construct(
		private readonly FlowDryRunLookups $lookups,
		private readonly FlowRunWalkServices $walk,
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
		return $this->dryRunObject(flowId: $flowId, ref: new ObjectRef(register: $register, schema: $schema, id: $objectId));
	}//end dryRun()

	/**
	 * The steps a flow would take for the referenced object, and what each would do.
	 *
	 * @param string    $flowId The flow uuid.
	 * @param ObjectRef $ref    The object's register, schema and uuid.
	 *
	 * @return array{status: string, error?: string, steps: array<int, array<string, mixed>>}
	 *
	 * @throws DoesNotExistException When the flow or the object is out of the caller's reach.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	public function dryRunObject(string $flowId, ObjectRef $ref): array {
		$document = $this->flowDocument(flowId: $flowId);

		// A lookup, not a person opening the object: no audit-trail row, so the
		// object does not land in anyone's recent list.
		$object = $this->lookups->objects->find(
			id: $ref->id,
			_extend: [],
			files: false,
			register: $ref->register,
			schema: $ref->schema,
			_rbac: true,
			_multitenancy: true,
			_render: true,
			_audit: false
		);
		if ($object instanceof ObjectEntity === false) {
			throw new DoesNotExistException('No such object');
		}

		return $this->walk(document: $document, object: $object);
	}//end dryRunObject()

	/**
	 * The steps a flow would take for an example payload, and what each would do.
	 *
	 * The payload stands in for the object: it is the data of an object that
	 * does not exist, so nothing is read and there is nothing to authorise
	 * beyond the flow itself.
	 *
	 * @param string              $flowId  The flow uuid.
	 * @param array<string,mixed> $payload The example object data.
	 *
	 * @return array{status: string, error?: string, steps: array<int, array<string, mixed>>}
	 *
	 * @throws DoesNotExistException When the flow is out of the caller's reach.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	public function dryRunPayload(string $flowId, array $payload): array {
		$object = new ObjectEntity();
		$object->setObject($payload);

		return $this->walk(document: $this->flowDocument(flowId: $flowId), object: $object);
	}//end dryRunPayload()

	/**
	 * The flow document the engine walks.
	 *
	 * @param string $flowId The flow uuid.
	 *
	 * @return array<string, mixed> The document.
	 *
	 * @throws DoesNotExistException When the flow is out of the caller's reach.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	private function flowDocument(string $flowId): array {
		// Throws for a flow outside the caller's organisation.
		$this->lookups->flows->find(uuid: $flowId);

		$document = $this->lookups->locator->resolveFlow(flowId: $flowId);
		if ($document === null) {
			throw new DoesNotExistException('No such flow');
		}

		return $document;
	}//end flowDocument()

	/**
	 * Walk the flow with the object and report each step.
	 *
	 * @param array<string, mixed> $document The flow document.
	 * @param ObjectEntity         $object   The object, stored or an example.
	 *
	 * @return array{status: string, error?: string, steps: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	private function walk(array $document, ObjectEntity $object): array {
		$dispatcher = new PreviewStepDispatcher(registry: $this->walk->registry);
		$start = new FlowRunStart(flow: $document, store: new FlowRunMarkingStore(run: new FlowRun()), subject: $object, dispatcher: $dispatcher);
		$result = $this->walk->engine->run(start: $start->withContext(context: ['dryRun' => true]));

		$answer = [
			'status' => (string)($result['status'] ?? ''),
			'steps' => $this->steps(log: ($result['log'] ?? []), nodes: $this->nodeReports(document: $document, previews: $dispatcher->previews())),
		];
		if (isset($result['error']) === true) {
			$answer['error'] = (string)$result['error'];
		}

		return $answer;
	}//end walk()

	/**
	 * The name of each node and what it would do, by id.
	 *
	 * @param array<string, mixed>                $document The flow document.
	 * @param array<string, array<string, mixed>> $previews What each step would do, by id.
	 *
	 * @return array<string, array{label: string, preview: array<string, mixed>|null}> The reports.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	private function nodeReports(array $document, array $previews): array {
		$reports = [];
		foreach (($document['nodes'] ?? []) as $node) {
			if (is_array($node) === true) {
				$id = (string)($node['id'] ?? '');
				$reports[$id] = ['label' => (string)($node['label'] ?? ''), 'preview' => ($previews[$id] ?? null)];
			}
		}

		return $reports;
	}//end nodeReports()

	/**
	 * The engine's log as the steps of the answer.
	 *
	 * @param array<int, array<string, mixed>>   $log   The engine's log.
	 * @param array<string, array<string, mixed>> $nodes The node reports, by id.
	 *
	 * @return array<int, array<string, mixed>> The steps.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	private function steps(array $log, array $nodes): array {
		$steps = [];
		foreach ($log as $entry) {
			$nodeId = (string)($entry['transition'] ?? '');
			$steps[] = [
				'nodeId' => $nodeId,
				'type' => (string)($entry['type'] ?? ''),
				'label' => ($nodes[$nodeId]['label'] ?? ''),
				'status' => (string)($entry['status'] ?? ''),
				'error' => ($entry['error'] ?? null),
				'itemsIn' => (int)($entry['itemsIn'] ?? 0),
				'itemsOut' => (int)($entry['itemsOut'] ?? 0),
				'preview' => ($nodes[$nodeId]['preview'] ?? null),
			];
		}

		return $steps;
	}//end steps()
}//end class
