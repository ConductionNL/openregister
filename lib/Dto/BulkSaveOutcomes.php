<?php

/**
 * BulkSaveOutcomes: builds the bulk-save outcome the bulk controller reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dto;

use OCA\OpenRegister\Service\Object\BatchOperationStatusInterface;

/**
 * Each method hands its arguments, unchanged, to the BulkSaveOutcome named
 * constructor of the same name and returns what it built, typed as the
 * interface, so callers outside Dto name only this class and the interface.
 * Types only: no summarising lives here.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Delegates to BulkSaveOutcome's named constructors.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkSaveOutcomes {

	/**
	 * Summarise a raw `ObjectService::saveObjects()` result.
	 *
	 * @param array $bulkResult     Raw result from ObjectService::saveObjects().
	 * @param int   $requestedCount Rows the caller submitted.
	 * @param int   $accountedCount Rows the result accounts for (saved + updated + unchanged).
	 *
	 * @return BulkSaveOutcomeInterface The summarised outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function fromBulkResult(array $bulkResult, int $requestedCount, int $accountedCount): BulkSaveOutcomeInterface {
		return BulkSaveOutcome::fromBulkResult(bulkResult: $bulkResult, requestedCount: $requestedCount, accountedCount: $accountedCount);
	}//end fromBulkResult()

	/**
	 * Summarise a streaming batch status into the same shape.
	 *
	 * @param BatchOperationStatusInterface $status         Per-row outcomes from the streaming save.
	 * @param int                           $requestedCount Rows the caller submitted.
	 * @param int                           $accountedCount Rows the status accounts for (created + updated + unchanged).
	 *
	 * @return BulkSaveOutcomeInterface The summarised outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function fromBatchStatus(
		BatchOperationStatusInterface $status,
		int $requestedCount,
		int $accountedCount
	): BulkSaveOutcomeInterface {
		return BulkSaveOutcome::fromBatchStatus(status: $status, requestedCount: $requestedCount, accountedCount: $accountedCount);
	}//end fromBatchStatus()
}//end class
