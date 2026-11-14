<?php

/**
 * The outside database source's breaker (`source:{id}`): a source that keeps
 * failing opens it, and while it is open neither find() nor findAll() opens a
 * connection: both refuse with the 503 dependency refusal, so a dead source
 * never reads as a missing record.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use Doctrine\DBAL\DriverManager;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Service\Resilience\BreakerRegistry;
use OCA\OpenRegister\Service\Resilience\BreakerThresholds;
use OCA\OpenRegister\Service\Resilience\CircuitBreaker;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
use OCA\OpenRegister\Tests\Unit\Service\Resilience\FakeMemcache;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Resilience/FakeMemcache.php';

/**
 * @covers \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerRegistry
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerThresholds
 * @uses   \OCA\OpenRegister\Service\Resilience\CircuitBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 */
class DbalObjectSourceBreakerTest extends TestCase {

	private const KEY = 'source:9';

	private static string $dbPath;

	private int $now = 2_000_000;

	private CircuitBreaker $breaker;

	/**
	 * How often a connection was asked for.
	 *
	 * @var integer
	 */
	private int $connects = 0;

	public static function setUpBeforeClass(): void {
		self::$dbPath = sys_get_temp_dir() . '/or-dbal-breaker-test.sqlite';
		if (file_exists(self::$dbPath) === true) {
			unlink(self::$dbPath);
		}

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => self::$dbPath]);
		$connection->executeStatement('CREATE TABLE people (id INTEGER PRIMARY KEY, name VARCHAR(64) NOT NULL)');
		$connection->insert('people', ['id' => 1, 'name' => 'Ada']);
		$connection->close();
	}

	public static function tearDownAfterClass(): void {
		if (file_exists(self::$dbPath) === true) {
			unlink(self::$dbPath);
		}
	}

	protected function setUp(): void {
		parent::setUp();
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->breaker = new CircuitBreaker(cache: new FakeMemcache(), thresholds: new BreakerThresholds(), clock: $clock, logger: new NullLogger(), shared: true);
	}

	/**
	 * The provider over source 9 at the given database path, counting connections.
	 *
	 * @param string $table The table the schema reads.
	 *
	 * @return DbalObjectSourceProvider
	 */
	private function provider(): DbalObjectSourceProvider {
		$source = new Source();
		$source->setId(9);
		$source->setUuid('00000000-0000-0000-0000-000000000009');
		$source->setType('database');
		$source->setAuthConfig(['driver' => 'pdo_sqlite', 'path' => self::$dbPath, 'writable' => false]);

		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('find')->willReturn($source);
		$sourceMapper->method('findForSystem')->willReturn($source);

		$factory = $this->createMock(DbalConnectionFactory::class);
		$factory->method('isDriverAvailable')->willReturn(true);
		$factory->method('getConnection')->willReturnCallback(
			function (): \Doctrine\DBAL\Connection {
				$this->connects++;
				return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => self::$dbPath]);
			}
		);

		$registry = $this->createMock(BreakerRegistry::class);
		$registry->method('breaker')->willReturn($this->breaker);

		return new DbalObjectSourceProvider(sourceMapper: $sourceMapper, connectionFactory: $factory, logger: new NullLogger(), breakers: $registry);
	}

	/**
	 * The people table, or a table that does not exist so every query fails.
	 *
	 * @param string $table The table.
	 *
	 * @return SourcedTable
	 */
	private static function table(string $table = 'people'): SourcedTable {
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(11);
		$schema->setSlug('people');
		$schema->setProperties(['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]);

		return new SourcedTable(
			table: new RegisterSchema(register: $register, schema: $schema),
			config: ['sourceId' => '9', 'table' => $table, 'idColumn' => 'id', 'columns' => ['id' => 'id', 'name' => 'name']]
		);
	}

	/**
	 * A healthy source reads and keeps its breaker closed, counting the calls.
	 *
	 * @return void
	 */
	public function testAHealthySourceReadsThroughAClosedBreaker(): void {
		$provider = $this->provider();

		$this->assertSame('Ada', $provider->find(self::table(), '1')?->getObject()['name'] ?? null);
		$this->assertCount(1, $provider->findAll(self::table()));
		$this->assertSame(1, $provider->count(self::table()));

		$state = $this->breaker->state(self::KEY);
		$this->assertSame('closed', $state['state']);
		$this->assertSame(3, $state['calls']);
		$this->assertSame(0, $state['failures']);
	}

	/**
	 * Failing queries open the breaker; then find() and findAll() refuse with no connection attempt.
	 *
	 * @return void
	 */
	public function testAFailingSourceOpensTheBreakerAndBothReadsRefuseWithoutConnecting(): void {
		$provider = $this->provider();
		for ($i = 0; $i < 10; $i++) {
			try {
				$provider->findAll(self::table('missing_table'));
				$this->fail('A query on a missing table must fail.');
			} catch (DbalObjectSourceException $e) {
				$this->assertSame(502, $e->getStatusCode());
			}
		}

		$this->assertSame('open', $this->breaker->state(self::KEY)['state']);
		$connects = $this->connects;

		foreach (['find', 'findAll'] as $read) {
			try {
				$read === 'find' ? $provider->find(self::table(), '1') : $provider->findAll(self::table());
				$this->fail($read . ' must refuse while the breaker is open.');
			} catch (DependencyUnavailableException $e) {
				$this->assertSame(self::KEY, $e->key);
				$this->assertGreaterThan(0, $e->retryAfter());
			}
		}

		$this->assertSame($connects, $this->connects, 'No connection is attempted while the breaker is open.');
	}

	/**
	 * After the cool-down one probe goes through, and a good read closes the breaker.
	 *
	 * @return void
	 */
	public function testASuccessfulProbeClosesTheBreaker(): void {
		$provider = $this->provider();
		for ($i = 0; $i < 10; $i++) {
			$this->breaker->recordFailure(self::KEY);
		}

		$this->now += 3600;

		$this->assertSame('Ada', $provider->find(self::table(), '1')?->getObject()['name'] ?? null);
		$this->assertSame('closed', $this->breaker->state(self::KEY)['state']);
	}
}
