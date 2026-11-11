<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use JsonSerializable;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Support\EmptyValueStripper;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden test for how ObjectsController strips empty values and collects the uuids of a rendered object.
 *
 * Set UPDATE_GOLDEN=1 to rewrite fixtures/objects-response-values-golden.json.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @covers \OCA\OpenRegister\Controller\Support\EmptyValueStripper
 * @covers \OCA\OpenRegister\Controller\Support\RenderedObjectUuids
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 */
class ObjectsControllerResponseValuesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-response-values-golden.json';
	private const A = '11111111-1111-4111-8111-111111111111';
	private const B = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE';
	private const C = '33333333-3333-4333-8333-333333333333';

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<mixed>}>
	 */
	public static function stripCases(): array {
		return [
			'nothing' => [[]],
			'null and empty string go' => [['a' => null, 'b' => '', 'c' => 'x']],
			'zero false and zero string stay' => [['a' => 0, 'b' => false, 'c' => '0', 'd' => 0.0]],
			'blank string stays' => [['a' => ' ']],
			'empty array goes' => [['a' => []]],
			'nested map' => [['a' => ['b' => null, 'c' => ['d' => ''], 'e' => 1]]],
			'map of empties goes' => [['a' => ['b' => null, 'c' => ['d' => '']]]],
			'list scalars stay, nulls too' => [['a' => [1, null, '', 'x']]],
			'list of maps' => [['a' => [['b' => null, 'c' => 1], ['d' => '']]]],
			'list keeps an emptied item' => [['a' => [['b' => null]]]],
			'list inside list item keeps its keys' => [['a' => [[1, null, 2]]]],
			'list of lists' => [['a' => [[[null], []]]]],
			'top level list' => [[null, 'a', '', ['b' => null], [], [1, null]]],
			'non-list int keys' => [['a' => [3 => null, 5 => 'x']]],
			'object stays' => [['a' => (object)['x' => 1]]],
		];
	}

	/**
	 * @return array<string, array{0: array<mixed>}>
	 */
	public static function nameCases(): array {
		return [
			'nothing' => [[]],
			'relations strings' => [['@self' => ['relations' => [self::A, 'no', self::B]]]],
			'relations map' => [['@self' => ['relations' => ['x' => self::A, 'y' => self::C]]]],
			'relations nested once' => [['@self' => ['relations' => [[self::A, 'no', 5], self::C]]]],
			'relations nested twice are skipped' => [['@self' => ['relations' => [[[self::A]]]]]],
			'relations not an array' => [['@self' => ['relations' => self::A], 'p' => self::B]],
			'relations numbers' => [['@self' => ['relations' => [5, null, true]]]],
			'properties' => [['p' => self::A, 'q' => 'no', 'r' => 7]],
			'@self object wins over the top level' => [['@self' => ['object' => ['p' => self::A]], 'q' => self::B]],
			'@self object not an array' => [['@self' => ['object' => 'text'], 'q' => self::B]],
			'@self key is skipped at the top' => [['@self' => ['x' => self::A], 'q' => self::B]],
			'id keys are skipped' => [['id' => self::A, '_id' => self::B, 'q' => self::C]],
			'array of uuids' => [['p' => [self::A, 'no', self::C]]],
			'nested maps recurse' => [['p' => [['q' => self::A, 'id' => self::B, 'r' => [self::C]]]]],
			'map value of strings' => [['p' => ['q' => self::A]]],
			'deep nesting' => [['p' => [['q' => [['r' => [['s' => self::C]]]]]]]],
			'duplicates' => [['@self' => ['relations' => [self::A, self::A]], 'p' => self::A, 'q' => self::B]],
			'uuid with text around' => [['p' => 'x' . self::A, 'q' => self::A . ' ']],
			'integer keys' => [[self::A, [self::C]]],
		];
	}

	#[DataProvider('stripCases')]
	public function testStrippedValuesMatchTheRecording(array $data): void {
		$this->compare(key: 'strip ' . $this->dataName(), actual: (new EmptyValueStripper())->stripped(data: $data));
	}

	public function testResultRowsMatchTheRecording(): void {
		$serializable = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['a' => null, 'b' => 1];
			}
		};
		$plain = new class {
			public string $kept = 'x';
		};
		$rows = ['one' => ['a' => null, 'b' => ''], 'two' => $serializable, 'three' => $plain, 'four' => ['c' => [['d' => '']]]];
		$this->compare(key: 'results', actual: $this->privateCall('stripEmptyFromResults', [$rows]));
	}

	#[DataProvider('nameCases')]
	public function testCollectedNamesMatchTheRecording(array $data): void {
		$asked = [];
		$cache = $this->createMock(ObjectNameCacheInterface::class);
		$cache->method('getMultipleObjectNames')->willReturnCallback(function (array $uuids) use (&$asked) {
			$asked[] = $uuids;
			return array_map(static fn (string $uuid): string => 'name of ' . $uuid, array_values($uuids));
		});
		$names = $this->privateCall('collectNamesForResponse', [$data, $cache]);
		$this->compare(key: 'names ' . $this->dataName(), actual: ['asked' => $asked, 'names' => $names]);
	}

	public function testNoCacheAnswersNoNames(): void {
		$this->assertSame([], $this->privateCall('collectNamesForResponse', [['p' => self::A], null]));
	}

	/**
	 * @param list<mixed> $args
	 */
	private function privateCall(string $method, array $args): mixed {
		$reflection = new ReflectionClass(ObjectsController::class);
		return $reflection->getMethod($method)->invokeArgs($reflection->newInstanceWithoutConstructor(), $args);
	}

	private function compare(string $key, mixed $actual): void {
		$actual = json_decode((string)json_encode($actual, JSON_FORCE_OBJECT), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded ??= [];
			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) . "\n");
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
