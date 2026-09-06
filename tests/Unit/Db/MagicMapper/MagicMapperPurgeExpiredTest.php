<?php

/**
 * Unit tests for MagicMapper::purgeExpired().
 *
 * The mapper does two things around the handler: it answers 0 for a table
 * that does not exist yet (a schema nothing has been appended to), and it
 * hands the handler the register+schema table name it resolves itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
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
use ReflectionProperty;

class MagicMapperPurgeExpiredTest extends TestCase {

	private MagicMapper $mapper;

	private MagicTableHandler&MockObject $tableHandler;

	private MagicBulkHandler&MockObject $bulkHandler;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(
			$this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class)
		);

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

		$this->mapper = new MagicMapper(
			$db,
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

		// Swap the two handlers the method touches for mocks; the rest stay real.
		$this->tableHandler = $this->createMock(MagicTableHandler::class);
		$this->bulkHandler = $this->createMock(MagicBulkHandler::class);
		$this->inject('tableHandler', $this->tableHandler);
		$this->inject('bulkHandler', $this->bulkHandler);

		$this->register = new Register();
		$this->register->setId(5);

		$this->schema = new Schema();
		$this->schema->setId(9);
	}

	private function inject(string $property, object $value): void {
		$reflection = new ReflectionProperty(MagicMapper::class, $property);
		$reflection->setAccessible(true);
		$reflection->setValue($this->mapper, $value);
	}

	public function testAMissingTableHasNothingToPurge(): void {
		$this->tableHandler->method('tableExistsForRegisterSchema')
			->with($this->register, $this->schema)
			->willReturn(false);
		$this->bulkHandler->expects($this->never())->method('purgeExpired');

		$this->assertSame(0, $this->mapper->purgeExpired($this->register, $this->schema));
	}

	public function testAnExistingTableIsSweptByTheHandlerUnderItsResolvedName(): void {
		$this->tableHandler->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->tableHandler->method('getTableNameForRegisterSchema')
			->with($this->register, $this->schema)
			->willReturn('openregister_table_5_9');

		$this->bulkHandler->expects($this->once())
			->method('purgeExpired')
			->with($this->register, $this->schema, 'openregister_table_5_9')
			->willReturn(7);

		$this->assertSame(7, $this->mapper->purgeExpired($this->register, $this->schema));
	}
}
