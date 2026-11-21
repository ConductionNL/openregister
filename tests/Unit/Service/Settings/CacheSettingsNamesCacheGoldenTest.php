<?php

/**
 * Golden test for the names-cache clear and warm-up results of CacheSettingsHandler.
 *
 * Pins every key and value of both results, the before and after statistics
 * included, so the two methods can be split without changing what the cache
 * settings page shows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Settings\SchemaCacheClearers;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The names-cache results, pinned whole.
 */
class CacheSettingsNamesCacheGoldenTest extends TestCase {

	/**
	 * A handler over an object cache whose statistics are read in turn.
	 *
	 * @param CacheHandler|null $cache The object cache, or null for none.
	 *
	 * @return CacheSettingsHandler The handler.
	 */
	private function handler(?CacheHandler $cache): CacheSettingsHandler {
		return new CacheSettingsHandler(
			$this->createMock(ICacheFactory::class),
			new SchemaCacheClearers($this->createMock(SchemaCacheHandler::class), $this->createMock(FacetResultCacheClearer::class)),
			$cache,
			null
		);
	}//end handler()

	/**
	 * An object cache answering these statistics, one per read.
	 *
	 * @param array<int, array<string, int>> $stats The statistics, in read order.
	 *
	 * @return CacheHandler The cache.
	 */
	private function cacheAnswering(array $stats): CacheHandler {
		$cache = $this->createMock(CacheHandler::class);
		$cache->method('getStats')->willReturnOnConsecutiveCalls(...$stats);

		return $cache;
	}//end cacheAnswering()

	public function testClearingTheNamesCacheReportsBeforeAndAfter(): void {
		$cache = $this->cacheAnswering(
			[
				['name_cache_size' => 50, 'name_hits' => 100, 'name_misses' => 10, 'other' => 1],
				['name_cache_size' => 4],
			]
		);
		$cache->expects($this->once())->method('clearNameCache');

		$this->assertSame(
			[
				'service' => 'names',
				'cleared' => 46,
				'before' => ['name_cache_size' => 50, 'name_hits' => 100, 'name_misses' => 10],
				'after' => ['name_cache_size' => 4, 'name_hits' => 0, 'name_misses' => 0],
				'success' => true,
			],
			$this->handler($cache)->clearCache('names')['results']['names']
		);
	}//end testClearingTheNamesCacheReportsBeforeAndAfter()

	public function testWarmingTheNamesCacheReportsBeforeAndAfter(): void {
		$cache = $this->cacheAnswering(
			[
				['name_cache_size' => 3, 'name_warmups' => 1, 'name_hits' => 9],
				['name_cache_size' => 28],
			]
		);
		$cache->method('warmupNameCache')->willReturn(25);

		$result = $this->handler($cache)->warmupNamesCache();

		$this->assertSame(['success', 'loaded_names', 'execution_time', 'before', 'after'], array_keys($result));
		$this->assertMatchesRegularExpression('/^\d+(\.\d+)?ms$/', $result['execution_time']);
		unset($result['execution_time']);
		$this->assertSame(
			[
				'success' => true,
				'loaded_names' => 25,
				'before' => ['name_cache_size' => 3, 'name_warmups' => 1],
				'after' => ['name_cache_size' => 28, 'name_warmups' => 0],
			],
			$result
		);
	}//end testWarmingTheNamesCacheReportsBeforeAndAfter()

	public function testAFailedWarmUpSaysWhy(): void {
		$cache = $this->cacheAnswering([['name_cache_size' => 3]]);
		$cache->method('warmupNameCache')->willThrowException(new RuntimeException('db gone'));

		$this->assertSame(
			['success' => false, 'error' => 'Cache warmup failed: db gone', 'loaded_names' => 0],
			$this->handler($cache)->warmupNamesCache()
		);
		$this->assertSame(
			['success' => false, 'error' => 'Cache warmup failed: CacheHandler not available', 'loaded_names' => 0],
			$this->handler(null)->warmupNamesCache()
		);
	}//end testAFailedWarmUpSaysWhy()
}//end class
