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
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\HoldOrder;

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
	 * @param RegisterSchemaRef $table  The register and the schema whose objects get the hold.
	 * @param string            $reason The reason for the bulk legal hold.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function bulkPlaceHold(RegisterSchemaRef $table, string $reason): void;

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
	 * @param HoldOrder    $order  The reason (e.g. WOO-verzoek reference) and the matter placing it,
	 *                             e.g. `filinq:legalHoldCase:<uuid>`; no matter for a manual hold.
	 *
	 * @return ObjectEntity The updated object with legal hold applied.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function placeHold(ObjectEntity $object, HoldOrder $order): ObjectEntity;

	/**
	 * Release a legal hold on an object.
	 *
	 * @param ObjectEntity $object The object to release the hold from.
	 * @param HoldOrder    $order  The reason for releasing and the matter releasing its own hold; no matter lifts every hold.
	 *
	 * @return ObjectEntity The updated object with legal hold released.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function releaseHold(ObjectEntity $object, HoldOrder $order): ObjectEntity;
}//end interface
