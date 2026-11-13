<?php

/**
 * The property-column budget of a cross-table UNION projection.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/unified-search-magic-table-storage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * `MagicMapper::shouldUseUnionQuery()`: related-row filters need the sequential route.
 *
 * @uses \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicTableSql
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowLookups
 * @uses \OCA\OpenRegister\Service\Search\SearchGrammar
 */
class MagicMapperUnionColumnBudgetTest extends TestCase {

	private IDBConnection&MockObject $db;

	private LoggerInterface&MockObject $logger;

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * Build a MagicMapper with the shared mock dependencies.
	 *
	 * @return MagicMapper
	 */
	private function makeMapper(): MagicMapper {
		$container = $this->makeContainer();

		return new MagicMapper(
			$this->db,
			new MagicMapperLookups(
				$this->schemaMapper,
				$this->registerMapper,
				$container
			),
			new MagicMapperIdentity(
				$this->userSession,
				$this->groupManager,
				$this->createMock(IUserManager::class)
			),
			new MagicMapperRuntime(
				$this->createMock(IConfig::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(IEventDispatcher::class),
				$this->logger
			)
		);
	}//end makeMapper()

	/**
	 * Build a DI container that resolves the lazily-fetched collaborators
	 * MagicMapper pulls during construction (DateTimeNormalizer, ConditionMatcher,
	 * SchemaTypeConverter), matching the production container contract.
	 *
	 * @return ContainerInterface&MockObject
	 */
	private function makeContainer(): ContainerInterface {
		$dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);
		$conditionMatcher = $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class);
		$schemaTypeConverter = $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($dateTimeNormalizer, $conditionMatcher, $schemaTypeConverter) {
				if ($id === DateTimeNormalizerInterface::class) {
					return $dateTimeNormalizer;
				}
				if ($id === \OCA\OpenRegister\Service\ConditionMatcher::class) {
					return $conditionMatcher;
				}
				if ($id === \OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface::class) {
					return $schemaTypeConverter;
				}
				return null;
			}
		);
		return $container;
	}//end makeContainer()

	/**
	 * A schema whose properties are the given names.
	 *
	 * @param array<int,string> $names The property names.
	 *
	 * @return \OCA\OpenRegister\Db\Schema
	 */
	private function schemaWith(array $names): \OCA\OpenRegister\Db\Schema {
		$schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
		$schema->method('getProperties')->willReturn(array_fill_keys($names, ['type' => 'string']));
		return $schema;
	}//end schemaWith()

	/**
	 * Register/schema pairs whose property names are p0 .. p(n-1), spread over two schemas.
	 *
	 * @param int $count The number of distinct property names.
	 *
	 * @return array<int,array{register:mixed,schema:mixed}>
	 */
	private function pairsWithColumns(int $count): array {
		$names = array_map(static fn (int $i): string => 'p' . $i, range(0, ($count - 1)));
		$half  = (int) ceil($count / 2);

		return [
			['register' => null, 'schema' => $this->schemaWith(names: array_slice($names, 0, $half))],
			['register' => null, 'schema' => $this->schemaWith(names: array_slice($names, $half))],
		];
	}//end pairsWithColumns()

	/**
	 * A narrow superset keeps every property column in the UNION projection, and logs nothing.
	 *
	 * @return void
	 */
	public function testANarrowSupersetKeepsItsPropertyColumns(): void {
		$this->logger->expects($this->never())->method('warning');
		$columns = (new \ReflectionMethod(MagicMapper::class, 'unionPropertyColumns'))
			->invoke($this->makeMapper(), $this->pairsWithColumns(count: 1500));

		$this->assertIsArray($columns);
		$this->assertCount(1500, $columns);
		$this->assertSame('p0', $columns['p0']);
	}//end testANarrowSupersetKeepsItsPropertyColumns()

	/**
	 * A superset over the budget falls back to the metadata-only projection, and says so.
	 *
	 * @return void
	 */
	public function testAWideSupersetFallsBackToMetadataOnlyAndLogsIt(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('exceeds the column budget'),
			$this->callback(static fn (array $context): bool => $context['budget'] === 1500 && $context['schemaCount'] === 2)
		);

		$columns = (new \ReflectionMethod(MagicMapper::class, 'unionPropertyColumns'))
			->invoke($this->makeMapper(), $this->pairsWithColumns(count: 1501));

		$this->assertNull($columns);
	}//end testAWideSupersetFallsBackToMetadataOnlyAndLogsIt()
}//end class
