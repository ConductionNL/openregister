<?php

/**
 * OpenRegister Gdpr RetentionSweepService
 *
 * Enforces the windowed retention the `dsar-case-subsystem` head declares on the
 * case (`retentionWindow` / `retainUntil` stamps). On each pass it walks the
 * data-subject-request cases, identifies those whose `retainUntil` has passed,
 * scrubs the case's evidence PII by reusing
 * {@see DataSubjectRequestService::erase(mode=pseudonymise)} (never a hand-rolled
 * scrub, ADR-011), and hard-deletes the case dossier through
 * {@see CaseObjectAccessor} — every destructive action audited.
 *
 * The sweep is legal-hold aware: it consults
 * {@see RetentionService::hasActiveLegalHold} and
 * {@see RetentionService::validateNotImmutable} before touching any case and
 * SKIPS a held/immutable case intact, mirroring the guarantee the erase path
 * already honours. A dry-run reports what WOULD be destroyed without acting.
 *
 * Scheduled bulk work with destructive side effects is the ADR-031
 * scheduled-work imperative exception; this service is driven by the
 * {@see \OCA\OpenRegister\BackgroundJob\DsarRetentionSweepJob} TimedJob.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Retention
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Retention;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Legal-hold-aware retention sweep over data-subject-request cases.
 */
class RetentionSweepService
{
    /**
     * Constructor.
     *
     * @param CaseObjectAccessor        $accessor         RBAC-scoped case query + audited delete.
     * @param RetentionService          $retentionService Legal-hold / immutability awareness.
     * @param DataSubjectRequestService $dsrService       Reused evidence-PII scrub (erase pseudonymise).
     * @param ITimeFactory              $time             Time source for the `retainUntil` cut-off.
     * @param LoggerInterface           $logger           Logger.
     */
    public function __construct(
        private readonly CaseObjectAccessor $accessor,
        private readonly RetentionService $retentionService,
        private readonly DataSubjectRequestService $dsrService,
        private readonly ITimeFactory $time,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Run a single retention pass.
     *
     * For every case whose `retainUntil` has passed and that is NOT under an
     * active legal hold / immutable status: (dry-run) report it; else scrub its
     * evidence PII via erase pseudonymise and hard-delete the dossier. Held or
     * immutable expired cases are reported as skipped and left intact.
     *
     * @param bool $dryRun When true, report candidates without deleting/scrubbing.
     *
     * @return array{dryRun: bool, evaluated: int, purged: array<int, string>, skippedHeld: array<int, string>, withinWindow: int}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Dry-run toggle mirrors AvgRetentionJob's contract.
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-retention-sweep/spec.md
     */
    public function runSweep(bool $dryRun=false): array
    {
        $now = $this->time->getTime();

        $summary = [
            'dryRun'       => $dryRun,
            'evaluated'    => 0,
            'purged'       => [],
            'skippedHeld'  => [],
            'withinWindow' => 0,
        ];

        foreach ($this->accessor->findAllCaseEntities() as $case) {
            $summary['evaluated']++;
            $uuid = (string) $case->getUuid();
            $data = $case->getObject();

            // Not yet expired → untouched.
            if ($this->isExpired(data: $data, now: $now) === false) {
                $summary['withinWindow']++;
                continue;
            }

            // Legal-hold / immutability aware: skip held or immutable cases
            // intact, exactly as the erase path already does. This is the
            // fail-safe against irreversible destruction.
            if ($this->isProtected(case: $case) === true) {
                $summary['skippedHeld'][] = $uuid;
                $this->logger->info(
                    message: sprintf('[RetentionSweep] case "%s" past window but under legal hold/immutable — skipped', $uuid)
                );
                continue;
            }

            if ($dryRun === true) {
                $summary['purged'][] = $uuid;
                continue;
            }

            $this->purge(case: $case, data: $data);
            $summary['purged'][] = $uuid;
        }//end foreach

        return $summary;
    }//end runSweep()

    /**
     * Whether a case's `retainUntil` has passed.
     *
     * A missing / unparseable `retainUntil` is treated as NOT expired
     * (fail-safe: never destroy a case whose window we cannot read).
     *
     * @param array<string, mixed> $data The case payload.
     * @param int                  $now  Current unix time.
     *
     * @return bool True when the case is past its retention window.
     */
    private function isExpired(array $data, int $now): bool
    {
        $retainUntil = (string) ($data['retainUntil'] ?? '');
        if ($retainUntil === '') {
            return false;
        }

        $expiryTs = strtotime($retainUntil);
        if ($expiryTs === false) {
            return false;
        }

        return ($expiryTs < $now);
    }//end isExpired()

    /**
     * Whether a case is protected from destruction (legal hold or immutable).
     *
     * @param ObjectEntity $case The case entity.
     *
     * @return bool True when the case must be left intact.
     */
    private function isProtected(ObjectEntity $case): bool
    {
        if ($this->retentionService->hasActiveLegalHold(object: $case) === true) {
            return true;
        }

        return ($this->retentionService->validateNotImmutable(object: $case) !== null);
    }//end isProtected()

    /**
     * Scrub a case's evidence PII (erase pseudonymise) then delete the dossier.
     *
     * Both actions are audited (the erase pins the DSAR activity; the delete
     * goes through ObjectService's audited delete path).
     *
     * @param ObjectEntity         $case The case entity.
     * @param array<string, mixed> $data The case payload.
     *
     * @return void
     */
    private function purge(ObjectEntity $case, array $data): void
    {
        $uuid      = (string) $case->getUuid();
        $subjectId = (string) ($data['subjectId'] ?? '');

        // Scrub evidence PII via the existing erase primitive (pseudonymise),
        // never a hand-rolled scrub. A missing subject id is logged, not fatal.
        if ($subjectId !== '') {
            try {
                $this->dsrService->erase(
                    subjectId: $subjectId,
                    eraseMode: DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    message: sprintf('[RetentionSweep] evidence scrub failed for case "%s": %s', $uuid, $e->getMessage())
                );
            }
        }

        try {
            $this->accessor->deleteForSweep(caseUuid: $uuid);
            $this->logger->info(
                message: sprintf('[RetentionSweep] case "%s" past window — dossier hard-deleted, evidence scrubbed', $uuid)
            );
        } catch (Throwable $e) {
            $this->logger->error(
                message: sprintf('[RetentionSweep] dossier delete failed for case "%s": %s', $uuid, $e->getMessage())
            );
        }
    }//end purge()
}//end class
