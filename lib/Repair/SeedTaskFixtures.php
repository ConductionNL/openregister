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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the five task fixture groups, idempotent on uuid.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class SeedTaskFixtures implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param TaskMapper $tasks The task table.
	 * @param TaskCandidateMapper $candidates The candidate index rows.
	 * @param TaskAuditMapper $audits The append-only audit.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly TaskMapper $tasks,
		private readonly TaskCandidateMapper $candidates,
		private readonly TaskAuditMapper $audits,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name in the repair log.
	 *
	 * @return string The name.
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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function run(IOutput $output): void {
		$seeded = 0;
		foreach ($this->fixtures() as $fixture) {
			try {
				if ($this->seedOne(fixture: $fixture) === true) {
					$seeded++;
				}
			} catch (Throwable $failure) {
				// A fixture must never fail an upgrade — but silence would be
				// the repair-step defect this fleet already paid for once, so
				// the skip is visible in the repair log AND the app log.
				$output->warning(sprintf('Task fixture %s failed: %s', (string)($fixture['uuid'] ?? '?'), $failure->getMessage()));
				$this->logger->warning(
					'[SeedTaskFixtures] Fixture failed: ' . $failure->getMessage(),
					['uuid' => ($fixture['uuid'] ?? null)]
				);
			}
		}

		$output->info(sprintf('Task fixtures: %d seeded, %d already present.', $seeded, count($this->fixtures()) - $seeded));
	}//end run()

	/**
	 * Seed one fixture, unless its uuid already exists.
	 *
	 * @param array<string, mixed> $fixture The fixture: task fields plus
	 *                                      `candidates` and `audit` sub-arrays.
	 *
	 * @return boolean True when inserted, false when already present.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	private function seedOne(array $fixture): bool {
		try {
			$this->tasks->findByUuid(uuid: (string)$fixture['uuid']);

			return false;
		} catch (DoesNotExistException) {
			// Absent: seed it.
		}

		$task = new Task();
		$task->hydrate($fixture['task']);
		$task->setUuid((string)$fixture['uuid']);
		$persisted = $this->tasks->insert($task);

		// The one-write-path invariant, preserved by hand: JSON and index
		// rows land together.
		$rows = [];
		foreach (($persisted->getCandidateUsers() ?? []) as $uid) {
			$rows[] = [
				'kind' => 'user',
				'ref' => (string)$uid,
			];
		}

		foreach (($persisted->getCandidateGroups() ?? []) as $groupId) {
			$rows[] = [
				'kind' => 'group',
				'ref' => (string)$groupId,
			];
		}

		if (trim((string)$persisted->getCandidateRole()) !== '') {
			$rows[] = [
				'kind' => 'role',
				'ref' => (string)$persisted->getCandidateRole(),
			];
		}

		$this->candidates->replaceForTask(taskId: (int)$persisted->getId(), candidates: $rows);

		foreach (($fixture['audit'] ?? []) as $auditFixture) {
			$entry = new TaskAudit();
			$entry->setTaskId((int)$persisted->getId());
			$entry->setAction((string)$auditFixture['action']);
			$entry->setStateAfter($auditFixture['stateAfter'] ?? null);
			$entry->setActor($auditFixture['actor'] ?? null);
			$entry->setPerformerType($auditFixture['performerType'] ?? null);
			$entry->setOnBehalfOf($auditFixture['onBehalfOf'] ?? null);
			$entry->setMandate($auditFixture['mandate'] ?? null);
			$entry->setReason($auditFixture['reason'] ?? null);
			$entry->setAuthorized((bool)($auditFixture['authorized'] ?? true));
			$this->audits->insert($entry);
		}

		return true;
	}//end seedOne()

	/**
	 * The five fixture groups from design.md — Seed Data.
	 *
	 * @return array<int, array<string, mixed>> The fixtures.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
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
			'task' => [
				'title' => 'Controleer bouwtekening op welstandseisen',
				'state' => Task::STATE_ENABLED,
				'isTerminal' => false,
				'performerType' => Task::PERFORMER_GROUP,
				'assignee' => null,
				'candidateGroups' => ['GEMEENTE_VERGUNNINGEN_TEAM'],
				'routingStrategy' => 'least-loaded',
				'priority' => 'normal',
				'dueAt' => new DateTime('2026-09-04T17:00:00+02:00'),
				'expiresAt' => null,
				'runUuid' => null,
				'objectUuid' => '00000000-0000-0000-0000-0000000000aa',
				'registerId' => 1,
				'schemaId' => 1,
				'requester' => 'EXAMPLE_BALIE_USER',
				'lastAction' => 'offer',
				'appId' => 'openregister',
			],
			'audit' => [
				[
					'action' => 'offer',
					'stateAfter' => Task::STATE_ENABLED,
					'actor' => 'EXAMPLE_BALIE_USER',
					'performerType' => Task::PERFORMER_GROUP,
					'authorized' => true,
				],
			],
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
			'task' => [
				'title' => 'Keur inkooporder > EUR 10.000 goed',
				'state' => Task::STATE_ACTIVE,
				'isTerminal' => false,
				'performerType' => Task::PERFORMER_USER,
				'assignee' => 'EXAMPLE_DELEGATE_USER',
				'onBehalfOf' => 'EXAMPLE_DIRECTOR_USER',
				'mandate' => 'Volmacht inkoop 2026, artikel 4 lid 2',
				'priority' => 'high',
				'dueAt' => new DateTime('2026-08-29T12:00:00+02:00'),
				'expiresAt' => new DateTime('2026-09-01T12:00:00+02:00'),
				'runUuid' => '00000000-0000-0000-0000-0000000000f1',
				'nodeId' => 'approve-purchase-order',
				'templateId' => '00000000-0000-0000-0000-0000000000e0',
				'templateVersion' => 3,
				'templateSnapshot' => ['checklist' => []],
				'objectUuid' => '00000000-0000-0000-0000-0000000000bb',
				'requester' => 'EXAMPLE_CONTROLLER_USER',
				'lastAction' => 'delegate',
				'appId' => 'openregister',
			],
			'audit' => [
				[
					'action' => 'delegate',
					'stateAfter' => Task::STATE_ACTIVE,
					'actor' => 'EXAMPLE_DIRECTOR_USER',
					'performerType' => Task::PERFORMER_USER,
					'onBehalfOf' => 'EXAMPLE_DIRECTOR_USER',
					'mandate' => 'Volmacht inkoop 2026, artikel 4 lid 2',
					'authorized' => true,
				],
			],
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
			'task' => [
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
			],
			'audit' => [
				[
					'action' => 'claim',
					'stateAfter' => Task::STATE_ACTIVE,
					'actor' => 'EXAMPLE_AGENT_IDENTITY',
					'performerType' => Task::PERFORMER_AGENT,
					'authorized' => true,
				],
			],
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
			'task' => [
				'title' => 'Beoordeel offerte serverhal',
				'state' => Task::STATE_COMPLETED,
				'isTerminal' => true,
				'performerType' => Task::PERFORMER_USER,
				'assignee' => 'EXAMPLE_REVIEWER_USER',
				'outcome' => 'approved',
				'priority' => 'normal',
				'completedAt' => new DateTime('2026-08-20T09:30:00+02:00'),
				'completedBy' => 'EXAMPLE_REVIEWER_USER',
				'runUuid' => null,
				'requester' => 'EXAMPLE_CONTROLLER_USER',
				'lastAction' => 'complete',
				'appId' => 'openregister',
			],
			'audit' => [
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
			],
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
			'task' => [
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
			],
			'audit' => [
				[
					'action' => 'terminate',
					'stateAfter' => Task::STATE_TERMINATED,
					'actor' => 'flow-run:00000000-0000-0000-0000-0000000000f2',
					'performerType' => Task::PERFORMER_USER,
					'reason' => "Run '00000000-0000-0000-0000-0000000000f2' reached terminal status 'stopped'.",
					'authorized' => true,
				],
			],
		];
	}//end terminatedByPropagation()
}//end class
