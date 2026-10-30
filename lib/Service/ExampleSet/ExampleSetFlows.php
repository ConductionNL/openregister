<?php

/**
 * OpenRegister Example Set Flows
 *
 * The flows an example set ships: a fragment's `flows` part declares a flow
 * of the shipping app (its steps, lines and their places on the canvas), so
 * the flow pages show a realistic process instead of an empty canvas. Written
 * switched off and published, as an app's own shipped flow is, and removed
 * with the set. A flow may carry finished `runs` on a subject object, so a
 * case's run history reads as it would after real work.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-set-flows/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
 * @spec openspec/changes/example-set-flow-runs/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the flows of an example set.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Check, write, prove and delete for one flow type sit together.
 *
 * @spec openspec/changes/example-set-flows/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
 */
class ExampleSetFlows extends ExampleSetEngineRows {
	/**
	 * The flow fields a fragment may set.
	 */
	public const FIELDS = [
		'name',
		'description',
		'trigger',
		'triggerRegister',
		'triggerSchema',
		'cron',
		'executionMode',
		'nodes',
		'edges',
		'annotations',
		'limits',
		'publish',
		'runs',
	];

	/**
	 * The run fields a fragment may set.
	 */
	public const RUN_FIELDS = ['status', 'subject', 'subjectRegister', 'subjectSchema', 'trigger', 'triggeredBy', 'error', 'created', 'updated'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the flow services on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part flows live in.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-flows/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
	 */
	public function part(): string {
		return 'flows';
	}//end part()

	/**
	 * The word for one flow, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-flows/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
	 */
	public function noun(): string {
		return 'flow';
	}//end noun()

	/**
	 * Why a filled flow cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts (unused: a flow is the app's).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-flows/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a flow field a set may write';
		}

		if (is_string($data['name'] ?? null) === false || trim($data['name']) === '') {
			return 'a flow needs a name';
		}

		$graph = $this->graphRefusal(nodes: ($data['nodes'] ?? null), edges: (array) ($data['edges'] ?? []));
		if ($graph !== '') {
			return $graph;
		}

		if (isset($data['publish']) === true && is_bool($data['publish']) === false) {
			return 'publish is true or false';
		}

		return $this->runsRefusal(runs: ($data['runs'] ?? []));
	}//end refusal()

	/**
	 * Why the declared runs cannot be written, or ''.
	 *
	 * Only a finished run may be declared: a queued or waiting run would be
	 * picked up by the flow worker and executed.
	 *
	 * @param mixed $runs The declared runs.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-flow-runs/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
	 */
	private function runsRefusal(mixed $runs): string {
		if (is_array($runs) === false || array_is_list($runs) === false) {
			return 'runs is a list';
		}

		foreach ($runs as $run) {
			$refusal = $this->runRefusal(run: (array) $run);
			if ($refusal !== '') {
				return $refusal;
			}
		}

		return '';
	}//end runsRefusal()

	/**
	 * Why one declared run cannot be written, or ''.
	 *
	 * @param array<string, mixed> $run The declared run.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-flow-runs/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
	 */
	private function runRefusal(array $run): string {
		$unknown = array_diff(array_keys($run), self::RUN_FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a run field a set may write';
		}

		if (in_array(($run['status'] ?? null), FlowRun::TERMINAL, true) === false) {
			return 'a run is finished: status is one of '.implode(', ', FlowRun::TERMINAL);
		}

		if (is_string($run['subject'] ?? null) === false || trim($run['subject']) === '') {
			return 'a run needs the object it ran on (subject)';
		}

		foreach (['created', 'updated'] as $field) {
			try {
				new DateTime((string) ($run[$field] ?? 'now'));
			} catch (Throwable) {
				return 'run '.$field.' "'.(string) $run[$field].'" is not a date';
			}
		}

		return '';
	}//end runRefusal()

	/**
	 * Why the steps and lines cannot make a flow, or ''.
	 *
	 * @param mixed                          $nodes The declared steps.
	 * @param array<int|string, mixed>       $edges The declared lines.
	 *
	 * @return string
	 */
	private function graphRefusal(mixed $nodes, array $edges): string {
		if (is_array($nodes) === false || $nodes === []) {
			return 'a flow needs its steps (nodes)';
		}

		$ids = [];
		foreach ($nodes as $node) {
			$node = (array) $node;
			if (is_string($node['id'] ?? null) === false || is_string($node['type'] ?? null) === false) {
				return 'every step needs an id and a type';
			}

			$ids[] = $node['id'];
		}

		foreach ($edges as $edge) {
			$edge = (array) $edge;
			if (in_array(($edge['from'] ?? null), $ids, true) === false || in_array(($edge['to'] ?? null), $ids, true) === false) {
				return 'every line runs from one step of the flow to another (from, to)';
			}
		}

		return '';
	}//end graphRefusal()

	/**
	 * Store the flow for the shipping app, switched off, then publish it
	 * unless the fragment says not to.
	 *
	 * @param string               $app  The app that ships the fragment; the flow is that app's.
	 * @param string               $key  The flow's key in the fragment (unused).
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return array{error: string, entry: array{uuid: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/changes/example-set-flows/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
	 */
	public function write(string $app, string $key, array $data): array {
		try {
			$flow = new Flow();
			$flow->setUuid($this->newUuid());
			$flow->setApp($app);
			$this->declare(flow: $flow, data: $data);
			$flow->setEnabled(false);
			$flow->setOwner(null);
			$flow->setOrganisation($this->container->get(OrganisationService::class)->getOrganisationForNewEntity());
			$flow->setCreated(new DateTime());
			$flow->setUpdated(new DateTime());

			$stored  = $this->container->get(FlowMapper::class)->insert($flow);
			$version = null;
			if (($data['publish'] ?? true) === true) {
				$version = $this->container->get(FlowVersionService::class)->publish(flow: $stored, publishedBy: null)->getVersion();
			}

			foreach ((array) ($data['runs'] ?? []) as $run) {
				$this->writeRun(flow: $stored, version: $version, run: (array) $run);
			}
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['uuid' => (string) $stored->getUuid()]];
	}//end write()

	/**
	 * A refresh updates a held flow where it is, so its uuid (and the pages that
	 * open it by that uuid) stays.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-flows-refresh-in-place/specs/example-sets/spec.md#requirement-a-refresh-updates-a-held-flow-to-its-declaration
	 */
	public function updatesInPlace(): bool {
		return true;
	}//end updatesInPlace()

	/**
	 * Update the flow the record holds to its declaration, and publish it again
	 * when what it declares changed; a flow that is gone is written again. The
	 * flow stays switched on or off as it is, and keeps its runs.
	 *
	 * @param array<string, mixed> $entry The record entry, with the flow's uuid.
	 * @param string               $app   The app that ships the fragment.
	 * @param string               $key   The flow's key in the fragment.
	 * @param array<string, mixed> $data  The filled fields.
	 *
	 * @return array{error: string, entry: array{uuid: string}|null} The record entry, or why the update failed.
	 *
	 * @spec openspec/changes/example-set-flows-refresh-in-place/specs/example-sets/spec.md#requirement-a-refresh-updates-a-held-flow-to-its-declaration
	 */
	public function update(array $entry, string $app, string $key, array $data): array {
		$uuid = (string) ($entry['uuid'] ?? '');
		try {
			$flow = $this->container->get(FlowMapper::class)->findByUuid($uuid);
		} catch (DoesNotExistException) {
			return $this->write(app: $app, key: $key, data: $data);
		}

		try {
			if ($this->declare(flow: $flow, data: $data) === true) {
				$flow->setUpdated(new DateTime());
				$this->container->get(FlowMapper::class)->update($flow);
				if (($data['publish'] ?? true) === true) {
					// The held flow is published already: its changed head goes out as the next version.
					$this->container->get(FlowVersionService::class)->publishShippedUpdate(flow: $flow);
				}
			}
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['uuid' => $uuid]];
	}//end update()

	/**
	 * Set the declared fields on a flow.
	 *
	 * @param Flow                 $flow The flow.
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return bool Whether a field changed.
	 */
	private function declare(Flow $flow, array $data): bool {
		$before = $this->declared(flow: $flow);
		$flow->setName((string) $data['name']);
		$flow->setDescription(($data['description'] ?? null));
		$flow->setTrigger(($data['trigger'] ?? null));
		$flow->setTriggerRegister(($data['triggerRegister'] ?? null));
		$flow->setTriggerSchema(($data['triggerSchema'] ?? null));
		$flow->setCron(($data['cron'] ?? null));
		$flow->setExecutionMode((string) ($data['executionMode'] ?? Flow::MODE_ASYNC));
		$flow->setNodes((array) $data['nodes']);
		$flow->setEdges((array) ($data['edges'] ?? []));
		$flow->setAnnotations((array) ($data['annotations'] ?? []));
		$flow->setLimits((array) ($data['limits'] ?? []));

		return $this->declared(flow: $flow) !== $before;
	}//end declare()

	/**
	 * The declarable fields of a flow, as one comparable string.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return string
	 */
	private function declared(Flow $flow): string {
		return (string) json_encode(
			value: [
				$flow->getName(),
				$flow->getDescription(),
				$flow->getTrigger(),
				$flow->getTriggerRegister(),
				$flow->getTriggerSchema(),
				$flow->getCron(),
				$flow->getExecutionMode(),
				$flow->getNodes(),
				$flow->getEdges(),
				$flow->getAnnotations(),
				$flow->getLimits(),
			]
		);
	}//end declared()

	/**
	 * Whether a written flow is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-flows/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
	 */
	public function lost(array $entry): bool {
		try {
			$this->container->get(FlowMapper::class)->findByUuid((string) $entry['uuid']);
		} catch (Throwable) {
			return true;
		}

		return false;
	}//end lost()

	/**
	 * Delete a flow the set wrote, with its versions and any runs.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/changes/example-set-flows/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
	 */
	public function revert(array $entry): string {
		try {
			$flow = $this->container->get(FlowMapper::class)->findByUuid((string) $entry['uuid']);
			$this->container->get(FlowService::class)->deleteStored(flow: $flow);
		} catch (DoesNotExistException) {
			return 'removed';
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * Store one finished run of the flow on its subject, in the flow's organisation.
	 *
	 * @param Flow                 $flow    The stored flow.
	 * @param int|null             $version The published version, or null for a draft.
	 * @param array<string, mixed> $run     The filled run fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-flow-runs/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
	 */
	private function writeRun(Flow $flow, ?int $version, array $run): void {
		$created = new DateTime((string) ($run['created'] ?? 'now'));

		$entity = new FlowRun();
		$entity->setUuid($this->newUuid());
		$entity->setFlowId((string) $flow->getUuid());
		$entity->setFlowVersion($version);
		$entity->setStatus((string) $run['status']);
		$entity->setSubjectUuid((string) $run['subject']);
		$entity->setSubjectRegister(($run['subjectRegister'] ?? null));
		$entity->setSubjectSchema(($run['subjectSchema'] ?? null));
		$entity->setTrigger((string) ($run['trigger'] ?? $flow->getTrigger() ?? 'manual'));
		$entity->setTriggeredBy(($run['triggeredBy'] ?? null));
		$entity->setError(($run['error'] ?? null));
		$entity->setOrganisation($flow->getOrganisation());
		$entity->setCreated($created);
		$entity->setUpdated(new DateTime((string) ($run['updated'] ?? $created->format('c'))));

		$this->container->get(FlowRunMapper::class)->insert($entity);
	}//end writeRun()

	/**
	 * A fresh version 4 uuid.
	 *
	 * @return string
	 */
	private function newUuid(): string {
		$data    = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end newUuid()
}//end class
