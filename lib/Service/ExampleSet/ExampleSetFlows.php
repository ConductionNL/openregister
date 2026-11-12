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
use OCA\OpenRegister\Service\OrganisationServiceInterface;
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
		'applicationSlug',
		'enabled',
	];

	/**
	 * What `{{flow:self}}` and `{{flow:self.uuid}}` fill to inside a flow, until the flow's uuid replaces it.
	 */
	private const OWN_UUID = '{{flow:self.uuid}}';

	/**
	 * The getters of a flow's declarable fields, in the order declared() compares them.
	 */
	private const DECLARED = [
		'getName',
		'getDescription',
		'getTrigger',
		'getTriggerRegister',
		'getTriggerSchema',
		'getCron',
		'getExecutionMode',
		'getNodes',
		'getEdges',
		'getAnnotations',
		'getLimits',
		'getApplicationSlug',
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
	 * A flow's own declaration may quote its own uuid (`{{flow:self.uuid}}`), which only exists once it is written.
	 *
	 * @param array<string, mixed> $context The context placeholders draw on.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragments-flow-may-quote-its-own-uuid
	 */
	protected function fillContext(array $context): array {
		$context['flows'] = (['self' => ['uuid' => self::OWN_UUID]] + (array) ($context['flows'] ?? []));

		return $context;
	}//end fillContext()

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
		$refusal = $this->fieldsRefusal(data: $data);
		if ($refusal !== '') {
			return $refusal;
		}

		$refusal = $this->graphRefusal(data: $data);
		if ($refusal !== '') {
			return $refusal;
		}

		return $this->settingsRefusal(data: $data);
	}//end refusal()

	/**
	 * A field a set may not write, or a flow without a name.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return string
	 */
	private function fieldsRefusal(array $data): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a flow field a set may write';
		}

		if (is_string($data['name'] ?? null) === false || trim($data['name']) === '') {
			return 'a flow needs a name';
		}

		return '';
	}//end fieldsRefusal()

	/**
	 * Why the switches or the runs of a flow cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data The filled fields; its steps are known to be there.
	 *
	 * @return string
	 */
	private function settingsRefusal(array $data): string {
		$refusal = $this->booleanRefusal(data: $data);
		if ($refusal !== '') {
			return $refusal;
		}

		$refusal = $this->switchesRefusal(data: $data);
		if ($refusal !== '') {
			return $refusal;
		}

		return $this->runs()->refusal(runs: ($data['runs'] ?? []), nodes: $data['nodes']);
	}//end settingsRefusal()

	/**
	 * A publish or enabled that is not true or false.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragments-flow-may-name-its-application-and-be-switched-on
	 */
	private function booleanRefusal(array $data): string {
		foreach (['publish', 'enabled'] as $field) {
			if (isset($data[$field]) === true && is_bool($data[$field]) === false) {
				return $field.' is true or false';
			}
		}

		return '';
	}//end booleanRefusal()

	/**
	 * An applicationSlug of the wrong type, or a flow switched on that is not published.
	 *
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragments-flow-may-name-its-application-and-be-switched-on
	 */
	private function switchesRefusal(array $data): string {
		if (isset($data['applicationSlug']) === true && is_string($data['applicationSlug']) === false) {
			return 'applicationSlug is text';
		}

		if (($data['enabled'] ?? false) === true && ($data['publish'] ?? true) === false) {
			return 'a flow that is switched on is published: it runs its published version';
		}

		return '';
	}//end switchesRefusal()

	/**
	 * Why the steps and lines cannot make a flow, or ''.
	 *
	 * @param array<string, mixed> $data The filled fields: the steps (`nodes`) and lines (`edges`).
	 *
	 * @return string
	 */
	private function graphRefusal(array $data): string {
		$ids = $this->stepIds(nodes: ($data['nodes'] ?? null));
		if (is_string($ids) === true) {
			return $ids;
		}

		foreach ((array) ($data['edges'] ?? []) as $edge) {
			if ($this->isStepLine(edge: (array) $edge, ids: $ids) === false) {
				return 'every line runs from one step of the flow to another (from, to)';
			}
		}

		return '';
	}//end graphRefusal()

	/**
	 * The ids of the declared steps, or why they cannot make a flow.
	 *
	 * @param mixed $nodes The declared steps.
	 *
	 * @return array<int, string>|string
	 */
	private function stepIds(mixed $nodes): array|string {
		if (is_array($nodes) === false || $nodes === []) {
			return 'a flow needs its steps (nodes)';
		}

		$steps = array_map(static fn (mixed $node): array => (array) $node, $nodes);
		foreach ($steps as $step) {
			if ($this->isStep(node: $step) === false) {
				return 'every step needs an id and a type';
			}
		}

		return array_column($steps, 'id');
	}//end stepIds()

	/**
	 * Whether a declared step has an id and a type.
	 *
	 * @param array<string, mixed> $node The declared step.
	 *
	 * @return bool
	 */
	private function isStep(array $node): bool {
		return is_string($node['id'] ?? null) === true && is_string($node['type'] ?? null) === true;
	}//end isStep()

	/**
	 * Whether a line runs from one step of the flow to another.
	 *
	 * @param array<string, mixed> $edge The declared line.
	 * @param array<int, string>   $ids  The ids of the steps.
	 *
	 * @return bool
	 */
	private function isStepLine(array $edge, array $ids): bool {
		return in_array(($edge['from'] ?? null), $ids, true) === true && in_array(($edge['to'] ?? null), $ids, true) === true;
	}//end isStepLine()


	/**
	 * The fragment's refusals, and a waiting run that no task of the fragment names: its slot would stay empty.
	 *
	 * @param array<string, mixed>    $fragment The fragment.
	 * @param ExampleSetFragmentCheck $check    The fill context, the account ids the set creates or adopts, and the filler.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-flow-may-carry-a-run-that-waits-on-a-task
	 */
	public function checkFragment(array $fragment, ExampleSetFragmentCheck $check): array {
		return array_merge(
			parent::checkFragment(fragment: $fragment, check: $check),
			$this->runs()->unnamedRefusals(fragment: $fragment)
		);
	}//end checkFragment()


	/**
	 * Store the flow for the shipping app, switched off, then publish it
	 * unless the fragment says not to, and switch it on when it says so.
	 *
	 * @param ExampleSetRow $row The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{error: string, entry: array{uuid: string, runs?: array<string, string>, waitsOn?: array<string, string>}|null}
	 *         The record entry (the flow's uuid, and its keyed runs), or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-flows
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragments-flow-may-name-its-application-and-be-switched-on
	 */
	public function write(ExampleSetRow $row): array {
		$app = $row->app;
		$data = $row->data;
		try {
			$flow = new Flow();
			$flow->setUuid($this->newUuid());
			$flow->setApp($app);
			$this->declare(flow: $flow, data: $data);
			$flow->setEnabled(false);
			$flow->setOwner(null);
			$flow->setOrganisation($this->container->get(OrganisationServiceInterface::class)->getOrganisationForNewEntity());
			$flow->setCreated(new DateTime());
			$flow->setUpdated(new DateTime());

			$stored  = $this->container->get(FlowMapper::class)->insert($flow);
			$version = null;
			if (($data['publish'] ?? true) === true) {
				$version = $this->container->get(FlowVersionService::class)->publish(flow: $stored)->getVersion();
			}

			if (($data['enabled'] ?? false) === true) {
				// Switched on only once published, so it runs the frozen graph.
				$stored->setEnabled(true);
				$stored = $this->container->get(FlowMapper::class)->update($stored);
			}

			$entry = ['uuid' => (string) $stored->getUuid()] + $this->runs()->write(
				write: new ExampleSetRunsWrite(
					flow: $stored,
					version: $version,
					runs: array_map(static fn (mixed $run): array => (array) $run, (array) ($data['runs'] ?? [])),
					newUuid: fn (): string => $this->newUuid()
				)
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
	 * @param ExampleSetRow        $row   The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null} The record entry, keeping the flow's runs,
	 *                                                                 or why the update failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-updates-a-held-flow-to-its-declaration
	 */
	public function update(array $entry, ExampleSetRow $row): array {
		$data = $row->data;
		$uuid = (string) ($entry['uuid'] ?? '');
		$flow = $this->held(uuid: $uuid);
		if ($flow === null) {
			return $this->write(row: $row);
		}

		try {
			$this->redeclare(flow: $flow, data: $data);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => (['uuid' => $uuid] + array_intersect_key($entry, ['runs' => true, 'waitsOn' => true]))];
	}//end update()

	/**
	 * The held flow, or null when it does not exist; any other failure is thrown.
	 *
	 * @param string $uuid The flow's uuid.
	 *
	 * @return Flow|null
	 */
	private function held(string $uuid): ?Flow {
		try {
			return $this->container->get(FlowMapper::class)->findByUuid($uuid);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end held()

	/**
	 * Set the declared fields on a held flow and, when one changed, store it and publish it again unless the
	 * fragment says not to.
	 *
	 * @param Flow                 $flow The held flow.
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return void
	 */
	private function redeclare(Flow $flow, array $data): void {
		if ($this->declare(flow: $flow, data: $data) === false) {
			return;
		}

		$flow->setUpdated(new DateTime());
		$this->container->get(FlowMapper::class)->update($flow);
		if (($data['publish'] ?? true) === true) {
			// The held flow is published already: its changed head goes out as the next version.
			$this->container->get(FlowVersionService::class)->publishShippedUpdate(flow: $flow);
		}
	}//end redeclare()

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
		$data   = $this->withOwnUuid(value: $data, uuid: (string) $flow->getUuid());
		$this->declareTrigger(flow: $flow, data: $data);
		$this->declareGraph(flow: $flow, data: $data);

		return $this->declared(flow: $flow) !== $before;
	}//end declare()

	/**
	 * Set the declared name, description, trigger and execution mode on a flow.
	 *
	 * @param Flow                 $flow The flow.
	 * @param array<string, mixed> $data The filled fields, the flow's own uuid in place.
	 *
	 * @return void
	 */
	private function declareTrigger(Flow $flow, array $data): void {
		$flow->setName((string) $data['name']);
		$flow->setDescription(($data['description'] ?? null));
		$flow->setTrigger(($data['trigger'] ?? null));
		$flow->setTriggerRegister(($data['triggerRegister'] ?? null));
		$flow->setTriggerSchema(($data['triggerSchema'] ?? null));
		$flow->setCron(($data['cron'] ?? null));
		$flow->setExecutionMode((string) ($data['executionMode'] ?? Flow::MODE_ASYNC));
	}//end declareTrigger()

	/**
	 * Set the declared steps, lines, annotations, limits and application on a flow.
	 *
	 * @param Flow                 $flow The flow.
	 * @param array<string, mixed> $data The filled fields, the flow's own uuid in place.
	 *
	 * @return void
	 */
	private function declareGraph(Flow $flow, array $data): void {
		$flow->setNodes((array) $data['nodes']);
		$flow->setEdges((array) ($data['edges'] ?? []));
		$flow->setAnnotations((array) ($data['annotations'] ?? []));
		$flow->setLimits((array) ($data['limits'] ?? []));
		$flow->setApplicationSlug(($data['applicationSlug'] ?? null));
	}//end declareGraph()

	/**
	 * A filled value with the flow's own uuid where it quoted itself.
	 *
	 * @param mixed  $value The filled value.
	 * @param string $uuid  The flow's uuid.
	 *
	 * @return mixed
	 */
	private function withOwnUuid(mixed $value, string $uuid): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->withOwnUuid(value: $item, uuid: $uuid), $value);
		}

		if (is_string($value) === true) {
			return str_replace(self::OWN_UUID, $uuid, $value);
		}

		return $value;
	}//end withOwnUuid()

	/**
	 * The declarable fields of a flow, as one comparable string.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return string
	 */
	private function declared(Flow $flow): string {
		return (string) json_encode(value: array_map(static fn (string $getter): mixed => $flow->$getter(), self::DECLARED));
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
