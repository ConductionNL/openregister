<?php

/**
 * Unit tests for ErasureRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
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

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use OCA\OpenRegister\Service\Gdpr\ErasureRequest;
use OCA\OpenRegister\Service\Gdpr\ErasureRun;
use PHPUnit\Framework\TestCase;

/**
 * Pins the summary an erasure run answers with, and when it counts as complete.
 *
 * @covers \OCA\OpenRegister\Service\Gdpr\ErasureRun
 * @uses \OCA\OpenRegister\Service\Gdpr\ErasureRequest
 */
final class ErasureRunTest extends TestCase {

	/**
	 * A run that only erased is complete and echoes its request.
	 *
	 * @return void
	 */
	public function testARunThatOnlyErasedIsComplete(): void {
		$request = new ErasureRequest(subjectId: 'bsn-1', type: 'bsn', eraseMode: 'whole-object', dryRun: true);
		$run     = new ErasureRun(request: $request, matchedCount: 2);
		$run->record(bucket: 'erased', row: ['uuid' => 'o1']);
		$run->record(bucket: 'erased', row: ['uuid' => 'o2']);

		$this->assertSame(expected: $request, actual: $run->request);
		$this->assertSame(
			expected: [
				'subject'       => 'bsn-1',
				'type'          => 'bsn',
				'eraseMode'     => 'whole-object',
				'dryRun'        => true,
				'matchedCount'  => 2,
				'erased'        => [['uuid' => 'o1'], ['uuid' => 'o2']],
				'held'          => [],
				'failed'        => [],
				'withheld'      => [],
				'complete'      => true,
				'failedCount'   => 0,
				'heldCount'     => 0,
				'withheldCount' => 0,
			],
			actual: $run->close()
		);
	}//end testARunThatOnlyErasedIsComplete()

	/**
	 * A failed, held or withheld record each makes the run incomplete, and is counted.
	 *
	 * @return void
	 */
	public function testFailedHeldOrWithheldMakeItIncomplete(): void {
		foreach (['failed', 'held', 'withheld'] as $bucket) {
			$run = new ErasureRun(request: new ErasureRequest(subjectId: 's'), matchedCount: 1);
			$run->record(bucket: $bucket, row: ['uuid' => 'o1']);

			$summary = $run->close();

			$this->assertFalse(condition: $summary['complete'], message: $bucket);
			$this->assertSame(expected: 1, actual: $summary[$bucket.'Count'], message: $bucket);
			$this->assertSame(expected: 'pseudonymise', actual: $summary['eraseMode']);
		}
	}//end testFailedHeldOrWithheldMakeItIncomplete()
}//end class
