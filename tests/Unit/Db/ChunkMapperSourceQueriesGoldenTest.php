<?php

/**
 * Golden test for the by-source queries of ChunkMapper.
 *
 * Records every query-builder call of findBySource(), deleteBySource() and
 * getLatestUpdatedTimestamp(). Re-record with UPDATE_GOLDEN=1.
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
 * The queries that select or delete one source's chunks.
 */
class ChunkMapperSourceQueriesGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/chunk-mapper-source-queries-golden.json';

	public function testSourceQueriesMatchGolden(): void {
		$calls = [
			'findBySource' => static fn (ChunkMapper $mapper): mixed => $mapper->findBySource('file', 42),
			'deleteBySource' => static fn (ChunkMapper $mapper): mixed => $mapper->deleteBySource('file', 42),
			'getLatestUpdatedTimestamp' => static fn (ChunkMapper $mapper): mixed => $mapper->getLatestUpdatedTimestamp('file', 42),
		];

		$actual = [];
		foreach ($calls as $name => $call) {
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = [[]];
			$db = $this->createMock(IDBConnection::class);
			$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
			$call(new ChunkMapper($db));
			$actual[$name] = $this->queryLog;
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);
	}//end testSourceQueriesMatchGolden()
}//end class
