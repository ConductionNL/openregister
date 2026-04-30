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
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateInterval;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Bewaartermijn-driven object retention enforcement.
 */
class AvgRetentionService
{
    /**
     * Constructor.
     *
     * @param IDBConnection               $db           DB for audit-trail aggregation.
     * @param VerwerkingsactiviteitMapper $vrwMapper    Catalog reader.
     * @param MagicMapper                 $objectMapper Object loader.
     * @param LoggerInterface             $logger       Logger.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly VerwerkingsactiviteitMapper $vrwMapper,
        private readonly MagicMapper $objectMapper,
        private readonly LoggerInterface $logger,
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
     *     "dryRun":              bool,
     *     "perActivity": [
     *       {"uuid": "...", "naam": "...", "bewaartermijn": "P10Y",
     *        "cutoff": "<iso>", "matchedObjects": <int>, "erased": <int>},
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
     */
    public function runRetentionPass(bool $dryRun=false): array
    {
        $now     = new DateTime();
        $summary = [
            'evaluatedActivities' => 0,
            'skippedActivities'   => 0,
            'objectsErased'       => 0,
            'dryRun'              => $dryRun,
            'perActivity'         => [],
        ];

        $activities = $this->vrwMapper->findAll(status: 'published');
        foreach ($activities as $activity) {
            $perActivity = $this->processActivity(
                activity: $activity,
                now: $now,
                dryRun: $dryRun
            );
            if ($perActivity === null) {
                $summary['skippedActivities']++;
                continue;
            }

            $summary['evaluatedActivities']++;
            $summary['objectsErased'] += $perActivity['erased'];
            $summary['perActivity'][]  = $perActivity;
        }

        return $summary;

    }//end runRetentionPass()

    /**
     * Evaluate one verwerkingsactiviteit. Null when its bewaartermijn
     * is unset or unparseable (caller increments `skippedActivities`).
     *
     * @param Verwerkingsactiviteit $activity Catalog entry.
     * @param DateTime              $now      Reference timestamp.
     * @param bool                  $dryRun   Pass-through dry-run flag.
     *
     * @return array<string, mixed>|null Per-activity result or null skip.
     */
    private function processActivity(Verwerkingsactiviteit $activity, DateTime $now, bool $dryRun): ?array
    {
        $bewaartermijn = (string) ($activity->getBewaartermijn() ?? '');
        if ($bewaartermijn === '') {
            return null;
        }

        $cutoff = $this->computeCutoff(now: $now, duration: $bewaartermijn);
        if ($cutoff === null) {
            $this->logger->warning(
                message: '[AVG retention] Unparseable bewaartermijn — skipping activity',
                context: [
                    'activity'      => $activity->getUuid(),
                    'bewaartermijn' => $bewaartermijn,
                ]
            );
            return null;
        }

        $candidates = $this->findOverdueObjectsForActivity(
            activityUuid: (string) $activity->getUuid(),
            cutoff: $cutoff
        );

        $erased = 0;
        if ($dryRun === false && $candidates !== []) {
            $erased = $this->erasePastRetention(
                candidates: $candidates,
                activity: $activity
            );
        }

        return [
            'uuid'           => $activity->getUuid(),
            'naam'           => $activity->getNaam(),
            'bewaartermijn'  => $bewaartermijn,
            'cutoff'         => $cutoff->format('c'),
            'matchedObjects' => count($candidates),
            'erased'         => $erased,
        ];

    }//end processActivity()

    /**
     * Compute the cut-off timestamp for a bewaartermijn duration.
     *
     * Accepts ISO-8601 duration syntax (`P10Y`, `P30D`, `P6M`) — the
     * canonical AVG / NEN-2082 representation. Returns null on parse
     * failure so the caller can flag the activity as skipped.
     *
     * @param DateTime $now      Reference timestamp.
     * @param string   $duration ISO-8601 duration string.
     *
     * @return DateTime|null Cutoff timestamp or null on parse error.
     */
    private function computeCutoff(DateTime $now, string $duration): ?DateTime
    {
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
     * @param string   $activityUuid Activity uuid.
     * @param DateTime $cutoff       Cut-off timestamp.
     *
     * @return array<int, array{object: int, object_uuid: string|null, register: int|null, schema: int|null}>
     */
    private function findOverdueObjectsForActivity(string $activityUuid, DateTime $cutoff): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                [
                    'object',
                    'object_uuid',
                    'register',
                    'schema',
                ]
            )
                ->selectAlias($qb->func()->max('created'), 'last_seen')
                ->from('openregister_audit_trails')
                ->where(
                    $qb->expr()->eq(
                        'processing_activity_id',
                        $qb->createNamedParameter($activityUuid)
                    )
                )
                ->groupBy('object', 'object_uuid', 'register', 'schema')
                ->having(
                    'MAX(created) < '.$qb->createNamedParameter(
                        $cutoff,
                        IQueryBuilder::PARAM_DATE
                    )
                );

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            $candidates = [];
            foreach ($rows as $row) {
                $candidates[] = [
                    'object'      => (int) ($row['object'] ?? 0),
                    'object_uuid' => ($row['object_uuid'] ?? null),
                    'register'    => (isset($row['register']) === true) ? (int) $row['register'] : null,
                    'schema'      => (isset($row['schema']) === true) ? (int) $row['schema'] : null,
                ];
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
     * Soft-delete each candidate. Returns the count of successful
     * erasures; failures are logged and skipped so a single bad
     * object doesn't abort the whole pass.
     *
     * @param array<int, array<string, mixed>> $candidates Overdue objects.
     * @param Verwerkingsactiviteit            $activity   Owning activity.
     *
     * @return int
     */
    private function erasePastRetention(array $candidates, Verwerkingsactiviteit $activity): int
    {
        $deletionData = [
            'deletedBy'     => 'system',
            'deletedAt'     => (new DateTime())->format(DateTime::ATOM),
            'reason'        => 'avg-bewaartermijn',
            'activityUuid'  => $activity->getUuid(),
            'bewaartermijn' => $activity->getBewaartermijn(),
        ];

        $erased = 0;
        foreach ($candidates as $candidate) {
            $object = $this->loadCandidate(candidate: $candidate);
            if ($object === null) {
                continue;
            }

            $object->setDeleted($deletionData);
            $object->setProcessingActivityId((string) $activity->getUuid());

            try {
                $this->objectMapper->update(entity: $object);
                $erased++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[AVG retention] Soft-delete failed during retention pass',
                    context: ['candidate' => $candidate, 'error' => $e->getMessage()]
                );
            }
        }

        return $erased;

    }//end erasePastRetention()

    /**
     * Load a candidate object — uuid first (deterministic), int id as
     * fallback for legacy audit rows.
     *
     * @param array<string, mixed> $candidate Single candidate row.
     *
     * @return ObjectEntity|null
     */
    private function loadCandidate(array $candidate): ?ObjectEntity
    {
        $uuid = (string) ($candidate['object_uuid'] ?? '');
        $id   = (int) ($candidate['object'] ?? 0);

        $identifier = ($uuid !== '') ? $uuid : $id;
        if ($identifier === 0 || $identifier === '') {
            return null;
        }

        try {
            return $this->objectMapper->find(
                $identifier,
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $e) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->debug(
                message: '[AVG retention] Failed to load candidate',
                context: ['candidate' => $candidate, 'error' => $e->getMessage()]
            );
            return null;
        }

    }//end loadCandidate()
}//end class
