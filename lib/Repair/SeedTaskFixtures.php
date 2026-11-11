<?php

/**
 * OpenRegister Repair — seed the task fixtures (flow-task-entity, ADR-001).
 *
 * Installs the five seed groups from the change's design.md — Seed Data:
 * a municipal pooled permit check with NO run attached, a consultancy
 * delegated approval with enforcing expiry ON a run, a travel-agency agent
 * task with a typed checklist, and two terminal tasks (one completed
 * `approved`, one terminated by propagation naming a stopped run) — plus one
 * audit fixture per task, including one DENIED entry, so the append-only
 * denial path is covered by seed data rather than only by a test double.
 *
 * All uuids are nil placeholders and all uids obviously fake. Idempotent on
 * uuid: a fixture that exists is left exactly as it is, so a re-run (every
 * upgrade) changes nothing.
 *
 * OFF BY DEFAULT. These are demo rows; a production instance must not wake
 * up with five Dutch tasks for people who do not exist. The step runs only
 * when the app config `openregister` / `seed_demo_tasks` is true
 * (`occ config:app:set openregister seed_demo_tasks --value=true --type=boolean`),
 * which a demo instance and the test environment set and nothing else does.
 *
 * WRITES THROUGH THE MAPPERS, NOT THE SERVICE, deliberately: repair steps
 * run without a user session, and TaskService correctly refuses actor-less
 * verbs. Fixtures are not user actions. The one service invariant that
 * matters is preserved by hand: candidate JSON and the candidate INDEX rows
 * are written together.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Repair\Support\ApprovalTaskStores;
use OCA\OpenRegister\Repair\Support\RepairPlatform;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Seeds the five task fixture groups, idempotent on uuid.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
 */
class SeedTaskFixtures implements IRepairStep {

	/**
	 * The app config key that switches the demo seed on.
	 */
	public const FLAG = 'seed_demo_tasks';

	/**
	 * The task fields of seed group 1: Municipality: a pooled permit check, NO flow attached. Its dates are set when the fixture is built.
	 */
	private const MUNICIPAL_PERMIT_CHECK_TASK = [
		'title' => 'Controleer bouwtekening op welstandseisen',
		'state' => Task::STATE_ENABLED,
		'isTerminal' => false,
		'performerType' => Task::PERFORMER_GROUP,
		'assignee' => null,
		'candidateGroups' => ['GEMEENTE_VERGUNNINGEN_TEAM'],
		'routingStrategy' => 'least-loaded',
		'priority' => 'normal',
		'dueAt' => null,
		'expiresAt' => null,
		'runUuid' => null,
		'objectUuid' => '00000000-0000-0000-0000-0000000000aa',
		'registerId' => 1,
		'schemaId' => 1,
		'requester' => 'EXAMPLE_BALIE_USER',
		'lastAction' => 'offer',
		'appId' => 'openregister',
	];

	/**
	 * The audit rows of seed group 1: Municipality: a pooled permit check, NO flow attached.
	 */
	private const MUNICIPAL_PERMIT_CHECK_AUDIT = [
		[
			'action' => 'offer',
			'stateAfter' => Task::STATE_ENABLED,
			'actor' => 'EXAMPLE_BALIE_USER',
			'performerType' => Task::PERFORMER_GROUP,
			'authorized' => true,
		],
	];

	/**
	 * The task fields of seed group 2: Consultancy: a delegated approval with enforcing expiry, on a run. Its dates are set when the fixture is built.
	 */
	private const CONSULTANCY_DELEGATED_APPROVAL_TASK = [
		'title' => 'Keur inkooporder > EUR 10.000 goed',
		'state' => Task::STATE_ACTIVE,
		'isTerminal' => false,
		'performerType' => Task::PERFORMER_USER,
		'assignee' => 'EXAMPLE_DELEGATE_USER',
		'onBehalfOf' => 'EXAMPLE_DIRECTOR_USER',
		'mandate' => 'Volmacht inkoop 2026, artikel 4 lid 2',
		'priority' => 'high',
		'dueAt' => null,
		'expiresAt' => null,
		'runUuid' => '00000000-0000-0000-0000-0000000000f1',
		'nodeId' => 'approve-purchase-order',
		'templateId' => '00000000-0000-0000-0000-0000000000e0',
		'templateVersion' => 3,
		'templateSnapshot' => ['checklist' => []],
		'objectUuid' => '00000000-0000-0000-0000-0000000000bb',
		'requester' => 'EXAMPLE_CONTROLLER_USER',
		'lastAction' => 'delegate',
		'appId' => 'openregister',
	];

	/**
	 * The audit rows of seed group 2: Consultancy: a delegated approval with enforcing expiry, on a run.
	 */
	private const CONSULTANCY_DELEGATED_APPROVAL_AUDIT = [
		[
			'action' => 'delegate',
			'stateAfter' => Task::STATE_ACTIVE,
			'actor' => 'EXAMPLE_DIRECTOR_USER',
			'performerType' => Task::PERFORMER_USER,
			'onBehalfOf' => 'EXAMPLE_DIRECTOR_USER',
			'mandate' => 'Volmacht inkoop 2026, artikel 4 lid 2',
			'authorized' => true,
		],
	];

	/**
	 * The task fields of seed group 3: Travel agency: an agent task with a checklist.
	 */
	private const TRAVEL_AGENCY_AGENT_TASK_TASK = [
		'title' => 'Verifieer visumvereisten voor reisgroep',
		'state' => Task::STATE_ACTIVE,
		'isTerminal' => false,
		'performerType' => Task::PERFORMER_AGENT,
		'assignee' => 'EXAMPLE_AGENT_IDENTITY',
		'priority' => 'urgent',
		'checklist' => [
			[
				'id' => 'c1',
				'label' => 'Paspoortgeldigheid > 6 maanden',
				'description' => null,
				'checked' => true,
			],
			[
				'id' => 'c2',
				'label' => 'Visumplicht per bestemming gecontroleerd',
				'description' => null,
				'checked' => false,
			],
			[
				'id' => 'c3',
				'label' => 'Transitvisum nodig?',
				'description' => null,
				'checked' => false,
			],
		],
		'epicTaskId' => null,
		'objectUuid' => '00000000-0000-0000-0000-0000000000cc',
		'runUuid' => null,
		'requester' => 'EXAMPLE_TRAVEL_PLANNER',
		'lastAction' => 'claim',
		'appId' => 'openregister',
	];

	/**
	 * The audit rows of seed group 3: Travel agency: an agent task with a checklist.
	 */
	private const TRAVEL_AGENCY_AGENT_TASK_AUDIT = [
		[
			'action' => 'claim',
			'stateAfter' => Task::STATE_ACTIVE,
			'actor' => 'EXAMPLE_AGENT_IDENTITY',
			'performerType' => Task::PERFORMER_AGENT,
			'authorized' => true,
		],
	];

	/**
	 * The task fields of seed group 4a: Terminal: completed with the collapse-preserved outcome. Its dates are set when the fixture is built.
	 */
	private const COMPLETED_APPROVAL_TASK = [
		'title' => 'Beoordeel offerte serverhal',
		'state' => Task::STATE_COMPLETED,
		'isTerminal' => true,
		'performerType' => Task::PERFORMER_USER,
		'assignee' => 'EXAMPLE_REVIEWER_USER',
		'outcome' => 'approved',
		'priority' => 'normal',
		'completedAt' => null,
		'completedBy' => 'EXAMPLE_REVIEWER_USER',
		'runUuid' => null,
		'requester' => 'EXAMPLE_CONTROLLER_USER',
		'lastAction' => 'complete',
		'appId' => 'openregister',
	];

	/**
	 * The audit rows of seed group 4a: Terminal: completed with the collapse-preserved outcome.
	 */
	private const COMPLETED_APPROVAL_AUDIT = [
		[
			'action' => 'complete',
			'stateAfter' => Task::STATE_COMPLETED,
			'actor' => 'EXAMPLE_REVIEWER_USER',
			'performerType' => Task::PERFORMER_USER,
			'authorized' => true,
		],
		// The DENIED entry: a stranger tried first, and the
		// append-only denial path has a fixture because of it.
		[
			'action' => 'complete',
			'stateAfter' => Task::STATE_ACTIVE,
			'actor' => 'EXAMPLE_STRANGER_USER',
			'performerType' => Task::PERFORMER_USER,
			'reason' => "Verb 'complete' denied: only the current assignee may perform it.",
			'authorized' => false,
		],
	];

	/**
	 * The task fields of seed group 4b: Terminal: terminated by propagation from a stopped run.
	 */
	private const TERMINATED_BY_PROPAGATION_TASK = [
		'title' => 'Vraag aanvullende stukken op',
		'state' => Task::STATE_TERMINATED,
		'isTerminal' => true,
		'performerType' => Task::PERFORMER_USER,
		'assignee' => 'EXAMPLE_CASEWORKER_USER',
		'outcome' => 'terminated',
		'priority' => 'normal',
		'runUuid' => '00000000-0000-0000-0000-0000000000f2',
		'nodeId' => 'request-documents',
		'requester' => 'EXAMPLE_CONTROLLER_USER',
		'lastAction' => 'terminate',
		'appId' => 'openregister',
	];

	/**
	 * The audit rows of seed group 4b: Terminal: terminated by propagation from a stopped run.
	 */
	private const TERMINATED_BY_PROPAGATION_AUDIT = [
		[
			'action' => 'terminate',
			'stateAfter' => Task::STATE_TERMINATED,
			'actor' => 'flow-run:00000000-0000-0000-0000-0000000000f2',
			'performerType' => Task::PERFORMER_USER,
			'reason' => "Run '00000000-0000-0000-0000-0000000000f2' reached terminal status 'stopped'.",
			'authorized' => true,
		],
	];

	/**
	 * Constructor.
	 *
	 * @param RepairPlatform     $platform The app config holding the opt-in flag, and failure reporting.
	 * @param ApprovalTaskStores $mappers  The task table, the candidate index rows and the append-only audit.
	 */
	public function __construct(
		private readonly RepairPlatform $platform,
		private readonly ApprovalTaskStores $mappers,
	) {

	}//end __construct()

	/**
	 * The step's name in the repair log.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function getName(): string {
		return 'Seed the task fixtures (flow-task-entity)';
	}//end getName()

	/**
	 * Install every fixture that does not exist yet.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function run(IOutput $output): void {
		if ($this->platform->config->getValueBool('openregister', self::FLAG, false) === false) {
			$output->info('Task fixtures: skipped (openregister/' . self::FLAG . ' is not enabled).');
			return;
		}

		$seeded = 0;
		foreach ($this->fixtures() as $fixture) {
			if ($this->seedOrReport(fixture: $fixture, output: $output) === true) {
				$seeded++;
			}
		}

		$output->info(sprintf('Task fixtures: %d seeded, %d already present.', $seeded, count($this->fixtures()) - $seeded));
	}//end run()

	/**
	 * Seed one fixture; a failure is reported and counts as not seeded.
	 *
	 * @param array<string, mixed> $fixture The fixture.
	 * @param IOutput              $output  The repair output.
	 *
	 * @return boolean True when inserted.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	private function seedOrReport(array $fixture, IOutput $output): bool {
		try {
			return $this->seedOne(fixture: $fixture);
		} catch (Throwable $failure) {
			// A fixture must never fail an upgrade — but silence would be
			// the repair-step defect this fleet already paid for once, so
			// the skip is visible in the repair log AND the app log.
			$output->warning(sprintf('Task fixture %s failed: %s', (string)($fixture['uuid'] ?? '?'), $failure->getMessage()));
			$this->platform->logger->warning(
				'[SeedTaskFixtures] Fixture failed: ' . $failure->getMessage(),
				['uuid' => ($fixture['uuid'] ?? null)]
			);
			return false;
		}
	}//end seedOrReport()

	/**
	 * Seed one fixture, unless its uuid already exists.
	 *
	 * @param array<string, mixed> $fixture The fixture: task fields plus
	 *                                      `candidates` and `audit` sub-arrays.
	 *
	 * @return boolean True when inserted, false when already present.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	private function seedOne(array $fixture): bool {
		if ($this->alreadySeeded(uuid: (string)$fixture['uuid']) === true) {
			return false;
		}

		$task = new Task();
		$task->hydrate($fixture['task']);
		$task->setUuid((string)$fixture['uuid']);
		$persisted = $this->mappers->tasks->insert($task);

		// The one-write-path invariant, preserved by hand: JSON and index
		// rows land together.
		$this->mappers->candidates->replaceForTask(taskId: (int)$persisted->getId(), candidates: self::candidateRows(task: $persisted));

		foreach (($fixture['audit'] ?? []) as $auditFixture) {
			$this->insertAudit(taskId: (int)$persisted->getId(), auditFixture: $auditFixture);
		}

		return true;
	}//end seedOne()

	/**
	 * Whether a task with the fixture's uuid already exists.
	 *
	 * @param string $uuid The fixture uuid.
	 *
	 * @return boolean True when present.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	private function alreadySeeded(string $uuid): bool {
		try {
			$this->mappers->tasks->findByUuid(uuid: $uuid);

			return true;
		} catch (DoesNotExistException) {
			// Absent: seed it.
			return false;
		}
	}//end alreadySeeded()

	/**
	 * The candidate index rows of a persisted task: its users, its groups, then its role.
	 *
	 * @param Task $task The persisted task.
	 *
	 * @return array<int, array{kind: string, ref: string}> The index rows.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	private static function candidateRows(Task $task): array {
		$role = [];
		if (trim((string)$task->getCandidateRole()) !== '') {
			$role = [self::candidateRefs(kind: 'role', refs: [$task->getCandidateRole()])[0]];
		}

		return array_merge(
			self::candidateRefs(kind: 'user', refs: ($task->getCandidateUsers() ?? [])),
			self::candidateRefs(kind: 'group', refs: ($task->getCandidateGroups() ?? [])),
			$role
		);
	}//end candidateRows()

	/**
	 * Index rows of one kind.
	 *
	 * @param string            $kind The candidate kind: user, group or role.
	 * @param array<int, mixed> $refs The candidate references.
	 *
	 * @return array<int, array{kind: string, ref: string}> The index rows.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	private static function candidateRefs(string $kind, array $refs): array {
		$rows = [];
		foreach ($refs as $ref) {
			$rows[] = [
				'kind' => $kind,
				'ref' => (string)$ref,
			];
		}

		return $rows;
	}//end candidateRefs()

	/**
	 * Insert one audit row for a seeded task.
	 *
	 * @param int                  $taskId       The seeded task's id.
	 * @param array<string, mixed> $auditFixture The audit fixture.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	private function insertAudit(int $taskId, array $auditFixture): void {
		$entry = new TaskAudit();
		$entry->setTaskId($taskId);
		$entry->setAction((string)$auditFixture['action']);
		$entry->setStateAfter($auditFixture['stateAfter'] ?? null);
		$entry->setActor($auditFixture['actor'] ?? null);
		$entry->setPerformerType($auditFixture['performerType'] ?? null);
		$entry->setOnBehalfOf($auditFixture['onBehalfOf'] ?? null);
		$entry->setMandate($auditFixture['mandate'] ?? null);
		$entry->setReason($auditFixture['reason'] ?? null);
		$entry->setAuthorized((bool)($auditFixture['authorized'] ?? true));
		$this->mappers->audits->insert($entry);
	}//end insertAudit()


	/**
	 * The five fixture groups from design.md — Seed Data.
	 *
	 * @return array<int, array<string, mixed>> The fixtures.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	private function fixtures(): array {
		return [
			$this->municipalPermitCheck(),
			$this->consultancyDelegatedApproval(),
			$this->travelAgencyAgentTask(),
			$this->completedApproval(),
			$this->terminatedByPropagation(),
		];
	}//end fixtures()

	/**
	 * Seed group 1: Municipality: a pooled permit check, NO flow attached.
	 *
	 * @return array<string, mixed> The fixture.
	 */
	private function municipalPermitCheck(): array {
		return [
			'uuid' => '00000000-0000-0000-0000-000000000001',
			'task' => array_merge(self::MUNICIPAL_PERMIT_CHECK_TASK, ['dueAt' => new DateTime('2026-09-04T17:00:00+02:00')]),
			'audit' => self::MUNICIPAL_PERMIT_CHECK_AUDIT,
		];
	}//end municipalPermitCheck()

	/**
	 * Seed group 2: Consultancy: a delegated approval with enforcing expiry, on a run.
	 *
	 * @return array<string, mixed> The fixture.
	 */
	private function consultancyDelegatedApproval(): array {
		return [
			'uuid' => '00000000-0000-0000-0000-000000000002',
			'task' => array_merge(
				self::CONSULTANCY_DELEGATED_APPROVAL_TASK,
				[
					'dueAt' => new DateTime('2026-08-29T12:00:00+02:00'),
					'expiresAt' => new DateTime('2026-09-01T12:00:00+02:00'),
				]
			),
			'audit' => self::CONSULTANCY_DELEGATED_APPROVAL_AUDIT,
		];
	}//end consultancyDelegatedApproval()

	/**
	 * Seed group 3: Travel agency: an agent task with a checklist.
	 *
	 * @return array<string, mixed> The fixture.
	 */
	private function travelAgencyAgentTask(): array {
		return [
			'uuid' => '00000000-0000-0000-0000-000000000003',
			'task' => self::TRAVEL_AGENCY_AGENT_TASK_TASK,
			'audit' => self::TRAVEL_AGENCY_AGENT_TASK_AUDIT,
		];
	}//end travelAgencyAgentTask()

	/**
	 * Seed group 4a: Terminal: completed with the collapse-preserved outcome.
	 *
	 * @return array<string, mixed> The fixture.
	 */
	private function completedApproval(): array {
		return [
			'uuid' => '00000000-0000-0000-0000-000000000004',
			'task' => array_merge(self::COMPLETED_APPROVAL_TASK, ['completedAt' => new DateTime('2026-08-20T09:30:00+02:00')]),
			'audit' => self::COMPLETED_APPROVAL_AUDIT,
		];
	}//end completedApproval()

	/**
	 * Seed group 4b: Terminal: terminated by propagation from a stopped run.
	 *
	 * @return array<string, mixed> The fixture.
	 */
	private function terminatedByPropagation(): array {
		return [
			'uuid' => '00000000-0000-0000-0000-000000000005',
			'task' => self::TERMINATED_BY_PROPAGATION_TASK,
			'audit' => self::TERMINATED_BY_PROPAGATION_AUDIT,
		];
	}//end terminatedByPropagation()
}//end class
