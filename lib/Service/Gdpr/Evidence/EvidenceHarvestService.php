<?php

/**
 * OpenRegister Gdpr EvidenceHarvestService
 *
 * Collects evidence for a data-subject-request case from the registered
 * {@see EvidenceSourceProvider} implementations, deduplicates items by
 * `contentHash` (re-runs are idempotent), and writes each item's `sourceId`,
 * `contentHash` and per-item `status` onto the case's declared `evidence`
 * sub-collection through {@see CaseObjectAccessor} (ObjectService, RBAC +
 * multitenancy). Each attach is audited through the case's immutable
 * hash-chained trail (the accessor pins the write to the DSAR activity).
 *
 * Harvesting reaches external systems via provider adapters — the ADR-003 /
 * ADR-031 external-integration imperative exception — and enumerates ONLY
 * registered providers (ADR-019): an unregistered source contributes nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Evidence
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

namespace OCA\OpenRegister\Service\Gdpr\Evidence;

use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\Case\CasePayload;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Harvests + dedups evidence onto a case.
 */
class EvidenceHarvestService implements EvidenceHarvestServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param EvidenceSourceRegistry $registry The provider registry (registered sources only).
	 * @param CaseObjectAccessor $accessor RBAC-scoped, audited case load/save.
	 * @param LoggerInterface $logger Logger for per-source diagnostics.
	 */
	public function __construct(
		private readonly EvidenceSourceRegistry $registry,
		private readonly CaseObjectAccessor $accessor,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Harvest evidence for a case and persist deduplicated items onto it.
	 *
	 * Enumerates registered, enabled providers; asks each to harvest for the
	 * case; and appends any item whose `contentHash` is not already present on
	 * the case's `evidence` sub-collection. Items with an already-present hash
	 * are skipped (idempotent re-runs). The mutated case is saved once, audited.
	 *
	 * @param string $caseUuid The case object uuid.
	 *
	 * @return array{caseUuid: string, evaluated: int, appended: int, skipped: int, providers: array<int, string>}
	 *
	 * @throws RuntimeException When the case cannot be loaded (absent or unauthorised).
	 *
	 * @spec openspec/specs/dsar-evidence-collection/spec.md
	 */
	public function harvest(string $caseUuid): array {
		$case = CasePayload::loaded(case: $this->accessor->load(caseUuid: $caseUuid), caseUuid: $caseUuid);
		$data = $case->getObject();
		$merge = new EvidenceMerge(data: $data);
		$run = new EvidenceHarvestRun(caseUuid: $caseUuid, data: $data, merge: $merge);

		foreach ($this->registry->list() as $provider) {
			$this->harvestFrom(run: $run, provider: $provider);
		}

		// Persist once (a single audited write) only when something changed.
		if ($merge->changed() === true) {
			$data['evidence'] = $merge->evidence();
			$this->accessor->save(case: $case, data: $data);
		}

		return $merge->summary(caseUuid: $caseUuid);
	}//end harvest()

	/**
	 * Harvest one provider into the run's merge, unless it is disabled.
	 *
	 * @param EvidenceHarvestRun     $run      The case being harvested and its merge.
	 * @param EvidenceSourceProvider $provider The provider.
	 *
	 * @return void
	 */
	private function harvestFrom(EvidenceHarvestRun $run, EvidenceSourceProvider $provider): void {
		if ($provider->isEnabled() === false) {
			return;
		}

		$run->merge->noteProvider(sourceId: $provider->getSourceId());
		$run->merge->merge(items: $this->itemsFrom(run: $run, provider: $provider));
	}//end harvestFrom()

	/**
	 * One provider's items; none when it fails.
	 *
	 * A failing source is visible + re-runnable: it contributes no items this
	 * pass, but does not abort the whole harvest.
	 *
	 * @param EvidenceHarvestRun     $run      The case uuid and its payload as loaded.
	 * @param EvidenceSourceProvider $provider The provider.
	 *
	 * @return array<int, EvidenceItem>
	 */
	private function itemsFrom(EvidenceHarvestRun $run, EvidenceSourceProvider $provider): array {
		try {
			return $provider->harvest(caseUuid: $run->caseUuid, case: $run->data);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: sprintf(
					'[EvidenceHarvestService] provider "%s" failed for case "%s": %s',
					$provider->getSourceId(),
					$run->caseUuid,
					$e->getMessage()
				)
			);
			return [];
		}
	}//end itemsFrom()
}//end class
