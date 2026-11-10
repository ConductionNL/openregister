<?php

/**
 * Golden test for the scope queries of SequenceMapper.
 *
 * Records every query-builder call of findForScope(), incrementScope() and
 * raiseTo(): the three scope predicates, the increment, and the conditional
 * raise that never moves a counter down. Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Db\SequenceMapper;
use OCA\OpenRegister\Service\SequenceScope;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\SequenceMapper
 */
final class SequenceMapperScopeGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/sequence-mapper-scope-golden.json';

	public function testOutcomesMatchGolden(): void {
		$row = ['id' => 7, 'register_id' => 3, 'schema_id' => 4, 'scope_key' => '2026', 'next_value' => 12];
		$actual = [
			'find found' => $this->recorded(fn (SequenceMapper $mapper): mixed => $mapper->findForScope(new SequenceScope(3, 4, '2026'))?->getNextValue(), [[$row]]),
			'find missing' => $this->recorded(fn (SequenceMapper $mapper): mixed => $mapper->findForScope(new SequenceScope(3, 4, '')), [[]]),
			'increment' => $this->recorded(fn (SequenceMapper $mapper): mixed => $mapper->incrementScope(new SequenceScope(3, 4, '2026-06')), []),
			'raise' => $this->recorded(fn (SequenceMapper $mapper): mixed => $mapper->raiseTo(new SequenceScope(1, 2, '2026'), 121), []),
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

		return ['result' => $call(new SequenceMapper($db)), 'queries' => $this->queryLog];
	}//end recorded()
}//end class
