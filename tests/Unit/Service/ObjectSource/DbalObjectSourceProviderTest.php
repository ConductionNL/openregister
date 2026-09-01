<?php

/**
 * Unit tests for DbalObjectSourceProvider against a real SQLite database.
 *
 * Covers:
 *  - findAll() lists rows as virtual ObjectEntity instances (live read)
 *  - filters and search values are BOUND parameters — a value carrying SQL
 *    metacharacters is matched literally, returning no injected rows
 *  - limit/offset are applied in SQL and count() reports the full-predicate total
 *  - find() resolves by idColumn, returns null when absent, reconstructs a
 *    composite-id predicate, and returns null on a list-only (no-PK) schema
 *  - only allowlisted (schema-introspected) columns are filterable
 *  - a connection failure surfaces the 502/503-carrying exception, and a
 *    missing driver degrades findAll to an empty list
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

use Doctrine\DBAL\DriverManager;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\DbalWriteException;
use OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for DbalObjectSourceProvider.
 */
class DbalObjectSourceProviderTest extends TestCase {

	/**
	 * Path to the test SQLite database.
	 *
	 * @var string
	 */
	private static string $dbPath;

	/**
	 * Build a 120-row people table plus a composite-PK table once for the class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::$dbPath = sys_get_temp_dir() . '/or-dbal-provider-test.sqlite';
		if (file_exists(self::$dbPath) === true) {
			unlink(self::$dbPath);
		}

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => self::$dbPath]);
		$connection->executeStatement(
			'CREATE TABLE people (id INTEGER PRIMARY KEY, name VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, secret VARCHAR(64))'
		);
		for ($i = 1; $i <= 120; $i++) {
			$status = (($i % 2) === 0) ? 'active' : 'inactive';
			$connection->insert('people', ['id' => $i, 'name' => 'Person ' . $i, 'status' => $status, 'secret' => 'hidden-' . $i]);
		}

		$connection->executeStatement(
			'CREATE TABLE tenant_codes (tenant_id INTEGER NOT NULL, code VARCHAR(16) NOT NULL, label VARCHAR(64), PRIMARY KEY (tenant_id, code))'
		);
		$connection->insert('tenant_codes', ['tenant_id' => 1, 'code' => 'A', 'label' => 'First']);
		$connection->insert('tenant_codes', ['tenant_id' => 2, 'code' => 'B', 'label' => 'Second']);
		$connection->close();
	}//end setUpBeforeClass()

	/**
	 * Remove the database after the class.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		if (file_exists(self::$dbPath) === true) {
			unlink(self::$dbPath);
		}
	}//end tearDownAfterClass()

	/**
	 * Build the provider wired to a SourceMapper stub returning the SQLite source.
	 *
	 * @param string|null $dbPath Override database path (defaults to the class DB).
	 * @param string $driver The configured driver.
	 *
	 * @return DbalObjectSourceProvider The provider under test.
	 */
	private function provider(?string $dbPath = null, string $driver = 'pdo_sqlite', bool $writable = false): DbalObjectSourceProvider {
		$source = new Source();
		$source->setId(9);
		$source->setUuid('00000000-0000-0000-0000-000000000000');
		$source->setType('database');
		$source->setAuthConfig(['driver' => $driver, 'path' => ($dbPath ?? self::$dbPath), 'writable' => $writable]);

		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('find')->willReturn($source);
		$sourceMapper->method('findAll')->willReturn([$source]);
		$sourceMapper->method('findForSystem')->willReturn($source);

		$store = new class implements CredentialStore {
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
			 * @return string|null Always null.
			 */
			public function get(string $uuid, string $scope = 'personal'): ?string {
				return null;
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

		return new DbalObjectSourceProvider(
			sourceMapper: $sourceMapper,
			connectionFactory: new DbalConnectionFactory(credentialStore: $store, logger: new NullLogger()),
			logger: new NullLogger()
		);
	}//end provider()

	/**
	 * A register with id 1.
	 *
	 * @return Register The register.
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(1);
		return $register;
	}//end register()

	/**
	 * A schema whose properties mirror the people table.
	 *
	 * @return Schema The schema.
	 */
	private function peopleSchema(): Schema {
		$schema = new Schema();
		$schema->setId(11);
		$schema->setSlug('people');
		$schema->setProperties(
			[
				'id' => ['type' => 'integer'],
				'name' => ['type' => 'string', 'maxLength' => 64],
				'status' => ['type' => 'string', 'maxLength' => 16],
				'secret' => ['type' => 'string', 'maxLength' => 64],
			]
		);

		return $schema;
	}//end peopleSchema()

	/**
	 * The people object-source config block.
	 *
	 * @param array<string, mixed> $overrides Config overrides.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function peopleConfig(array $overrides = []): array {
		return array_merge(
			[
				'sourceId' => '9',
				'table' => 'people',
				'idColumn' => 'id',
			],
			$overrides
		);
	}//end peopleConfig()

	/**
	 * getId() is the id introspection binds schemas to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testGetId(): void {
		$this->assertSame('dbal-source', $this->provider()->getId());
	}//end testGetId()

	/**
	 * Regression for openregister#2089: resolving the schema's configured
	 * Source MUST go through `SourceMapper::findForSystem()` — the
	 * unfiltered, RBAC-free system lookup — never through the
	 * organisation-filtered `find()`/`findAll()` path. A Source that belongs
	 * to a DIFFERENT organisation than the caller's active one (the exact
	 * `saasMode: true` scenario from the issue, where admin override is
	 * unconditionally disabled) MUST still resolve and serve objects.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dbal-source-resolution-system-context/specs/dbal-source-resolution-system-context/spec.md
	 */
	public function testResolveSourceUsesSystemLookupAcrossOrganisations(): void {
		$source = new Source();
		$source->setId(9);
		$source->setUuid('00000000-0000-0000-0000-000000000000');
		$source->setType('database');
		// Belongs to an organisation different from whatever the caller's
		// active organisation would be — resolution must not depend on it.
		$source->setOrganisation('286a9152-4b09-4714-9115-fabbbad342d0');
		$source->setAuthConfig(['driver' => 'pdo_sqlite', 'path' => self::$dbPath, 'writable' => false]);

		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->expects($this->atLeastOnce())
			->method('findForSystem')
			->with($this->equalTo('9'))
			->willReturn($source);
		// The organisation-filtered lookups must NEVER be used to resolve a
		// schema's configured source — that is precisely the #2089 defect.
		$sourceMapper->expects($this->never())->method('find');
		$sourceMapper->expects($this->never())->method('findAll');

		$store = new class implements \OCA\OpenRegister\Service\Credential\CredentialStore {
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
			 * @return string|null Always null.
			 */
			public function get(string $uuid, string $scope = 'personal'): ?string {
				return null;
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

		$provider = new DbalObjectSourceProvider(
			sourceMapper: $sourceMapper,
			connectionFactory: new DbalConnectionFactory(credentialStore: $store, logger: new NullLogger()),
			logger: new NullLogger()
		);

		$objects = $provider->findAll(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: ['limit' => 5],
			config: $this->peopleConfig()
		);

		$this->assertCount(5, $objects);
	}//end testResolveSourceUsesSystemLookupAcrossOrganisations()

	/**
	 * findAll() with a filter and a limit applies both in SQL.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testFindAllAppliesFilterAndLimitInSql(): void {
		$objects = $this->provider()->findAll(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: ['filters' => ['status' => 'active'], 'limit' => 10, 'offset' => 0],
			config: $this->peopleConfig()
		);

		$this->assertCount(10, $objects);
		foreach ($objects as $object) {
			$this->assertSame('active', $object->getObject()['status']);
		}
	}//end testFindAllAppliesFilterAndLimitInSql()

	/**
	 * Free-text `_search` LIKEs across mixed-type columns via a text CAST.
	 *
	 * Live-observed on PostgreSQL: `integer LIKE text` raises "operator does
	 * not exist" when the search OR-chain touches non-text columns — every
	 * column is CAST to the platform's text type first. The search must both
	 * match string content and tolerate (and match) numeric columns.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testSearchCastsMixedColumnTypes(): void {
		$byStatus = $this->provider()->findAll(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: ['_search' => 'active', 'limit' => 100, 'offset' => 0],
			config: $this->peopleConfig()
		);
		$this->assertNotEmpty($byStatus);
		foreach ($byStatus as $object) {
			$this->assertStringContainsString('active', implode(' ', array_map('strval', $object->getObject())));
		}

		// A purely numeric term must hit the INTEGER id column via the cast.
		$byId = $this->provider()->findAll(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: ['_search' => '7', 'limit' => 100, 'offset' => 0],
			config: $this->peopleConfig()
		);
		$this->assertNotEmpty($byId);
	}//end testSearchCastsMixedColumnTypes()

	/**
	 * A filter value with SQL metacharacters is bound, not interpolated —
	 * matched literally, returning no injected rows.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testInjectionAttemptIsMatchedLiterally(): void {
		$provider = $this->provider();

		$objects = $provider->findAll(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: ['filters' => ['status' => "' OR 1=1 --"]],
			config: $this->peopleConfig()
		);
		$this->assertSame([], $objects);

		$count = $provider->count(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: ['filters' => ['status' => "' OR 1=1 --"]],
			config: $this->peopleConfig()
		);
		$this->assertSame(0, $count);
	}//end testInjectionAttemptIsMatchedLiterally()

	/**
	 * count() reflects the full predicate while findAll() returns the SQL page.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testCountReflectsFullPredicateWithPagedFindAll(): void {
		$provider = $this->provider();
		$query = ['filters' => ['status' => 'active'], 'limit' => 50, 'offset' => 50];

		$page = $provider->findAll(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: $query,
			config: $this->peopleConfig()
		);
		$this->assertCount(10, $page);

		$total = $provider->count(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: $query,
			config: $this->peopleConfig()
		);
		$this->assertSame(60, $total);
	}//end testCountReflectsFullPredicateWithPagedFindAll()

	/**
	 * Filters on columns outside the schema allowlist are ignored, and
	 * `nonFilterable` columns declared in config are excluded from predicates.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testOnlyAllowlistedColumnsAreFilterable(): void {
		$provider = $this->provider();

		// "rowid" is a real SQLite column but not on the schema — ignored.
		$all = $provider->count(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: ['filters' => ['rowid' => '1']],
			config: $this->peopleConfig()
		);
		$this->assertSame(120, $all);

		// "secret" is a schema column but declared non-filterable — ignored.
		$stillAll = $provider->count(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: ['filters' => ['secret' => 'hidden-1']],
			config: $this->peopleConfig(overrides: ['nonFilterable' => ['secret']])
		);
		$this->assertSame(120, $stillAll);
	}//end testOnlyAllowlistedColumnsAreFilterable()

	/**
	 * find() resolves a single row by idColumn and returns null when absent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testFindByIdAndAbsentReturnsNull(): void {
		$provider = $this->provider();

		$object = $provider->find(
			register: $this->register(),
			schema: $this->peopleSchema(),
			id: '7',
			config: $this->peopleConfig()
		);
		$this->assertNotNull($object);
		$this->assertSame('7', $object->getUuid());
		$this->assertSame('Person 7', $object->getObject()['name']);

		$absent = $provider->find(
			register: $this->register(),
			schema: $this->peopleSchema(),
			id: '99999',
			config: $this->peopleConfig()
		);
		$this->assertNull($absent);
	}//end testFindByIdAndAbsentReturnsNull()

	/**
	 * A composite-PK schema reconstructs the WHERE from the joined id parts,
	 * and rows carry the joined id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testCompositeIdRoundTrip(): void {
		$provider = $this->provider();
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('tenant-codes');
		$schema->setProperties(
			[
				'tenant_id' => ['type' => 'integer'],
				'code' => ['type' => 'string'],
				'label' => ['type' => 'string'],
			]
		);
		$config = [
			'sourceId' => '9',
			'table' => 'tenant_codes',
			'idColumn' => null,
			'idColumns' => ['tenant_id', 'code'],
		];

		$objects = $provider->findAll(
			register: $this->register(),
			schema: $schema,
			query: [],
			config: $config
		);
		$this->assertCount(2, $objects);
		$this->assertSame('1::A', $objects[0]->getUuid());

		$found = $provider->find(
			register: $this->register(),
			schema: $schema,
			id: '2::B',
			config: $config
		);
		$this->assertNotNull($found);
		$this->assertSame('Second', $found->getObject()['label']);
	}//end testCompositeIdRoundTrip()

	/**
	 * A list-only schema (no PK) returns null from find() while findAll/count work.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testListOnlySchemaFindReturnsNull(): void {
		$provider = $this->provider();
		$config = $this->peopleConfig(overrides: ['idColumn' => null]);

		$this->assertNull(
			$provider->find(register: $this->register(), schema: $this->peopleSchema(), id: '1', config: $config)
		);

		$objects = $provider->findAll(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: ['limit' => 5],
			config: $config
		);
		$this->assertCount(5, $objects);

		$total = $provider->count(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: [],
			config: $config
		);
		$this->assertSame(120, $total);
	}//end testListOnlySchemaFindReturnsNull()

	/**
	 * Sort is applied only for allowlisted columns.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testSortAppliedForAllowlistedColumn(): void {
		$objects = $this->provider()->findAll(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: ['sort' => ['id' => 'desc'], 'limit' => 1],
			config: $this->peopleConfig()
		);

		$this->assertSame('120', $objects[0]->getUuid());
	}//end testSortAppliedForAllowlistedColumn()

	/**
	 * A connection failure to a reachable-driver source surfaces the 503-class
	 * typed exception (never a bare 500 path).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testUnreachableDatabaseSurfaces503(): void {
		// A directory path is not a valid SQLite file — the connection fails.
		$provider = $this->provider(dbPath: sys_get_temp_dir());

		try {
			$provider->findAll(
				register: $this->register(),
				schema: $this->peopleSchema(),
				query: [],
				config: $this->peopleConfig()
			);
			$this->fail('Expected DbalObjectSourceException');
		} catch (DbalObjectSourceException $e) {
			$this->assertContains($e->getStatusCode(), [502, 503]);
		}
	}//end testUnreachableDatabaseSurfaces503()

	/**
	 * A schema bound to a source whose driver extension is unavailable degrades
	 * findAll to an empty list (and count to 0) instead of erroring.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testMissingDriverDegradesToEmptyList(): void {
		// oci8 is not in the supported list, so isDriverAvailable() is false.
		$provider = $this->provider(driver: 'oci8');

		$objects = $provider->findAll(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: [],
			config: $this->peopleConfig()
		);
		$this->assertSame([], $objects);

		$total = $provider->count(
			register: $this->register(),
			schema: $this->peopleSchema(),
			query: [],
			config: $this->peopleConfig()
		);
		$this->assertSame(0, $total);
	}//end testMissingDriverDegradesToEmptyList()

	/**
	 * Full write round-trip on a writable source: insert with a generated key,
	 * update, delete — asserted against the real SQLite file.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testWritableInsertUpdateDeleteRoundTrip(): void {
		$provider = $this->provider(writable: true);
		$this->assertInstanceOf(WritableObjectSourceProvider::class, $provider);

		$created = $provider->insert(
			register: $this->register(),
			schema: $this->peopleSchema(),
			data: ['name' => 'Willem Writes', 'status' => 'active'],
			config: $this->peopleConfig()
		);
		$newId = (string)$created->getUuid();
		$this->assertNotSame('', $newId);
		$this->assertSame('Willem Writes', $created->getObject()['name']);

		$updated = $provider->update(
			register: $this->register(),
			schema: $this->peopleSchema(),
			id: $newId,
			data: ['status' => 'inactive'],
			config: $this->peopleConfig()
		);
		$this->assertSame('inactive', $updated->getObject()['status']);
		$this->assertSame('Willem Writes', $updated->getObject()['name']);

		$this->assertTrue(
			$provider->remove(
				register: $this->register(),
				schema: $this->peopleSchema(),
				id: $newId,
				config: $this->peopleConfig()
			)
		);
		$this->assertNull(
			$provider->find(register: $this->register(), schema: $this->peopleSchema(), id: $newId, config: $this->peopleConfig())
		);
	}//end testWritableInsertUpdateDeleteRoundTrip()

	/**
	 * The live source flag is authoritative: a non-writable source rejects
	 * every write with the v1 read-only message — the re-lock contract.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testWriteRejectedWhenSourceNotWritable(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/read-only projection/');

		$this->provider(writable: false)->insert(
			register: $this->register(),
			schema: $this->peopleSchema(),
			data: ['name' => 'Nope', 'status' => 'active'],
			config: $this->peopleConfig()
		);
	}//end testWriteRejectedWhenSourceNotWritable()

	/**
	 * Unknown properties are rejected with a 400 — never silently dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testUnknownPropertyRejected(): void {
		try {
			$this->provider(writable: true)->insert(
				register: $this->register(),
				schema: $this->peopleSchema(),
				data: ['name' => 'X', 'status' => 'active', 'bogus' => 1],
				config: $this->peopleConfig()
			);
			$this->fail('Expected DbalWriteException was not thrown.');
		} catch (DbalWriteException $e) {
			$this->assertSame(400, $e->getStatusCode());
			$this->assertStringContainsString('bogus', $e->getMessage());
		}
	}//end testUnknownPropertyRejected()

	/**
	 * An external NOT NULL violation maps to a sanitized 422.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testNotNullViolationMapsTo422(): void {
		try {
			$this->provider(writable: true)->insert(
				register: $this->register(),
				schema: $this->peopleSchema(),
				data: ['status' => 'active'],
				config: $this->peopleConfig()
			);
			$this->fail('Expected DbalWriteException was not thrown.');
		} catch (DbalWriteException $e) {
			$this->assertSame(422, $e->getStatusCode());
			$this->assertStringNotContainsString('INSERT', $e->getMessage());
			$this->assertStringNotContainsString('people', $e->getMessage());
		}
	}//end testNotNullViolationMapsTo422()

	/**
	 * No-PK tables reject id-addressed writes; absent ids yield 404 parity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testNoPkAndAbsentIdWriteBehaviour(): void {
		$provider = $this->provider(writable: true);

		try {
			$provider->update(
				register: $this->register(),
				schema: $this->peopleSchema(),
				id: '1',
				data: ['status' => 'x'],
				config: $this->peopleConfig(overrides: ['idColumn' => null])
			);
			$this->fail('Expected DbalWriteException was not thrown.');
		} catch (DbalWriteException $e) {
			$this->assertSame(400, $e->getStatusCode());
		}

		$this->assertFalse(
			$provider->remove(
				register: $this->register(),
				schema: $this->peopleSchema(),
				id: '99999',
				config: $this->peopleConfig()
			)
		);

		try {
			$provider->update(
				register: $this->register(),
				schema: $this->peopleSchema(),
				id: '99999',
				data: ['status' => 'x'],
				config: $this->peopleConfig()
			);
			$this->fail('Expected DoesNotExistException was not thrown.');
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$this->assertStringContainsString('99999', $e->getMessage());
		}
	}//end testNoPkAndAbsentIdWriteBehaviour()

	/**
	 * Views never accept writes, even on a writable source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testViewNeverWritable(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/read-only projection/');

		$this->provider(writable: true)->insert(
			register: $this->register(),
			schema: $this->peopleSchema(),
			data: ['name' => 'X', 'status' => 'active'],
			config: $this->peopleConfig(overrides: ['isView' => true])
		);
	}//end testViewNeverWritable()

	/**
	 * Composite-key inserts must supply every key part; update predicates must
	 * match the composite shape.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testCompositeKeyWriteRules(): void {
		$provider = $this->provider(writable: true);
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('tenant_codes');
		$schema->setProperties(
			[
				'tenant_id' => ['type' => 'integer'],
				'code' => ['type' => 'string'],
				'label' => ['type' => 'string'],
			]
		);
		$config = [
			'sourceId' => '9',
			'table' => 'tenant_codes',
			'idColumn' => null,
			'idColumns' => ['tenant_id', 'code'],
		];

		try {
			$provider->insert(register: $this->register(), schema: $schema, data: ['tenant_id' => 3, 'label' => 'Missing code'], config: $config);
			$this->fail('Expected DbalWriteException was not thrown.');
		} catch (DbalWriteException $e) {
			$this->assertSame(400, $e->getStatusCode());
		}

		$created = $provider->insert(
			register: $this->register(),
			schema: $schema,
			data: ['tenant_id' => 3, 'code' => 'C', 'label' => 'Third'],
			config: $config
		);
		$this->assertSame('3::C', (string)$created->getUuid());

		$updated = $provider->update(register: $this->register(), schema: $schema, id: '3::C', data: ['label' => 'Third v2'], config: $config);
		$this->assertSame('Third v2', $updated->getObject()['label']);

		$this->assertTrue($provider->remove(register: $this->register(), schema: $schema, id: '3::C', config: $config));
	}//end testCompositeKeyWriteRules()
}//end class
