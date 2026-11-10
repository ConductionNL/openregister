<?php

/**
 * EvidenceHarvestRun — one harvest of evidence into one DSAR case.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Evidence;

/**
 * The case being harvested, its payload as loaded, and the merge the providers fill.
 *
 * Built by EvidenceHarvestService::harvest() and never handed out of it.
 *
 * @spec openspec/specs/dsar-evidence-collection/spec.md
 */
final class EvidenceHarvestRun {
	/**
	 * Open the harvest.
	 *
	 * @param string               $caseUuid The case uuid.
	 * @param array<string, mixed> $data     The case payload as loaded.
	 * @param EvidenceMerge        $merge    The merge every provider adds to.
	 *
	 * @spec openspec/specs/dsar-evidence-collection/spec.md
	 */
	public function __construct(
		public readonly string $caseUuid,
		public readonly array $data,
		public readonly EvidenceMerge $merge,
	) {
	}//end __construct()
}//end class
