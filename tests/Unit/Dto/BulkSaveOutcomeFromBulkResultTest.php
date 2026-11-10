<?php

/**
 * Characterisation of BulkSaveOutcome::fromBulkResult over every branch it takes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Dto
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Dto;

use OCA\OpenRegister\Dto\BulkRowCount;
use OCA\OpenRegister\Dto\BulkSaveOutcome;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Dto\BulkSaveOutcome
 */
final class BulkSaveOutcomeFromBulkResultTest extends TestCase {

	/**
	 * A complete save reports nothing.
	 *
	 * @return void
	 */
	public function testACompleteSaveHasNoFailures(): void {
		$outcome = BulkSaveOutcome::fromSaveResult(['invalid' => 'not-a-list'], new BulkRowCount(2, 2));
		$this->assertSame(0, $outcome->failedCount());
		$this->assertSame([], $outcome->failures());
		$this->assertTrue($outcome->isComplete());
	}//end testACompleteSaveHasNoFailures()

	/**
	 * Rejected rows are reported one by one; malformed entries are skipped or defaulted.
	 *
	 * @return void
	 */
	public function testRejectedRowsAreReported(): void {
		$bulk = [
			'invalid' => [
				'skipped',
				['index' => '3', 'object' => ['id' => 'u-1'], 'error' => 'bad date', 'type' => 'ValidationException'],
				['index' => 'x', 'object' => 'not-a-row'],
			],
		];
		$outcome = BulkSaveOutcome::fromSaveResult($bulk, new BulkRowCount(2, 0));
		$this->assertSame(2, $outcome->failedCount());
		$this->assertSame(
			[
				['index' => 3, 'uuid' => 'u-1', 'error' => 'bad date', 'type' => 'ValidationException'],
				['index' => null, 'uuid' => null, 'error' => 'Object was rejected without a recorded reason', 'type' => 'BulkSaveRejection'],
			],
			$outcome->failures()
		);
	}//end testRejectedRowsAreReported()

	/**
	 * Rows neither saved nor rejected become one unaccounted entry carrying the batch errors.
	 *
	 * @return void
	 */
	public function testUnaccountedRowsAreReportedWithBatchErrors(): void {
		$bulk = [
			'invalid' => [['index' => 0, 'object' => [], 'error' => 'no']],
			'errors' => [['error' => 'db down'], ['type' => 'BulkSafeguardException', 'error' => 'hidden'], 'junk'],
		];
		$outcome = BulkSaveOutcome::fromSaveResult($bulk, new BulkRowCount(5, 1));
		$this->assertSame(4, $outcome->failedCount());
		$failures = $outcome->failures();
		$this->assertCount(2, $failures);
		$this->assertSame(
			[
				'index' => null,
				'uuid' => null,
				'error' => '3 object(s) were not written and the save path recorded no per-object reason. Reported errors: db down',
				'type' => 'UnaccountedObject',
			],
			$failures[1]
		);

		$bare = BulkSaveOutcome::fromSaveResult(['errors' => 'none'], new BulkRowCount(1, 3));
		$this->assertSame(0, $bare->failedCount());
		$plain = BulkSaveOutcome::fromSaveResult([], new BulkRowCount(2, 1));
		$this->assertSame('1 object(s) were not written and the save path recorded no per-object reason.', $plain->failures()[0]['error']);
	}//end testUnaccountedRowsAreReportedWithBatchErrors()
}//end class
