<?php

/**
 * Golden test for MagicFacetHandler::normalizeArrayFacetBuckets().
 *
 * Records the buckets it answers for raw facet rows holding empty keys, JSON
 * arrays (valid, invalid, empty, padded, with empty elements), JSON-encoded
 * scalars and objects, plain strings and non-string keys, and the cap on the
 * number of buckets. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 */
class MagicFacetHandlerArrayBucketsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/facet-array-buckets-golden.json';

	private static function scenarios(): array {
		$many = [];
		for ($i = 0; $i < 10005; $i++) {
			$many[] = ['key' => 'v' . $i, 'count' => ($i % 7) + 1];
		}

		return [
			'empty' => [],
			'skipped keys' => [
				['key' => null, 'count' => 4],
				['key' => '', 'count' => 3],
				['key' => 'null', 'count' => 2],
				['key' => 'kept', 'count' => 1],
			],
			'arrays and singles merge' => [
				['key' => '["a","b"]', 'count' => 3],
				['key' => 'a', 'count' => 2],
				['key' => '  ["b", "c"]', 'count' => 1],
				['key' => '["c"]', 'count' => 5],
			],
			'array elements' => [
				['key' => '["x", null, "", 5, true, false, 1.5, "null", 0]', 'count' => 2],
				['key' => '[]', 'count' => 9],
				['key' => '[null, ""]', 'count' => 9],
			],
			'invalid and non-array json' => [
				['key' => '[invalid', 'count' => 1],
				['key' => '["unterminated"', 'count' => 2],
				['key' => '[1, 2]x', 'count' => 3],
			],
			'json scalars' => [
				['key' => '"quoted"', 'count' => 1],
				['key' => '5', 'count' => 2],
				['key' => '1.50', 'count' => 3],
				['key' => 'true', 'count' => 4],
				['key' => 'false', 'count' => 5],
				['key' => '-0', 'count' => 6],
			],
			'json objects' => [
				['key' => '{"k":"v"}', 'count' => 1],
				['key' => '{"a":1,"b":2}', 'count' => 2],
				['key' => '{}', 'count' => 3],
				['key' => '{"n":null}', 'count' => 4],
			],
			'non-string keys' => [
				['key' => 7, 'count' => 1],
				['key' => 2.5, 'count' => 2],
				['key' => true, 'count' => 3],
				['key' => false, 'count' => 4],
				['key' => 0, 'count' => 5],
			],
			'plain strings and ties' => [
				['key' => 'plain', 'count' => 2],
				['key' => 'other', 'count' => 2],
				['key' => 'Plain', 'count' => 2],
				['key' => '07', 'count' => 1],
				['key' => '7', 'count' => 1],
			],
			'string counts' => [
				['key' => 'a', 'count' => '3'],
				['key' => '["a"]', 'count' => '2'],
			],
			'capped' => $many,
		];
	}//end scenarios()

	public function testArrayBucketsMatchGolden(): void {
		$handler = (new ReflectionClass(MagicFacetHandler::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(MagicFacetHandler::class, 'normalizeArrayFacetBuckets');
		$method->setAccessible(true);

		$actual = [];
		foreach (self::scenarios() as $name => $rawBuckets) {
			$buckets = $method->invoke($handler, $rawBuckets);
			if (count($buckets) > 20) {
				$buckets = [
					'count' => count($buckets),
					'head' => array_slice($buckets, 0, 5),
					'tail' => array_slice($buckets, -5),
				];
			}

			$actual[$name] = $buckets;
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testArrayBucketsMatchGolden()
}//end class
