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
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Legal-hold-aware retention sweep over data-subject-request cases.
 */
class RetentionSweepService implements RetentionSweepInterface {
	/**
	 * Constructor.
	 *
	 * @param CaseObjectAccessor $accessor RBAC-scoped case query + audited delete.
	 * @param RetentionServiceInterface $retentionService Legal-hold / immutability awareness.
	 * @param DataSubjectRequestService $dsrService Reused evidence-PII scrub (erase pseudonymise).
	 * @param ITimeFactory $time Time source for the `retainUntil` cut-off.
	 * @param LoggerInterface $logger Logger.
	 * @param ArchivalRetentionGuard $archivalGuard Refuses destruction of a legally retained case.
	 */
	public function __construct(
		private readonly CaseObjectAccessor $accessor,
		private readonly RetentionServiceInterface $retentionService,
		private readonly DataSubjectRequestService $dsrService,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly ArchivalRetentionGuard $archivalGuard,
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
	 * RETENTION WINS OVER ERASURE. A case on a schema declaring
	 * `x-openregister-archival` is kept whatever its `retainUntil` says, and is
	 * reported in `withheld` with the ground and the wording to pass on. The
	 * refusal comes from
	 * {@see \OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard}, so it
	 * reads the same definition as every other delete door.
	 *
	 * @param bool $dryRun When true, report candidates without deleting/scrubbing.
	 *
	 * A case whose dossier delete fails is named in `failed`, never in
	 * `purged`: the summary is the record of what was destroyed.
	 *
	 * @return array{dryRun: bool, evaluated: int, purged: array<int, string>,
	 *               failed: array<int, string>,
	 *               skippedHeld: array<int, string>, withinWindow: int,
	 *               withheld: array<int, array<string, mixed>>, withheldCount: int}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Dry-run toggle mirrors AvgRetentionJob's contract.
	 *
	 * @spec openspec/specs/dsar-retention-sweep/spec.md
	 */
	public function runSweep(bool $dryRun = false): array {
		$now = $this->time->getTime();
		$summary = $this->openSummary(dryRun: $dryRun);

		foreach ($this->accessor->findAllCaseEntities() as $case) {
			$summary['evaluated']++;
			$this->sweepCase(case: $case, now: $now, dryRun: $dryRun, summary: $summary);
		}

		$summary['withheldCount'] = count($summary['withheld']);

		return $summary;
	}//end runSweep()

	/**
	 * The empty sweep summary.
	 *
	 * @param bool $dryRun Whether this pass only reports.
	 *
	 * @return array<string, mixed>
	 */
	private function openSummary(bool $dryRun): array {
		return [
			'dryRun' => $dryRun,
			'evaluated' => 0,
			'purged' => [],
			'failed' => [],
			'skippedHeld' => [],
			// RETENTION WINS OVER ERASURE: cases the Archiefwet keeps are named
			// here with their ground, not silently swept.
			'withheld' => [],
			'withinWindow' => 0,
		];
	}//end openSummary()

	/**
	 * Decide one case: untouched, skipped, withheld, or purged (reported only on a dry run).
	 *
	 * @param ObjectEntity         $case    The case entity.
	 * @param int                  $now     Current unix time.
	 * @param bool                 $dryRun  When true, nothing is scrubbed or deleted.
	 * @param array<string, mixed> $summary The sweep summary (mutated by reference).
	 *
	 * @return void
	 */
	private function sweepCase(ObjectEntity $case, int $now, bool $dryRun, array &$summary): void {
		$data = $case->getObject();

		// Not yet expired → untouched.
		if ($this->isExpired(data: $data, now: $now) === false) {
			$summary['withinWindow']++;
			return;
		}

		$this->sweepExpired(case: $case, data: $data, dryRun: $dryRun, summary: $summary);
	}//end sweepCase()

	/**
	 * An expired case: skipped or withheld when refused, failed when the delete fails, else purged.
	 *
	 * @param ObjectEntity         $case    The expired case entity.
	 * @param array<string, mixed> $data    The case payload.
	 * @param bool                 $dryRun  When true, nothing is scrubbed or deleted.
	 * @param array<string, mixed> $summary The sweep summary (mutated by reference).
	 *
	 * @return void
	 */
	private function sweepExpired(ObjectEntity $case, array $data, bool $dryRun, array &$summary): void {
		$refusal = $this->refusalFor(case: $case);
		if ($refusal !== null) {
			$summary[$refusal[0]][] = $refusal[1];
			return;
		}

		$summary[$this->outcomeOf(case: $case, data: $data, dryRun: $dryRun)][] = (string)$case->getUuid();
	}//end sweepExpired()

	/**
	 * The bucket an unrefused expired case lands in: `purged`, or `failed` when its delete fails.
	 *
	 * A dry run destroys nothing and reports the case as `purged`.
	 *
	 * @param ObjectEntity         $case   The expired case entity.
	 * @param array<string, mixed> $data   The case payload.
	 * @param bool                 $dryRun When true, nothing is scrubbed or deleted.
	 *
	 * @return string The summary bucket.
	 */
	private function outcomeOf(ObjectEntity $case, array $data, bool $dryRun): string {
		if ($dryRun === true || $this->purge(case: $case, data: $data) === true) {
			return 'purged';
		}

		return 'failed';
	}//end outcomeOf()

	/**
	 * Why an expired case must be left intact, as [bucket, entry], or null.
	 *
	 * Legal-hold / immutability aware: a held or immutable case is skipped
	 * intact, exactly as the erase path already does. This is the fail-safe
	 * against irreversible destruction.
	 *
	 * RETENTION WINS OVER ERASURE, AND THIS SWEEP HAD AN EXPLICIT BYPASS.
	 * `isProtected()` reads the case's own legal hold and archiefstatus; it
	 * says nothing about `x-openregister-archival` on the case schema. Worse,
	 * the delete in purge() hands `_retentionSweep: true` to ObjectService,
	 * which is exactly the flag that waves a row past the archival gate. So an
	 * archival case was hard-deleted here with no refusal anywhere in the
	 * chain. The gate is asked BEFORE the bypass is used, of the same
	 * Schema::hasArchivalAnnotation() the four HTTP doors ask. Refused cases
	 * are named in `withheld`; the rest of the sweep runs on.
	 *
	 * @param ObjectEntity $case The expired case entity.
	 *
	 * @return array{0: string, 1: mixed}|null `skippedHeld` + uuid, `withheld` + refusal, or null.
	 */
	private function refusalFor(ObjectEntity $case): ?array {
		$uuid = (string)$case->getUuid();
		if ($this->isProtected(case: $case) === true) {
			$this->logger->info(
				message: sprintf('[RetentionSweep] case "%s" past window but under legal hold/immutable — skipped', $uuid)
			);
			return ['skippedHeld', $uuid];
		}

		$refusal = $this->archivalGuard->erasureRefusal(object: $case);
		if ($refusal !== null) {
			return ['withheld', $refusal];
		}

		return null;
	}//end refusalFor()

	/**
	 * Whether a case's `retainUntil` has passed.
	 *
	 * A missing / unparseable `retainUntil` is treated as NOT expired
	 * (fail-safe: never destroy a case whose window we cannot read).
	 *
	 * @param array<string, mixed> $data The case payload.
	 * @param int $now Current unix time.
	 *
	 * @return bool True when the case is past its retention window.
	 */
	private function isExpired(array $data, int $now): bool {
		$retainUntil = (string)($data['retainUntil'] ?? '');
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
	private function isProtected(ObjectEntity $case): bool {
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
	 * @param ObjectEntity $case The case entity.
	 * @param array<string, mixed> $data The case payload.
	 *
	 * @return bool True only when the dossier delete succeeded.
	 */
	private function purge(ObjectEntity $case, array $data): bool {
		$uuid = (string)$case->getUuid();
		$subjectId = (string)($data['subjectId'] ?? '');

		// Scrub evidence PII via the existing erase primitive (pseudonymise),
		// never a hand-rolled scrub. A missing subject id is logged, not fatal.
		if ($subjectId !== '') {
			$this->scrubEvidence(uuid: $uuid, subjectId: $subjectId);
		}

		return $this->deleteDossier(uuid: $uuid);
	}//end purge()

	/**
	 * Erase-pseudonymise the subject's evidence PII; a failure is logged.
	 *
	 * @param string $uuid      The case uuid (for the log).
	 * @param string $subjectId The case's subject id.
	 *
	 * @return void
	 */
	private function scrubEvidence(string $uuid, string $subjectId): void {
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
	}//end scrubEvidence()

	/**
	 * Hard-delete the dossier through the audited sweep delete; a failure is logged.
	 *
	 * @param string $uuid The case uuid.
	 *
	 * @return bool True only when the dossier delete succeeded.
	 */
	private function deleteDossier(string $uuid): bool {
		$deleted = $this->tryDelete(uuid: $uuid);
		if ($deleted === null) {
			return false;
		}

		if ($deleted === false) {
			$this->logger->error(message: sprintf('[RetentionSweep] dossier delete failed for case "%s"', $uuid));
			return false;
		}

		$this->logger->info(
			message: sprintf('[RetentionSweep] case "%s" past window — dossier hard-deleted, evidence scrubbed', $uuid)
		);

		return true;
	}//end deleteDossier()

	/**
	 * Run the audited sweep delete; a thrown failure is logged and reported as null.
	 *
	 * @param string $uuid The case uuid.
	 *
	 * @return bool|null The delete's own result, or null when it threw.
	 */
	private function tryDelete(string $uuid): bool|null {
		try {
			return $this->accessor->deleteForSweep(caseUuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->error(
				message: sprintf('[RetentionSweep] dossier delete failed for case "%s": %s', $uuid, $e->getMessage())
			);
			return null;
		}
	}//end tryDelete()
}//end class
