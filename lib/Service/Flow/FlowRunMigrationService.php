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
use OCA\OpenRegister\Db\FlowRunFilter;
use OCA\OpenRegister\Db\FlowRunListing;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunSubjectScope;
use OCA\OpenRegister\Db\FlowRunViewer;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\TimerAnchorMove;
use OCA\OpenRegister\Kernel\Contracts\TimerChange;
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
class FlowRunMigrationService implements FlowRunMigrationServiceInterface {

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
	 * @param FlowRunMapperInterface    $runs      The run store.
	 * @param FlowRunMigrationValidator $validator Whether a run fits the target version.
	 * @param FlowRunMigrationTimers    $timers    Open timers of a run, and their supersession.
	 * @param LoggerInterface           $logger    The logger.
	 */
	public function __construct(
		private readonly FlowRunMapperInterface $runs,
		private readonly FlowRunMigrationValidator $validator,
		private readonly FlowRunMigrationTimers $timers,
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
	 * @param FlowRun          $run       The run.
	 * @param FlowRunMigration $migration The version asked for and the node mapping onto it.
	 *
	 * @return array{ok: bool, marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function validate(FlowRun $run, FlowRunMigration $migration): array {
		return $this->validator->validate(run: $run, migration: $migration);
	}//end validate()

	/**
	 * Move one run to another version.
	 *
	 * @param string                 $runUuid The run.
	 * @param FlowRunMigrationTarget $target  The version to move onto, why, who asked and the node mapping.
	 *
	 * @return array{migrated: bool, dryRun: bool, run: string, from: int|null, to: int,
	 *         marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function migrate(string $runUuid, FlowRunMigrationTarget $target): array {
		return $this->perform(migration: $target->forRun(runUuid: $runUuid, dryRun: false));
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
	 * @param string                 $runUuid The run.
	 * @param FlowRunMigrationTarget $target  The version to move onto, why, who asked and the node mapping.
	 *
	 * @return array{migrated: bool, dryRun: bool, run: string, from: int|null, to: int,
	 *         marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function preview(string $runUuid, FlowRunMigrationTarget $target): array {
		return $this->perform(migration: $target->forRun(runUuid: $runUuid, dryRun: true));
	}//end preview()

	/**
	 * The shared body of {@see self::migrate()} and {@see self::preview()}.
	 *
	 * @param FlowRunMigration $migration The run, target, reason, actor, mapping and dry-run choice.
	 *
	 * @return array{migrated: bool, dryRun: bool, run: string, from: int|null, to: int,
	 *         marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function perform(FlowRunMigration $migration): array {
		$dryRun = $migration->isDryRun();
		if ($migration->reason === '' && $dryRun === false) {
			return $this->refusal(
				migration: $migration,
				reason: 'Say why this run is being moved to another version. The reason is kept on the run.',
			);
		}

		try {
			$run = $this->runs->findByUuid(uuid: $migration->runUuid);
		} catch (Throwable $e) {
			return $this->refusal(migration: $migration, reason: 'That run could not be found, so nothing was migrated.');
		}

		$verdict = $this->validate(run: $run, migration: $migration);

		return $this->actOnVerdict(move: new FlowRunMigrationRun(run: $run, migration: $migration), verdict: $verdict);
	}//end perform()

	/**
	 * Answer a refused verdict or a dry run without writing; otherwise apply the move.
	 *
	 * @param FlowRunMigrationRun  $move    The run being moved, its request and the version it was on.
	 * @param array<string, mixed> $verdict What validate() decided.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private function actOnVerdict(FlowRunMigrationRun $move, array $verdict): array {
		$migration = $move->migration;
		if ($verdict['ok'] === false) {
			return $this->answer(
				migration: $migration,
				fields: ['from' => $move->from, 'dryRun' => $migration->isDryRun(), 'unmapped' => $verdict['unmapped'], 'reason' => $verdict['reason']]
			);
		}

		// 🔑 THE DRY RUN RETURNS BEFORE THE FIRST WRITE, not after a rollback.
		// A preview that wrote and undid would take the run lock, touch the
		// log, and show up in an audit trail as a migration that happened.
		if ($migration->isDryRun() === true) {
			return $this->answer(migration: $migration, fields: ['from' => $move->from, 'dryRun' => true, 'marking' => $verdict['marking']]);
		}

		$this->apply(move: $move, marking: $verdict['marking']);

		return $this->answer(
			migration: $migration,
			fields: ['from' => $move->from, 'migrated' => true, 'marking' => $verdict['marking'], 'reason' => $migration->reason]
		);
	}//end actOnVerdict()

	/**
	 * Write the migration: the run's version, marking and log, then its timers.
	 *
	 * @param FlowRunMigrationRun $move    The run, as read, and the migration being applied.
	 * @param array<string, int>  $marking The validated marking on the target version.
	 *
	 * @return void
	 */
	private function apply(FlowRunMigrationRun $move, array $marking): void {
		$run       = $move->run;
		$migration = $move->migration;
		$log       = $this->appendLog(run: $run, migration: $migration);
		$run->setFlowVersion($migration->targetVersion);
		$run->setMarking($marking);
		$run->setLog($log);
		$this->runs->update($run);

		// AFTER the run is written, deliberately. A timer superseded against a
		// run that then failed to save would point at a node the run is not on.
		$moved = $this->supersedeTimers(migration: $migration);

		$this->logger->info(
			message: '[FlowRunMigrationService] run ' . $migration->runUuid . ' migrated from version '
				. (string)$move->from . ' to ' . $migration->targetVersion . ' by ' . $migration->actor,
			context: ['file' => __FILE__, 'line' => __LINE__, 'timersSuperseded' => $moved]
		);
	}//end apply()

	/**
	 * One migration answer: the defaults of a migration that did not happen, overridden by `$fields`.
	 *
	 * The run's version before (`from`) is among `$fields` once the run was read.
	 *
	 * @param FlowRunMigration     $migration The migration asked for.
	 * @param array<string, mixed> $fields    The keys that differ from the defaults.
	 *
	 * @return array{migrated: bool, dryRun: bool, run: string, from: int|null, to: int,
	 *         marking: array<string, int>, unmapped: array<int, string>, reason: string}
	 */
	private function answer(FlowRunMigration $migration, array $fields): array {
		$defaults = [
			'migrated' => false,
			'dryRun' => false,
			'run' => $migration->runUuid,
			'from' => null,
			'to' => $migration->targetVersion,
			'marking' => [],
			'unmapped' => [],
			'reason' => '',
		];

		return array_replace($defaults, $fields);
	}//end answer()

	/**
	 * Move every run pinned to one version onto another, reporting per run.
	 *
	 * 🔴 A RUN THAT CANNOT MOVE IS SKIPPED AND NAMED, NOT DROPPED. A bulk
	 * migration that reported only a count would leave an administrator
	 * believing every run moved, and the ones that did not are exactly the ones
	 * somebody has to go and look at.
	 *
	 * @param VersionRunsMigration $request The flow, the version to move off, the target and the batch bound.
	 *
	 * @return array{migrated: int, skipped: int, results: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-runs-can-be-migrated-in-bulk-per-version
	 */
	public function migrateRunsOfVersion(VersionRunsMigration $request): array {
		$listing = new FlowRunListing(filter: new FlowRunFilter(flowId: $request->flowId), limit: $request->limit);
		$results = array_map(
			fn (FlowRun $run): array => $this->migrate(runUuid: (string)$run->getUuid(), target: $request->target),
			$this->runsOnVersion(listing: $listing, version: $request->sourceVersion)
		);
		$migrated = count(array_filter($results, static fn (array $outcome): bool => $outcome['migrated'] === true));

		return ['migrated' => $migrated, 'skipped' => (count($results) - $migrated), 'results' => $results];
	}//end migrateRunsOfVersion()

	/**
	 * The seam a consuming app calls: move the live runs of one subject, or say why not.
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
	 * @param SubjectRunsMigration $request The subject, the target reference and who asked.
	 *
	 * @return array{migrated: bool, reason: string, runs: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function migrateSubjectRuns(SubjectRunsMigration $request): array {
		$live = $this->liveRunsOf(subjectUuid: $request->subjectUuid);
		$refused = self::subjectRefusal(live: $live, targetDefinitionRef: $request->targetDefinitionRef);
		if ($refused !== null) {
			return $refused;
		}

		$outcomes = array_map(
			fn (FlowRun $run): array => $this->migrate(runUuid: (string)$run->getUuid(), target: $request->target()),
			($live ?? [])
		);

		return self::subjectOutcome(outcomes: $outcomes, targetDefinitionRef: $request->targetDefinitionRef);
	}//end migrateSubjectRuns()

	/**
	 * The answer for a subject whose runs are not moved one by one, or null when they are.
	 *
	 * @param array<int, FlowRun>|null $live                The live runs, or null when unreadable.
	 * @param string                   $targetDefinitionRef The target version, as a number, or a flow uuid.
	 *
	 * @return array{migrated: bool, reason: string, runs: array<int, array<string, mixed>>}|null The answer.
	 */
	private static function subjectRefusal(?array $live, string $targetDefinitionRef): ?array {
		if ($live === null) {
			// An unreadable run store is NOT "no runs". Saying so lets the
			// caller stop rather than proceed on an answer nobody checked.
			return ['migrated' => false, 'reason' => 'The flow runs of this object could not be read, so nothing was migrated.', 'runs' => []];
		}

		return self::liveRunsRefusal(live: $live, targetDefinitionRef: $targetDefinitionRef);
	}//end subjectRefusal()

	/**
	 * Why the readable live runs of a subject are not migrated, or null when they are.
	 *
	 * @param array<int, FlowRun> $live                The subject's live runs.
	 * @param string              $targetDefinitionRef The requested target.
	 *
	 * @return array<string, mixed>|null The answer, or null to proceed.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	private static function liveRunsRefusal(array $live, string $targetDefinitionRef): ?array {
		if ($live === []) {
			return ['migrated' => true, 'reason' => 'This object has no flow run in progress, so there was nothing to migrate.', 'runs' => []];
		}

		// A version NUMBER is the only reference this change can act on.
		// Anything else names another flow, and this does not move a run
		// between flows.
		if (ctype_digit($targetDefinitionRef) === false) {
			return ['migrated' => false, 'reason' => self::otherFlowReason(count: count($live)), 'runs' => []];
		}

		return null;
	}//end liveRunsRefusal()

	/**
	 * Why runs on a subject are not moved onto another flow.
	 *
	 * @param int $count How many runs are in progress.
	 *
	 * @return string The reason.
	 */
	private static function otherFlowReason(int $count): string {
		$livePlural = 's';
		if ($count === 1) {
			$livePlural = '';
		}

		return 'This object has ' . $count . ' flow run'
			. $livePlural . ' in progress on a different process. '
			. 'A run is moved between VERSIONS of one flow, never between flows, because two flows '
			. 'share no steps to map a token onto. Finish or stop the run first.';
	}//end otherFlowReason()

	/**
	 * The subject's answer once each of its runs has been asked to move.
	 *
	 * @param array<int, array<string, mixed>> $outcomes            One outcome per run.
	 * @param string                           $targetDefinitionRef The target version, as given.
	 *
	 * @return array{migrated: bool, reason: string, runs: array<int, array<string, mixed>>} The answer.
	 */
	private static function subjectOutcome(array $outcomes, string $targetDefinitionRef): array {
		$allMoved = (in_array(false, array_column($outcomes, 'migrated'), true) === false);
		$outcomeReason = ($outcomes[0]['reason'] ?? 'A run of this object could not be moved.');
		if ($allMoved === true) {
			$outcomeReason = 'Every run of this object moved to version ' . $targetDefinitionRef . '.';
		}

		return ['migrated' => $allMoved, 'reason' => $outcomeReason, 'runs' => $outcomes];
	}//end subjectOutcome()

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
			foreach ($this->runs->findActive(scope: new FlowRunSubjectScope(subject: $subjectUuid)) as $run) {
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
	 * @param FlowRunListing $listing The flow's runs, with the batch bound.
	 * @param int            $version The version.
	 *
	 * @return array<int, FlowRun> The runs.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
	 */
	public function runsOnVersion(FlowRunListing $listing, int $version): array {
		$everyone = new FlowRunViewer(requesterUid: null, ownedFlowIds: []);

		return array_values(
			array_filter(
				$this->runs->findAllRuns(listing: $listing, viewer: $everyone),
				static fn (mixed $run): bool => self::migratableOn(run: $run, version: $version)
			)
		);
	}//end runsOnVersion()

	/**
	 * Whether a listed row is a run on this version in a status a migration may move.
	 *
	 * @param mixed $run     A row of the listing.
	 * @param int   $version The version the runs must be on.
	 *
	 * @return bool
	 */
	private static function migratableOn(mixed $run, int $version): bool {
		return $run instanceof FlowRun
			&& (int)$run->getFlowVersion() === $version
			&& in_array((string)$run->getStatus(), FlowRunMigrationValidator::MIGRATABLE_STATUSES, true) === true;
	}//end migratableOn()

	/**
	 * The run log with a `migrated` entry appended.
	 *
	 * Both versions, the mapping, the reason and the actor, because "the
	 * version changed" with nothing beside it sends the next person digging
	 * through the version table to work out what it used to walk.
	 *
	 * @param FlowRun          $run       The run, still on the version it leaves.
	 * @param FlowRunMigration $migration The version it joins, mapping, reason and actor.
	 *
	 * @return array<int, array<string, mixed>> The log.
	 */
	private function appendLog(FlowRun $run, FlowRunMigration $migration): array {
		$log = ($run->getLog() ?? []);
		if (is_array($log) === false) {
			$log = [];
		}

		$log[] = [
			'type' => self::LOG_ENTRY,
			'fromVersion' => $run->getFlowVersion(),
			'toVersion' => $migration->targetVersion,
			'mapping' => $migration->mapping(),
			'reason' => $migration->reason,
			'actor' => $migration->actor,
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
	 * @param FlowRunMigration $migration The run, node mapping and actor.
	 *
	 * @return int How many were superseded.
	 */
	private function supersedeTimers(FlowRunMigration $migration): int {
		$mapping = $migration->mapping();
		if ($mapping === []) {
			return 0;
		}

		$moved = 0;
		foreach ($this->timers->timers->findOpenByRun(runUuid: $migration->runUuid) as $timer) {
			if (self::nodeMoved(timer: $timer, mapping: $mapping) === true && $this->supersede(timer: $timer, migration: $migration) === true) {
				$moved++;
			}
		}

		return $moved;
	}//end supersedeTimers()

	/**
	 * Whether a timer's node is one the mapping moves.
	 *
	 * @param FlowTimer             $timer   The open timer.
	 * @param array<string, string> $mapping Old node id to new node id.
	 *
	 * @return boolean True when the timer's node is mapped.
	 */
	private static function nodeMoved(FlowTimer $timer, array $mapping): bool {
		$nodeId = trim((string)$timer->getNodeId());

		return $nodeId !== '' && array_key_exists($nodeId, $mapping) === true;
	}//end nodeMoved()

	/**
	 * Supersede one timer, logging rather than failing when it cannot be.
	 *
	 * NOT fatal to the migration, and said out loud. The run has already
	 * moved; refusing here would leave it on the target version with the
	 * caller told it failed, which is the one state nobody can act on.
	 *
	 * @param FlowTimer        $timer     The open timer.
	 * @param FlowRunMigration $migration The run and actor.
	 *
	 * @return boolean True when superseded.
	 */
	private function supersede(FlowTimer $timer, FlowRunMigration $migration): bool {
		$uuid = (string)$timer->getUuid();
		try {
			$this->timers->timerService->supersede(
				move: new TimerAnchorMove(uuid: $uuid, anchorEventAt: new DateTime()),
				change: new TimerChange(reason: self::LOG_ENTRY, actor: $migration->actor)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowRunMigrationService] run ' . $migration->runUuid . ' migrated, but timer '
					. $uuid . ' could not be superseded: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return false;
		}

		return true;
	}//end supersede()

	/**
	 * A refusal shaped like every other answer.
	 *
	 * @param FlowRunMigration $migration The run and the version asked for.
	 * @param string           $reason    Why not.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function refusal(FlowRunMigration $migration, string $reason): array {
		return $this->answer(migration: $migration, fields: ['reason' => $reason]);
	}//end refusal()
}//end class
