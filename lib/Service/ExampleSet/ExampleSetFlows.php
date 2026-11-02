<?php

/**
 * OpenRegister Example Set Flows
 *
 * The flows an example set ships: a fragment's `flows` part declares a flow
 * of the shipping app (its steps, lines and their places on the canvas), so
 * the flow pages show a realistic process instead of an empty canvas. Written
 * switched off and published, as an app's own shipped flow is, and removed
 * with the set. A flow may carry finished `runs` on a subject object, so a
 * case's run history reads as it would after real work, and runs that wait
 * on a task of the same fragment (ExampleSetFlowRuns).
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-flow-may-carry-a-run-that-waits-on-a-task
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the flows of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One check, write, proof and delete step per flow part.
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
	public const RUN_FIELDS = ExampleSetFlowRuns::FIELDS;

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
	 */
	public function part(): string {
		return 'flows';
	}//end part()

	/**
	 * The word for one flow, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
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

		return $this->runs()->refusal(runs: ($data['runs'] ?? []), nodes: $data['nodes']);
	}//end refusal()

	/**
	 * The fragment's refusals, and a waiting run that no task of the fragment names: its slot would stay empty.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The fill context.
	 * @param array<int, string>   $users    The account ids the set creates or adopts.
	 * @param ExampleSetValues     $values   Fills the placeholders.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-flow-may-carry-a-run-that-waits-on-a-task
	 */
	public function checkFragment(array $fragment, array $context, array $users, ExampleSetValues $values): array {
		return array_merge(
			parent::checkFragment(fragment: $fragment, context: $context, users: $users, values: $values),
			$this->runs()->unnamedRefusals(fragment: $fragment)
		);
	}//end checkFragment()

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
	 * @return array{error: string, entry: array{uuid: string, runs: array<string, string>, waitsOn: array<string, string>}|null}
	 *         The record entry (the flow's uuid, and its keyed runs), or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
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

			$entry = ['uuid' => (string) $stored->getUuid()] + $this->runs()->write(
				flow: $stored,
				version: $version,
				runs: array_map(static fn (mixed $run): array => (array) $run, (array) ($data['runs'] ?? [])),
				newUuid: fn (): string => $this->newUuid()
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => $entry];
	}//end write()

	/**
	 * A refresh updates a held flow where it is, so its uuid (and the pages that
	 * open it by that uuid) stays.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-updates-a-held-flow-to-its-declaration
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
	 * @return array{error: string, entry: array<string, mixed>|null} The record entry, keeping the flow's runs,
	 *                                                                 or why the update failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-updates-a-held-flow-to-its-declaration
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

		return ['error' => '', 'entry' => (['uuid' => $uuid] + array_intersect_key($entry, ['runs' => true, 'waitsOn' => true]))];
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
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
	 * The runs a flow carries.
	 *
	 * @return ExampleSetFlowRuns
	 */
	private function runs(): ExampleSetFlowRuns {
		return new ExampleSetFlowRuns(container: $this->container);
	}//end runs()

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
