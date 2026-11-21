<?php

/**
 * OpenRegister Example Set Flow Runs
 *
 * The runs a set's flow carries. A finished run makes a case's run history
 * read as it would after real work. A waiting run is parked on one
 * `openregister.user-task` step and written as that step leaves a live wait,
 * so the flow worker treats it as one: it is written with an empty resume slot
 * and no heartbeat, and the task of the same fragment that names it fills the
 * slot with its uuid and sets the heartbeat. From then on the heartbeat
 * re-reads the open task and parks again, and completing the task continues
 * the run.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use Psr\Container\ContainerInterface;

/**
 * Checks and writes the runs of a set's flows, and fills a waiting run's slot.
 *
 * @spec openspec/specs/example-sets/spec.md
 */
class ExampleSetFlowRuns {
	/**
	 * The run fields a fragment may set.
	 */
	public const FIELDS = [
		'key',
		'node',
		'status',
		'subject',
		'subjectRegister',
		'subjectSchema',
		'trigger',
		'triggeredBy',
		'error',
		'created',
		'updated',
	];

	/**
	 * The step type a run may wait on: its resume slot keeps the task under FlowTaskBridge::SLOT_TASK_UUID.
	 */
	public const STEP_TYPE = 'openregister.user-task';

	/**
	 * Minutes to the next heartbeat, the user-task step's own default.
	 */
	private const HEARTBEAT_MINUTES = 15;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the run mapper on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Why the declared runs of a flow cannot be written, or ''.
	 *
	 * A run is finished, or waits on a user-task step: a queued or running run
	 * would be picked up by the flow worker and executed.
	 *
	 * @param mixed $runs  The declared runs.
	 * @param mixed $nodes The flow's declared steps.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
	 */
	public function refusal(mixed $runs, mixed $nodes): string {
		if (is_array($runs) === false || array_is_list($runs) === false) {
			return 'runs is a list';
		}

		$check = new ExampleSetFlowRunCheck();
		foreach ($runs as $run) {
			$refusal = $check->refusal(run: (array) $run, nodes: $nodes);
			if ($refusal !== '') {
				return $refusal;
			}
		}

		return '';
	}//end refusal()

	/**
	 * The fragment's waiting runs that no task of the fragment names: their slot would stay empty.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function unnamedRefusals(array $fragment): array {
		$named    = array_map(static fn (mixed $task): string => trim((string) ($task['data']['runUuid'] ?? '')), (array) ($fragment['tasks'] ?? []));
		$refusals = [];
		foreach ((array) ($fragment['flows'] ?? []) as $flow) {
			foreach ($this->unnamedIn(flow: $flow, named: $named) as $refusal) {
				$refusals[] = $fragment['file'].': '.$refusal;
			}
		}

		return $refusals;
	}//end unnamedRefusals()

	/**
	 * The waiting runs of one flow that none of the named runs is, each as a refusal without the fragment's file.
	 *
	 * @param mixed              $flow  The declared flow.
	 * @param array<int, string> $named The runs the fragment's tasks name, as `{{flow:key.runs.key}}`.
	 *
	 * @return array<int, string>
	 */
	private function unnamedIn(mixed $flow, array $named): array {
		$refusals = [];
		foreach ($this->waitingKeys(runs: (array) ($flow['data']['runs'] ?? [])) as $key) {
			$reference = '{{flow:'.$flow['key'].'.runs.'.$key.'}}';
			if (in_array($reference, $named, true) === false) {
				$refusals[] = 'flow "'.$flow['key'].'": waiting run "'.$key.'": no task of its fragment names it ('.$reference.' as runUuid)';
			}
		}

		return $refusals;
	}//end unnamedIn()

	/**
	 * The keys of the waiting runs among declared runs; a waiting run without a key has none to name.
	 *
	 * @param array<int|string, mixed> $runs The declared runs.
	 *
	 * @return array<int, string>
	 */
	private function waitingKeys(array $runs): array {
		$keys = [];
		foreach ($runs as $run) {
			$run = (array) $run;
			if (($run['status'] ?? null) === FlowRun::STATUS_SUSPENDED && is_string($run['key'] ?? null) === true) {
				$keys[] = $run['key'];
			}
		}

		return $keys;
	}//end waitingKeys()

	/**
	 * Why a task cannot wait in the run it names, or '': a run without its step, a run no flow of the set
	 * declares as waiting, or a step the run does not wait on.
	 *
	 * @param array<string, mixed> $data    The filled task fields.
	 * @param array<string, mixed> $context The fill context, with the fragment's flows.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function taskRefusal(array $data, array $context): string {
		$run  = trim((string) ($data['runUuid'] ?? ''));
		$node = trim((string) ($data['nodeId'] ?? ''));
		if (($run === '') !== ($node === '')) {
			return 'runUuid and nodeId go together: the run the task waits in, and the step';
		}

		if ($run === '') {
			return '';
		}

		return $this->stepRefusal(step: ['run' => $run, 'node' => $node], waitsOn: $this->stepOf(run: $run, context: $context));
	}//end taskRefusal()

	/**
	 * The step a waiting run waits on, from the first flow of the set that declares the run; null when none does.
	 *
	 * @param string               $run     The run's uuid.
	 * @param array<string, mixed> $context The fill context, with the fragment's flows.
	 *
	 * @return mixed
	 */
	private function stepOf(string $run, array $context): mixed {
		foreach ((array) ($context['flows'] ?? []) as $flow) {
			$waitsOn = ($flow['waitsOn'][$run] ?? null);
			if ($waitsOn !== null) {
				return $waitsOn;
			}
		}

		return null;
	}//end stepOf()

	/**
	 * Why a task cannot wait on a step of a run, or ''.
	 *
	 * @param array{run: string, node: string} $step    The run and the step the task names.
	 * @param mixed                            $waitsOn The step the run waits on, or null for a run no flow declares.
	 *
	 * @return string
	 */
	private function stepRefusal(array $step, mixed $waitsOn): string {
		if ($waitsOn === null) {
			return 'runUuid "'.$step['run'].'" is not a waiting run a flow of the set declares';
		}

		if ($waitsOn !== $step['node']) {
			return 'nodeId "'.$step['node'].'" is not the step run "'.$step['run'].'" waits on ("'.$waitsOn.'")';
		}

		return '';
	}//end stepRefusal()

	/**
	 * Store the declared runs of a stored flow, in the flow's organisation.
	 *
	 * @param ExampleSetRunsWrite $write The stored flow, its version, the runs and the uuid source.
	 *
	 * @return array{runs?: array<string, string>, waitsOn?: array<string, string>} `runs` (run key to uuid) and
	 *                                                                             `waitsOn` (waiting run uuid to step),
	 *                                                                             each present only when it holds something.
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function write(ExampleSetRunsWrite $write): array {
		$entry = [];
		foreach ($write->runs as $run) {
			$entity = $this->entity(write: $write, run: $run);
			$entry  = array_replace_recursive($entry, $this->insert(run: $run, entity: $entity));
		}

		return $entry;
	}//end write()

	/**
	 * Store one run, parked first when it waits on a step.
	 *
	 * @param array<string, mixed> $run    The filled run.
	 * @param FlowRun              $entity The run as it is stored.
	 *
	 * @return array{runs?: array<string, string>, waitsOn?: array<string, string>} What write() records of the run.
	 */
	private function insert(array $run, FlowRun $entity): array {
		$entry = [];
		if (($run['status'] ?? null) === FlowRun::STATUS_SUSPENDED) {
			$this->park(run: $entity, node: (string) $run['node']);
			$entry['waitsOn'][$entity->getUuid()] = (string) $run['node'];
		}

		$this->container->get(FlowRunMapperInterface::class)->insert($entity);
		if (is_string($run['key'] ?? null) === true) {
			$entry['runs'][$run['key']] = $entity->getUuid();
		}

		return $entry;
	}//end insert()

	/**
	 * Put a task in the slot of the step its run waits on, and set the heartbeat. A task that names no run
	 * changes nothing.
	 *
	 * @param ExampleSetRunSlot $slot The waiting run, its step and the task that fills it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function fill(ExampleSetRunSlot $slot): void {
		if (trim($slot->runUuid) === '') {
			return;
		}

		$mapper = $this->container->get(FlowRunMapperInterface::class);
		$run    = $mapper->findByUuid(uuid: $slot->runUuid);
		$run->setContext($this->withTask(context: (array) $run->getContext(), slot: ['node' => $slot->nodeId, 'task' => $slot->taskUuid]));
		$run->setResumeAt((new DateTime())->modify('+'.self::HEARTBEAT_MINUTES.' minutes'));
		$run->setUpdated(new DateTime());
		$mapper->update($run);
	}//end fill()

	/**
	 * A run's context with a task in the slot of a step, asked now; what else the slot holds is kept.
	 *
	 * @param array<string, mixed>              $context The run's context.
	 * @param array{node: string, task: string} $slot    The step and the task it waits for.
	 *
	 * @return array<string, mixed>
	 */
	private function withTask(array $context, array $slot): array {
		$slots = (array) ($context[FlowResumeState::CONTEXT_KEY] ?? []);

		$slots[$slot['node']] = [
			FlowTaskBridge::SLOT_TASK_UUID => $slot['task'],
			FlowTaskBridge::SLOT_ASKED_AT  => (new DateTime())->format('c'),
		] + (array) ($slots[$slot['node']] ?? []);
		$context[FlowResumeState::CONTEXT_KEY] = $slots;

		return $context;
	}//end withTask()

	/**
	 * One run of the flow on its subject, as declared.
	 *
	 * @param ExampleSetRunsWrite $write The stored flow, its version and the uuid source.
	 * @param array<string, mixed> $run The declared run.
	 *
	 * @return FlowRun
	 */
	private function entity(ExampleSetRunsWrite $write, array $run): FlowRun {
		$entity = new FlowRun();
		$entity->setUuid((string) ($write->newUuid)());
		$entity->setFlowId((string) $write->flow->getUuid());
		$entity->setFlowVersion($write->version);
		$entity->setStatus((string) $run['status']);
		self::setSubject(entity: $entity, run: $run);
		$entity->setTrigger((string) ($run['trigger'] ?? $write->flow->getTrigger() ?? 'manual'));
		$entity->setTriggeredBy(($run['triggeredBy'] ?? null));
		$entity->setError(($run['error'] ?? null));
		$entity->setOrganisation($write->flow->getOrganisation());
		self::setMoments(entity: $entity, run: $run);

		return $entity;
	}//end entity()

	/**
	 * Date the run as declared: created then, updated then or else when created.
	 *
	 * @param FlowRun              $entity The run being built.
	 * @param array<string, mixed> $run    The declared run.
	 *
	 * @return void
	 */
	private static function setMoments(FlowRun $entity, array $run): void {
		$created = new DateTime((string) ($run['created'] ?? 'now'));
		$entity->setCreated($created);
		$entity->setUpdated(new DateTime((string) ($run['updated'] ?? $created->format('c'))));
	}//end setMoments()

	/**
	 * Point the run at its declared subject.
	 *
	 * @param FlowRun              $entity The run being built.
	 * @param array<string, mixed> $run    The declared run.
	 *
	 * @return void
	 */
	private static function setSubject(FlowRun $entity, array $run): void {
		$entity->setSubjectUuid((string) $run['subject']);
		$entity->setSubjectRegister(($run['subjectRegister'] ?? null));
		$entity->setSubjectSchema(($run['subjectSchema'] ?? null));
	}//end setSubject()

	/**
	 * Park a run on a step as the user-task step does: one token and one item there, the step's slot empty
	 * until its task fills it, and no heartbeat before that.
	 *
	 * @param FlowRun $run  The run.
	 * @param string  $node The step it waits on.
	 *
	 * @return void
	 */
	private function park(FlowRun $run, string $node): void {
		$item  = ['json' => [], 'binary' => [], 'pairedItem' => ['item' => 0]];
		$actor = $run->getTriggeredBy();

		$run->setMarking([$node => 1]);
		$run->setPlaceItems([$node => [$item]]);
		$run->setItems([$item]);
		$run->setRunAs($actor);
		$run->setResumeAt(null);
		$run->setContext(self::parkedContext(run: $run, node: $node));
	}//end park()

	/**
	 * The context of a run parked at a node, acting as whoever triggered it.
	 *
	 * @param FlowRun $run  The run.
	 * @param string  $node The node it waits at.
	 *
	 * @return array<string, mixed> The context.
	 */
	private static function parkedContext(FlowRun $run, string $node): array {
		$actor = $run->getTriggeredBy();

		return [
			'runUuid'                    => $run->getUuid(),
			'flowId'                     => $run->getFlowId(),
			'triggeredBy'                => $actor,
			'runAs'                      => $actor,
			FlowResumeState::CONTEXT_KEY => [$node => []],
		];
	}//end parkedContext()
}//end class
