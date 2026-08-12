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
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Harvests + dedups evidence onto a case.
 */
class EvidenceHarvestService {
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Linear enumerate→dedup→append loop; per-provider/per-item guards.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same loop; path count inflated by nested per-item guards.
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
	 */
	public function harvest(string $caseUuid): array {
		$case = $this->accessor->load(caseUuid: $caseUuid);
		if ($case === null) {
			throw new RuntimeException(
				message: sprintf('Case "%s" not found or not authorised.', $caseUuid)
			);
		}

		$data = $case->getObject();
		$evidence = [];
		if (isset($data['evidence']) === true && is_array($data['evidence']) === true) {
			$evidence = array_values($data['evidence']);
		}

		// Index existing content hashes so re-runs never duplicate an item.
		$seenHashes = [];
		foreach ($evidence as $existing) {
			if (is_array($existing) === true && isset($existing['contentHash']) === true) {
				$seenHashes[(string)$existing['contentHash']] = true;
			}
		}

		$evaluated = 0;
		$appended = 0;
		$skipped = 0;
		$providerIds = [];

		foreach ($this->registry->list() as $provider) {
			if ($provider->isEnabled() === false) {
				continue;
			}

			$providerIds[] = $provider->getSourceId();

			try {
				$items = $provider->harvest(caseUuid: $caseUuid, case: $data);
			} catch (Throwable $e) {
				// A failing source is visible + re-runnable: it contributes no
				// items this pass, but does not abort the whole harvest.
				$this->logger->warning(
					message: sprintf(
						'[EvidenceHarvestService] provider "%s" failed for case "%s": %s',
						$provider->getSourceId(),
						$caseUuid,
						$e->getMessage()
					)
				);
				continue;
			}

			foreach ($items as $item) {
				$evaluated++;
				$hash = $item->getContentHash();

				if ($hash === '' || isset($seenHashes[$hash]) === true) {
					$skipped++;
					continue;
				}

				$seenHashes[$hash] = true;
				$evidence[] = $item->toEvidenceRecord();
				$appended++;
			}//end foreach
		}//end foreach

		// Persist once (a single audited write) only when something changed.
		if ($appended > 0) {
			$data['evidence'] = $evidence;
			$this->accessor->save(case: $case, data: $data);
		}

		return [
			'caseUuid' => $caseUuid,
			'evaluated' => $evaluated,
			'appended' => $appended,
			'skipped' => $skipped,
			'providers' => $providerIds,
		];
	}//end harvest()
}//end class
