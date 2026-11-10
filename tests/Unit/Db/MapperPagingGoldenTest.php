<?php

/**
 * Golden test for the limit and offset of the paged list reads in lib/Db.
 *
 * Records every query-builder call of each paged read with no page, a limit
 * only, an offset only and both. Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\OutcomeFilter;
use OCA\OpenRegister\Db\QueryPage;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * @covers \OCA\OpenRegister\Files\Db\AnonymisationLogMapper
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @covers \OCA\OpenRegister\Db\BulkJobMapper
 * @covers \OCA\OpenRegister\Db\BulkJobMemberMapper
 * @covers \OCA\OpenRegister\Files\Db\ChunkMapper
 * @covers \OCA\OpenRegister\Db\EmailLinkMapper
 * @covers \OCA\OpenRegister\Db\EndpointLogMapper
 * @covers \OCA\OpenRegister\Db\ImportPreviewMapper
 * @covers \OCA\OpenRegister\Db\ImportPreviewRowMapper
 * @covers \OCA\OpenRegister\Db\SchemaChangelogMapper
 * @covers \OCA\OpenRegister\Db\SchemaRunEntryMapper
 * @covers \OCA\OpenRegister\Db\SchemaRunMapper
 * @covers \OCA\OpenRegister\Db\SearchTrailMapper
 * @covers \OCA\OpenRegister\Db\WebhookLogMapper
 * @covers \OCA\OpenRegister\Db\WebhookMapper
 * @uses   \OCA\OpenRegister\Db\QueryPage
 * @uses   \OCA\OpenRegister\Db\OutcomeFilter
 */
class MapperPagingGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/mapper-paging-golden.json';

	private static function sites(): array {
		return [
			'anonymisation log by file' => [AnonymisationLogMapper::class, 'findByFileId', ['fileId' => 4, 'query' => ListQuery::class]],
			'anonymisation log by object' => [AnonymisationLogMapper::class, 'findByObjectUuid', ['objectUuid' => 'o-1', 'query' => ListQuery::class]],
			'audit trail all' => [AuditTrailMapper::class, 'findAll', []],
			'bulk job by actor' => [BulkJobMapper::class, 'findByActor', ['startedBy' => 'alice', 'state' => 'running']],
			'bulk job all' => [BulkJobMapper::class, 'findAllJobs', ['state' => null]],
			'email link by object' => [EmailLinkMapper::class, 'findByObjectUuid', ['objectUuid' => 'o-2']],
			'endpoint log all (newest first trait)' => [EndpointLogMapper::class, 'findAll', []],
			'import preview by actor' => [ImportPreviewMapper::class, 'findByActor', ['createdBy' => 'bob', 'query' => ListQuery::class]],
			'schema changelog by schema' => [SchemaChangelogMapper::class, 'findBySchema', ['schemaId' => 3, 'query' => ListQuery::class]],
			'schema run entry by run' => [SchemaRunEntryMapper::class, 'findByRun', ['runId' => 5, 'outcome' => 'failed']],
			'schema run by schema' => [SchemaRunMapper::class, 'findBySchema', ['schemaId' => 6, 'query' => ListQuery::class]],
			'search trail all' => [SearchTrailMapper::class, 'findAll', []],
			'webhook log by webhook' => [WebhookLogMapper::class, 'findByWebhook', ['webhookId' => 7, 'page' => null]],
			'chunk unvectorized' => [ChunkMapper::class, 'findUnvectorized', []],
			'chunk file source summaries' => [ChunkMapper::class, 'getFileSourceSummaries', ['search' => 'abc']],
			'webhook all' => [WebhookMapper::class, 'findAll', ['filters' => ['a' => 1]]],
			'bulk job member by job' => [BulkJobMemberMapper::class, 'findByJob', ['jobId' => 8, 'outcome' => 'failed']],
			'import preview row by preview' => [ImportPreviewRowMapper::class, 'findByPreview', ['previewId' => 9, 'query' => ListQuery::class, 'filters' => ['decision' => 'skip']]],
		];
	}//end sites()

	private static function pages(): array {
		return [
			'no page' => [],
			'limit' => ['limit' => 5],
			'offset' => ['offset' => 20],
			'both' => ['limit' => 0, 'offset' => 0],
		];
	}//end pages()

	public function testPagedReadsMatchGolden(): void {
		$actual = [];
		foreach (self::sites() as $site => [$class, $method, $args]) {
			foreach (self::pages() as $pageName => $page) {
				$actual["{$site} / {$pageName}"] = $this->read(class: $class, call: [$method, self::withPage(args: $args, page: $page)]);
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
	}//end testPagedReadsMatchGolden()

	public function testEveryPagedReadSetsBothBounds(): void {
		foreach (self::sites() as $site => [$class, $method, $args]) {
			$calls = preg_replace('/^#\d+ /', '', $this->read(class: $class, call: [$method, self::withPage(args: $args, page: ['limit' => 5, 'offset' => 20])])['queries']);
			$this->assertContains('setMaxResults(5)', $calls, $site);
			$this->assertContains('setFirstResult(20)', $calls, $site);
		}
	}//end testEveryPagedReadSetsBothBounds()

	/**
	 * The call arguments for a page; outcome lists take the outcome and page as one filter,
	 * and ListQuery reads take the page as one ListQuery.
	 */
	private static function withPage(array $args, array $page): array {
		if (array_key_exists('page', $args) === true) {
			return ['page' => new ListQuery(($page['limit'] ?? ListQuery::UNBOUNDED), ($page['offset'] ?? null))] + $args;
		}

		if (($args['query'] ?? null) === ListQuery::class) {
			$filters = ($args['filters'] ?? []);
			unset($args['filters']);
			return ['query' => new ListQuery(limit: ($page['limit'] ?? ListQuery::UNBOUNDED), offset: ($page['offset'] ?? null), filters: $filters)] + $args;
		}

		if (array_key_exists('outcome', $args) === false) {
			return $args + $page;
		}

		$filter = new OutcomeFilter(outcome: $args['outcome'], page: new QueryPage(limit: $page['limit'] ?? null, offset: $page['offset'] ?? null));
		unset($args['outcome']);
		return $args + ['filter' => $filter];
	}//end withPage()

	private function read(string $class, array $call): array {
		[$method, $args] = $call;
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [];

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$db->method('escapeLikeParameter')->willReturnCallback(fn (string $value): string => addcslashes($value, '%_\\'));

		$outcome = [];
		try {
			$outcome['count'] = count($this->mapper(class: $class, db: $db)->$method(...$args));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end read()

	private function mapper(string $class, IDBConnection $db): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			if ($param->getName() === 'db') {
				$args[] = $db;
				continue;
			}

			if ($type instanceof ReflectionNamedType && $type->isBuiltin() === false && (new ReflectionClass($type->getName()))->isFinal() === true) {
				// A final service bundle cannot be doubled: build it from doubles of its own services.
				$args[] = $this->mapper(class: $type->getName(), db: $db);
				continue;
			}

			if ($type instanceof ReflectionNamedType && $type->isBuiltin() === false) {
				$args[] = $this->createMock($type->getName());
				continue;
			}

			$args[] = $param->getDefaultValue();
		}

		return new $class(...$args);
	}//end mapper()
}//end class
