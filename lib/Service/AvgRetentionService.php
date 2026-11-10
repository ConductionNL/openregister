<?php

/**
 * AVG / GDPR retention-enforcement service.
 *
 * Walks the audit-trail ledger, groups rows by `processing_activity_id`,
 * computes the bewaartermijn cut-off per activity (per Art 5(1)(e),
 * storage limitation), and soft-deletes any object whose oldest audit
 * row predates the cut-off.
 *
 * Exposed as a service so it can be driven from:
 *   - the daily TimedJob (`AvgRetentionJob`)
 *   - operator-triggered admin endpoints
 *   - a CLI command for back-filling
 *
 * Each erasure carries the SAME processing-activity attribution as
 * the original write — so a Verzoek/Klacht audit row with
 * `processing_activity_id = X` will be soft-deleted under that same X
 * activity, preserving the audit chain. The deletion event itself is
 * tagged with `reason='avg-bewaartermijn'` so it's distinguishable from
 * Art 17 vergetelheid + manual operator deletes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateInterval;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Retention-period-driven object retention enforcement.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One over, from the archival
 * retention guard: the pass must ask whether a record is legally retained
 * before it erases it, and that question has one owner.
 */
class AvgRetentionService implements AvgRetentionPassInterface {

	/**
	 * The audit-trail columns that identify an overdue object.
	 *
	 * @var list<string>
	 */
	private const OVERDUE_COLUMNS = [
		'object',
		'object_uuid',
		'register',
		'schema',
	];

	/**
	 * The summary of a pass before any activity is evaluated.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_SUMMARY = [
		'evaluatedActivities' => 0,
		'skippedActivities' => 0,
		'objectsErased' => 0,
		// RETENTION WINS OVER ERASURE. The archival obligation on a schema
		// outlives this activity's bewaartermijn, so those records are left
		// live and named here rather than counted as erased.
		'objectsWithheld' => 0,
		'withheld' => [],
		'dryRun' => false,
		'perActivity' => [],
	];

	/**
	 * The outcome of an activity whose run erased nothing.
	 *
	 * @var array{erased: int, withheld: array<int, array<string, mixed>>}
	 */
	private const NOTHING_ERASED = [
		'erased' => 0,
		'withheld' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db DB for audit-trail aggregation.
	 * @param VerwerkingsactiviteitMapperInterface $vrwMapper Catalog reader.
	 * @param MagicMapperInterface $objectMapper Object loader.
	 * @param LoggerInterface $logger Logger.
	 * @param ArchivalRetentionGuard $archivalGuard Refuses erasure of a held or legally retained record.
	 * @param RetentionClockService|null $clocks Reports a disagreement between the AVG and Archiefwet clocks.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly VerwerkingsactiviteitMapperInterface $vrwMapper,
		private readonly MagicMapperInterface $objectMapper,
		private readonly LoggerInterface $logger,
		private readonly ArchivalRetentionGuard $archivalGuard,
		private readonly ?RetentionClockService $clocks = null,
	) {

	}//end __construct()

	/**
	 * Run a single retention-enforcement pass.
	 *
	 * Returns a summary envelope:
	 *
	 *   {
	 *     "evaluatedActivities": <int>,
	 *     "skippedActivities":   <int>,
	 *     "objectsErased":       <int>,
	 *     "objectsWithheld":     <int>,
	 *     "withheld": [
	 *       {"uuid": "...", "schema": "...", "ground": "ARCHIVAL_RETENTION_OBLIGATION",
	 *        "message": "...", "basis": "...", "action": "..."},
	 *       ...
	 *     ],
	 *     "dryRun":              bool,
	 *     "perActivity": [
	 *       {"uuid": "...", "naam": "...", "bewaartermijn": "P10Y",
	 *        "cutoff": "<iso>", "matchedObjects": <int>, "erased": <int>,
	 *        "withheld": [...]},
	 *       ...
	 *     ]
	 *   }
	 *
	 * Activities without a `bewaartermijn` (or with a malformed
	 * duration) are reported under `skippedActivities` so operators
	 * can see at-a-glance which catalog rows aren't yet retention-
	 * enforced.
	 *
	 * @param bool $dryRun When true, evaluates and reports without
	 *                     actually soft-deleting anything.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function runRetentionPass(bool $dryRun = false): array {
		$now = new DateTime();
		$summary = self::EMPTY_SUMMARY;
		$summary['dryRun'] = $dryRun;

		$activities = $this->vrwMapper->findAll(status: 'published');
		foreach ($activities as $activity) {
			$perActivity = $this->processActivity(
				activity: $activity,
				now: $now,
				dryRun: $dryRun
			);
			$summary = $this->withActivityReport(summary: $summary, perActivity: $perActivity);
		}

		return $summary;
	}//end runRetentionPass()

	/**
	 * Add one activity's report to the pass summary, or count it as skipped.
	 *
	 * @param array<string, mixed>      $summary     The summary so far.
	 * @param array<string, mixed>|null $perActivity The activity's report, or null when it was skipped.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function withActivityReport(array $summary, ?array $perActivity): array {
		if ($perActivity === null) {
			$summary['skippedActivities']++;
			return $summary;
		}

		$summary['evaluatedActivities']++;
		$summary['objectsErased'] += $perActivity['erased'];
		$summary['objectsWithheld'] += count($perActivity['withheld']);
		$summary['withheld'] = array_merge($summary['withheld'], $perActivity['withheld']);
		$summary['perActivity'][] = $perActivity;

		return $summary;
	}//end withActivityReport()

	/**
	 * Evaluate one verwerkingsactiviteit. Null when its bewaartermijn
	 * is unset or unparseable (caller increments `skippedActivities`).
	 *
	 * @param Verwerkingsactiviteit $activity Catalog entry.
	 * @param DateTime $now Reference timestamp.
	 * @param bool $dryRun Pass-through dry-run flag.
	 *
	 * @return array<string, mixed>|null Per-activity result or null skip.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function processActivity(Verwerkingsactiviteit $activity, DateTime $now, bool $dryRun): ?array {
		$cutoff = $this->activityCutoff(activity: $activity, now: $now);
		if ($cutoff === null) {
			return null;
		}

		$candidates = $this->findOverdueObjectsForActivity(
			activityUuid: (string)$activity->getUuid(),
			cutoff: $cutoff
		);

		$outcome = self::NOTHING_ERASED;
		if ($dryRun === false) {
			$outcome = $this->erasureOutcome(candidates: $candidates, activity: $activity);
		}

		return $this->activityHead(activity: $activity, cutoff: $cutoff) + ['matchedObjects' => count($candidates)] + $outcome;
	}//end processActivity()

	/**
	 * The cut-off of an activity's bewaartermijn.
	 *
	 * @param Verwerkingsactiviteit $activity Catalog entry.
	 * @param DateTime              $now      Reference timestamp.
	 *
	 * @return DateTime|null The cut-off, or null when the period is unset or unparseable (logged).
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function activityCutoff(Verwerkingsactiviteit $activity, DateTime $now): ?DateTime {
		$retentionPeriod = (string)($activity->getRetentionPeriod() ?? '');
		if ($retentionPeriod === '') {
			return null;
		}

		$cutoff = $this->computeCutoff(now: $now, duration: $retentionPeriod);
		if ($cutoff === null) {
			$this->warnUnparseablePeriod(activity: $activity, retentionPeriod: $retentionPeriod);
		}

		return $cutoff;
	}//end activityCutoff()

	/**
	 * Warn that an activity is skipped for an unparseable bewaartermijn.
	 *
	 * @param Verwerkingsactiviteit $activity        Catalog entry.
	 * @param string                $retentionPeriod The period as declared.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function warnUnparseablePeriod(Verwerkingsactiviteit $activity, string $retentionPeriod): void {
		$this->logger->warning(
			message: '[AVG retention] Unparseable retentionPeriod — skipping activity',
			context: [
				'activity' => $activity->getUuid(),
				'retentionPeriod' => $retentionPeriod,
			]
		);
	}//end warnUnparseablePeriod()

	/**
	 * Erase the overdue objects of a live run, when there are any.
	 *
	 * @param array<int, array<string, mixed>> $candidates Overdue objects.
	 * @param Verwerkingsactiviteit            $activity   Catalog entry.
	 *
	 * @return array{erased: int, withheld: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function erasureOutcome(array $candidates, Verwerkingsactiviteit $activity): array {
		if ($candidates === []) {
			return self::NOTHING_ERASED;
		}

		return $this->erasePastRetention(
			candidates: $candidates,
			activity: $activity
		);
	}//end erasureOutcome()

	/**
	 * The identity, period and cut-off an activity's report starts with.
	 *
	 * @param Verwerkingsactiviteit $activity Catalog entry.
	 * @param DateTime              $cutoff   The bewaartermijn cut-off.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function activityHead(Verwerkingsactiviteit $activity, DateTime $cutoff): array {
		return [
			'uuid' => $activity->getUuid(),
			'name' => $activity->getName(),
			'retentionPeriod' => (string)($activity->getRetentionPeriod() ?? ''),
			'cutoff' => $cutoff->format('c'),
		];
	}//end activityHead()

	/**
	 * Compute the cut-off timestamp for a retentionPeriod duration.
	 *
	 * Accepts ISO-8601 duration syntax (`P10Y`, `P30D`, `P6M`) — the
	 * canonical AVG / NEN-2082 representation. Returns null on parse
	 * failure so the caller can flag the activity as skipped.
	 *
	 * @param DateTime $now Reference timestamp.
	 * @param string $duration ISO-8601 duration string.
	 *
	 * @return DateTime|null Cutoff timestamp or null on parse error.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function computeCutoff(DateTime $now, string $duration): ?DateTime {
		try {
			$interval = new DateInterval($duration);
		} catch (Exception $e) {
			return null;
		}

		$cutoff = clone $now;
		$cutoff->sub($interval);
		return $cutoff;
	}//end computeCutoff()

	/**
	 * Find objects whose latest audit-trail row predates the cut-off.
	 *
	 * "Latest audit row" rather than "oldest" because we want to keep
	 * objects that have been touched recently — the bewaartermijn
	 * clock resets on every write under that processing activity.
	 *
	 * @param string $activityUuid Activity uuid.
	 * @param DateTime $cutoff Cut-off timestamp.
	 *
	 * @return array<int, array{object: int, object_uuid: string|null, register: int|null, schema: int|null}>
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function findOverdueObjectsForActivity(string $activityUuid, DateTime $cutoff): array {
		try {
			$candidates = [];
			foreach ($this->lastSeenBefore(activityUuid: $activityUuid, cutoff: $cutoff) as $row) {
				$candidates[] = $this->overdueCandidate(row: $row);
			}

			return $candidates;
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AVG retention] Failed to enumerate overdue objects for activity',
				context: ['activity' => $activityUuid, 'error' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end findOverdueObjectsForActivity()


	/**
	 * The objects an activity's audit rows touched, with the latest touch before the cut-off.
	 *
	 * @param string   $activityUuid Activity uuid.
	 * @param DateTime $cutoff       Cut-off timestamp.
	 *
	 * @return array<int, array<string, mixed>> One row per object.
	 */
	private function lastSeenBefore(string $activityUuid, DateTime $cutoff): array {
		$qb = $this->lastSeenPerObject(activityUuid: $activityUuid);
		$qb->having(
			'MAX(created) < ' . $qb->createNamedParameter(
				$cutoff,
				IQueryBuilder::PARAM_DATETIME_MUTABLE
			)
		);

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end lastSeenBefore()

	/**
	 * The latest audit row per object under one activity.
	 *
	 * @param string $activityUuid Activity uuid.
	 *
	 * @return IQueryBuilder The grouped query, without the cut-off.
	 */
	private function lastSeenPerObject(string $activityUuid): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::OVERDUE_COLUMNS)
			->selectAlias($qb->func()->max('created'), 'last_seen')
			->from('openregister_audit_trails')
			->where(
				$qb->expr()->eq(
					'processing_activity_id',
					$qb->createNamedParameter($activityUuid)
				)
			)
			->groupBy('object', 'object_uuid', 'register', 'schema');

		return $qb;
	}//end lastSeenPerObject()

	/**
	 * One overdue object from its audit row.
	 *
	 * @param array<string, mixed> $row The grouped audit row.
	 *
	 * @return array{object: int, object_uuid: string|null, register: int|null, schema: int|null}
	 */
	private function overdueCandidate(array $row): array {
		return [
			'object' => (int)($row['object'] ?? 0),
			'object_uuid' => ($row['object_uuid'] ?? null),
			'register' => $this->intOrNull(row: $row, key: 'register'),
			'schema' => $this->intOrNull(row: $row, key: 'schema'),
		];
	}//end overdueCandidate()

	/**
	 * A row value as an int, or null when it is absent or null.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string               $key The column.
	 *
	 * @return int|null The value.
	 */
	private function intOrNull(array $row, string $key): ?int {
		if (isset($row[$key]) === true) {
			return (int)$row[$key];
		}

		return null;
	}//end intOrNull()

	/**
	 * Soft-delete each candidate. Returns the count of successful
	 * erasures; failures are logged and skipped so a single bad
	 * object doesn't abort the whole pass.
	 *
	 * RETENTION WINS OVER ERASURE. This pass enforces the verwerkingsregister's
	 * bewaartermijn, which is shorter than the archival obligation a schema
	 * declares with `x-openregister-archival`. When the two disagree the
	 * archival obligation wins: the record is left live and named in `withheld`,
	 * so an operator reading the run sees a record kept rather than one erased.
	 * Withholding one record does not stop the pass.
	 *
	 * A LEGAL HOLD WINS TOO, AND IT USED NOT TO. This pass soft-deletes rather
	 * than destroys, so a record it took was recoverable, but a record under
	 * objection or under a court order still disappeared from every list the
	 * handler reads. The guard now refuses a held record under its own ground,
	 * so a hold on an ORDINARY schema, which declares no archival annotation at
	 * all, is honoured here as well.
	 *
	 * USED TO RETURN A BARE INT, which is why a withheld record could not be told
	 * apart from an erased one: there was no per-object channel at all.
	 *
	 * @param array<int, array<string, mixed>> $candidates Overdue objects.
	 * @param Verwerkingsactiviteit $activity Owning activity.
	 *
	 * @return array{erased: int, withheld: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function erasePastRetention(array $candidates, Verwerkingsactiviteit $activity): array {
		$run = new AvgRetentionErasureRun(
			deletionData: $this->retentionDeletionData(activity: $activity),
			activityUuid: (string)$activity->getUuid()
		);

		foreach ($candidates as $candidate) {
			$this->eraseCandidate(candidate: $candidate, run: $run);
		}

		return $run->outcome();
	}//end erasePastRetention()


	/**
	 * The `deleted` stamp of a retention erasure under one activity.
	 *
	 * New writes use the renamed `retentionPeriod` key (verwerkingsregister-i18n); already
	 * -persisted historical `deleted` blobs keep their old `bewaartermijn` key unrewritten —
	 * they are point-in-time compliance-evidence snapshots (see design.md). The `reason` tag
	 * itself is left as `avg-bewaartermijn` — a stable internal category value, not a field
	 * identifier, out of this rename's scope.
	 *
	 * @param Verwerkingsactiviteit $activity Owning activity.
	 *
	 * @return array<string, mixed> The stamp.
	 */
	private function retentionDeletionData(Verwerkingsactiviteit $activity): array {
		return [
			'deletedBy' => 'system',
			'deletedAt' => (new DateTime())->format(DateTime::ATOM),
			'reason' => 'avg-bewaartermijn',
			'activityUuid' => $activity->getUuid(),
			'retentionPeriod' => $activity->getRetentionPeriod(),
		];
	}//end retentionDeletionData()

	/**
	 * Soft-delete one candidate, or record why it stays.
	 *
	 * @param array<string, mixed>   $candidate The overdue object.
	 * @param AvgRetentionErasureRun $run       The run: stamp, erased count and withheld refusals.
	 *
	 * @return void
	 */
	private function eraseCandidate(array $candidate, AvgRetentionErasureRun $run): void {
		$object = $this->loadCandidate(candidate: $candidate);
		if ($object === null) {
			return;
		}

		$refusal = $this->retentionRefusal(object: $object);
		if ($refusal !== null) {
			$run->withheld[] = $refusal;
			return;
		}

		$run->stamp(object: $object);
		if ($this->saveErasure(object: $object, candidate: $candidate) === true) {
			$run->erased++;
		}
	}//end eraseCandidate()

	/**
	 * Why an overdue object must stay: an archival obligation or legal hold, or
	 * retention clocks that disagree.
	 *
	 * @param ObjectEntity $object The overdue object.
	 *
	 * @return array<string, mixed>|null The refusal, or null when it may be erased.
	 */
	private function retentionRefusal(ObjectEntity $object): ?array {
		$refusal = $this->archivalGuard->erasureRefusal(object: $object);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->clockRefusal(object: $object);
	}//end retentionRefusal()

	/**
	 * The refusal when the retention clocks disagree.
	 *
	 * TWO CLOCKS, AND THE DISAGREEMENT IS AN OUTPUT. The AVG says delete when
	 * the purpose ends; the selectielijst says keep for N years. Where they
	 * disagree the object stays and the conflict is reported, because
	 * resolving it silently is wrong in one direction for every object it
	 * touches.
	 *
	 * @param ObjectEntity $object The overdue object.
	 *
	 * @return array<string, mixed>|null The refusal, or null when the clocks agree.
	 */
	private function clockRefusal(ObjectEntity $object): ?array {
		$clockRefusal = $this->clocks?->refusalFor(object: $object);
		if ($clockRefusal === null) {
			return null;
		}

		return array_merge(
			[
				'uuid' => (string)$object->getUuid(),
				'schema' => $object->getSchema(),
				'ground' => 'RETENTION_CLOCKS_DISAGREE',
			],
			$clockRefusal->toResponseBody()
		);
	}//end clockRefusal()

	/**
	 * Save a stamped object; a failure is logged and skipped so a single bad
	 * object doesn't abort the whole pass.
	 *
	 * @param ObjectEntity         $object    The stamped object.
	 * @param array<string, mixed> $candidate The overdue object, for the log.
	 *
	 * @return bool True when the object was saved.
	 */
	private function saveErasure(ObjectEntity $object, array $candidate): bool {
		try {
			$this->objectMapper->update(entity: $object);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AVG retention] Soft-delete failed during retention pass',
				context: ['candidate' => $candidate, 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end saveErasure()

	/**
	 * Load a candidate object — uuid first (deterministic), int id as
	 * fallback for legacy audit rows.
	 *
	 * @param array<string, mixed> $candidate Single candidate row.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function loadCandidate(array $candidate): ?ObjectEntity {
		$identifier = $this->candidateIdentifier(candidate: $candidate);
		if ($identifier === 0 || $identifier === '') {
			return null;
		}

		return $this->findUnscoped(identifier: $identifier, candidate: $candidate);
	}//end loadCandidate()

	/**
	 * The identifier a candidate is loaded by: its uuid, else its id.
	 *
	 * @param array<string, mixed> $candidate Overdue audit-trail row.
	 *
	 * @return int|string The uuid, or the id when the row has no uuid.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function candidateIdentifier(array $candidate): int|string {
		$uuid = (string)($candidate['object_uuid'] ?? '');
		$id = (int)($candidate['object'] ?? 0);

		$identifier = $id;
		if ($uuid !== '') {
			$identifier = $uuid;
		}

		return $identifier;
	}//end candidateIdentifier()

	/**
	 * Load a candidate outside RBAC and tenancy; null when it is gone or fails to load.
	 *
	 * @param int|string           $identifier The uuid or id.
	 * @param array<string, mixed> $candidate  Overdue audit-trail row (logged on failure).
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function findUnscoped(int|string $identifier, array $candidate): ?ObjectEntity {
		try {
			return $this->objectMapper->find(
				$identifier,
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (\Throwable $e) {
			$this->logLoadFailure(candidate: $candidate, error: $e->getMessage());
			return null;
		}
	}//end findUnscoped()

	/**
	 * Log a candidate that failed to load.
	 *
	 * @param array<string, mixed> $candidate Overdue audit-trail row.
	 * @param string               $error     The failure message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function logLoadFailure(array $candidate, string $error): void {
		$this->logger->debug(
			message: '[AVG retention] Failed to load candidate',
			context: ['candidate' => $candidate, 'error' => $error]
		);
	}//end logLoadFailure()
}//end class
