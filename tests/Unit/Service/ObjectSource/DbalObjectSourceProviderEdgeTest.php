<?php

/**
 * Unit tests for the edge branches of the DBAL object-source provider.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\DbalWriteException;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Locks the provider's availability check, its connection fallbacks and its
 * write-error translation against a real SQLite table.
 *
 * @covers \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\RegisterSchema
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalAggregator
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalListClauses
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalObjectRef
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalStatement
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalTarget
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalWriteException
 * @uses   \OCA\OpenRegister\Service\ObjectSource\SourcedTable
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException
 * @uses   \OCA\OpenRegister\Support\QueryLimit
 */
final class DbalObjectSourceProviderEdgeTest extends TestCase {

	/**
	 * The SQLite file the real-database cases use.
	 *
	 * @var string
	 */
	private static string $dbPath;

	/**
	 * Create the people table.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::$dbPath = sys_get_temp_dir() . '/or-dbal-provider-edge.sqlite';
		if (file_exists(self::$dbPath) === true) {
			unlink(self::$dbPath);
		}

		$connection = self::connection();
		$connection->executeStatement(
			'CREATE TABLE people (id INTEGER PRIMARY KEY, name VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL)'
		);
		$connection->insert('people', ['id' => 1, 'name' => 'Person 1', 'status' => 'active']);
		$connection->insert('people', ['id' => 2, 'name' => 'Person 2', 'status' => 'inactive']);
		$connection->close();
	}//end setUpBeforeClass()

	/**
	 * Remove the SQLite file.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		if (file_exists(self::$dbPath) === true) {
			unlink(self::$dbPath);
		}
	}//end tearDownAfterClass()

	/**
	 * A fresh connection to the SQLite file.
	 *
	 * @return Connection
	 */
	private static function connection(): Connection {
		return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => self::$dbPath]);
	}//end connection()

	/**
	 * A writable database source for the given driver.
	 *
	 * @param string $driver The configured driver.
	 *
	 * @return Source
	 */
	private function source(string $driver = 'pdo_sqlite'): Source {
		$source = new Source();
		$source->setId(9);
		$source->setUuid('00000000-0000-0000-0000-000000000000');
		$source->setType('database');
		$source->setAuthConfig(['driver' => $driver, 'path' => self::$dbPath, 'writable' => true]);

		return $source;
	}//end source()

	/**
	 * The provider over the given connection factory.
	 *
	 * @param DbalConnectionFactory $factory The connection factory.
	 *
	 * @return DbalObjectSourceProvider
	 */
	private function provider(DbalConnectionFactory $factory): DbalObjectSourceProvider {
		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('findForSystem')->willReturn($this->source());

		return new DbalObjectSourceProvider(sourceMapper: $sourceMapper, connectionFactory: $factory, logger: new NullLogger());
	}//end provider()

	/**
	 * A factory that hands out the given connections in order, then refuses to connect.
	 *
	 * @param array<int, Connection> $connections     The connections to hand out.
	 * @param bool                   $driverAvailable What isDriverAvailable() answers.
	 *
	 * @return DbalConnectionFactory
	 */
	private function factoryReturning(array $connections, bool $driverAvailable = true): DbalConnectionFactory {
		$factory = $this->createMock(DbalConnectionFactory::class);
		$factory->method('isDriverAvailable')->willReturn($driverAvailable);
		$factory->method('getConnection')->willReturnCallback(
			static function () use (&$connections): Connection {
				$next = array_shift($connections);
				if ($next === null) {
					throw new DbalConnectionException('unreachable');
				}

				return $next;
			}
		);

		return $factory;
	}//end factoryReturning()

	/**
	 * The people table, bound to a schema with the given properties.
	 *
	 * @param array<string, mixed> $properties The schema properties.
	 * @param array<string, mixed> $overrides  Config overrides.
	 *
	 * @return SourcedTable
	 */
	private function table(array $properties, array $overrides = []): SourcedTable {
		$register = new Register();
		$register->setId(1);

		$schema = new Schema();
		$schema->setId(11);
		$schema->setSlug('people');
		$schema->setProperties($properties);

		return new SourcedTable(
			new RegisterSchema($register, $schema),
			array_merge(['sourceId' => '9', 'table' => 'people', 'idColumn' => 'id'], $overrides)
		);
	}//end table()

	/**
	 * The scalar people properties.
	 *
	 * @return array<string, mixed>
	 */
	private function peopleProperties(): array {
		return [
			'id'     => ['type' => 'integer'],
			'name'   => ['type' => 'string'],
			'status' => ['type' => 'string'],
		];
	}//end peopleProperties()

	/**
	 * The provider is enabled as soon as one supported driver is installed.
	 *
	 * @return void
	 */
	public function testIsEnabledWhenOneSupportedDriverIsAvailable(): void {
		$factory = $this->createMock(DbalConnectionFactory::class);
		$factory->method('isDriverAvailable')->willReturnCallback(
			static fn (string $driver): bool => $driver === DbalConnectionFactory::SUPPORTED_DRIVERS[count(DbalConnectionFactory::SUPPORTED_DRIVERS) - 1]
		);

		$this->assertTrue($this->provider(factory: $factory)->isEnabled());
	}//end testIsEnabledWhenOneSupportedDriverIsAvailable()

	/**
	 * Without any supported driver the provider is disabled, after asking for every one.
	 *
	 * @return void
	 */
	public function testIsDisabledWhenNoSupportedDriverIsAvailable(): void {
		$factory = $this->createMock(DbalConnectionFactory::class);
		$factory->expects($this->exactly(count(DbalConnectionFactory::SUPPORTED_DRIVERS)))
			->method('isDriverAvailable')
			->willReturn(false);

		$this->assertFalse($this->provider(factory: $factory)->isEnabled());
	}//end testIsDisabledWhenNoSupportedDriverIsAvailable()

	/**
	 * A list on a source whose driver extension is missing degrades to an empty list.
	 *
	 * @return void
	 */
	public function testListWithAMissingDriverIsEmpty(): void {
		$provider = $this->provider(factory: $this->factoryReturning(connections: [], driverAvailable: false));

		$this->assertSame([], $provider->findAll(source: $this->table(properties: $this->peopleProperties())));
	}//end testListWithAMissingDriverIsEmpty()

	/**
	 * A config without a source id lists nothing and never connects.
	 *
	 * @return void
	 */
	public function testListWithoutASourceIdIsEmptyWithoutConnecting(): void {
		$factory = $this->createMock(DbalConnectionFactory::class);
		$factory->expects($this->never())->method('getConnection');

		$this->assertSame([], $this->provider(factory: $factory)->findAll(source: $this->table(properties: $this->peopleProperties(), overrides: ['sourceId' => ''])));
	}//end testListWithoutASourceIdIsEmptyWithoutConnecting()

	/**
	 * A list on an unreachable database with an installed driver is a 503.
	 *
	 * @return void
	 */
	public function testListOnAnUnreachableDatabaseIsA503(): void {
		$provider = $this->provider(factory: $this->factoryReturning(connections: []));

		try {
			$provider->findAll(source: $this->table(properties: $this->peopleProperties()));
		} catch (DbalObjectSourceException $e) {
			$this->assertSame('The external database is unreachable.', $e->getMessage());
			$this->assertSame(503, $e->getStatusCode());
			$this->assertInstanceOf(DbalConnectionException::class, $e->getPrevious());
			return;
		}

		$this->fail('Expected DbalObjectSourceException was not thrown.');
	}//end testListOnAnUnreachableDatabaseIsA503()

	/**
	 * A schema without scalar columns (a non-array definition is skipped) selects every column.
	 *
	 * @return void
	 */
	public function testASchemaWithoutScalarColumnsSelectsEveryColumn(): void {
		$provider = $this->provider(factory: $this->factoryReturning(connections: [self::connection()]));

		$objects = $provider->findAll(
			source: $this->table(properties: ['broken' => 'string', 'tags' => ['type' => 'array']]),
			query: ['sort' => ['id' => 'ASC']]
		);

		$this->assertCount(2, $objects);
		$this->assertSame('Person 1', $objects[0]->getObject()['name']);
		$this->assertSame('inactive', $objects[1]->getObject()['status']);
	}//end testASchemaWithoutScalarColumnsSelectsEveryColumn()

	/**
	 * An explicit unlimited limit is honoured rather than replaced by the default page.
	 *
	 * @return void
	 */
	public function testAnExplicitUnlimitedLimitReturnsEveryRow(): void {
		$provider = $this->provider(factory: $this->factoryReturning(connections: [self::connection()]));

		$objects = $provider->findAll(source: $this->table(properties: $this->peopleProperties()), query: ['limit' => null]);

		$this->assertCount(2, $objects);
	}//end testAnExplicitUnlimitedLimitReturnsEveryRow()

	/**
	 * When the updated row cannot be re-read, the written values are echoed under the id.
	 *
	 * @return void
	 */
	public function testAnUpdateWhoseReReadFailsEchoesTheWrittenValues(): void {
		$provider = $this->provider(factory: $this->factoryReturning(connections: [self::connection()]));

		$updated = $provider->update(
			source: $this->table(properties: $this->peopleProperties()),
			id: '2',
			data: ['status' => 'archived']
		);

		$this->assertSame('archived', $updated->getObject()['status']);
		$this->assertSame('2', $updated->getObject()['id']);
		$this->assertSame('archived', self::connection()->fetchOne('SELECT status FROM people WHERE id = 2'));
	}//end testAnUpdateWhoseReReadFailsEchoesTheWrittenValues()

	/**
	 * A driver error during an update is translated into a sanitized write exception.
	 *
	 * @return void
	 */
	public function testAnUpdateDriverErrorIsTranslated(): void {
		$provider = $this->provider(factory: $this->factoryReturning(connections: [self::connection()]));

		try {
			$provider->update(
				source: $this->table(properties: $this->peopleProperties()),
				id: '1',
				data: ['name' => null]
			);
		} catch (DbalWriteException $e) {
			$this->assertStringNotContainsString('people', $e->getMessage());
			$this->assertInstanceOf(\Doctrine\DBAL\Exception::class, $e->getPrevious());
			$this->assertSame('Person 1', self::connection()->fetchOne('SELECT name FROM people WHERE id = 1'));
			return;
		}

		$this->fail('Expected DbalWriteException was not thrown.');
	}//end testAnUpdateDriverErrorIsTranslated()

	/**
	 * A driver error during a delete is translated into a sanitized write exception.
	 *
	 * @return void
	 */
	public function testADeleteDriverErrorIsTranslated(): void {
		$provider = $this->provider(factory: $this->factoryReturning(connections: [self::connection()]));

		try {
			$provider->remove(
				source: $this->table(properties: $this->peopleProperties(), overrides: ['table' => 'no_such_table']),
				id: '1'
			);
		} catch (DbalWriteException $e) {
			$this->assertStringNotContainsString('no_such_table', $e->getMessage());
			$this->assertInstanceOf(\Doctrine\DBAL\Exception::class, $e->getPrevious());
			return;
		}

		$this->fail('Expected DbalWriteException was not thrown.');
	}//end testADeleteDriverErrorIsTranslated()

	/**
	 * A composite id whose part count differs from the key's is refused with a 400.
	 *
	 * @return void
	 */
	public function testACompositeIdOfTheWrongShapeIsRefused(): void {
		$provider = $this->provider(factory: $this->factoryReturning(connections: [self::connection()]));

		try {
			$provider->remove(
				source: $this->table(properties: $this->peopleProperties(), overrides: ['idColumn' => null, 'idColumns' => ['id', 'name']]),
				id: '1'
			);
		} catch (DbalWriteException $e) {
			$this->assertSame(400, $e->getStatusCode());
			$this->assertSame('The object id does not match the table\'s composite key shape.', $e->getMessage());
			$this->assertSame(2, (int)self::connection()->fetchOne('SELECT COUNT(*) FROM people'));
			return;
		}

		$this->fail('Expected DbalWriteException was not thrown.');
	}//end testACompositeIdOfTheWrongShapeIsRefused()
}//end class
