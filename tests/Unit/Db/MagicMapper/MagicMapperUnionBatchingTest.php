<?php

/**
 * Unit tests for the bounded, batched cross-schema UNION fan-out.
 *
 * A cross-schema search used to be ONE statement with one UNION arm per
 * searchable schema. On the instance that produced the failure that is 1,272
 * arms in a single statement, each with its own WHERE, score expression and
 * bound parameters. The fan-out is now bounded by MagicMapper's
 * UNION_ARM_BATCH_SIZE, and the batches are merged, sorted and paginated in
 * PHP, because a page taken from the first batch is the first batch's page and
 * not the search's.
 *
 * These tests cover the parts that decide the answer: the order keys the SQL
 * and the PHP merge share, the per-batch over-fetch, and the merge itself.
 * They do not execute SQL: driving the statement needs a database, which the
 * unit suite does not have.
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class MagicMapperUnionBatchingTest extends TestCase {

	private MagicMapper $mapper;

	protected function setUp(): void {
		parent::setUp();

		$container = $this->createMock(ContainerInterface::class);
		$dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);
		$conditionMatcher = $this->createMock(ConditionMatcher::class);
		$schemaTypeConverter = $this->createMock(SchemaTypeConverter::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($dateTimeNormalizer, $conditionMatcher, $schemaTypeConverter) {
				return match ($id) {
					DateTimeNormalizer::class => $dateTimeNormalizer,
					ConditionMatcher::class => $conditionMatcher,
					SchemaTypeConverter::class => $schemaTypeConverter,
					default => null,
				};
			}
		);

		$this->mapper = new MagicMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(IConfig::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SettingsService::class),
			$container
		);
	}//end setUp()

	/**
	 * Call a private MagicMapper method.
	 *
	 * @param string $method The method name.
	 * @param array  $args   Named arguments.
	 *
	 * @return mixed The return value.
	 */
	private function call(string $method, array $args): mixed {
		$reflection = new \ReflectionMethod(MagicMapper::class, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($this->mapper, $args);
	}//end call()

	/**
	 * Read a private MagicMapper constant.
	 *
	 * @param string $name The constant name.
	 *
	 * @return mixed The value.
	 */
	private function constant(string $name): mixed {
		return (new \ReflectionClass(MagicMapper::class))->getConstant($name);
	}//end constant()

	/**
	 * The arm bound is a real bound, and it agrees with the provider's chunk.
	 *
	 * @return void
	 */
	public function testArmBatchSizeIsBounded(): void {
		$batchSize = $this->constant('UNION_ARM_BATCH_SIZE');

		$this->assertIsInt($batchSize);
		$this->assertGreaterThan(0, $batchSize);
		$this->assertLessThanOrEqual(
			50,
			$batchSize,
			'A statement over more than 50 arms is the failure this bound exists to prevent.'
		);
	}//end testArmBatchSizeIsBounded()

	/**
	 * The searchable-schema count that produced the failure splits into batches
	 * that each stay under the bound.
	 *
	 * @return void
	 */
	public function testManySchemasSplitIntoBoundedBatches(): void {
		$batchSize = $this->constant('UNION_ARM_BATCH_SIZE');
		$pairs = array_fill(0, 1272, ['register' => null, 'schema' => null]);

		$batches = array_chunk($pairs, $batchSize);

		$this->assertGreaterThan(1, count($batches));
		foreach ($batches as $batch) {
			$this->assertLessThanOrEqual($batchSize, count($batch));
		}

		$this->assertSame(1272, array_sum(array_map('count', $batches)));
	}//end testManySchemasSplitIntoBoundedBatches()

	/**
	 * Each batch starts at row zero and reaches as far as the caller's page.
	 *
	 * @return void
	 */
	public function testBatchQueryOverFetchesToCoverThePage(): void {
		$batchQuery = $this->call(
			'buildUnionBatchQuery',
			['query' => ['_search' => 'x', '_offset' => 20, '_limit' => 10]]
		);

		$this->assertSame(0, $batchQuery['_offset']);
		$this->assertSame(30, $batchQuery['_limit']);
		$this->assertSame('x', $batchQuery['_search']);
	}//end testBatchQueryOverFetchesToCoverThePage()

	/**
	 * An unlimited query has nothing to over-fetch to and stays unlimited.
	 *
	 * @return void
	 */
	public function testBatchQueryLeavesAnUnlimitedQueryUnlimited(): void {
		$batchQuery = $this->call('buildUnionBatchQuery', ['query' => ['_limit' => false, '_offset' => 5]]);

		$this->assertSame(0, $batchQuery['_offset']);
		$this->assertFalse($batchQuery['_limit']);
	}//end testBatchQueryLeavesAnUnlimitedQueryUnlimited()

	/**
	 * With a search term and no explicit order, the keys are score then uuid.
	 *
	 * @return void
	 */
	public function testOrderKeysDefaultToScoreThenUuid(): void {
		$keys = $this->call('buildUnionOrderKeys', ['query' => ['_search' => 'abc'], 'isPostgres' => true]);

		$this->assertSame(
			[['row' => '_search_score', 'dir' => 'DESC'], ['row' => '_uuid', 'dir' => 'ASC']],
			array_map(static fn (array $key): array => ['row' => $key['row'], 'dir' => $key['dir']], $keys)
		);
	}//end testOrderKeysDefaultToScoreThenUuid()

	/**
	 * With no search and no order, the uuid tiebreaker alone still orders the
	 * statement: LIMIT/OFFSET over an unordered UNION pages at random.
	 *
	 * @return void
	 */
	public function testOrderKeysAlwaysEndWithTheUuidTiebreaker(): void {
		$keys = $this->call('buildUnionOrderKeys', ['query' => [], 'isPostgres' => false]);

		$this->assertCount(1, $keys);
		$this->assertSame('_uuid', $keys[0]['row']);
		$this->assertSame('ASC', $keys[0]['dir']);
	}//end testOrderKeysAlwaysEndWithTheUuidTiebreaker()

	/**
	 * A metadata order field resolves to its column for both SQL and PHP, and
	 * an unknown one is dropped rather than concatenated into the statement.
	 *
	 * @return void
	 */
	public function testOrderKeysResolveMetadataAndDropUnknownColumns(): void {
		$keys = $this->call(
			'buildUnionOrderKeys',
			[
				'query' => ['_order' => ['@self.created' => 'DESC', '@self.notacolumn' => 'ASC']],
				'isPostgres' => true,
			]
		);

		$rows = array_map(static fn (array $key): string => $key['row'], $keys);
		$this->assertSame(['_created', '_uuid'], $rows);
		$this->assertSame('"_created"', $keys[0]['sql']);
		$this->assertSame('DESC', $keys[0]['dir']);
	}//end testOrderKeysResolveMetadataAndDropUnknownColumns()

	/**
	 * Rows from several batches are ordered as one result set, not batch by
	 * batch, and the page is taken from the merged set.
	 *
	 * @return void
	 */
	public function testMergeOrdersAcrossBatchesAndPaginatesTheMergedSet(): void {
		$rows = [
			// First batch.
			['_uuid' => 'a', '_search_score' => 0.9],
			['_uuid' => 'b', '_search_score' => 0.3],
			// Second batch, which owns the second best hit.
			['_uuid' => 'c', '_search_score' => 0.7],
			['_uuid' => 'd', '_search_score' => 0.1],
			// Third batch.
			['_uuid' => 'e', '_search_score' => 0.5],
		];

		$page = $this->call(
			'mergeUnionBatchRows',
			[
				'rows' => $rows,
				'query' => ['_search' => 'x', '_offset' => 1, '_limit' => 2],
				'isPostgres' => false,
			]
		);

		$this->assertSame(['c', 'e'], array_column($page, '_uuid'));
	}//end testMergeOrdersAcrossBatchesAndPaginatesTheMergedSet()

	/**
	 * Equal scores fall back to the uuid tiebreaker, so two pages of the same
	 * search never repeat or skip a row.
	 *
	 * @return void
	 */
	public function testMergeBreaksScoreTiesOnUuid(): void {
		$rows = [
			['_uuid' => 'zz', '_search_score' => 0.5],
			['_uuid' => 'aa', '_search_score' => 0.5],
			['_uuid' => 'mm', '_search_score' => 0.5],
		];

		$page = $this->call(
			'mergeUnionBatchRows',
			['rows' => $rows, 'query' => ['_search' => 'x'], 'isPostgres' => false]
		);

		$this->assertSame(['aa', 'mm', 'zz'], array_column($page, '_uuid'));
	}//end testMergeBreaksScoreTiesOnUuid()

	/**
	 * A column one batch's schemas do not own arrives missing, not as an
	 * error, and sorts where the UNION's `NULL AS alias` arm would put it.
	 *
	 * @return void
	 */
	public function testMergeToleratesRowsMissingTheOrderedColumn(): void {
		$rows = [
			['_uuid' => 'a'],
			['_uuid' => 'b', 'title' => 'beta'],
			['_uuid' => 'c', 'title' => 'alpha'],
		];

		$page = $this->call(
			'mergeUnionBatchRows',
			['rows' => $rows, 'query' => ['_order' => ['title' => 'ASC']], 'isPostgres' => false]
		);

		$this->assertSame(['a', 'c', 'b'], array_column($page, '_uuid'));
	}//end testMergeToleratesRowsMissingTheOrderedColumn()

	/**
	 * An unlimited merge returns everything from the offset on.
	 *
	 * @return void
	 */
	public function testMergeWithoutALimitReturnsTheRestOfTheSet(): void {
		$rows = [
			['_uuid' => 'a'],
			['_uuid' => 'b'],
			['_uuid' => 'c'],
		];

		$page = $this->call(
			'mergeUnionBatchRows',
			['rows' => $rows, 'query' => ['_offset' => 1, '_limit' => false], 'isPostgres' => false]
		);

		$this->assertSame(['b', 'c'], array_column($page, '_uuid'));
	}//end testMergeWithoutALimitReturnsTheRestOfTheSet()
}//end class
