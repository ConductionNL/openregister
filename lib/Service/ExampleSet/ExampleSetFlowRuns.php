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
 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-flow-may-carry-a-run-that-waits-on-a-task
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
use Throwable;

/**
 * Checks and writes the runs of a set's flows, and fills a waiting run's slot.
 *
 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-flow-may-carry-a-run-that-waits-on-a-task
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

		foreach ($runs as $run) {
			$refusal = $this->runRefusal(run: (array) $run, nodes: $nodes);
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
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-flow-may-carry-a-run-that-waits-on-a-task
	 */
	public function unnamedRefusals(array $fragment): array {
		$named = [];
		foreach ((array) ($fragment['tasks'] ?? []) as $task) {
			$named[] = trim((string) ($task['data']['runUuid'] ?? ''));
		}

		$refusals = [];
		foreach ((array) ($fragment['flows'] ?? []) as $flow) {
			foreach ((array) ($flow['data']['runs'] ?? []) as $run) {
				$run = (array) $run;
				if (($run['status'] ?? null) !== FlowRun::STATUS_SUSPENDED || is_string($run['key'] ?? null) === false) {
					continue;
				}

				$reference = '{{flow:'.$flow['key'].'.runs.'.$run['key'].'}}';
				if (in_array($reference, $named, true) === false) {
					$refusals[] = $fragment['file'].': flow "'.$flow['key'].'": waiting run "'.$run['key']
						.'": no task of its fragment names it ('.$reference.' as runUuid)';
				}
			}
		}

		return $refusals;
	}//end unnamedRefusals()

	/**
	 * Why a task cannot wait in the run it names, or '': a run without its step, a run no flow of the set
	 * declares as waiting, or a step the run does not wait on.
	 *
	 * @param array<string, mixed> $data    The filled task fields.
	 * @param array<string, mixed> $context The fill context, with the fragment's flows.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-task-may-name-the-run-it-waits-in-and-carry-its-claim
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

		foreach ((array) ($context['flows'] ?? []) as $flow) {
			$waitsOn = ($flow['waitsOn'][$run] ?? null);
			if ($waitsOn === null) {
				continue;
			}

			if ($waitsOn !== $node) {
				return 'nodeId "'.$node.'" is not the step run "'.$run.'" waits on ("'.$waitsOn.'")';
			}

			return '';
		}

		return 'runUuid "'.$run.'" is not a waiting run a flow of the set declares';
	}//end taskRefusal()

	/**
	 * Store the declared runs of a stored flow, in the flow's organisation.
	 *
	 * @param Flow                             $flow    The stored flow.
	 * @param int|null                         $version The published version, or null for a draft.
	 * @param array<int, array<string, mixed>> $runs    The filled runs.
	 * @param callable(): string               $newUuid Makes a fresh uuid.
	 *
	 * @return array{runs?: array<string, string>, waitsOn?: array<string, string>} `runs` (run key to uuid) and
	 *                                                                             `waitsOn` (waiting run uuid to step),
	 *                                                                             each present only when it holds something.
	 *
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-flow-may-carry-a-run-that-waits-on-a-task
	 */
	public function write(Flow $flow, ?int $version, array $runs, callable $newUuid): array {
		$entry = [];
		foreach ($runs as $run) {
			$uuid    = (string) $newUuid();
			$waiting = (($run['status'] ?? null) === FlowRun::STATUS_SUSPENDED);
			$entity  = $this->entity(flow: $flow, version: $version, run: $run, uuid: $uuid);
			if ($waiting === true) {
				$this->park(run: $entity, node: (string) $run['node']);
				$entry['waitsOn'][$uuid] = (string) $run['node'];
			}

			$this->container->get(FlowRunMapperInterface::class)->insert($entity);
			if (is_string($run['key'] ?? null) === true) {
				$entry['runs'][$run['key']] = $uuid;
			}
		}

		return $entry;
	}//end write()

	/**
	 * Put a task in the slot of the step its run waits on, and set the heartbeat. A task that names no run
	 * changes nothing.
	 *
	 * @param string $runUuid  The waiting run, or ''.
	 * @param string $nodeId   The step it waits on.
	 * @param string $taskUuid The task it waits for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-flow-may-carry-a-run-that-waits-on-a-task
	 */
	public function fill(string $runUuid, string $nodeId, string $taskUuid): void {
		if (trim($runUuid) === '') {
			return;
		}

		$mapper  = $this->container->get(FlowRunMapperInterface::class);
		$run     = $mapper->findByUuid(uuid: $runUuid);
		$context = (array) $run->getContext();
		$slots   = (array) ($context[FlowResumeState::CONTEXT_KEY] ?? []);

		$slots[$nodeId] = [
			FlowTaskBridge::SLOT_TASK_UUID => $taskUuid,
			FlowTaskBridge::SLOT_ASKED_AT  => (new DateTime())->format('c'),
		] + (array) ($slots[$nodeId] ?? []);
		$context[FlowResumeState::CONTEXT_KEY] = $slots;

		$run->setContext($context);
		$run->setResumeAt((new DateTime())->modify('+'.self::HEARTBEAT_MINUTES.' minutes'));
		$run->setUpdated(new DateTime());
		$mapper->update($run);
	}//end fill()

	/**
	 * Why one declared run cannot be written, or ''.
	 *
	 * @param array<string, mixed> $run   The declared run.
	 * @param mixed                $nodes The flow's declared steps.
	 *
	 * @return string
	 */
	private function runRefusal(array $run, mixed $nodes): string {
		$unknown = array_diff(array_keys($run), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a run field a set may write';
		}

		$status = ($run['status'] ?? null);
		if ($status === FlowRun::STATUS_SUSPENDED) {
			$waits = $this->waitRefusal(run: $run, nodes: $nodes);
			if ($waits !== '') {
				return $waits;
			}
		} else if (in_array($status, FlowRun::TERMINAL, true) === false) {
			return 'a run is finished (status is one of '.implode(', ', FlowRun::TERMINAL).') or waits on a step (suspended)';
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
	 * Why a waiting run cannot be written, or '': it needs a key and a user-task step of its flow.
	 *
	 * @param array<string, mixed> $run   The declared run.
	 * @param mixed                $nodes The flow's declared steps.
	 *
	 * @return string
	 */
	private function waitRefusal(array $run, mixed $nodes): string {
		if (is_string($run['key'] ?? null) === false || trim($run['key']) === '') {
			return 'a waiting run needs a key, so a task can name it';
		}

		$node = (string) ($run['node'] ?? '');
		foreach ((array) $nodes as $step) {
			$step = (array) $step;
			if (($step['id'] ?? null) === $node && ($step['type'] ?? null) === self::STEP_TYPE) {
				return '';
			}
		}

		return 'waiting run "'.$run['key'].'" waits on an '.self::STEP_TYPE.' step of its flow (node), not "'.$node.'"';
	}//end waitRefusal()

	/**
	 * One run of the flow on its subject, as declared.
	 *
	 * @param Flow                 $flow    The stored flow.
	 * @param int|null             $version The published version, or null for a draft.
	 * @param array<string, mixed> $run     The filled run fields.
	 * @param string               $uuid    The run's uuid.
	 *
	 * @return FlowRun
	 */
	private function entity(Flow $flow, ?int $version, array $run, string $uuid): FlowRun {
		$created = new DateTime((string) ($run['created'] ?? 'now'));

		$entity = new FlowRun();
		$entity->setUuid($uuid);
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

		return $entity;
	}//end entity()

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
		$run->setContext(
			[
				'runUuid'                    => $run->getUuid(),
				'flowId'                     => $run->getFlowId(),
				'triggeredBy'                => $actor,
				'runAs'                      => $actor,
				FlowResumeState::CONTEXT_KEY => [$node => []],
			]
		);
	}//end park()
}//end class
