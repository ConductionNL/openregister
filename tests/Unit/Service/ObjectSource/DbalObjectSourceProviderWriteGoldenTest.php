<?php

/**
 * Characterisation tests for the DbalObjectSourceProvider read/write branches
 * the main test does not reach: a caller-supplied key, a duplicate key, an
 * update without writable columns, the PostgreSQL RETURNING insert, the
 * constraint-to-status mapping and the find() failure paths.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionContract;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\DbalWriteException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Pins outcomes of the DbalObjectSourceProvider branches before they are split up.
 */
class DbalObjectSourceProviderWriteGoldenTest extends TestCase {

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
		self::$dbPath = sys_get_temp_dir() . '/or-dbal-provider-write-golden.sqlite';
		if (file_exists(self::$dbPath) === true) {
			unlink(self::$dbPath);
		}

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => self::$dbPath]);
		$connection->executeStatement(
			'CREATE TABLE people (id INTEGER PRIMARY KEY, name VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL)'
		);
		$connection->insert('people', ['id' => 1, 'name' => 'Person 1', 'status' => 'active']);
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
	 * A writable database source pointing at the SQLite file.
	 *
	 * @return Source
	 */
	private function source(): Source {
		$source = new Source();
		$source->setId(9);
		$source->setUuid('00000000-0000-0000-0000-000000000000');
		$source->setType('database');
		$source->setAuthConfig(['driver' => 'pdo_sqlite', 'path' => self::$dbPath, 'writable' => true]);

		return $source;
	}//end source()

	/**
	 * The provider, over the given connection factory (a real one by default).
	 *
	 * @param DbalConnectionFactory|null $factory The connection factory.
	 *
	 * @return DbalObjectSourceProvider
	 */
	private function provider(?DbalConnectionFactory $factory = null): DbalObjectSourceProvider {
		$source       = $this->source();
		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('findForSystem')->willReturn($source);

		if ($factory === null) {
			$store = new class implements CredentialStore {
				public function put(string $uuid, string $secret, string $scope = 'personal'): void {
				}//end put()

				public function get(string $uuid, string $scope = 'personal'): ?string {
					return null;
				}//end get()

				public function delete(string $uuid, string $scope = 'personal'): void {
				}//end delete()
			};
			$factory = new DbalConnectionFactory(credentialStore: $store, logger: new NullLogger());
		}

		return new DbalObjectSourceProvider(sourceMapper: $sourceMapper, connectionFactory: $factory, logger: new NullLogger());
	}//end provider()

	/**
	 * A factory that hands out the given connections in order, then refuses to connect.
	 *
	 * @param array<int, Connection> $connections The connections to hand out.
	 *
	 * @return DbalConnectionFactory
	 */
	private function factoryReturning(array $connections): DbalConnectionFactory {
		$factory = $this->createMock(DbalConnectionFactory::class);
		$factory->method('isDriverAvailable')->willReturn(true);
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
	 * A register.
	 *
	 * @return Register
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(1);
		return $register;
	}//end register()

	/**
	 * The people schema.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(11);
		$schema->setSlug('people');
		$schema->setProperties(
			[
				'id'     => ['type' => 'integer'],
				'name'   => ['type' => 'string'],
				'status' => ['type' => 'string'],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * The people config.
	 *
	 * @param array<string, mixed> $overrides Config overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function config(array $overrides = []): array {
		return array_merge(['sourceId' => '9', 'table' => 'people', 'idColumn' => 'id'], $overrides);
	}//end config()

	/**
	 * The status code and message of the DbalWriteException a call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function writeFailure(callable $call): array {
		try {
			$call();
		} catch (DbalWriteException $e) {
			return [$e->getStatusCode(), $e->getMessage()];
		}

		$this->fail('Expected DbalWriteException was not thrown.');
	}//end writeFailure()

	/**
	 * A caller-supplied primary key is written and the row re-read under that key.
	 *
	 * @return void
	 */
	public function testCallerSuppliedKeyIsWrittenAndReRead(): void {
		$created = $this->provider()->insert(
			source: new SourcedTable(new RegisterSchema($this->register(), $this->schema()), $this->config()),
			data: ['id' => 500, 'name' => 'Given Key', 'status' => 'active']
		);

		// The synthetic `id` key is stripped from the payload, so the database generates the key.
		$this->assertSame('Given Key', $created->getObject()['name']);
		$this->assertNotSame('500', (string)$created->getUuid());

		$explicit = $this->provider()->insert(
			source: new SourcedTable(new RegisterSchema($this->register(), $this->schema()), $this->config(overrides: ['idColumn' => 'name'])),
			data: ['name' => 'Coded', 'status' => 'active']
		);
		$this->assertSame('Coded', (string)$explicit->getUuid());
	}//end testCallerSuppliedKeyIsWrittenAndReRead()

	/**
	 * An update that only names key columns has nothing to write.
	 *
	 * @return void
	 */
	public function testUpdateOfKeyColumnsOnlyIsRefused(): void {
		$failure = $this->writeFailure(
			fn () => $this->provider()->update(
				source: new SourcedTable(new RegisterSchema($this->register(), $this->schema()), $this->config()),
				id: '1',
				data: ['id' => 1]
			)
		);
		$this->assertSame([400, 'The update contains no writable columns.'], $failure);
	}//end testUpdateOfKeyColumnsOnlyIsRefused()

	/**
	 * A no-key insert echoes the written values.
	 *
	 * @return void
	 */
	public function testNoKeyInsertEchoesValues(): void {
		$created = $this->provider()->insert(
			source: new SourcedTable(new RegisterSchema($this->register(), $this->schema()), $this->config(overrides: ['idColumn' => null])),
			data: ['name' => 'Appended', 'status' => 'inactive']
		);
		$this->assertSame('Appended', $created->getObject()['name']);
		$this->assertSame('inactive', $created->getObject()['status']);
	}//end testNoKeyInsertEchoesValues()

	/**
	 * On PostgreSQL the generated key comes back through RETURNING.
	 *
	 * @return void
	 */
	public function testPostgresInsertUsesReturning(): void {
		$captured   = [];
		$connection = $this->createMock(Connection::class);
		$connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$connection->expects($this->never())->method('insert');
		$connection->expects($this->once())->method('fetchOne')->willReturnCallback(
			static function (string $sql, array $params) use (&$captured): string {
				$captured = [$sql, $params];
				return '77';
			}
		);

		$created = $this->provider(factory: $this->factoryReturning(connections: [$connection]))->insert(
			source: new SourcedTable(new RegisterSchema($this->register(), $this->schema()), $this->config()),
			data: ['name' => 'Pg', 'status' => 'active']
		);

		$this->assertSame(
			['INSERT INTO "people" ("name", "status") VALUES (?, ?) RETURNING "id"', ['Pg', 'active']],
			$captured
		);
		// The re-read cannot connect, so the written values are echoed.
		$this->assertSame('Pg', $created->getObject()['name']);
	}//end testPostgresInsertUsesReturning()

	/**
	 * Each driver error class maps to its own sanitized status and message.
	 *
	 * @return void
	 */
	public function testDriverErrorsMapToStatus(): void {
		$driverError = $this->createMock(DriverExceptionContract::class);
		$driverError->method('getSQLState')->willReturn('23000');

		$cases = [
			[new UniqueConstraintViolationException($driverError, null), [409, 'A unique constraint on the external table was violated.']],
			[new ForeignKeyConstraintViolationException($driverError, null), [409, 'A foreign-key constraint on the external table was violated.']],
			[new ConstraintViolationException($driverError, null), [422, 'A constraint on the external table was violated.']],
		];

		foreach ($cases as [$error, $expected]) {
			$this->assertSame($expected, $this->insertFailingWith(error: $error));
		}

		$generic = new \Doctrine\DBAL\Exception('boom');
		$this->assertSame(
			$this->expectedFromSqlState(sqlState: ''),
			$this->insertFailingWith(error: $generic)
		);
	}//end testDriverErrorsMapToStatus()

	/**
	 * The status and message an insert reports when the driver throws the given error.
	 *
	 * @param Throwable $error The driver error.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function insertFailingWith(Throwable $error): array {
		$connection = $this->createMock(Connection::class);
		$connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
		$connection->method('insert')->willThrowException($error);

		return $this->writeFailure(
			fn () => $this->provider(factory: $this->factoryReturning(connections: [$connection]))->insert(
				source: new SourcedTable(new RegisterSchema($this->register(), $this->schema()), $this->config()),
				data: ['name' => 'X', 'status' => 'active']
			)
		);
	}//end insertFailingWith()

	/**
	 * The status and message DbalWriteException::fromSqlState gives for a state.
	 *
	 * @param string $sqlState The SQLSTATE.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function expectedFromSqlState(string $sqlState): array {
		$exception = DbalWriteException::fromSqlState(sqlState: $sqlState, previous: null);
		return [$exception->getStatusCode(), $exception->getMessage()];
	}//end expectedFromSqlState()

	/**
	 * find() answers null when the database cannot be reached.
	 *
	 * @return void
	 */
	public function testFindAnswersNullWhenUnreachable(): void {
		$provider = $this->provider(factory: $this->factoryReturning(connections: []));
		$this->assertNull(
			$provider->find(source: new SourcedTable(new RegisterSchema($this->register(), $this->schema()), $this->config()), id: '1')
		);
	}//end testFindAnswersNullWhenUnreachable()

	/**
	 * A query error in find() surfaces as a 502.
	 *
	 * @return void
	 */
	public function testFindQueryErrorIs502(): void {
		try {
			$this->provider()->find(
				source: new SourcedTable(new RegisterSchema($this->register(), $this->schema()), $this->config(overrides: ['table' => 'no_such_table'])),
				id: '1'
			);
			$this->fail('Expected DbalObjectSourceException');
		} catch (DbalObjectSourceException $e) {
			$this->assertSame([502, 'The external database returned an error.'], [$e->getStatusCode(), $e->getMessage()]);
		}
	}//end testFindQueryErrorIs502()

	/**
	 * A sourced schema whose source cannot be resolved finds nothing.
	 *
	 * @return void
	 */
	public function testFindWithoutSourceIsNull(): void {
		$this->assertNull(
			$this->provider()->find(
				source: new SourcedTable(new RegisterSchema($this->register(), $this->schema()), $this->config(overrides: ['sourceId' => ''])),
				id: '1'
			)
		);
	}//end testFindWithoutSourceIsNull()
}//end class
