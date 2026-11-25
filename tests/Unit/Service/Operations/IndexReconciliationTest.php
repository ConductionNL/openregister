<?php

/**
 * The five search index probes of the consistency check and their repairs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  Unit\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\ConsistencyCheckWouldWriteException;
use OCA\OpenRegister\Exception\RepairRefusedException;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairService;
use OCA\OpenRegister\Service\Operations\IndexReconciliationProbes;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCA\OpenRegister\Service\Operations\OperatorAct;
use OCA\OpenRegister\Kernel\Contracts\FileExtractionQueueInterface;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Each probe is driven through the real ConsistencyCheckService over a recording
 * query builder: the test reads the query the probe built (its tables, joins and
 * conditions) and the finding it reports for the rows the database answers.
 */
#[CoversClass(IndexReconciliationProbes::class)]
#[CoversClass(ConsistencyCheckService::class)]
#[CoversClass(ConsistencyRepairService::class)]
final class IndexReconciliationTest extends TestCase {

	/** @var array<int, list<string>> The calls each built query received, in order. */
	private array $calls = [];

	/** @var array<string, list<array<string, mixed>>> The rows a select from each table answers. */
	private array $tables = [];

	/** @var list<mixed> Every bound value, in order. */
	private array $params = [];

	/** @var list<array{verb: string, table: string, ids: mixed}> The writes, in order. */
	private array $writes = [];

	/** @var list<int> The file ids queued for extraction. */
	private array $queued = [];

	/** @var list<array<string, mixed>> The repair acts recorded. */
	private array $acts = [];

	private string $provider = IDBConnection::PLATFORM_POSTGRES;

	private bool $vectorisation = true;

	private IDBConnection $db;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
		$this->db->method('getDatabaseProvider')->willReturnCallback(fn (): string => $this->provider);
	}

	/**
	 * A query builder that records what a probe or a repair does with it.
	 */
	private function builder(): IQueryBuilder {
		$index = count($this->calls);
		$this->calls[$index] = [];
		$state = ['from' => '', 'verb' => 'SELECT', 'ids' => null];
		$qb = $this->createMock(IQueryBuilder::class);
		$str = static fn (array $args): string => implode(',', array_map('strval', array_filter($args, static fn ($a) => $a !== null)));

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'gt', 'gte', 'isNull', 'like', 'notLike', 'in'] as $op) {
			$expr->method($op)->willReturnCallback(static fn (...$args): string => $op . '(' . $str($args) . ')');
		}
		$expr->method('andX')->willReturnCallback(static function (...$parts) use ($str): ICompositeExpression {
			return new class ('and(' . $str($parts) . ')') implements ICompositeExpression {
				public function __construct(private string $text) {
				}

				public function addMultiple(array $parts = []): ICompositeExpression {
					return $this;
				}

				public function add($part): ICompositeExpression {
					return $this;
				}

				public function count(): int {
					return 1;
				}

				public function getType(): string {
					return 'AND';
				}

				public function __toString(): string {
					return $this->text;
				}
			};
		});
		$expr->method('castColumn')->willReturnCallback(function ($column) {
			$function = $this->createMock(IQueryFunction::class);
			$function->method('__toString')->willReturn('text(' . $column . ')');
			return $function;
		});
		$qb->method('expr')->willReturn($expr);
		$qb->method('getConnection')->willReturn($this->db);
		$qb->method('createNamedParameter')->willReturnCallback(function ($value) use (&$state) {
			$this->params[] = $value;
			if (is_array($value) === true) {
				$state['ids'] = $value;
			}
			return ':p' . (count($this->params) - 1);
		});
		$qb->method('createFunction')->willReturnCallback(function (string $sql) {
			$function = $this->createMock(IQueryFunction::class);
			$function->method('__toString')->willReturn($sql);
			return $function;
		});
		foreach (['select', 'from', 'leftJoin', 'innerJoin', 'where', 'andWhere', 'orderBy', 'set'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$args) use ($qb, $method, $index, $str, &$state) {
				$this->calls[$index][] = $method . ' ' . $str($args);
				if ($method === 'from') {
					$state['from'] = $args[0];
				}
				return $qb;
			});
		}
		foreach (['delete' => 'DELETE', 'update' => 'UPDATE'] as $method => $verb) {
			$qb->method($method)->willReturnCallback(function (string $table) use ($qb, $verb, &$state) {
				$state['verb'] = $verb;
				$state['from'] = $table;
				return $qb;
			});
		}
		$qb->method('getSQL')->willReturnCallback(static function () use (&$state): string {
			return $state['verb'] . ' * FROM ' . $state['from'];
		});
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('executeQuery')->willReturnCallback(function () use (&$state) {
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn($this->tables[$state['from']] ?? []);
			return $result;
		});
		$qb->method('executeStatement')->willReturnCallback(function () use (&$state): int {
			$this->writes[] = ['verb' => $state['verb'], 'table' => $state['from'], 'ids' => $state['ids']];
			return count((array) $state['ids']);
		});

		return $qb;
	}

	private function probes(): IndexReconciliationProbes {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key): string => $key === 'fileManagement' ? (string) json_encode(['vectorizationEnabled' => $this->vectorisation]) : ''
		);

		return new IndexReconciliationProbes($appConfig);
	}

	private function check(): ConsistencyCheckService {
		return new ConsistencyCheckService($this->db, null, $this->probes());
	}

	private function repair(?FileExtractionQueueInterface $jobs = null): ConsistencyRepairService {
		$recorder = $this->createMock(JobRunRecorder::class);
		$recorder->method('recordAct')->willReturnCallback(function (OperatorAct $act): null {
			$this->acts[] = ['actor' => $act->actor, 'details' => $act->details, 'message' => $act->message];
			return null;
		});

		return new ConsistencyRepairService($this->db, $this->check(), $recorder, $jobs);
	}

	private function jobs(): FileExtractionQueueInterface {
		$jobs = $this->createMock(FileExtractionQueueInterface::class);
		$jobs->method('queueFileExtraction')->willReturnCallback(function (int $fileId): void {
			$this->queued[] = $fileId;
		});

		return $jobs;
	}

	public function testTheIndexProbesJoinTheShippedOnesUnderStableSlugs(): void {
		$this->assertSame(
			['orphan-relations', 'orphan-follows', 'runs-never-closed', 'index-file-missing', 'index-chunk-orphaned', 'index-chunk-stale', 'index-embedding-missing', 'index-embedding-orphaned'],
			$this->check()->slugs()
		);
		$this->assertSame(
			['orphan-relations', 'orphan-follows', 'runs-never-closed'],
			(new ConsistencyCheckService($this->db))->slugs(),
			'Without the index probes wired the shipped set is unchanged.'
		);
	}

	public function testAMissingFileIsReported(): void {
		$this->tables = ['filecache' => [['fileid' => 41, 'path' => 'files/a.pdf'], ['fileid' => 42, 'path' => 'files/b.pdf'], ['fileid' => 43, 'path' => 'files/c.pdf']]];

		$finding = $this->check()->checkOne('index-file-missing');

		$this->assertSame(3, $finding['count']);
		$this->assertSame([41, 42, 43], array_column($finding['objects'], 'fileid'));
		$this->assertNull($finding['unavailable']);
		$query = $this->calls[0];
		$this->assertSame('select fc.fileid,fc.path', $query[0]);
		$this->assertSame('from filecache,fc', $query[1]);
		$this->assertContains('leftJoin fc,openregister_chunks,ch,and(eq(ch.source_id,fc.fileid),eq(ch.source_type,:p0))', $query);
		$this->assertContains('where isNull(ch.id)', $query);
		$this->assertSame('file', $this->params[0]);
		$this->assertContains('httpd/unix-directory', $this->params, 'directories are not files in scope');
		$this->assertContains('%files_trashbin%', $this->params, 'trashed files are out of scope');
		$this->assertContains('files/%', $this->params, 'only a user\'s files folder is in scope');
	}

	public function testAnOrphanedChunkIsReported(): void {
		$this->tables = ['openregister_chunks' => [['id' => 7, 'source_id' => 99, 'chunk_index' => 0], ['id' => 8, 'source_id' => 99, 'chunk_index' => -1]]];

		$finding = $this->check()->checkOne('index-chunk-orphaned');

		$this->assertSame(2, $finding['count']);
		$this->assertSame(
			['select ch.id,ch.source_id,ch.chunk_index', 'from openregister_chunks,ch', 'leftJoin ch,filecache,fc,eq(fc.fileid,ch.source_id)', 'where eq(ch.source_type,:p0)', 'andWhere isNull(fc.fileid)', 'orderBy ch.id,ASC'],
			$this->calls[0]
		);
	}

	public function testAStaleChunkIsReported(): void {
		$this->tables = ['openregister_chunks' => [['id' => 11, 'source_id' => 5, 'updated_at' => '2026-01-01 00:00:00']]];

		$finding = $this->check()->checkOne('index-chunk-stale');

		$this->assertSame(1, $finding['count']);
		$this->assertContains('innerJoin ch,filecache,fc,eq(fc.fileid,ch.source_id)', $this->calls[0]);
		$this->assertContains('andWhere lt(ch.updated_at,TO_TIMESTAMP(fc.mtime))', $this->calls[0]);
	}

	public function testTheStaleProbeReadsTheModificationTimeInEachDialect(): void {
		$this->assertSame('TO_TIMESTAMP(fc.mtime)', IndexReconciliationProbes::unixToDatetime(IDBConnection::PLATFORM_POSTGRES, 'fc.mtime'));
		$this->assertSame('FROM_UNIXTIME(fc.mtime)', IndexReconciliationProbes::unixToDatetime(IDBConnection::PLATFORM_MYSQL, 'fc.mtime'));
		$this->assertSame('FROM_UNIXTIME(fc.mtime)', IndexReconciliationProbes::unixToDatetime(IDBConnection::PLATFORM_MARIADB, 'fc.mtime'));
		$this->assertSame("DATETIME(fc.mtime, 'unixepoch')", IndexReconciliationProbes::unixToDatetime(IDBConnection::PLATFORM_SQLITE, 'fc.mtime'));
		$this->assertStringContainsString('NUMTODSINTERVAL(fc.mtime', IndexReconciliationProbes::unixToDatetime(IDBConnection::PLATFORM_ORACLE, 'fc.mtime'));

		$this->provider = IDBConnection::PLATFORM_MYSQL;
		$this->check()->checkOne('index-chunk-stale');
		$this->assertContains('andWhere lt(ch.updated_at,FROM_UNIXTIME(fc.mtime))', $this->calls[0]);
	}

	public function testAMissingEmbeddingIsReportedOnlyWhenVectorisationIsOn(): void {
		$this->tables = ['openregister_chunks' => [['id' => 21, 'source_id' => 5, 'chunk_index' => 2]]];

		$on = $this->check()->checkOne('index-embedding-missing');

		$this->assertSame(1, $on['count']);
		$this->assertNull($on['unavailable']);
		$this->assertContains(
			'leftJoin ch,openregister_vectors,v,and(eq(v.entity_type,ch.source_type),eq(v.entity_id,text(ch.source_id)),eq(v.chunk_index,ch.chunk_index))',
			$this->calls[0]
		);
		$this->assertContains('andWhere isNull(v.id)', $this->calls[0]);
		$this->assertContains(true, $this->params, 'only chunks recorded as vectorised are missing; the rest are queued');

		$this->vectorisation = false;
		$this->calls = [];
		$off = $this->check()->checkOne('index-embedding-missing');

		$this->assertSame(0, $off['count']);
		$this->assertSame([], $off['objects']);
		$this->assertSame('Vectorisation is switched off, so missing embeddings are not checked.', $off['unavailable']);
		$this->assertSame([], $this->calls, 'a probe that does not apply runs no query');
	}

	public function testAnOrphanedEmbeddingIsReported(): void {
		$this->tables = ['openregister_vectors' => [['id' => 31, 'entity_id' => '99', 'chunk_index' => 0]]];

		$finding = $this->check()->checkOne('index-embedding-orphaned');

		$this->assertSame(1, $finding['count']);
		$this->assertSame('select v.id,v.entity_id,v.chunk_index', $this->calls[0][0]);
		$this->assertContains(
			'leftJoin v,openregister_chunks,ch,and(eq(v.entity_type,ch.source_type),eq(v.entity_id,text(ch.source_id)),eq(v.chunk_index,ch.chunk_index))',
			$this->calls[0]
		);
		$this->assertContains('andWhere isNull(ch.id)', $this->calls[0]);
	}

	public function testEveryIndexProbeIsARead(): void {
		$report = $this->check()->check();

		$this->assertSame(8, $report['checked']);
		$this->assertSame([], $this->writes, 'the check writes nothing');

		$writing = new ConsistencyCheckService(
			$this->db,
			['index-file-missing' => ['title' => 't', 'description' => 'd', 'table' => 'filecache', 'query' => static fn (IQueryBuilder $qb): IQueryBuilder => $qb->delete('filecache')]]
		);
		$this->expectException(ConsistencyCheckWouldWriteException::class);
		$writing->check();
	}

	public function testRepairQueuesExtractionForMissingFiles(): void {
		$this->tables = ['filecache' => [['fileid' => 41, 'path' => 'files/a.pdf'], ['fileid' => 42, 'path' => 'files/b.pdf'], ['fileid' => 43, 'path' => 'files/c.pdf']]];

		$outcome = $this->repair($this->jobs())->apply('index-file-missing', 'admin');

		$this->assertSame(3, $outcome['changed']);
		$this->assertSame(
			[41, 42, 43],
			$this->queued
		);
		$this->assertSame([], $this->writes, 'queueing the extractions changes nothing else');
		$this->assertSame('admin', $this->acts[0]['actor']);
	}

	public function testRepairQueuesAStaleFileOnceForAllItsChunks(): void {
		$this->tables = ['openregister_chunks' => [['id' => 11, 'source_id' => 5], ['id' => 12, 'source_id' => 5], ['id' => 13, 'source_id' => 6]]];

		$outcome = $this->repair($this->jobs())->apply('index-chunk-stale', 'admin');

		$this->assertSame(2, $outcome['changed']);
		$this->assertSame([5, 6], $this->queued);
		$this->assertSame([], $this->writes, 'the stale chunks keep answering until the new ones land');
	}

	public function testRepairDeletesOrphans(): void {
		$this->tables = [
			'openregister_chunks' => [['id' => 7, 'source_id' => 99, 'chunk_index' => 0], ['id' => 8, 'source_id' => 99, 'chunk_index' => -1]],
			'openregister_vectors' => [['id' => 31, 'entity_id' => '99', 'chunk_index' => 0]],
		];
		$repair = $this->repair($this->jobs());

		$chunks = $repair->apply('index-chunk-orphaned', 'admin');
		$vectors = $repair->apply('index-embedding-orphaned', 'admin');

		$this->assertSame(2, $chunks['changed']);
		$this->assertSame(1, $vectors['changed']);
		$this->assertSame(
			[['verb' => 'DELETE', 'table' => 'openregister_chunks', 'ids' => [7, 8]], ['verb' => 'DELETE', 'table' => 'openregister_vectors', 'ids' => [31]]],
			$this->writes
		);
		$this->assertSame([], $this->queued);
	}

	public function testRepairMarksChunksWithoutAnEmbeddingForVectorisation(): void {
		$this->tables = ['openregister_chunks' => [['id' => 21, 'source_id' => 5, 'chunk_index' => 2]]];

		$outcome = $this->repair($this->jobs())->apply('index-embedding-missing', 'admin');

		$this->assertSame(1, $outcome['changed']);
		$this->assertSame([['verb' => 'UPDATE', 'table' => 'openregister_chunks', 'ids' => [21]]], $this->writes);
		$this->assertContains(false, $this->params, 'the chunk is marked unvectorised');
		$this->assertContains('where in(id,:p' . (count($this->params) - 1) . ')', end($this->calls));
	}

	public function testAQueueingRepairWithoutAJobListIsRefused(): void {
		$this->tables = ['filecache' => [['fileid' => 41, 'path' => 'files/a.pdf']]];

		$this->expectException(RepairRefusedException::class);
		$this->repair()->apply('index-file-missing', 'admin');
	}
}
