<?php

/**
 * SchemaRowCache keys stored rows on id and `updated` stamp and stays out of the way without a local cache.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-write-sub-500ms/tasks.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

use OCA\OpenRegister\Db\SchemaMapper\SchemaRowCache;
use OCA\OpenRegister\Tests\Unit\Db\ArrayCacheDouble;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper\SchemaRowCache
 */
class SchemaRowCacheTest extends TestCase {
	private const ROW = ['id' => 7, 'uuid' => 'u7', 'slug' => 'seven', 'updated' => '2026-11-16 10:00:00', 'properties' => '{"a":{}}'];

	/**
	 * The cache the row cache writes to.
	 *
	 * @var ArrayCacheDouble
	 */
	private ArrayCacheDouble $store;

	protected function setUp(): void {
		$this->store = new ArrayCacheDouble();
	}

	public function testAStoredRowAnswersAProbeWithTheSameIdAndStamp(): void {
		$cache = new SchemaRowCache($this->factory());
		$cache->put(self::ROW);

		self::assertTrue($cache->isAvailable());
		self::assertSame(self::ROW, $cache->get(['id' => '7', 'updated' => '2026-11-16 10:00:00']));
		self::assertSame(['row:7:2026-11-16 10:00:00' => SchemaRowCache::TTL], $this->store->ttls);
	}

	public function testANewerStampMisses(): void {
		$cache = new SchemaRowCache($this->factory());
		$cache->put(self::ROW);

		self::assertNull($cache->get(['id' => 7, 'updated' => '2026-11-16 10:00:01']));
	}

	public function testARowWithoutAStampIsNeverStoredOrServed(): void {
		$cache = new SchemaRowCache($this->factory());
		$cache->put(['id' => 7, 'updated' => null]);
		$cache->put(['id' => 7, 'updated' => '']);

		self::assertSame([], $this->store->values);
		self::assertNull($cache->get(['id' => 7, 'updated' => null]));
		self::assertNull(SchemaRowCache::key(['id' => 'x', 'updated' => '2026-11-16 10:00:00']));
	}

	public function testAnEntryUnderTheWrongKeyIsNotServed(): void {
		$this->store->values['row:7:2026-11-16 10:00:00'] = ['id' => 8, 'updated' => '2026-11-16 10:00:00'];
		$cache = new SchemaRowCache($this->factory());

		self::assertNull($cache->get(['id' => 7, 'updated' => '2026-11-16 10:00:00']));
	}

	public function testForgetDropsEveryStampOfThatSchemaOnly(): void {
		$cache = new SchemaRowCache($this->factory());
		$cache->put(self::ROW);
		$cache->put(['id' => 7, 'updated' => '2026-11-16 09:00:00']);
		$cache->put(['id' => 70, 'updated' => '2026-11-16 10:00:00']);

		$cache->forget(7);

		self::assertSame(['row:7:'], $this->store->cleared);
		self::assertSame(['row:70:2026-11-16 10:00:00'], array_keys($this->store->values));
	}

	public function testWithoutALocalCacheNothingIsStored(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isLocalCacheAvailable')->willReturn(false);
		$factory->expects(self::never())->method('createLocal');
		$cache = new SchemaRowCache($factory);
		$cache->put(self::ROW);
		$cache->forget(7);

		self::assertFalse($cache->isAvailable());
		self::assertNull($cache->get(self::ROW));
		self::assertFalse((new SchemaRowCache(null))->isAvailable());
	}

	public function testAFactoryThatThrowsDisablesTheCache(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isLocalCacheAvailable')->willReturn(true);
		$factory->method('createLocal')->willThrowException(new RuntimeException('no apcu'));

		self::assertFalse((new SchemaRowCache($factory))->isAvailable());
	}

	private function factory(): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isLocalCacheAvailable')->willReturn(true);
		$factory->method('createLocal')->with('openregister_schema_rows')->willReturn($this->store);
		return $factory;
	}
}//end class
