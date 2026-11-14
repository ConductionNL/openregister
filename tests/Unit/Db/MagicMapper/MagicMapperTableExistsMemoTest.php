<?php

/**
 * Unit tests for the request-scoped table-existence memo of MagicMapper.
 *
 * An object update used to ask `information_schema.tables` the same question
 * many times per request. `checkTableExistsInDatabase()` now remembers a
 * positive answer for the request; these tests pin when it asks the database
 * and when it must ask again (object-write-at-instance-floor task 5).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/object-write-at-instance-floor/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
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
 * A positive answer is asked once per request; a negative one, and one for a
 * table dropped since, are asked again.
 */
class MagicMapperTableExistsMemoTest extends TestCase {

	/**
	 * How often the database was asked whether a table exists.
	 */
	private int $probes = 0;

	/**
	 * Whether the probed table exists, as the database would answer.
	 */
	private bool $exists = true;

	/**
	 * Statements executed through the connection.
	 *
	 * @var list<string>
	 */
	private array $executed = [];

	/**
	 * A MagicMapper over a connection that answers the probe as $this->exists says.
	 *
	 * @return MagicMapper
	 */
	private function mapper(): MagicMapper {
		$platform = $this->createMock(AbstractMySQLPlatform::class);
		$platform->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('prepare')->willReturnCallback(
			function (string $sql): IPreparedStatement {
				$this->assertStringContainsString('information_schema.tables', $sql);
				$this->probes++;
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('execute')->willReturn($this->createMock(IResult::class));
				$statement->method('fetch')->willReturn($this->exists ? ['1' => 1] : false);
				return $statement;
			}
		);

		$connection = new class($platform, $this->executed) {
			/**
			 * @param AbstractMySQLPlatform $platform The platform.
			 * @param list<string>          $executed The executed statements, by reference.
			 */
			public function __construct(private readonly AbstractMySQLPlatform $platform, private array &$executed) {
			}

			public function getDatabasePlatform(): AbstractMySQLPlatform {
				return $this->platform;
			}

			public function executeStatement(string $sql): int {
				$this->executed[] = $sql;
				return 0;
			}
		};
		$builder = $this->createMock(IQueryBuilder::class);
		$builder->method('getConnection')->willReturn($connection);
		$db->method('getQueryBuilder')->willReturn($builder);

		return new MagicMapper(
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
				$this->createMock(IConfig::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(IEventDispatcher::class),
				$this->createMock(LoggerInterface::class)
			)
		);
	}//end mapper()

	/**
	 * The container MagicMapper pulls its collaborators from.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$services = [
			DateTimeNormalizerInterface::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverterInterface::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): ?object => ($services[$id] ?? null));

		return $container;
	}//end container()

	/**
	 * A table that exists is asked about once, however often it is checked.
	 *
	 * @return void
	 */
	public function testAnExistingTableIsProbedOnce(): void {
		$mapper = $this->mapper();

		$this->assertTrue($mapper->checkTableExistsInDatabase(tableName: 'openregister_table_7_8'));
		$this->assertTrue($mapper->checkTableExistsInDatabase(tableName: 'openregister_table_7_8'));
		$this->assertTrue($mapper->checkTableExistsInDatabase(tableName: 'openregister_table_7_8'));

		$this->assertSame(1, $this->probes);
	}//end testAnExistingTableIsProbedOnce()

	/**
	 * "Does not exist" is not remembered: the table may be created next.
	 *
	 * @return void
	 */
	public function testAMissingTableIsAskedAgain(): void {
		$this->exists = false;
		$mapper = $this->mapper();

		$this->assertFalse($mapper->checkTableExistsInDatabase(tableName: 'openregister_table_7_8'));
		$this->exists = true;
		$this->assertTrue($mapper->checkTableExistsInDatabase(tableName: 'openregister_table_7_8'));

		$this->assertSame(2, $this->probes);
	}//end testAMissingTableIsAskedAgain()

	/**
	 * After a drop the remembered "exists" is gone: the next check asks the
	 * database, and answers what it says. Other tables stay remembered.
	 *
	 * @return void
	 */
	public function testADroppedTableIsAskedAgain(): void {
		$mapper = $this->mapper();
		$mapper->checkTableExistsInDatabase(tableName: 'openregister_table_7_8');
		$mapper->checkTableExistsInDatabase(tableName: 'openregister_table_7_9');

		$mapper->dropTable(tableName: 'openregister_table_7_8');
		$this->exists = false;

		$this->assertFalse($mapper->checkTableExistsInDatabase(tableName: 'openregister_table_7_8'), 'a dropped table still read as existing');
		$this->assertTrue($mapper->checkTableExistsInDatabase(tableName: 'openregister_table_7_9'), 'another table lost its memo');
		$this->assertSame(3, $this->probes);
		$this->assertCount(1, $this->executed);
		$this->assertStringStartsWith('DROP TABLE IF EXISTS', $this->executed[0]);
	}//end testADroppedTableIsAskedAgain()
}//end class
