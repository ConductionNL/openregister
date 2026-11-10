<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Dto;

use OCA\OpenRegister\Dto\BulkSaveOutcome;
use OCA\OpenRegister\Dto\StreamedRowCount;
use OCA\OpenRegister\Service\Object\BatchOperationStatusInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pins the streamed-batch summary: recorded failures, the unaccounted remainder, and the delegate.
 */
class BulkSaveOutcomeStreamedBatchTest extends TestCase {
	/**
	 * A status with one recorded failure.
	 *
	 * @return BatchOperationStatusInterface The status.
	 */
	private function statusWithOneFailure(): BatchOperationStatusInterface {
		$status = $this->createMock(BatchOperationStatusInterface::class);
		$status->method('getFailed')->willReturn([['index' => 1, 'uuid' => 'u-1', 'message' => 'bad', 'exceptionClass' => 'ValidationException']]);
		$status->method('getFailedCount')->willReturn(1);
		return $status;
	}//end statusWithOneFailure()

	/**
	 * Rows neither accounted for nor recorded as failed get one collective entry.
	 *
	 * @return void
	 */
	public function testUnaccountedRowsGetOneCollectiveFailure(): void {
		$outcome = BulkSaveOutcome::fromStreamedBatch(status: $this->statusWithOneFailure(), rows: new StreamedRowCount(requested: 5, accounted: 2));

		$this->assertSame(3, $outcome->failedCount);
		$this->assertSame(['index' => 1, 'uuid' => 'u-1', 'error' => 'bad', 'type' => 'ValidationException'], $outcome->failures[0]);
		$this->assertSame('UnaccountedObject', $outcome->failures[1]['type']);
		$this->assertCount(2, $outcome->failures);
	}//end testUnaccountedRowsGetOneCollectiveFailure()

	/**
	 * A fully accounted batch adds no collective entry.
	 *
	 * @return void
	 */
	public function testAccountedBatchAddsNoCollectiveFailure(): void {
		$outcome = BulkSaveOutcome::fromStreamedBatch(status: $this->statusWithOneFailure(), rows: new StreamedRowCount(requested: 3, accounted: 2));

		$this->assertSame(1, $outcome->failedCount);
		$this->assertCount(1, $outcome->failures);
	}//end testAccountedBatchAddsNoCollectiveFailure()

	/**
	 * The three-count signature answers the same as the row-count one.
	 *
	 * @return void
	 */
	public function testOldSignatureDelegates(): void {
		$old = BulkSaveOutcome::fromBatchStatus(status: $this->statusWithOneFailure(), requestedCount: 5, accountedCount: 2);
		$new = BulkSaveOutcome::fromStreamedBatch(status: $this->statusWithOneFailure(), rows: new StreamedRowCount(requested: 5, accounted: 2));

		$this->assertSame($new->failedCount, $old->failedCount);
		$this->assertSame($new->failures, $old->failures);
	}//end testOldSignatureDelegates()
}//end class
