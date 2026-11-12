<?php

/**
 * Golden test for MagicMapper::runUnionBatch().
 *
 * Records the table checks, the prepared UNION SQL with its ordering and
 * paging, the log lines and the rows per platform, register+schema pair set
 * and query, so a decomposition keeps the exact statement. Re-record with
 * UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
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

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionOrder
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionPage
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionArm
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses   \OCA\OpenRegister\Support\OrderParameter
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RecentLensOrder
 * @uses   \OCA\OpenRegister\Support\QueryLimit
 * @uses   \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses   \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 * @uses   \OCA\OpenRegister\Search\Service\Search\PropertySearchProfile
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Search\SearchTermParser
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchLeaf
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchLeafScope
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionScore
 */
class MagicMapperUnionBatchGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/union-batch-golden.json';

	private array $events = [];

	/**
	 * What a union statement answers, when not the default two rows.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $unionRows = null;

	/**
	 * The row numbers each batch holds, when statements answer from them.
	 *
	 * @var array<string, array<int, int>>|null
	 */
	private ?array $batchTables = null;

	/**
	 * The limit and offset of every statement per batch.
	 *
	 * @var array<string, array<int, array{0: int|null, 1: int}>>
	 */
	private array $batchReads = [];

	/**
	 * The uuids of the rows converted to objects, in order.
	 *
	 * @var array<int, string>
	 */
	private array $converted = [];

	private static function queries(): array {
		return [
			'empty' => [],
			'search' => ['_search' => 'zaak'],
			'ordered page' => ['_search' => 'zaak', '_order' => ['title' => 'desc', '@self.created' => 'asc'], '_limit' => 5, '_offset' => 10],
			'title order' => ['_order' => ['title' => 'desc'], '_limit' => 5, '_offset' => 10],
			'created order' => ['_order' => ['@self.created' => 'asc', 'count' => 'ASC']],
			'unlimited with offset' => ['_limit' => false, '_offset' => 3],
			'unlimited with an offset of one' => ['_limit' => false, '_offset' => 1],
			'unlimited' => ['_limit' => false],
			'over max' => ['_limit' => 5000, '_offset' => -4],
			'zero limit' => ['_limit' => 0],
			'a property under another spelling' => ['_order' => ['Title' => 'asc']],
			'unknown fields' => ['_order' => ['_foo' => 'asc', 'nope' => 'desc', 'title' => 'asc']],
			'only unknown fields, searching' => ['_search' => 'zaak', '_order' => ['_foo' => 'asc']],
		];
	}//end queries()

	private static function pairSets(): array {
		return [
			'none' => [],
			'two' => ['a', 'b'],
			'mixed' => ['a', 'b', 'c', 'no schema', 'no register'],
			'disabled only' => ['c'],
			'over budget' => ['a', 'wide'],
		];
	}//end pairSets()

	public function testRunUnionBatchMatchesGolden(): void {
		$actual = [];
		foreach (['postgres', 'mysql'] as $platform) {
			foreach (self::pairSets() as $setName => $pairNames) {
				foreach (self::queries() as $queryName => $query) {
					$name = "{$platform} / {$setName} / {$queryName}";
					$actual[$name] = $this->runBatch(platform: $platform, pairNames: $pairNames, query: $query);
				}
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
	}//end testRunUnionBatchMatchesGolden()

	public function testTheMergeOfManyBatchesLeavesOutAPropertyUnderAnotherSpelling(): void {
		$this->unionRows = [
			['_uuid' => 'u-1', 'title' => 'z', '_union_register_id' => 1, '_union_schema_id' => 2],
			['_uuid' => 'u-2', 'title' => 'a', '_union_register_id' => 1, '_union_schema_id' => 2],
		];
		$pairs = array_map(fn (int $index): array => $this->pair(name: 'a'), range(1, 51));

		$this->makeMapper(platform: 'postgres')->searchAcrossMultipleTables(query: ['_order' => ['Title' => 'asc']], registerSchemaPairs: $pairs);

		$this->assertSame(['u-1', 'u-1', 'u-2', 'u-2'], $this->converted);
	}//end testTheMergeOfManyBatchesLeavesOutAPropertyUnderAnotherSpelling()

	private function runBatch(string $platform, array $pairNames, array $query): array {
		$this->events = [];
		foreach (['columnExistsCache', 'columnTypeCache'] as $cache) {
			$property = new \ReflectionProperty(MagicMapper::class, $cache);
			$property->setAccessible(true);
			$property->setValue(null, []);
		}

		$mapper = $this->makeMapper(platform: $platform);
		$pairs = array_map(fn (string $name): array => $this->pair(name: $name), $pairNames);

		$outcome = [];
		try {
			$method = new \ReflectionMethod(MagicMapper::class, 'runUnionBatch');
			$method->setAccessible(true);
			$outcome['rows'] = $method->invoke($mapper, $query, $pairs);
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['events'] = $this->events;
		return $outcome;
	}//end runBatch()

	private function pair(string $name): array {
		$register = new Register();
		$register->setId(1);
		$register->setConfiguration(['schemas' => ['3' => ['magicMapping' => true]]]);
		if ($name === 'no schema') {
			return ['register' => $register];
		}

		if ($name === 'no register') {
			$schema = new Schema();
			$schema->setId(6);
			return ['schema' => $schema];
		}

		$properties = [
			'a' => ['title' => ['type' => 'string'], 'count' => ['type' => 'integer'], '$schema' => ['type' => 'string']],
			'b' => ['title' => ['type' => 'string'], 'status' => ['type' => 'string'], 'required' => ['title']],
			'c' => ['title' => ['type' => 'string']],
			'wide' => [],
		][$name];
		if ($name === 'wide') {
			for ($i = 0; $i < 1501; $i++) {
				$properties['p' . $i] = ['type' => 'string'];
			}
		}

		$schema = new Schema();
		$schema->setId(['a' => 2, 'b' => 3, 'c' => 4, 'wide' => 5][$name]);
		$schema->setSlug('schema-' . $name);
		$schema->setProperties($properties);
		return ['register' => $register, 'schema' => $schema];
	}//end pair()

	private function makeMapper(string $platform): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform === 'postgres' ? new PostgreSQLPlatform() : new MySQLPlatform());
		$db->method('prepare')->willReturnCallback(fn (string $sql): IPreparedStatement => $this->statement(sql: $sql));
		$db->method('quote')->willReturnCallback(fn ($value): string => "'" . str_replace("'", "''", (string) $value) . "'");
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($db): IQueryBuilder {
				$qb = $this->createMock(IQueryBuilder::class);
				$qb->method('getConnection')->willReturn($db);
				return $qb;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->events[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => ($key === 'dbtableprefix' ? 'oc_' : $default)
		);

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$db,
					new MagicMapperLookups(
						$this->createMock(SchemaMapper::class),
						$this->createMock(RegisterMapper::class),
						$this->container()
					),
					new MagicMapperIdentity(
						$this->createMock(IUserSession::class),
						$this->createMock(IGroupManager::class),
						$this->createMock(IUserManager::class)
					),
					new MagicMapperRuntime(
						$config,
						$this->createMock(IAppConfig::class),
						$this->createMock(IEventDispatcher::class),
						$logger
					),
				]
			)
			->onlyMethods(['existsTableForRegisterSchema', 'ensureTableForRegisterSchema', 'getTableNameForRegisterSchema', 'convertRowToObjectEntity'])
			->getMock();

		$mapper->method('convertRowToObjectEntity')->willReturnCallback(
			function (array $row): ?ObjectEntity {
				$this->converted[] = $row['_uuid'];
				return null;
			}
		);

		$mapper->method('existsTableForRegisterSchema')->willReturnCallback(
			function (Register $register, Schema $schema): bool {
				$this->events[] = ['tableExists' => $schema->getId()];
				return in_array($schema->getId(), [2, 5], true);
			}
		);
		$mapper->method('ensureTableForRegisterSchema')->willReturnCallback(
			function (Register $register, Schema $schema): bool {
				$this->events[] = ['ensureTable' => $schema->getId()];
				return true;
			}
		);
		$mapper->method('getTableNameForRegisterSchema')->willReturnCallback(
			fn (Register $register, Schema $schema): string => 'openregister_table_1_' . $schema->getId()
		);

		return $mapper;
	}//end makeMapper()

	private function statement(string $sql): IPreparedStatement {
		$isColumns = str_contains($sql, 'information_schema');
		if ($isColumns === false) {
			$this->events[] = ['prepare' => $sql];
		}

		$rows = $this->unionRows ?? [['_uuid' => 'u-1', '_schema' => 2], ['_uuid' => 'u-2', '_schema' => 3]];
		if ($this->batchTables !== null) {
			$rows = $this->batchAnswer(sql: $sql);
		}

		if ($isColumns === true) {
			$rows = [];
			foreach (['_id', '_uuid', '_name', '_description', '_summary', '_register', '_schema', '_created', '_updated', '_deleted', '_owner', '_organisation', 'title', 'count', 'status', 'p7'] as $column) {
				$rows[] = ['col' => $column, 'col_type' => 'text'];
			}
		}

		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturnCallback(
			function () use ($statement): IResult {
				return $this->createMock(IResult::class);
			}
		);
		$statement->method('fetch')->willReturnCallback(
			function () use (&$rows) {
				return array_shift($rows) ?? false;
			}
		);
		$statement->method('fetchAll')->willReturnCallback(fn (): array => $rows);

		return $statement;
	}//end statement()

	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): ?object => match ($id) {
				\OCA\OpenRegister\Service\DateTimeNormalizerInterface::class => $this->createMock(\OCA\OpenRegister\Service\DateTimeNormalizer::class),
				\OCA\OpenRegister\Service\ConditionMatcher::class => $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
				\OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface::class => $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class),
				default => null,
			}
		);
		return $container;
	}//end container()

	/**
	 * A page past the bound holds the rows of the merged order, whichever batch owns them.
	 *
	 * @return void
	 */
	public function testAPagePastTheBoundIsTheMergedOrdersPage(): void {
		$this->batchTables = ['large' => range(0, 1799), 'small' => range(1800, 2400)];

		$this->searchManyBatches(query: ['_limit' => 3, '_offset' => 1500]);

		$this->assertSame(['u-01500', 'u-01501', 'u-01502'], $this->converted);
	}//end testAPagePastTheBoundIsTheMergedOrdersPage()

	/**
	 * A page past the bound is not cut short when one batch owns every row.
	 *
	 * @return void
	 */
	public function testAPagePastTheBoundIsWholeWhenOneBatchOwnsIt(): void {
		$this->batchTables = ['large' => range(0, 2400), 'small' => []];

		$this->searchManyBatches(query: ['_limit' => 2, '_offset' => 2100]);

		$this->assertSame(['u-02100', 'u-02101'], $this->converted);
	}//end testAPagePastTheBoundIsWholeWhenOneBatchOwnsIt()

	/**
	 * No statement reads more than the bound, and a batch that ran out is not read again.
	 *
	 * @return void
	 */
	public function testABatchIsReadInBoundedStatementsUntilItRunsOut(): void {
		$this->batchTables = ['large' => range(0, 2400), 'small' => range(2401, 2410)];

		$this->searchManyBatches(query: ['_limit' => 10, '_offset' => 2100]);

		$this->assertSame(
			[
				'large' => [[1000, 0], [1000, 1000], [110, 2000]],
				'small' => [[1000, 0]],
			],
			$this->batchReads
		);
	}//end testABatchIsReadInBoundedStatementsUntilItRunsOut()

	/**
	 * A first page stays one statement per batch, and a limit above the bound reads no further than the bound.
	 *
	 * @return void
	 */
	public function testAPageWithinTheBoundIsOneStatementPerBatch(): void {
		$this->batchTables = ['large' => range(0, 2400), 'small' => range(2401, 2410)];

		$this->searchManyBatches(query: ['_limit' => 5000]);

		$this->assertSame(['large' => [[1000, 0]], 'small' => [[1000, 0]]], $this->batchReads);
		$this->assertCount(1000, $this->converted);
	}//end testAPageWithinTheBoundIsOneStatementPerBatch()

	/**
	 * `_limit=false` stays one statement per batch, without a limit.
	 *
	 * @return void
	 */
	public function testAnUnlimitedSearchIsOneStatementPerBatch(): void {
		$this->batchTables = ['large' => range(0, 1200), 'small' => range(1201, 1210)];

		$this->searchManyBatches(query: ['_limit' => false, '_offset' => 5]);

		$this->assertCount(1206, $this->converted);
		$this->assertSame(['large' => [[null, 0]], 'small' => [[null, 0]]], $this->batchReads);
	}//end testAnUnlimitedSearchIsOneStatementPerBatch()

	/**
	 * Run a search over two batches: fifty arms in the first, one in the second.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return void
	 */
	private function searchManyBatches(array $query): void {
		$pairs = array_map(fn (int $index): array => $this->pair(name: 'a'), range(1, 51));

		$this->makeMapper(platform: 'mysql')->searchAcrossMultipleTables(query: $query, registerSchemaPairs: $pairs);
	}//end searchManyBatches()

	/**
	 * What one statement over a batch answers, read from its own LIMIT and OFFSET.
	 *
	 * @param string $sql The statement.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function batchAnswer(string $sql): array {
		$table = 'small';
		if (str_contains($sql, ' UNION ALL ') === true) {
			$table = 'large';
		}

		preg_match('/ LIMIT (\d+) OFFSET (\d+)$/', $sql, $window);
		$limit = isset($window[1]) ? (int) $window[1] : null;
		$offset = (int) ($window[2] ?? 0);
		$this->batchReads[$table][] = [$limit, $offset];

		return array_map(
			static fn (int $number): array => [
				'_uuid' => sprintf('u-%05d', $number),
				'_union_register_id' => 1,
				'_union_schema_id' => 2,
			],
			array_slice($this->batchTables[$table], $offset, $limit)
		);
	}//end batchAnswer()
}//end class
