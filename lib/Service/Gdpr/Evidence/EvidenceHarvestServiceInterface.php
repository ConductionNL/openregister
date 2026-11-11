<?php

/**
 * EvidenceHarvestServiceInterface: what callers outside the Service component use of EvidenceHarvestService.
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

use RuntimeException;

/**
 * Harvests the evidence of a DSAR case.
 */
interface EvidenceHarvestServiceInterface {

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
	public function harvest(string $caseUuid): array;
}//end interface
