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
	 * @param array        $bulkResult Raw result from ObjectService::saveObjects().
	 * @param BulkRowCount $rows       Rows the caller submitted and rows the result accounts for.
	 *
	 * @return BulkSaveOutcomeInterface The summarised outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function fromBulkResult(array $bulkResult, BulkRowCount $rows): BulkSaveOutcomeInterface {
		return BulkSaveOutcome::fromSaveResult(bulkResult: $bulkResult, rows: $rows);
	}//end fromBulkResult()

	/**
	 * Summarise a streaming batch status into the same shape.
	 *
	 * @param BatchOperationStatusInterface $status Per-row outcomes from the streaming save.
	 * @param StreamedRowCount              $rows   Rows the caller submitted and rows the status accounts for.
	 *
	 * @return BulkSaveOutcomeInterface The summarised outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function fromBatchStatus(BatchOperationStatusInterface $status, StreamedRowCount $rows): BulkSaveOutcomeInterface {
		return BulkSaveOutcome::fromStreamedBatch(status: $status, rows: $rows);
	}//end fromBatchStatus()
}//end class
