<?php

/**
 * ObjectMergerInterface: merge two objects into one, and undo a merge.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Previews, executes and reverses the merge of one object into another. Implemented by MergeService.
 */
interface ObjectMergerInterface {

	/**
	 * Preview a merge with NO side effects: no object, mergeOperation, or
	 * event is written. Returns the projected survivor golden record +
	 * provenance (via `SurvivorshipResolver` over the union of both objects'
	 * linked source records) and the reversal deadline.
	 *
	 * @param string $from Uuid of the object that would be merged away.
	 * @param string $into Uuid of the object that would survive.
	 *
	 * @return array<string, mixed> Preview payload.
	 *
	 * @throws \RuntimeException When the uuids are equal or either object is unreadable.
	 * @throws \OCA\OpenRegister\Exception\MergeNotFullyReadableException When either object carries a property the caller may not read.
	 */
	public function previewMerge(string $from, string $into): array;

	/**
	 * Execute a merge atomically (server-authoritative): snapshot both
	 * objects, relink the losing object's source records onto the survivor,
	 * recompute the survivor via `SurvivorshipResolver`, flip statuses,
	 * persist a `mergeOperation` row, and dispatch `ObjectsMergedEvent`.
	 *
	 * @param string $from Uuid of the object being merged away.
	 * @param string $into Uuid of the surviving object.
	 * @param string $reason Steward-supplied merge reason.
	 * @param string $mergedBy Acting user uid.
	 * @param array<string, mixed>|null $decisions Per-property choices (`property => 'from' | 'into'`).
	 *                                            Null keeps the pre-existing behaviour exactly: the
	 *                                            resolver's proposal applies and no payload property
	 *                                            is rewritten.
	 *
	 * @return array<string, mixed> The persisted `mergeOperation` row.
	 *
	 * @throws \RuntimeException When self-merge, already-merged, or the survivor is not active.
	 * @throws \OCA\OpenRegister\Exception\MergeDecisionException When the decision map does not describe the preview it approves.
	 * @throws \OCA\OpenRegister\Exception\MergeNotFullyReadableException When either object carries a property the caller may not read.
	 */
	public function executeMerge(
		string $from,
		string $into,
		string $reason,
		string $mergedBy,
		?array $decisions = null,
	): array;

	/**
	 * Reverse a merge within the reversal window: restore both objects from
	 * `preMergeSnapshot` (golden record, provenance, status, source links),
	 * mark the operation reversed, and dispatch a reversal `ObjectsMergedEvent`.
	 * Rejected outside the window with no mutation.
	 *
	 * @param string $mergeOperationId Uuid of the `mergeOperation` row.
	 * @param string $reversedBy Acting user uid.
	 *
	 * @return array<string, mixed> The updated `mergeOperation` row.
	 *
	 * @throws \RuntimeException When the operation is missing or no longer reversible.
	 */
	public function reverseMerge(string $mergeOperationId, string $reversedBy): array;
}//end interface
