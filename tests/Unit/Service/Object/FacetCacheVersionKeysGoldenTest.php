<?php

/**
 * OpenRegister FacetCacheVersionKeysGoldenTest
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
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The exact keys, tokens and backend calls of the facet freshness counter.
 *
 * @covers \OCA\OpenRegister\Service\Object\FacetCacheVersion
 */
final class FacetCacheVersionKeysGoldenTest extends TestCase {
	/**
	 * Which keys one write bumps, in order.
	 *
	 * @return array<string, array{0: string|null, 1: string|null, 2: array<int, string>}>
	 */
	public static function writes(): array {
		$global = 'facetver:r*:s*';
		return [
			'both'              => ['7', '42', [$global, 'facetver:r*:s42', 'facetver:r7:s*', 'facetver:r7:s42']],
			'register only'     => ['7', null, [$global, 'facetver:r7:s*']],
			'schema only'       => [null, '42', [$global, 'facetver:r*:s42']],
			'neither'           => [null, null, [$global]],
			'empty strings'     => ['', '', [$global]],
			'empty register'    => ['', '42', [$global, 'facetver:r*:s42']],
			'empty schema'      => ['7', '', [$global, 'facetver:r7:s*']],
			'ids that read 0'   => ['0', '0', [$global, 'facetver:r*:s0', 'facetver:r0:s*', 'facetver:r0:s0']],
			'a star as id'      => ['*', '42', [$global, 'facetver:r*:s42']],
			'stars as both ids' => ['*', '*', [$global]],
		];
	}//end writes()

	/**
	 * A write increments its scope keys, each once, in a fixed order.
	 *
	 * @param string|null        $register Register of the write.
	 * @param string|null        $schema   Schema of the write.
	 * @param array<int, string> $expected Keys incremented, in order.
	 *
	 * @return void
	 */
	#[DataProvider('writes')]
	public function testAWriteIncrementsExactlyTheseKeys(?string $register, ?string $schema, array $expected): void {
		$incremented = [];
		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willReturnCallback(
			static function (string $key) use (&$incremented): int {
				$incremented[] = $key;
				return 1;
			}
		);

		$this->versionsOver($cache)->bump($register, $schema);

		$this->assertSame($expected, $incremented);
	}//end testAWriteIncrementsExactlyTheseKeys()

	/**
	 * What a query reads, after one write to register 7, schema 42.
	 *
	 * @return array<string, array{0: array<int, string>, 1: array<int, string>, 2: string}>
	 */
	public static function queries(): array {
		$wide = array_map('strval', range(1, 33));
		return [
			'neither'             => [[], [], '/=1'],
			'blanks only'         => [[''], ['', ''], '/=1'],
			'registers only'      => [['7', '8'], [], '7/=1,8/=0'],
			'schemas only'        => [[], ['42', '43'], '/42=1,/43=0'],
			'one pair'            => [['7'], ['42'], '7/42=1'],
			'pairs in order'      => [['7', '8'], ['42', '43'], '7/42=1,7/43=0,8/42=0,8/43=0'],
			'repeats fold'        => [['7', '7', ''], ['42', '42'], '7/42=1'],
			'gaps close'          => [['', '7'], ['', '42'], '7/42=1'],
			'a blank schema only' => [['7'], [''], '7/=1'],
			'32 pairs enumerate'  => [['7'], array_map('strval', range(11, 42)), self::row('7', range(11, 42))],
			'33 pairs go global'  => [['7'], $wide, '/=1'],
		];
	}//end queries()

	/**
	 * The token of a pair list in one register, where only pair 7/42 was written.
	 *
	 * @param string          $register Register of each pair.
	 * @param array<int, int> $schemas  Schemas of the pairs.
	 *
	 * @return string The expected token.
	 */
	private static function row(string $register, array $schemas): string {
		$parts = array_map(
			static fn (int $schema): string => $register . '/' . $schema . '=' . ($schema === 42 ? 1 : 0),
			$schemas
		);
		return implode(',', $parts);
	}//end row()

	/**
	 * A token spells out each scope a query reads and its counter.
	 *
	 * @param array<int, string> $registers Registers named by the query.
	 * @param array<int, string> $schemas   Schemas named by the query.
	 * @param string             $expected  The token.
	 *
	 * @return void
	 */
	#[DataProvider('queries')]
	public function testATokenSpellsTheScopesItReads(array $registers, array $schemas, string $expected): void {
		$versions = $this->versionsOver(new FakeMemcache());
		$versions->bump('7', '42');

		$this->assertSame($expected, $versions->tokenForScope($registers, $schemas));
	}//end testATokenSpellsTheScopesItReads()

	/**
	 * A backend that fails to increment takes the read-and-write path, with the long TTL.
	 *
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function storedCounters(): array {
		return [
			'nothing yet'  => [null, 1],
			'an int'       => [4, 5],
			'a text digit' => ['4', 5],
			'not a number' => ['stale', 1],
		];
	}//end storedCounters()

	/**
	 * An inc() answering false falls back to get and set.
	 *
	 * @param mixed $stored   What the backend holds.
	 * @param int   $expected What gets written.
	 *
	 * @return void
	 */
	#[DataProvider('storedCounters')]
	public function testAFailedIncFallsBackToReadAndWrite(mixed $stored, int $expected): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willReturn(false);
		$cache->method('get')->willReturn($stored);
		$cache->expects($this->once())->method('set')
			->with('facetver:r*:s*', $expected, FacetCacheVersion::VERSION_TTL);

		$this->versionsOver($cache)->bump(null, null);
	}//end testAFailedIncFallsBackToReadAndWrite()

	/**
	 * A plain cache has no inc(), so it always reads and writes.
	 *
	 * @return void
	 */
	public function testAPlainCacheReadsAndWrites(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(2);
		$cache->expects($this->once())->method('set')
			->with('facetver:r*:s*', 3, FacetCacheVersion::VERSION_TTL);

		$this->versionsOver($cache)->bump(null, null);
	}//end testAPlainCacheReadsAndWrites()

	/**
	 * A write that fails is logged per key, stays marked as bumped and throws nothing.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLoggedAndCountsAsBumped(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects($this->once())->method('get')->willThrowException(new RuntimeException('down'));
		$cache->expects($this->never())->method('set');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[FacetCacheVersion] could not bump facetver:r*:s*: down');

		$versions = $this->versionsOver($cache, $logger);
		$versions->bump(null, null);
		$versions->bump(null, null);
	}//end testAFailedWriteIsLoggedAndCountsAsBumped()

	/**
	 * A read that fails answers 0 and logs nothing.
	 *
	 * @return void
	 */
	public function testAFailedReadAnswersZeroInSilence(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->assertSame(0, $this->versionsOver($cache, $logger)->version('7', '42'));
	}//end testAFailedReadAnswersZeroInSilence()

	/**
	 * Stored counters read as numbers only.
	 *
	 * @return void
	 */
	public function testAStoredCounterReadsAsANumberOrZero(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnMap(
			[
				['facetver:r7:s42', '9'],
				['facetver:r7:s*', 'stale'],
				['facetver:r*:s*', null],
			]
		);
		$versions = $this->versionsOver($cache);

		$this->assertSame(9, $versions->version('7', '42'));
		$this->assertSame(0, $versions->version('7', null));
		$this->assertSame(0, $versions->version('', ''));
	}//end testAStoredCounterReadsAsANumberOrZero()

	/**
	 * Without a backend nothing is counted and the token still spells its scopes.
	 *
	 * @return void
	 */
	public function testWithoutABackendEverythingReadsZero(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willThrowException(new RuntimeException('none'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[FacetCacheVersion] cache backend unavailable: none');

		$versions = new FacetCacheVersion($factory, $logger);
		$versions->bump('7', '42');

		$this->assertSame(0, $versions->version('7', '42'));
		$this->assertSame('7/42=0', $versions->tokenForScope(['7'], ['42']));
	}//end testWithoutABackendEverythingReadsZero()

	/**
	 * Build the counter over one backend.
	 *
	 * @param ICache               $cache  The backend.
	 * @param LoggerInterface|null $logger The logger, a silent one when left out.
	 *
	 * @return FacetCacheVersion The counter.
	 */
	private function versionsOver(ICache $cache, ?LoggerInterface $logger = null): FacetCacheVersion {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new FacetCacheVersion($factory, $logger ?? $this->createMock(LoggerInterface::class));
	}//end versionsOver()
}//end class
