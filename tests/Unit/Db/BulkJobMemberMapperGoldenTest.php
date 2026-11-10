<?php

/**
 * Golden test for the reads of BulkJobMemberMapper.
 *
 * Records every query-builder call and the result of the pending batch,
 * the walked count and the uuid lists, with rows and without.
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

use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Db\IdCursor;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\BulkJobMemberMapper
 * @uses   \OCA\OpenRegister\Db\BulkJobMember
 * @uses   \OCA\OpenRegister\Db\IdCursor
 */
class BulkJobMemberMapperGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/bulk-job-member-mapper-golden.json';

	private function cases(): array {
		return [
			'pending batch' => fn (BulkJobMemberMapper $m) => $m->findPendingBatch(jobId: 7, cursor: new IdCursor(afterId: 40, limit: 25)),
			'pending batch from start' => fn (BulkJobMemberMapper $m) => $m->findPendingBatch(jobId: 7, cursor: new IdCursor(afterId: 0, limit: 1)),
			'walked count' => fn (BulkJobMemberMapper $m) => $m->countWalked(jobId: 7, cursor: 40),
			'counts by outcome' => fn (BulkJobMemberMapper $m) => $m->countByOutcome(jobId: 7),
			'by job and object' => fn (BulkJobMemberMapper $m) => $m->findByJobAndObject(jobId: 7, objectUuid: 'obj-3'),
			'written uuids' => fn (BulkJobMemberMapper $m) => $m->findWrittenUuidsByJob(jobId: 7),
			'all uuids' => fn (BulkJobMemberMapper $m) => $m->findUuidsByJob(jobId: 7),
		];
	}//end cases()

	private static function rowSets(): array {
		return [
			'rows' => [['id' => 41, 'object_uuid' => 'obj-1', 'outcome' => 'applied', 'member_count' => 3], ['id' => 42, 'object_uuid' => 'obj-2', 'outcome' => 'failed', 'member_count' => 2]],
			'empty' => [],
		];
	}//end rowSets()

	public function testReadsMatchGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $call) {
			foreach (self::rowSets() as $rowName => $rows) {
				$actual["{$name} / {$rowName}"] = $this->outcomeOf(call: $call, rows: $rows);
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
	}//end testReadsMatchGolden()

	private function outcomeOf(callable $call, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [$rows];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		$outcome = [];
		try {
			$outcome['result'] = $this->shape(value: $call(new BulkJobMemberMapper($db)));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end outcomeOf()

	private function shape(mixed $value): mixed {
		if (is_object($value) === true) {
			return get_class($value) . '#' . $value->getId();
		}

		if (is_array($value) === true) {
			return array_map(fn ($v) => $this->shape(value: $v), $value);
		}

		return $value;
	}//end shape()
}//end class
