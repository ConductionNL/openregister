<?php

/**
 * BatchOperationStatus: the tally one bulk write keeps.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\BatchOperationStatus;
use OCA\OpenRegister\Service\Object\RowFailure;
use PHPUnit\Framework\TestCase;

/**
 * Counts, the duration guard and the array form. No test pins a clock value.
 *
 * @covers \OCA\OpenRegister\Service\Object\BatchOperationStatus
 */
class BatchOperationStatusTest extends TestCase {

	/**
	 * Before start and completion there is no duration; start and complete keep their first moment.
	 *
	 * @return void
	 */
	public function testTheDurationNeedsBothEndsAndTheFirstMomentsStay(): void {
		$status = new BatchOperationStatus();
		$this->assertNull($status->getDurationSeconds());
		$status->start();
		$this->assertNull($status->getDurationSeconds(), 'started but not completed');
		$started = $status->toArray()['startedAt'];
		usleep(1000);
		$status->start();
		$this->assertSame($started, $status->toArray()['startedAt']);

		$status->complete();
		$completed = $status->toArray()['completedAt'];
		usleep(1000);
		$status->complete();

		$this->assertSame($completed, $status->toArray()['completedAt']);
		$this->assertGreaterThanOrEqual(0.0, $status->getDurationSeconds());
		$this->assertSame($completed - $started, $status->getDurationSeconds());
	}//end testTheDurationNeedsBothEndsAndTheFirstMomentsStay()

	/**
	 * Each outcome is listed and counted; processed is their sum; cache hits and misses count separately.
	 *
	 * @return void
	 */
	public function testOutcomesAreListedCountedAndSummed(): void {
		$status = new BatchOperationStatus();
		$status->recordCreated('c-1');
		$status->recordCreated('c-2');
		$status->recordUpdated('u-1');
		$status->recordUnchanged('n-1');
		$status->recordFailed(new RowFailure(uuid: null, message: 'invalid', exceptionClass: 'RuntimeException', index: 4));
		$status->recordReferenceCacheHit();
		$status->recordReferenceCacheHit();
		$status->recordReferenceCacheMiss();

		$this->assertSame(['c-1', 'c-2'], $status->getCreated());
		$this->assertSame(['u-1'], $status->getUpdated());
		$this->assertSame(['n-1'], $status->getUnchanged());
		$this->assertSame([['uuid' => null, 'message' => 'invalid', 'exceptionClass' => 'RuntimeException', 'index' => 4]], $status->getFailed());
		$this->assertSame(5, $status->getProcessedCount());
		$array = $status->toArray();
		unset($array['startedAt'], $array['completedAt']);
		$this->assertSame(
			[
				'durationSeconds' => null,
				'processedCount' => 5,
				'createdCount' => 2,
				'updatedCount' => 1,
				'unchangedCount' => 1,
				'failedCount' => 1,
				'created' => ['c-1', 'c-2'],
				'updated' => ['u-1'],
				'unchanged' => ['n-1'],
				'failed' => [['uuid' => null, 'message' => 'invalid', 'exceptionClass' => 'RuntimeException', 'index' => 4]],
				'referenceCacheHits' => 2,
				'referenceCacheMisses' => 1,
			],
			$array
		);
		$this->assertSame(2, $status->getReferenceCacheHits());
		$this->assertSame(1, $status->getReferenceCacheMisses());
	}//end testOutcomesAreListedCountedAndSummed()
}//end class
