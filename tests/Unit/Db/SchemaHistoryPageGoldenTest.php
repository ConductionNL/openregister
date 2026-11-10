<?php

/**
 * Golden test for the per-schema history pages of SchemaChangelogMapper and SchemaRunMapper.
 *
 * Records every query-builder call of both findBySchema() reads: the schema
 * predicate, the newest-first order, and the page bounds that are given.
 * Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Db\QueryPage;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\SchemaChangelogMapper
 * @covers \OCA\OpenRegister\Db\SchemaRunMapper
 */
final class SchemaHistoryPageGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/schema-history-page-golden.json';

	public function testOutcomesMatchGolden(): void {
		$row = ['id' => 7, 'schema_id' => 3];
		$actual = [
			'changelog unbounded' => $this->recorded(fn (IDBConnection $db): mixed => count((new SchemaChangelogMapper($db))->findBySchema(3, new QueryPage(null, null))), [[$row]]),
			'changelog page' => $this->recorded(fn (IDBConnection $db): mixed => count((new SchemaChangelogMapper($db))->findBySchema(3, new QueryPage(5, 10))), [[$row]]),
			'changelog limit only' => $this->recorded(fn (IDBConnection $db): mixed => count((new SchemaChangelogMapper($db))->findBySchema(3, new QueryPage(5, null))), [[]]),
			'runs unbounded' => $this->recorded(fn (IDBConnection $db): mixed => count((new SchemaRunMapper($db))->findPageForSchema(4, new QueryPage(null, null))), [[$row]]),
			'runs page' => $this->recorded(fn (IDBConnection $db): mixed => count((new SchemaRunMapper($db))->findPageForSchema(4, new QueryPage(25, 0))), [[$row, $row]]),
			'runs offset only' => $this->recorded(fn (IDBConnection $db): mixed => count((new SchemaRunMapper($db))->findBySchema(4, null, 2)), [[]]),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
	}//end testOutcomesMatchGolden()

	/**
	 * Run one mapper call against recording builders.
	 *
	 * @param callable $call The call.
	 * @param array<int, array<int, array<string, mixed>>> $rows The row sets served to queries.
	 *
	 * @return array{result: mixed, queries: array<int, string>}
	 */
	private function recorded(callable $call, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		return ['result' => $call($db), 'queries' => $this->queryLog];
	}//end recorded()
}//end class
