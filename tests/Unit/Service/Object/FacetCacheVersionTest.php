<?php

/**
 * OpenRegister FacetCacheVersionTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Service\Object\FacetCacheVersion;
use OCA\OpenRegister\Tests\Unit\Support\FakeMemcache;
use OCA\OpenRegister\Tests\Unit\Support\PlainFakeCache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Scoping rules of the facet freshness counter.
 *
 * The counter decides how wide an object write reaches. Too narrow and a stale
 * bucket list survives the write that changed it; too wide and every index page
 * in the fleet recomputes on every write in the fleet. These tests pin both edges.
 */
final class FacetCacheVersionTest extends TestCase {
	private FakeMemcache $cache;

	private FacetCacheVersion $versions;

	/**
	 * Build the counter over an in-memory cache.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->cache = new FakeMemcache();
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);

		$this->versions = new FacetCacheVersion($cacheFactory, $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * A cold instance keys exactly as it did before the counter existed.
	 *
	 * @return void
	 */
	public function testAnUnwrittenScopeReadsAsZero(): void {
		$this->assertSame(0, $this->versions->version('7', '42'));
		$this->assertSame(0, $this->versions->version(null, null));
	}//end testAnUnwrittenScopeReadsAsZero()

	/**
	 * A write moves the token of the scope it landed in.
	 *
	 * @return void
	 */
	public function testAWriteMovesTheTokenOfItsOwnScope(): void {
		$before = $this->versions->tokenForScope(['7'], ['42']);

		$this->versions->bump('7', '42');

		$this->assertNotSame(
			$before,
			$this->versions->tokenForScope(['7'], ['42']),
			'the scope written to must produce a different token'
		);
	}//end testAWriteMovesTheTokenOfItsOwnScope()

	/**
	 * A write leaves every other scope's token alone.
	 *
	 * This is the whole reason the counter is per scope rather than a flush.
	 *
	 * @return void
	 */
	public function testAWriteLeavesOtherScopesUntouched(): void {
		$otherSchema = $this->versions->tokenForScope(['7'], ['43']);
		$otherRegister = $this->versions->tokenForScope(['8'], ['42']);

		$this->versions->bump('7', '42');

		$this->assertSame($otherSchema, $this->versions->tokenForScope(['7'], ['43']));
		$this->assertSame($otherRegister, $this->versions->tokenForScope(['8'], ['42']));
	}//end testAWriteLeavesOtherScopesUntouched()

	/**
	 * A query naming schemas but no register still sees writes to those schemas.
	 *
	 * Multi-schema publication queries arrive this way, and they must not be
	 * left on the TTL just because the register is implicit.
	 *
	 * @return void
	 */
	public function testASchemaOnlyQuerySeesAWriteToThatSchemaInAnyRegister(): void {
		$before = $this->versions->tokenForScope([], ['42']);
		$otherSchemaBefore = $this->versions->tokenForScope([], ['43']);

		$this->versions->bump('7', '42');

		$this->assertNotSame($before, $this->versions->tokenForScope([], ['42']));
		$this->assertSame(
			$otherSchemaBefore,
			$this->versions->tokenForScope([], ['43']),
			'a schema-only query for a different schema is unaffected'
		);
	}//end testASchemaOnlyQuerySeesAWriteToThatSchemaInAnyRegister()

	/**
	 * A query naming a register but no schema sees writes anywhere in it.
	 *
	 * @return void
	 */
	public function testARegisterOnlyQuerySeesAWriteToAnySchemaInThatRegister(): void {
		$before = $this->versions->tokenForScope(['7'], []);
		$otherRegisterBefore = $this->versions->tokenForScope(['8'], []);

		$this->versions->bump('7', '42');

		$this->assertNotSame($before, $this->versions->tokenForScope(['7'], []));
		$this->assertSame(
			$otherRegisterBefore,
			$this->versions->tokenForScope(['8'], []),
			'a register nobody wrote to keeps its token'
		);
	}//end testARegisterOnlyQuerySeesAWriteToAnySchemaInThatRegister()

	/**
	 * A query naming nothing is answered from everything, so any write moves it.
	 *
	 * @return void
	 */
	public function testAnUnscopedQuerySeesEveryWrite(): void {
		$before = $this->versions->tokenForScope([], []);

		$this->versions->bump('99', '999');

		$this->assertNotSame($before, $this->versions->tokenForScope([], []));
	}//end testAnUnscopedQuerySeesEveryWrite()

	/**
	 * A query spanning more pairs than the ceiling widens to the global counter.
	 *
	 * The token must not grow with the query: a cross-collection search is
	 * invalidated by almost any write anyway.
	 *
	 * @return void
	 */
	public function testAVeryWideQueryFallsBackToTheGlobalCounter(): void {
		$registers = array_map('strval', range(1, 10));
		$schemas = array_map('strval', range(100, 109));

		$wide = $this->versions->tokenForScope($registers, $schemas);

		$this->assertSame(
			$this->versions->tokenForScope([], []),
			$wide,
			'100 pairs is past the ceiling, so the token is the global one'
		);

		$this->versions->bump('1', '100');

		$this->assertSame(
			$this->versions->tokenForScope([], []),
			$this->versions->tokenForScope($registers, $schemas),
			'and it stays the global one after a write'
		);
		$this->assertNotSame($wide, $this->versions->tokenForScope($registers, $schemas));
	}//end testAVeryWideQueryFallsBackToTheGlobalCounter()

	/**
	 * One write costs four counter increments, and no read of the data cache.
	 *
	 * The cost of invalidation is the thing that decides whether this fix is
	 * worth having, so it is asserted rather than described.
	 *
	 * @return void
	 */
	public function testOneWriteCostsFourCounterIncrementsAndNothingElse(): void {
		$this->cache->reads = 0;
		$this->cache->writes = 0;

		$this->versions->bump('7', '42');

		$this->assertSame(4, $this->cache->writes, 'pair, schema, register and global');
		$this->assertSame(0, $this->cache->reads, 'an atomic increment reads nothing back');
	}//end testOneWriteCostsFourCounterIncrementsAndNothingElse()

	/**
	 * A backend without atomic increment still records a bump, with headroom.
	 *
	 * The fallback writes an explicit TTL. It has to outlive the facet entries
	 * it invalidates, or a counter could expire back to zero while a superseded
	 * facet response was still live and reachable again.
	 *
	 * @return void
	 */
	public function testTheFallbackCounterOutlivesTheFacetEntriesItInvalidates(): void {
		$plainCache = new PlainFakeCache();
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($plainCache);

		$versions = new FacetCacheVersion($cacheFactory, $this->createMock(LoggerInterface::class));

		$before = $versions->tokenForScope(['7'], ['42']);
		$versions->bump('7', '42');

		$this->assertNotSame($before, $versions->tokenForScope(['7'], ['42']));
		$this->assertGreaterThan(
			3600,
			$plainCache->lastTtl,
			'the counter must outlive FacetHandler::FACET_CACHE_TTL'
		);
	}//end testTheFallbackCounterOutlivesTheFacetEntriesItInvalidates()
}//end class
