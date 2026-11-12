<?php

/**
 * Golden test for the chunk counts of ChunkMapper.
 *
 * Records every query-builder call and the result of countAll(),
 * countIndexed() and countVectorized(). Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Db\ChunkMapper
 */
class ChunkMapperCountsGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/chunk-mapper-counts-golden.json';

	public function testCountsMatchGolden(): void {
		$actual = [];
		foreach (['countAll', 'countIndexed', 'countVectorized'] as $method) {
			foreach (['rows' => [[['n' => '7']]], 'empty' => [[]]] as $name => $rows) {
				$this->queryLog = [];
				$this->builderCount = 0;
				$this->parameterCount = 0;
				$this->queuedRows = $rows;
				$db = $this->createMock(IDBConnection::class);
				$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
				$result = (new ChunkMapper($db))->$method();
				$actual["{$method} / {$name}"] = ['result' => $result, 'queries' => $this->queryLog];
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
	}//end testCountsMatchGolden()
}//end class
