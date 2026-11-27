<?php

/**
 * Unit tests for the magic-table existence memo a statistics walk fills.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\SettingsService;
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

/**
 * The statistics walk hands the magic tables it listed from information_schema
 * to the mapper before counting, so counting a listed table probes nothing.
 *
 * @spec openspec/specs/built-in-dashboards/spec.md#requirement-dashboardservice-must-assemble-or-canned-statistics-and-chart-payloads-with-fail-soft-semantics
 */
class MagicMapperStatisticsTableMemoTest extends TestCase {

	private IDBConnection&MockObject $db;

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	public function testACountRemembersTheListedTablesAsExisting(): void {
		$mapper = $this->makeMapper();
		$handler = (new \ReflectionProperty(MagicMapper::class, 'statisticsHandler'))->getValue($mapper);
		(new \ReflectionProperty(MagicStatisticsHandler::class, 'magicTablesCache'))->setValue($handler, ['openregister_table_1_10', 'openregister_table_2_10']);
		$callback = (new \ReflectionProperty(MagicStatisticsHandler::class, 'countCallback'))->getValue($handler);

		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(10);
		try {
			$callback([], $register, $schema);
		} catch (\Throwable $e) {
			// The count itself needs a database; only the memo is under test.
		}

		$memo = (new \ReflectionProperty(MagicMapper::class, 'tableExistsMemo'))->getValue($mapper);
		$this->assertSame(['openregister_table_1_10' => true, 'openregister_table_2_10' => true], array_intersect_key($memo, ['openregister_table_1_10' => 1, 'openregister_table_2_10' => 1]));
		$this->assertTrue($mapper->checkTableExistsInDatabase('openregister_table_2_10'), 'a listed table is known to exist without a query');
	}

	public function testNothingListedRemembersNothing(): void {
		$mapper = $this->makeMapper();
		$handler = (new \ReflectionProperty(MagicMapper::class, 'statisticsHandler'))->getValue($mapper);
		$callback = (new \ReflectionProperty(MagicStatisticsHandler::class, 'countCallback'))->getValue($handler);

		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(10);
		try {
			$callback([], $register, $schema);
		} catch (\Throwable $e) {
			// The count itself needs a database; only the memo is under test.
		}

		$memo = (new \ReflectionProperty(MagicMapper::class, 'tableExistsMemo'))->getValue($mapper);
		$this->assertArrayNotHasKey('openregister_table_2_10', $memo);
	}

	private function makeMapper(): MagicMapper {
		return new MagicMapper(
			$this->db,
			new MagicMapperLookups(
				$this->schemaMapper,
				$this->registerMapper,
				$this->makeContainer()
			),
			new MagicMapperIdentity(
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
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
	 * Build a DI container resolving the collaborators MagicMapper lazily fetches.
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
}//end class
