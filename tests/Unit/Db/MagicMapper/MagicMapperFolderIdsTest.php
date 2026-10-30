<?php

/**
 * The folder scan behind an organisation's storage figure must not under-report in silence.
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
 * @spec openspec/changes/organisation-usage-measured/specs/organisation-usage-measured/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
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
 * `MagicMapper::findFolderIdsByOrganisation()`.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses \OCA\OpenRegister\Db\StoredEntity
 */
class MagicMapperFolderIdsTest extends TestCase {

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
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(IConfig::class),
			$this->createMock(IEventDispatcher::class),
			$this->userSession,
			$this->groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->logger,
			$this->createMock(SettingsService::class),
			$container
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
				if ($id === DateTimeNormalizer::class) {
					return $dateTimeNormalizer;
				}
				if ($id === \OCA\OpenRegister\Service\ConditionMatcher::class) {
					return $conditionMatcher;
				}
				if ($id === \OCA\OpenRegister\Service\Object\SchemaTypeConverter::class) {
					return $schemaTypeConverter;
				}
				return null;
			}
		);
		return $container;
	}//end makeContainer()

	/**
	 * A query builder whose query yields the given folder ids, or throws.
	 *
	 * @param array<int, mixed>|null $folders The `_folder` values, or null to throw on execute.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function folderQuery(?array $folders): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['selectDistinct', 'from', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturn(':p');

		if ($folders === null) {
			$qb->method('executeQuery')->willThrowException(new Exception('Unknown column _folder'));
			return $qb;
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturnOnConsecutiveCalls(...array_merge($folders, [false]));
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}//end folderQuery()

	/**
	 * Two magic tables are discovered, with the given queries behind them.
	 *
	 * @param IQueryBuilder $first  The first table's query.
	 * @param IQueryBuilder $second The second table's query.
	 *
	 * @return void
	 */
	private function discoverTwoTables(IQueryBuilder $first, IQueryBuilder $second): void {
		$stmt = $this->createMock(IPreparedStatement::class);
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn(
			[['table_name' => 'oc_openregister_table_1_1'], ['table_name' => 'oc_openregister_table_1_2']]
		);
		$stmt->method('execute')->willReturn($result);
		$this->db->method('prepare')->willReturn($stmt);
		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($first, $second);
	}//end discoverTwoTables()

	/**
	 * The control: every table readable, the distinct numeric folders come back.
	 *
	 * @return void
	 */
	public function testEveryReadableTableContributesItsFolders(): void {
		$this->discoverTwoTables($this->folderQuery([11, '12']), $this->folderQuery([12, 13]));
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame([11, 12, 13], $this->makeMapper()->findFolderIdsByOrganisation(organisationUuid: 'org-1'));
	}//end testEveryReadableTableContributesItsFolders()

	/**
	 * 🔴 A TABLE THAT FAILS MAKES THE MEASUREMENT FAIL, AND IS LOGGED BY NAME.
	 *
	 * Skipping it returned a plain int that was too low, which the service then
	 * presented as a measured storage figure.
	 *
	 * @return void
	 */
	public function testAFailingTableFailsTheMeasurementAndIsLogged(): void {
		$this->discoverTwoTables($this->folderQuery([11]), $this->folderQuery(null));

		$logged = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = $context;
			}
		);

		try {
			$this->makeMapper()->findFolderIdsByOrganisation(organisationUuid: 'org-1');
			$this->fail('Expected the scan to fail');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('oc_openregister_table_1_2', $e->getMessage());
			$this->assertStringNotContainsString('oc_openregister_table_1_1', $e->getMessage());
		}

		$this->assertCount(1, $logged);
		$this->assertSame('oc_openregister_table_1_2', $logged[0]['table']);
	}//end testAFailingTableFailsTheMeasurementAndIsLogged()
}//end class
