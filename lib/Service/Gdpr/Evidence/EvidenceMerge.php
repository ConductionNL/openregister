<?php

/**
 * OpenRegister Gdpr EvidenceMerge
 *
 * One harvest pass's view of a case's `evidence` sub-collection: the existing
 * rows, the content hashes already present, and the counters the harvest
 * reports. An item whose hash is empty or already present (on the case, or
 * earlier in the same pass) is skipped, so re-runs never duplicate evidence.
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

use OCA\OpenRegister\Service\Gdpr\Case\CasePayload;

/**
 * Dedup-by-hash accumulator for one evidence harvest pass.
 *
 * @spec openspec/specs/dsar-evidence-collection/spec.md
 */
final class EvidenceMerge {

	/**
	 * The case's evidence rows: existing first, then appended.
	 *
	 * @var array<int, mixed>
	 */
	private array $evidence;

	/**
	 * Content hashes already present, as keys.
	 *
	 * @var array<string, true>
	 */
	private array $seenHashes = [];

	/**
	 * Items offered by enabled providers.
	 *
	 * @var int
	 */
	private int $evaluated = 0;

	/**
	 * Items appended.
	 *
	 * @var int
	 */
	private int $appended = 0;

	/**
	 * Items skipped (empty or already-present hash).
	 *
	 * @var int
	 */
	private int $skipped = 0;

	/**
	 * Ids of the enabled providers asked, in order.
	 *
	 * @var array<int, string>
	 */
	private array $providerIds = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data The case payload.
	 */
	public function __construct(array $data) {
		$this->evidence = CasePayload::listField(data: $data, key: 'evidence');
		foreach ($this->evidence as $existing) {
			if (is_array($existing) === true && isset($existing['contentHash']) === true) {
				$this->seenHashes[(string)$existing['contentHash']] = true;
			}
		}

	}//end __construct()

	/**
	 * Record that an enabled provider was asked.
	 *
	 * @param string $sourceId The provider's source id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dsar-evidence-collection/spec.md
	 */
	public function noteProvider(string $sourceId): void {
		$this->providerIds[] = $sourceId;

	}//end noteProvider()

	/**
	 * Merge one provider's items.
	 *
	 * @param array<int, EvidenceItem> $items The harvested items.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dsar-evidence-collection/spec.md
	 */
	public function merge(array $items): void {
		foreach ($items as $item) {
			$this->mergeItem(item: $item);
		}

	}//end merge()

	/**
	 * Whether anything was appended (so the case must be saved).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dsar-evidence-collection/spec.md
	 */
	public function changed(): bool {
		return $this->appended > 0;

	}//end changed()

	/**
	 * The merged evidence sub-collection.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/dsar-evidence-collection/spec.md
	 */
	public function evidence(): array {
		return $this->evidence;

	}//end evidence()

	/**
	 * The harvest report.
	 *
	 * @param string $caseUuid The case uuid.
	 *
	 * @return array{caseUuid: string, evaluated: int, appended: int, skipped: int, providers: array<int, string>}
	 *
	 * @spec openspec/specs/dsar-evidence-collection/spec.md
	 */
	public function summary(string $caseUuid): array {
		return [
			'caseUuid' => $caseUuid,
			'evaluated' => $this->evaluated,
			'appended' => $this->appended,
			'skipped' => $this->skipped,
			'providers' => $this->providerIds,
		];

	}//end summary()

	/**
	 * Append one item unless its hash is empty or already present.
	 *
	 * @param EvidenceItem $item The harvested item.
	 *
	 * @return void
	 */
	private function mergeItem(EvidenceItem $item): void {
		$this->evaluated++;
		$hash = $item->getContentHash();
		if ($hash === '' || isset($this->seenHashes[$hash]) === true) {
			$this->skipped++;
			return;
		}

		$this->seenHashes[$hash] = true;
		$this->evidence[] = $item->toEvidenceRecord();
		$this->appended++;

	}//end mergeItem()
}//end class
