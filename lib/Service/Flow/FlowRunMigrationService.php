<?php

/**
 * Moving a run in flight from one version of its flow to another.
 *
 * 🔴 NEVER AUTOMATIC, AND THAT IS THE POINT (D-1). `flow-definition-versioning`
 * forbids re-pointing a run because a silent move is the failure it exists to
 * stop: a run that walked one graph and finished under another is a process
 * nobody can read back afterwards. Publishing a new version still moves
 * nothing. This is the deliberate exception, performed by a named person with a
 * reason, validated first, and refused when the run has nowhere to land.
 *
 * 🔴 THE MARKING IS THE CONTRACT (D-2). A run IS its marking. Validation asks,
 * for every place holding a token, whether the target has a node of the same
 * kind under the same id or under the mapping. Same id and kind needs no
 * mapping; a rename needs one; a removed node fails unless it is mapped to a
 * successor. Nothing else about the graph matters to the run, so nothing else
 * is compared: a target that rewrote every edge but kept the nodes a token sits
 * on is a target this run can move to.
 *
 * 🔑 THE VALIDATOR IS ONE METHOD AND SERVES BOTH ANSWERS (D-3). `dryRun` runs
 * the same code the apply does, so what a UI shows before an administrator
 * commits cannot disagree with what happens when they do. Two validators is how
 * a preview promises a migration the write then refuses.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validate and apply a run's move to another version of its flow.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The act spans the run, the
 *  versions, the graph and the timers bound to the nodes it moves, and those
 *  are four collaborators. Splitting it would put the order of writes in more
 *  than one file, which is the property that has to stay readable in one place.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
 */
class FlowRunMigrationService {

	/**
	 * What a migration is called in the run log and on a superseded timer.
	 *
	 * ONE literal, used by both, because a reader correlating a timer with the
	 * log entry that caused it has to be able to match on something.
	 *
	 * @var string
	 */
	public const LOG_ENTRY = 'migrated';

	/**
	 * Constructor.
	 *
	 * @param FlowRunMapper               $runs         The run store.
	 * @param FlowRunMigrationValidator   $validator    Whether a run fits the target version.
	 * @param FlowTimerMapper             $timers       Open timers of a run.
	 * @param FlowTimerService            $timerService Supersession.
	 * @param LoggerInterface             $logger       The logger.
	 */
	public function __construct(
		private readonly FlowRunMapper $runs,
		private readonly FlowRunMigrationValidator $validator,
		private readonly FlowTimerMapper $timers,
		private readonly FlowTimerService $timerService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this run's marking fits the target, and where it would land.
	 *
	 * Delegated to {@see FlowRunMigrationValidator}, and kept here because the
	 * migrate endpoint's own validate action and the two bulk paths below all
	 * ask the question through this service.
	 *
	 * @param FlowRun               $run           The run.
	 * @param int                   $targetVersion The version asked for.
	 * @param array<string, string> $mapping       Old node id to new node id.
	 *
	 * @return array{ok: bool, marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function validate(FlowRun $run, int $targetVersion, array $mapping = []): array {
		return $this->validator->validate(run: $run, targetVersion: $targetVersion, mapping: $mapping);
	}//end validate()

	/**
	 * Move one run to another version.
	 *
	 * @param string                $runUuid       The run.
	 * @param int                   $targetVersion The version to move onto.
	 * @param string                $reason        Why, recorded on the run.
	 * @param string                $actor         Who asked.
	 * @param array<string, string> $mapping       Old node id to new node id.
	 *
	 * @return array{migrated: bool, dryRun: bool, run: string, from: int|null, to: int,
	 *         marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function migrate(
		string $runUuid,
		int $targetVersion,
		string $reason,
		string $actor,
		array $mapping = [],
	): array {
		return $this->perform(
			runUuid: $runUuid,
			targetVersion: $targetVersion,
			reason: $reason,
			actor: $actor,
			mapping: $mapping,
			dryRun: false
		);
	}//end migrate()

	/**
	 * Say what moving one run to another version would do, writing nothing.
	 *
	 * 🔑 THE SAME VALIDATOR SERVES BOTH ANSWERS (D-3). This runs the code the
	 * apply runs, so what a UI shows before an administrator commits cannot
	 * disagree with what happens when they do. It is a separate entry point
	 * rather than `migrate(..., dryRun: true)` because a preview and a write
	 * are two acts, and the flag that told them apart was the argument most
	 * easily lost between the endpoint and here.
	 *
	 * @param string                $runUuid       The run.
	 * @param int                   $targetVersion The version to move onto.
	 * @param string                $reason        Why, for the answer's own record.
	 * @param string                $actor         Who asked.
	 * @param array<string, string> $mapping       Old node id to new node id.
	 *
	 * @return array{migrated: bool, dryRun: bool, run: string, from: int|null, to: int,
	 *         marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function preview(
		string $runUuid,
		int $targetVersion,
		string $reason,
		string $actor,
		array $mapping = [],
	): array {
		return $this->perform(
			runUuid: $runUuid,
			targetVersion: $targetVersion,
			reason: $reason,
			actor: $actor,
			mapping: $mapping,
			dryRun: true
		);
	}//end preview()

	/**
	 * The shared body of {@see self::migrate()} and {@see self::preview()}.
	 *
	 * @param string                $runUuid       The run.
	 * @param int                   $targetVersion The version to move onto.
	 * @param string                $reason        Why, recorded on the run.
	 * @param string                $actor         Who asked.
	 * @param array<string, string> $mapping       Old node id to new node id.
	 * @param boolean               $dryRun        True to answer without writing.
	 *
	 * @return array{migrated: bool, dryRun: bool, run: string, from: int|null, to: int,
	 *         marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function perform(
		string $runUuid,
		int $targetVersion,
		string $reason,
		string $actor,
		array $mapping,
		bool $dryRun,
	): array {
		$reason = trim($reason);
		if ($reason === '' && $dryRun === false) {
			return $this->refusal(
				runUuid: $runUuid,
				target: $targetVersion,
				reason: 'Say why this run is being moved to another version. The reason is kept on the run.',
			);
		}

		try {
			$run = $this->runs->findByUuid(uuid: $runUuid);
		} catch (Throwable $e) {
			return $this->refusal(
				runUuid: $runUuid,
				target: $targetVersion,
				reason: 'That run could not be found, so nothing was migrated.',
			);
		}

		$verdict = $this->validate(run: $run, targetVersion: $targetVersion, mapping: $mapping);
		$from = $run->getFlowVersion();

		if ($verdict['ok'] === false) {
			return [
				'migrated' => false,
				'dryRun' => $dryRun,
				'run' => $runUuid,
				'from' => $from,
				'to' => $targetVersion,
				'marking' => [],
				'unmapped' => $verdict['unmapped'],
				'reason' => $verdict['reason'],
			];
		}

		// 🔑 THE DRY RUN RETURNS BEFORE THE FIRST WRITE, not after a rollback.
		// A preview that wrote and undid would take the run lock, touch the
		// log, and show up in an audit trail as a migration that happened.
		if ($dryRun === true) {
			return [
				'migrated' => false,
				'dryRun' => true,
				'run' => $runUuid,
				'from' => $from,
				'to' => $targetVersion,
				'marking' => $verdict['marking'],
				'unmapped' => [],
				'reason' => '',
			];
		}

		$run->setFlowVersion($targetVersion);
		$run->setMarking($verdict['marking']);
		$run->setLog($this->appendLog(run: $run, from: $from, to: $targetVersion, mapping: $mapping, reason: $reason, actor: $actor));
		$this->runs->update($run);

		// AFTER the run is written, deliberately. A timer superseded against a
		// run that then failed to save would point at a node the run is not on.
		$moved = $this->supersedeTimers(runUuid: $runUuid, mapping: $mapping, actor: $actor);

		$this->logger->info(
			message: '[FlowRunMigrationService] run ' . $runUuid . ' migrated from version '
				. (string)$from . ' to ' . $targetVersion . ' by ' . $actor,
			context: ['file' => __FILE__, 'line' => __LINE__, 'timersSuperseded' => $moved]
		);

		return [
			'migrated' => true,
			'dryRun' => false,
			'run' => $runUuid,
			'from' => $from,
			'to' => $targetVersion,
			'marking' => $verdict['marking'],
			'unmapped' => [],
			'reason' => $reason,
		];
	}//end perform()

	/**
	 * Move every run pinned to one version onto another, reporting per run.
	 *
	 * 🔴 A RUN THAT CANNOT MOVE IS SKIPPED AND NAMED, NOT DROPPED. A bulk
	 * migration that reported only a count would leave an administrator
	 * believing every run moved, and the ones that did not are exactly the ones
	 * somebody has to go and look at.
	 *
	 * @param string                $flowId        The flow.
	 * @param int                   $sourceVersion The version to move off.
	 * @param int                   $targetVersion The version to move onto.
	 * @param string                $reason        Why.
	 * @param string                $actor         Who asked.
	 * @param array<string, string> $mapping       One mapping for all of them.
	 * @param int                   $limit         The batch bound.
	 *
	 * @return array{migrated: int, skipped: int, results: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-runs-can-be-migrated-in-bulk-per-version
	 */
	public function migrateRunsOfVersion(
		string $flowId,
		int $sourceVersion,
		int $targetVersion,
		string $reason,
		string $actor,
		array $mapping = [],
		int $limit = 100,
	): array {
		$results = [];
		$migrated = 0;
		$skipped = 0;

		foreach ($this->runsOnVersion(flowId: $flowId, version: $sourceVersion, limit: $limit) as $run) {
			$outcome = $this->migrate(
				runUuid: (string)$run->getUuid(),
				targetVersion: $targetVersion,
				reason: $reason,
				actor: $actor,
				mapping: $mapping,
			);

			$results[] = $outcome;
			if ($outcome['migrated'] === true) {
				$migrated++;
				continue;
			}

			$skipped++;
		}

		return ['migrated' => $migrated, 'skipped' => $skipped, 'results' => $results];
	}//end migrateRunsOfVersion()

	/**
	 * The seam a consuming app calls: move the runs of one subject, if any.
	 *
	 * 🔴 `migrated: true` WITH NO RUN IN FLIGHT IS THE CORRECT ANSWER, AND IT IS
	 * THE ONE THING A CALLER MUST NOT READ AS A FAILURE. dossiq's
	 * `case-type-rebind` asks this before it rewrites a case's blueprint, and it
	 * stops the whole rebind when the engine refuses. A case with no live run
	 * has nothing that could disagree with the rebind, so refusing it would
	 * block a correction on a case where there was never a problem. `migrated`
	 * therefore means "the run side is consistent with what you are about to
	 * do", and it is FALSE only when there is a run that could not be moved.
	 *
	 * 🔴 IT DOES NOT MOVE A RUN TO A DIFFERENT FLOW. This change is
	 * version-to-version within one flow, because the marking is the contract
	 * and two unrelated flows share no node ids to map. A consumer rebinding
	 * across case types, where the target has its OWN flow, gets `migrated:
	 * false` naming that: the run walks a process the target does not have, and
	 * moving it silently is exactly the write nobody could read back.
	 *
	 * @param string $subjectUuid         The object the run is about.
	 * @param string $targetDefinitionRef The target version, as a number, or a flow uuid.
	 * @param string $actorUid            Who asked, recorded as `runAs` on the entry (ADR-099).
	 *
	 * @return array{migrated: bool, reason: string, runs: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function migrateRunForSubject(string $subjectUuid, string $targetDefinitionRef, string $actorUid): array {
		$live = $this->liveRunsOf(subjectUuid: $subjectUuid);
		if ($live === null) {
			// An unreadable run store is NOT "no runs". Saying so lets the
			// caller stop rather than proceed on an answer nobody checked.
			return [
				'migrated' => false,
				'reason' => 'The flow runs of this object could not be read, so nothing was migrated.',
				'runs' => [],
			];
		}

		if ($live === []) {
			return [
				'migrated' => true,
				'reason' => 'This object has no flow run in progress, so there was nothing to migrate.',
				'runs' => [],
			];
		}

		// A version NUMBER is the only reference this change can act on.
		// Anything else names another flow, and this does not move a run
		// between flows.
		if (ctype_digit($targetDefinitionRef) === false) {
			$livePlural = 's';
			if (count($live) === 1) {
				$livePlural = '';
			}

			return [
				'migrated' => false,
				'reason' => 'This object has ' . count($live) . ' flow run'
					. $livePlural . ' in progress on a different process. '
					. 'A run is moved between VERSIONS of one flow, never between flows, because two flows '
					. 'share no steps to map a token onto. Finish or stop the run first.',
				'runs' => [],
			];
		}

		$outcomes = [];
		$allMoved = true;
		foreach ($live as $run) {
			$outcome = $this->migrate(
				runUuid: (string)$run->getUuid(),
				targetVersion: (int)$targetDefinitionRef,
				reason: 'Migrated with its subject by ' . $actorUid,
				actor: $actorUid,
			);
			$outcomes[] = $outcome;
			if ($outcome['migrated'] === false) {
				$allMoved = false;
			}
		}

		$outcomeReason = ($outcomes[0]['reason'] ?? 'A run of this object could not be moved.');
		if ($allMoved === true) {
			$outcomeReason = 'Every run of this object moved to version ' . $targetDefinitionRef . '.';
		}

		return [
			'migrated' => $allMoved,
			'reason' => $outcomeReason,
			'runs' => $outcomes,
		];
	}//end migrateRunForSubject()

	/**
	 * The runs still in progress on one object, or null when they cannot be read.
	 *
	 * Null and the empty array are DIFFERENT answers and the caller acts on
	 * each differently: no runs means there is nothing to migrate, while an
	 * unreadable store means nobody knows.
	 *
	 * @param string $subjectUuid The object the runs are about.
	 *
	 * @return array<int, FlowRun>|null The live runs, or null.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function liveRunsOf(string $subjectUuid): ?array {
		$live = [];

		try {
			foreach ($this->runs->findActive(subject: $subjectUuid) as $run) {
				if ($run instanceof FlowRun === true) {
					$live[] = $run;
				}
			}
		} catch (Throwable $e) {
			return null;
		}

		return $live;
	}//end liveRunsOf()

	/**
	 * The runs still pinned to one version, bounded.
	 *
	 * @param string $flowId  The flow.
	 * @param int    $version The version.
	 * @param int    $limit   The batch bound.
	 *
	 * @return array<int, FlowRun> The runs.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
	 */
	public function runsOnVersion(string $flowId, int $version, int $limit = 100): array {
		$found = [];
		foreach ($this->runs->findAllRuns(flowId: $flowId, limit: $limit) as $run) {
			if ($run instanceof FlowRun === false) {
				continue;
			}

			if ((int)$run->getFlowVersion() !== $version) {
				continue;
			}

			if (in_array((string)$run->getStatus(), FlowRunMigrationValidator::MIGRATABLE_STATUSES, true) === true) {
				$found[] = $run;
			}
		}

		return $found;
	}//end runsOnVersion()

	/**
	 * The run log with a `migrated` entry appended.
	 *
	 * Both versions, the mapping, the reason and the actor, because "the
	 * version changed" with nothing beside it sends the next person digging
	 * through the version table to work out what it used to walk.
	 *
	 * @param FlowRun               $run     The run.
	 * @param int|null              $from    The version it leaves.
	 * @param int                   $to      The version it joins.
	 * @param array<string, string> $mapping The node mapping.
	 * @param string                $reason  Why.
	 * @param string                $actor   Who.
	 *
	 * @return array<int, array<string, mixed>> The log.
	 */
	private function appendLog(FlowRun $run, ?int $from, int $to, array $mapping, string $reason, string $actor): array {
		$log = ($run->getLog() ?? []);
		if (is_array($log) === false) {
			$log = [];
		}

		$log[] = [
			'type' => self::LOG_ENTRY,
			'fromVersion' => $from,
			'toVersion' => $to,
			'mapping' => $mapping,
			'reason' => $reason,
			'actor' => $actor,
			'at' => (new DateTime())->format('Y-m-d\TH:i:sP'),
		];

		return $log;
	}//end appendLog()

	/**
	 * Supersede the open timers whose node moved under the mapping (D-4).
	 *
	 * 🔑 ONLY THE ONES WHOSE NODE CHANGED. A timer on a node the target kept
	 * under the same id is measuring the same wait against the same deadline,
	 * and re-arming it would restart a clock the applicant is already counting.
	 * Elapsed time is kept either way: `supersede()` re-arms from the anchoring
	 * event, not from now.
	 *
	 * @param string                $runUuid The run.
	 * @param array<string, string> $mapping The node mapping.
	 * @param string                $actor   Who asked.
	 *
	 * @return int How many were superseded.
	 */
	private function supersedeTimers(string $runUuid, array $mapping, string $actor): int {
		if ($mapping === []) {
			return 0;
		}

		$moved = 0;
		foreach ($this->timers->findOpenByRun(runUuid: $runUuid) as $timer) {
			$nodeId = trim((string)$timer->getNodeId());
			if ($nodeId === '' || array_key_exists($nodeId, $mapping) === false) {
				continue;
			}

			try {
				$this->timerService->supersede(
					uuid: (string)$timer->getUuid(),
					anchorEventAt: new DateTime(),
					reason: self::LOG_ENTRY,
					actor: $actor,
				);
				$moved++;
			} catch (Throwable $e) {
				// NOT fatal to the migration, and said out loud. The run has
				// already moved; refusing here would leave it on the target
				// version with the caller told it failed, which is the one
				// state nobody can act on.
				$this->logger->error(
					message: '[FlowRunMigrationService] run ' . $runUuid . ' migrated, but timer '
						. (string)$timer->getUuid() . ' could not be superseded: ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}//end try
		}

		return $moved;
	}//end supersedeTimers()

	/**
	 * A refusal shaped like every other answer.
	 *
	 * @param string $runUuid The run.
	 * @param int    $target  The version asked for.
	 * @param string $reason  Why not.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function refusal(string $runUuid, int $target, string $reason): array {
		return [
			'migrated' => false,
			'dryRun' => false,
			'run' => $runUuid,
			'from' => null,
			'to' => $target,
			'marking' => [],
			'unmapped' => [],
			'reason' => $reason,
		];
	}//end refusal()
}//end class
