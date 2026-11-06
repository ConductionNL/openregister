<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\ResultNameCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cases were recorded on the private ObjectService::collectNamesForResults().
 *
 * @covers \OCA\OpenRegister\Service\Object\ResultNameCollector
 * @uses   \OCA\OpenRegister\Formats\UuidFormat
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ResultNameCollectorTest extends TestCase {
	private const A = '550e8400-e29b-41d4-a716-446655440000';
	private const B = '660e8400-e29b-41d4-a716-446655440000';
	private const C = '770e8400-e29b-41d4-a716-446655440000';
	private const D = '880e8400-e29b-41d4-a716-446655440000';

	/**
	 * @return array<string, array{0: array<mixed>, 1: array<int, string>}>
	 */
	public static function cases(): array {
		$a = self::A;
		$b = self::B;
		$c = self::C;
		$d = self::D;
		return [
			'no results' => [[], []],
			'results that are neither entity nor array' => [['not-an-array', 42], []],
			'direct relation, others dropped' => [[['@self' => ['relations' => [$a, 'not-uuid']]]], [$a]],
			'nested relation' => [[['@self' => ['relations' => [[$a, 'not-uuid', 7]]]]], [$a]],
			'relations that are no array' => [[['@self' => ['relations' => $a]]], []],
			'metadata before object data' => [
				[['@self' => ['relations' => [$a], 'organisation' => $b, 'owner' => $c, 'object' => ['x' => $d]]]],
				[$a, $b, $c, $d],
			],
			'metadata that is no string' => [[['@self' => ['organisation' => 5, 'owner' => null]]], []],
			'flat array' => [[['related' => $a, 'title' => 'Test']], [$a]],
			'skipped keys' => [[['id' => $a, '_id' => $b, 'object' => $c, 'kept' => $d]], [$d]],
			'non-array self falls back to the row' => [[['@self' => $a, 'kept' => $b]], [$b]],
			'self without object reads the row' => [[['@self' => ['owner' => $a], 'kept' => $b]], [$a, $b]],
			'self with a scalar object reads the row' => [[['@self' => ['object' => 'x'], 'kept' => $b]], [$b]],
			'list of uuids, nested arrays skipped' => [[['list' => [$a, 'no', [$b], $c]]], [$a, $c]],
			'duplicates keep their first position' => [[['x' => $a, 'y' => $b, 'z' => $a, 'w' => $c]], [0 => $a, 1 => $b, 3 => $c]],
			'several results' => [[['x' => $a], 'skipped', ['y' => $b]], [$a, $b]],
		];
	}//end cases()

	/**
	 * @param array<mixed>      $results  The search results.
	 * @param array<int,string> $expected The uuids handed to the name lookup.
	 */
	#[DataProvider('cases')]
	public function testCollectsTheUuidsOfSearchResults(array $results, array $expected): void {
		$this->assertSame($expected, $this->lookedUp($results));
	}//end testCollectsTheUuidsOfSearchResults()

	public function testEntityRelationsMetadataAndObjectDataInOrder(): void {
		$entity = new ObjectEntity();
		$entity->setRelations(['r' => self::A]);
		$entity->setOrganisation(self::B);
		$entity->setOwner(self::C);
		$entity->setObject(['x' => self::D, 'id' => self::A]);

		$this->assertSame([self::A, self::B, self::C, self::D], $this->lookedUp([$entity]));
	}//end testEntityRelationsMetadataAndObjectDataInOrder()

	public function testEntityWithNothingToNameSkipsTheLookup(): void {
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->expects($this->never())->method('getMultipleObjectNames');

		$this->assertSame([], $this->collect(results: [new ObjectEntity()], cache: $cache));
	}//end testEntityWithNothingToNameSkipsTheLookup()

	public function testReturnsTheNamesTheCacheResolved(): void {
		$cache = $this->createStub(CacheHandlerInterface::class);
		$cache->method('getMultipleObjectNames')->willReturn([self::A => 'First']);

		$this->assertSame([self::A => 'First'], $this->collect(results: [['x' => self::A]], cache: $cache));
	}//end testReturnsTheNamesTheCacheResolved()

	/**
	 * @param array<mixed> $results The search results.
	 *
	 * @return array<int, string> The uuids handed to the name lookup.
	 */
	private function lookedUp(array $results): array {
		$seen  = [];
		$cache = $this->createStub(CacheHandlerInterface::class);
		$cache->method('getMultipleObjectNames')->willReturnCallback(
			function (array $uuids) use (&$seen): array {
				$seen = $uuids;
				return [];
			}
		);
		$this->collect(results: $results, cache: $cache);
		return $seen;
	}//end lookedUp()

	/**
	 * @param array<mixed>          $results The search results.
	 * @param CacheHandlerInterface $cache   The name lookup.
	 *
	 * @return array<string, string> The names.
	 */
	private function collect(array $results, CacheHandlerInterface $cache): array {
		return (new ResultNameCollector(cacheHandler: $cache))->namesFor(results: $results);
	}//end collect()
}//end class
