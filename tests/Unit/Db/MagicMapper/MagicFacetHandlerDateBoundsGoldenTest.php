<?php

/**
 * Golden test for MagicFacetHandler::getDateBoundsForBucket().
 *
 * Records the from/to bounds (or the error) for bucket keys of every shape
 * under every histogram interval, known and unknown, including leap years,
 * ISO week 53 and out-of-range quarters. An empty key is left out: as a month
 * it resolves against today's date. Re-record with UPDATE_GOLDEN=1.
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
class MagicFacetHandlerDateBoundsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/facet-date-bounds-golden.json';

	private const INTERVALS = ['year', 'quarter', 'month', 'week', 'day', 'hour', '', 'Month'];

	private const KEYS = [
		'2025', '2024', '2025-02', '2024-02', '2025-12', '2025-1', '2025-13', '2025-00',
		'2025-Q1', '2025-Q2', '2025-Q3', '2025-Q4', '2024-Q1', '2025-Q0', '2025-Q5', '2025-Q9', '25-Q1',
		'2020-53', '2021-53', '2026-01', '2025-60', '2025-02-15', '1999-W12', 'abc',
	];

	public function testDateBoundsMatchGolden(): void {
		$handler = (new ReflectionClass(MagicFacetHandler::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(MagicFacetHandler::class, 'getDateBoundsForBucket');
		$method->setAccessible(true);

		$actual = [];
		foreach (self::INTERVALS as $interval) {
			foreach (self::KEYS as $key) {
				try {
					$actual[$interval . '|' . $key] = $method->invoke($handler, $key, $interval);
				} catch (\Throwable $e) {
					$actual[$interval . '|' . $key] = ['error' => get_class($e), 'message' => $e->getMessage()];
				}
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testDateBoundsMatchGolden()
}//end class
