<?php

/**
 * Golden test for StateHistoryMapper::closeOpenInterval().
 *
 * Records every query-builder call of closing the open interval of one
 * object's lifecycle property: the moment it left, the object and property
 * predicates, and the open-interval guard. Re-record with UPDATE_GOLDEN=1.
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

use DateTime;
use OCA\OpenRegister\Db\StateHistory\TrackedProperty;
use OCA\OpenRegister\Db\StateHistoryMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\StateHistoryMapper
 */
final class StateHistoryCloseGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/state-history-close-golden.json';

	public function testOutcomesMatchGolden(): void {
		$actual = [
			'close' => $this->recorded(fn (StateHistoryMapper $mapper): mixed => $mapper->closeOpenInterval(new TrackedProperty('uuid-1', 'status'), new DateTime('2026-03-01T10:00:00+00:00')), []),
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

		return ['result' => $call(new StateHistoryMapper($db)), 'queries' => $this->queryLog];
	}//end recorded()
}//end class
