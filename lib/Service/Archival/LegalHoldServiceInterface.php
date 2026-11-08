<?php

/**
 * LegalHoldServiceInterface: what callers outside the component use of LegalHoldService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The legal holds the archival endpoints place, list and release.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface LegalHoldServiceInterface {

	/**
	 * Schedule a bulk legal hold operation on all objects in a schema.
	 *
	 * @param int $schemaId The schema ID to apply holds to.
	 * @param int $registerId The register ID.
	 * @param string $reason The reason for the bulk legal hold.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function bulkPlaceHold(int $schemaId, int $registerId, string $reason): void;

	/**
	 * The records that carry an active legal hold, with their active holds.
	 *
	 * Walks every retention source (the magic tables and the legacy blob
	 * table) and keeps the records whose hold is active; a released hold is
	 * not listed. A scan stopped at its cap says so in `truncated`.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, truncated: bool}
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function listActiveHolds(): array;

	/**
	 * Place a legal hold on an object.
	 *
	 * @param ObjectEntity $object The object to place a hold on.
	 * @param string $reason The reason for the legal hold (e.g. WOO-verzoek reference).
	 * @param string|null $ownerKey The matter placing it, e.g. `filinq:legalHoldCase:<uuid>`; null for a manual hold.
	 *
	 * @return ObjectEntity The updated object with legal hold applied.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function placeHold(ObjectEntity $object, string $reason, ?string $ownerKey = null): ObjectEntity;

	/**
	 * Release a legal hold on an object.
	 *
	 * @param ObjectEntity $object The object to release the hold from.
	 * @param string $reason The reason for releasing the hold.
	 * @param string|null $ownerKey The matter releasing its own hold; null lifts every hold.
	 *
	 * @return ObjectEntity The updated object with legal hold released.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function releaseHold(ObjectEntity $object, string $reason, ?string $ownerKey = null): ObjectEntity;
}//end interface
