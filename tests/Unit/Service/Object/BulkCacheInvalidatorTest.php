<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Service\Object\BulkCacheInvalidator;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\SavedScopeIds;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\BulkCacheInvalidator
 * @uses   \OCA\OpenRegister\Service\Object\SavedScopeIds
 */
class BulkCacheInvalidatorTest extends TestCase {
	public function testBulkSaveWithNothingAffectedClearsNothing(): void {
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->expects($this->never())->method('invalidateForObjectChange');

		$invalidator = new BulkCacheInvalidator($cache, $this->createStub(LoggerInterface::class));
		$invalidator->afterBulkSave(['statistics' => []], new SavedScopeIds(1, 2));
	}//end testBulkSaveWithNothingAffectedClearsNothing()

	public function testBulkSaveClearsTheScopeItRanUnder(): void {
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->expects($this->once())->method('invalidateForObjectChange')->with(null, 'bulk_save', 1, 2);

		$invalidator = new BulkCacheInvalidator($cache, $this->createStub(LoggerInterface::class));
		$invalidator->afterBulkSave(['statistics' => ['objectsUpdated' => 1]], new SavedScopeIds(1, 2));
	}//end testBulkSaveClearsTheScopeItRanUnder()

	public function testFailedBulkSaveClearIsLoggedWithItsScope(): void {
		$cache = $this->createStub(CacheHandlerInterface::class);
		$cache->method('invalidateForObjectChange')->willThrowException(new Exception('cache down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[ObjectService] Bulk save cache invalidation failed',
			['error' => 'cache down', 'totalAffected' => 3, 'registerId' => 1, 'schemaId' => null]
		);

		$invalidator = new BulkCacheInvalidator($cache, $logger);
		$invalidator->afterBulkSave(
			['statistics' => ['objectsCreated' => 1, 'objectsUpdated' => 2]],
			new SavedScopeIds(1, null)
		);
	}//end testFailedBulkSaveClearIsLoggedWithItsScope()

	public function testBulkDeleteWithoutPairsFallsBackToABroadClear(): void {
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->expects($this->once())->method('invalidateForObjectChange')->with(null, 'bulk_delete', null, null);

		$invalidator = new BulkCacheInvalidator($cache, $this->createStub(LoggerInterface::class));
		$invalidator->afterBulkDelete(['deleted' => ['a'], 'pairs' => []]);
	}//end testBulkDeleteWithoutPairsFallsBackToABroadClear()

	public function testBulkDeleteThatDeletedNothingClearsNothing(): void {
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->expects($this->never())->method('invalidateForObjectChange');

		$invalidator = new BulkCacheInvalidator($cache, $this->createStub(LoggerInterface::class));
		$invalidator->afterBulkDelete(['deleted' => [], 'pairs' => []]);
	}//end testBulkDeleteThatDeletedNothingClearsNothing()

	public function testOneFailedPairDoesNotStopTheNext(): void {
		$seen  = [];
		$cache = $this->createStub(CacheHandlerInterface::class);
		$cache->method('invalidateForObjectChange')->willReturnCallback(
			function (...$args) use (&$seen): void {
				$seen[] = $args[3];
				if ($args[3] === 2) {
					throw new Exception('cache down');
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[ObjectService] Bulk delete cache invalidation failed',
			['error' => 'cache down', 'deletedCount' => 2, 'registerId' => 1, 'schemaId' => 2]
		);

		$invalidator = new BulkCacheInvalidator($cache, $logger);
		$invalidator->afterBulkDelete(
			[
				'deleted' => ['a', 'b'],
				'pairs'   => [
					['registerId' => 1, 'schemaId' => 2],
					['registerId' => 1, 'schemaId' => 3],
				],
			]
		);

		$this->assertSame([2, 3], $seen);
	}//end testOneFailedPairDoesNotStopTheNext()
}//end class
