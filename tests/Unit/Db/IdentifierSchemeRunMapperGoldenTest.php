<?php

/**
 * The scheme run store reads a run by id and the running run of a sequence.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\IdentifierSchemeRun;
use OCA\OpenRegister\Db\IdentifierSchemeRunMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Each mapper query, recorded and compared with the golden file.
 */
#[CoversClass(IdentifierSchemeRunMapper::class)]
#[UsesClass(IdentifierSchemeRun::class)]
final class IdentifierSchemeRunMapperGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * The golden file.
	 */
	private const FIXTURE = __DIR__ . '/fixtures/identifier-scheme-run-mapper-golden.json';

	/**
	 * The queries and results match the golden file.
	 *
	 * @return void
	 */
	public function testOutcomesMatchGolden(): void {
		$row = [
			'id' => 4, 'sequence' => 'case', 'active_sequence' => 'case', 'register_id' => 3, 'schema_id' => 9,
			'property' => 'identifier', 'previous_format' => '{year}-{seq:4}', 'state' => 'running', 'total' => 10,
			'processed' => 2, 'rewritten' => 2, 'skipped' => 0, 'failed' => 0, 'started_by' => 'admin',
			'started' => null, 'finished' => null, 'last_error' => null,
		];
		$actual = [
			'by id found' => $this->recorded(fn (IdentifierSchemeRunMapper $mapper): mixed => $mapper->findById(4)?->jsonSerialize(), [[$row]]),
			'by id missing' => $this->recorded(fn (IdentifierSchemeRunMapper $mapper): mixed => $mapper->findById(5), [[]]),
			'running found' => $this->recorded(fn (IdentifierSchemeRunMapper $mapper): mixed => $mapper->findRunning('case')?->getId(), [[$row]]),
			'running none' => $this->recorded(fn (IdentifierSchemeRunMapper $mapper): mixed => $mapper->findRunning('letter'), [[]]),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
		$this->assertSame('identifier', $actual['by id found']['result']['property']);
		$this->assertSame(4, $actual['running found']['result']);
	}//end testOutcomesMatchGolden()

	/**
	 * Run one mapper call over a recording query builder.
	 *
	 * @param callable $call The call.
	 * @param array    $rows The rows each query returns, in order.
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

		return ['result' => $call(new IdentifierSchemeRunMapper($db)), 'queries' => $this->queryLog];
	}//end recorded()
}//end class
