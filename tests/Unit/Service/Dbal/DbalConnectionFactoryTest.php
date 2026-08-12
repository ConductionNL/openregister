<?php

/**
 * Unit tests for DbalConnectionFactory.
 *
 * Covers:
 *  - connecting to the SQLite permits fixture succeeds and runs a trivial read
 *  - the connection is cached per request (same instance for the same source)
 *  - a configured credential that cannot be resolved fails CLOSED with a typed
 *    DbalConnectionException — never an unauthenticated connection — and the
 *    log line never contains the secret
 *  - unsupported drivers and missing config are rejected
 *  - isDriverAvailable() gates on the supported-driver list + loaded extension
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dbal
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

namespace OCA\OpenRegister\Tests\Unit\Service\Dbal;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Test class for DbalConnectionFactory.
 */
class DbalConnectionFactoryTest extends TestCase {

	/**
	 * Path to the generated SQLite permits fixture.
	 *
	 * @var string
	 */
	private static string $fixturePath;

	/**
	 * Build the permits fixture once for the class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		include_once __DIR__ . '/../../../fixtures/dbal/build-permits-sqlite.php';
		self::$fixturePath = sys_get_temp_dir() . '/or-dbal-factory-test-permits.sqlite';
		build_permits_sqlite(path: self::$fixturePath);
	}//end setUpBeforeClass()

	/**
	 * Remove the fixture after the class.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		if (file_exists(self::$fixturePath) === true) {
			unlink(self::$fixturePath);
		}
	}//end tearDownAfterClass()

	/**
	 * Build a CredentialStore stub returning the given secret (or null).
	 *
	 * @param string|null $secret The secret get() returns.
	 *
	 * @return CredentialStore The stub store.
	 */
	private function store(?string $secret): CredentialStore {
		return new class($secret) implements CredentialStore {
			/**
			 * @param string|null $secret The stubbed secret.
			 */
			public function __construct(
				private readonly ?string $secret,
			) {
			}//end __construct()

			/**
			 * {@inheritDoc}
			 *
			 * @param string $uuid The credential UUID.
			 * @param string $secret The secret.
			 * @param string $scope The scope.
			 *
			 * @return void
			 */
			public function put(string $uuid, string $secret, string $scope = 'personal'): void {
			}//end put()

			/**
			 * {@inheritDoc}
			 *
			 * @param string $uuid The credential UUID.
			 * @param string $scope The scope.
			 *
			 * @return string|null The stubbed secret.
			 */
			public function get(string $uuid, string $scope = 'personal'): ?string {
				return $this->secret;
			}//end get()

			/**
			 * {@inheritDoc}
			 *
			 * @param string $uuid The credential UUID.
			 * @param string $scope The scope.
			 *
			 * @return void
			 */
			public function delete(string $uuid, string $scope = 'personal'): void {
			}//end delete()
		};
	}//end store()

	/**
	 * Build a database Source pointing at the SQLite fixture.
	 *
	 * @param array<string, mixed> $authConfig Overriding authConfig entries.
	 *
	 * @return Source The source.
	 */
	private function sqliteSource(array $authConfig = []): Source {
		$source = new Source();
		$source->setId(42);
		$source->setUuid('00000000-0000-0000-0000-000000000000');
		$source->setType('database');
		$source->setAuthConfig(array_merge(['driver' => 'pdo_sqlite', 'path' => self::$fixturePath], $authConfig));

		return $source;
	}//end sqliteSource()

	/**
	 * Connecting to the SQLite fixture succeeds and a trivial read works.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testConnectsToSqliteFixture(): void {
		$factory = new DbalConnectionFactory(credentialStore: $this->store(secret: null), logger: new NullLogger());
		$connection = $factory->getConnection(source: $this->sqliteSource());

		$count = $connection->fetchOne('SELECT COUNT(*) FROM permits');
		$this->assertSame(2, (int)$count);
	}//end testConnectsToSqliteFixture()

	/**
	 * The same source yields the same cached connection within a request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testConnectionIsCachedPerSource(): void {
		$factory = new DbalConnectionFactory(credentialStore: $this->store(secret: null), logger: new NullLogger());
		$source = $this->sqliteSource();

		$this->assertSame(
			$factory->getConnection(source: $source),
			$factory->getConnection(source: $source)
		);
	}//end testConnectionIsCachedPerSource()

	/**
	 * A configured credential that resolves to null FAILS CLOSED with the typed
	 * exception, and neither the exception nor the log carries a secret.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testUnresolvableCredentialFailsClosed(): void {
		$logger = new class extends AbstractLogger {

			/**
			 * Captured log messages.
			 *
			 * @var array<int, string>
			 */
			public array $messages = [];

			/**
			 * {@inheritDoc}
			 *
			 * @param mixed $level Log level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->messages[] = (string)$message;
			}//end log()
		};

		$factory = new DbalConnectionFactory(credentialStore: $this->store(secret: null), logger: $logger);
		$source = $this->sqliteSource(authConfig: ['credential' => '11111111-1111-1111-1111-111111111111']);

		try {
			$factory->getConnection(source: $source);
			$this->fail('Expected DbalConnectionException for an unresolvable credential');
		} catch (DbalConnectionException $e) {
			$this->assertStringNotContainsString('11111111', $e->getMessage());
		}

		foreach ($logger->messages as $message) {
			$this->assertStringNotContainsString('password', strtolower($message));
		}
	}//end testUnresolvableCredentialFailsClosed()

	/**
	 * An unsupported driver is rejected before any connection attempt.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testUnsupportedDriverIsRejected(): void {
		$factory = new DbalConnectionFactory(credentialStore: $this->store(secret: null), logger: new NullLogger());

		$this->expectException(DbalConnectionException::class);
		$factory->getConnection(source: $this->sqliteSource(authConfig: ['driver' => 'oci8']));
	}//end testUnsupportedDriverIsRejected()

	/**
	 * A network source without host/dbname is rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testMissingHostIsRejected(): void {
		if (in_array('mysql', \PDO::getAvailableDrivers(), true) === false) {
			$this->markTestSkipped('pdo_mysql not available');
		}

		$factory = new DbalConnectionFactory(credentialStore: $this->store(secret: null), logger: new NullLogger());
		$source = $this->sqliteSource(authConfig: ['driver' => 'pdo_mysql', 'path' => null]);

		$this->expectException(DbalConnectionException::class);
		$factory->getConnection(source: $source);
	}//end testMissingHostIsRejected()

	/**
	 * isDriverAvailable() accepts loaded supported drivers and rejects others.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testIsDriverAvailable(): void {
		$factory = new DbalConnectionFactory(credentialStore: $this->store(secret: null), logger: new NullLogger());

		$this->assertTrue($factory->isDriverAvailable(driver: 'pdo_sqlite'));
		$this->assertFalse($factory->isDriverAvailable(driver: 'oci8'));
		$this->assertFalse($factory->isDriverAvailable(driver: ''));
	}//end testIsDriverAvailable()
}//end class
