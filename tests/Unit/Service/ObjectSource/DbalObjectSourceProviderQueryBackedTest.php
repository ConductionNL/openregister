<?php

/**
 * Reads of a query-backed schema are guarded and run read-only
 * (dbal-query-schema-guarded-and-previewed, design D-1 and D-2).
 *
 * @category Test
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
 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
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
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Dbal\ReadOnlyReadScope;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A file-backed SQLite database with an orders table; the schema reads a query over it.
 */
class DbalObjectSourceProviderQueryBackedTest extends TestCase {

	/**
	 * The database file.
	 *
	 * @var string
	 */
	private string $dbPath;

	/**
	 * How many reads the read-only scope wrapped.
	 *
	 * @var integer
	 */
	private int $scopedReads = 0;

	/**
	 * Create the orders table.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dbPath = sys_get_temp_dir() . '/or-dbal-query-backed-' . bin2hex(random_bytes(4)) . '.sqlite';
		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
		$connection->executeStatement('CREATE TABLE orders (id INTEGER PRIMARY KEY, status VARCHAR(16), amount INTEGER)');
		for ($i = 1; $i <= 30; $i++) {
			$connection->executeStatement('INSERT INTO orders (id, status, amount) VALUES (?, ?, ?)', [$i, ($i % 3 === 0) ? 'open' : 'closed', $i * 10]);
		}

		$connection->close();
	}//end setUp()

	/**
	 * Remove the database file.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->dbPath);
		parent::tearDown();
	}//end tearDown()

	/**
	 * The provider, with a read scope that counts the reads it wraps.
	 *
	 * @return DbalObjectSourceProvider
	 */
	private function provider(): DbalObjectSourceProvider {
		$source = new Source();
		$source->setId(9);
		$source->setUuid('00000000-0000-0000-0000-000000000009');
		$source->setType('database');
		$source->setAuthConfig(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);

		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('find')->willReturn($source);
		$sourceMapper->method('findAll')->willReturn([$source]);
		$sourceMapper->method('findForSystem')->willReturn($source);

		$store = new class implements CredentialStore {
			/**
			 * @param string $uuid   The credential UUID.
			 * @param string $secret The secret.
			 * @param string $scope  The scope.
			 *
			 * @return void
			 */
			public function put(string $uuid, string $secret, string $scope = 'personal'): void {
			}//end put()

			/**
			 * @param string $uuid  The credential UUID.
			 * @param string $scope The scope.
			 *
			 * @return string|null Always null.
			 */
			public function get(string $uuid, string $scope = 'personal'): ?string {
				return null;
			}//end get()

			/**
			 * @param string $uuid  The credential UUID.
			 * @param string $scope The scope.
			 *
			 * @return void
			 */
			public function delete(string $uuid, string $scope = 'personal'): void {
			}//end delete()
		};

		$test = $this;
		$scope = new class($test) extends ReadOnlyReadScope {
			/**
			 * @param DbalObjectSourceProviderQueryBackedTest $test The test that counts.
			 */
			public function __construct(private DbalObjectSourceProviderQueryBackedTest $test) {
			}//end __construct()

			/**
			 * Count, then run the read as the real scope does.
			 *
			 * @param Connection $connection The connection.
			 * @param callable   $read       The read.
			 * @param integer    $timeoutMs  The timeout.
			 *
			 * @return mixed The read's result.
			 */
			public function run(Connection $connection, callable $read, int $timeoutMs = self::DEFAULT_TIMEOUT_MS): mixed {
				$this->test->countScopedRead();
				$this->test->assertFalse($connection->isTransactionActive());
				return parent::run(connection: $connection, read: $read, timeoutMs: $timeoutMs);
			}//end run()
		};

		return new DbalObjectSourceProvider(
			sourceMapper: $sourceMapper,
			connectionFactory: new DbalConnectionFactory(credentialStore: $store, logger: new NullLogger()),
			logger: new NullLogger(),
			readScope: $scope
		);
	}//end provider()

	/**
	 * Count one read the scope wrapped.
	 *
	 * @return void
	 */
	public function countScopedRead(): void {
		$this->scopedReads++;
	}//end countScopedRead()

	/**
	 * The sourced table of a schema over a query, or over the table.
	 *
	 * @param array<string, mixed> $config The config fields that differ.
	 *
	 * @return SourcedTable
	 */
	private static function sourced(array $config): SourcedTable {
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('open-orders');
		$schema->setProperties(['id' => ['type' => 'integer'], 'status' => ['type' => 'string'], 'amount' => ['type' => 'integer']]);

		return new SourcedTable(new RegisterSchema($register, $schema), array_merge(['sourceId' => '9', 'idColumn' => 'id'], $config));
	}//end sourced()

	/**
	 * A query-backed list, count and find each run inside the read-only scope.
	 *
	 * @return void
	 */
	public function testQueryBackedReadsRunInTheReadOnlyScope(): void {
		$provider = $this->provider();
		$sourced = self::sourced(['query' => "SELECT id, status, amount FROM orders WHERE status = 'open';"]);

		$objects = $provider->findAll(source: $sourced, query: ['limit' => 5]);
		$this->assertCount(5, $objects);
		$this->assertSame(10, $provider->count(source: $sourced));
		foreach ($objects as $object) {
			$this->assertSame('open', $object->getObject()['status']);
		}

		$this->assertNotNull($provider->find(source: $sourced, id: '3'));
		$this->assertSame(3, $this->scopedReads);
	}//end testQueryBackedReadsRunInTheReadOnlyScope()

	/**
	 * A table-backed schema reads without the scope, as before.
	 *
	 * @return void
	 */
	public function testTableBackedReadsAreUnchanged(): void {
		$provider = $this->provider();

		$this->assertSame(30, $provider->count(source: self::sourced(['table' => 'orders'])));
		$this->assertSame(0, $this->scopedReads);
	}//end testTableBackedReadsAreUnchanged()

	/**
	 * A stored query the guard refuses is never run: 422 naming the rule, the table untouched.
	 *
	 * @return void
	 */
	public function testARefusedQueryIsNeverRun(): void {
		$provider = $this->provider();

		try {
			$provider->count(source: self::sourced(['query' => 'SELECT 1; DELETE FROM orders']));
			$this->fail('A stored query with a second statement was run.');
		} catch (DbalObjectSourceException $exception) {
			$this->assertSame(422, $exception->getStatusCode());
			$this->assertSame('The query of this schema was refused: A query-backed schema may contain only one statement.', $exception->getMessage());
		}

		$this->assertSame(0, $this->scopedReads);
		$check = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
		$this->assertSame(30, (int)$check->fetchOne('SELECT COUNT(*) FROM orders'));
	}//end testARefusedQueryIsNeverRun()
}//end class
