<?php

/**
 * Golden test for ExportRunMapper::findForActor().
 *
 * Records every query-builder call and the result over the actor scoping
 * (an administrator with no actor, one actor), the period bounds and the
 * column filters, including the ones that are dropped. Re-record with
 * UPDATE_GOLDEN=1.
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
use DateTimeZone;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ExportRunMapper
 */
final class ExportRunMapperFindGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/export-run-mapper-find-golden.json';

	/**
	 * findForActor() argument sets, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$from = new DateTime('2026-01-02T03:04:05+00:00', new DateTimeZone('UTC'));
		$until = new DateTime('2026-02-03T04:05:06+00:00', new DateTimeZone('UTC'));
		return [
			'administrator, defaults' => ['actor' => null],
			'one actor' => ['actor' => 'anna'],
			'paging' => ['actor' => 'anna', 'limit' => 5, 'offset' => 10],
			'period' => ['actor' => null, 'filters' => ['from' => $from, 'until' => $until]],
			'from only' => ['actor' => 'anna', 'filters' => ['from' => $from]],
			'until only' => ['actor' => 'anna', 'filters' => ['until' => $until]],
			'period as strings is ignored' => ['actor' => 'anna', 'filters' => ['from' => '2026-01-01', 'until' => '2026-02-01']],
			'every column' => [
				'actor' => null,
				'filters' => ['register' => 'r', 'schema' => 's', 'profile' => 'p', 'source' => 'api', 'status' => 'ready', 'actor' => 'bob'],
			],
			'actor filter cannot widen a scoped read' => ['actor' => 'anna', 'filters' => ['actor' => 'bob']],
			'dropped filters' => [
				'actor' => 'anna',
				'filters' => ['bogus' => 'x', 'status' => '', 'register' => ['a'], 'schema' => null, 'profile' => 7, 'source' => true],
			],
			'filters and period order' => ['actor' => 'anna', 'filters' => ['status' => 'ready', 'until' => $until, 'register' => 'r', 'from' => $from]],
		];
	}//end cases()

	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => $args) {
			$actual[$name] = $this->outcome(args: $args);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
	}//end testOutcomesMatchGolden()

	public function testAnActorReadIsScopedAndAnAdministratorReadIsNot(): void {
		// Mutation check: dropping the actor predicate would make these equal.
		$open = $this->outcome(args: ['actor' => null]);
		$scoped = $this->outcome(args: ['actor' => 'anna']);

		$this->assertNotSame($open['queries'], $scoped['queries']);
		$this->assertContains('#1 param :p1 = anna, 2', $scoped['queries']);
		$this->assertContains('#1 expr.eq(actor, :p1, NULL)', $scoped['queries']);
	}//end testAnActorReadIsScopedAndAnAdministratorReadIsNot()

	/**
	 * Run listForActor() against a recording connection.
	 *
	 * @param array<string, mixed> $args Named arguments.
	 *
	 * @return array<string, mixed> The result and the query log.
	 */
	private function outcome(array $args): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [[['id' => 1, 'uuid' => 'e1', 'actor' => 'anna', 'status' => 'ready']]];

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$mapper = new ExportRunMapper(db: $db);

		$outcome = [];
		try {
			$outcome['result'] = array_map(
				static fn (ExportRun $run): array => ['uuid' => $run->getUuid(), 'actor' => $run->getActor()],
				$mapper->listForActor(
					actor: ($args['actor'] ?? null),
					query: new ListQuery(limit: ($args['limit'] ?? 50), offset: ($args['offset'] ?? 0), filters: ($args['filters'] ?? []))
				)
			);
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end outcome()
}//end class
