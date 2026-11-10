<?php

/**
 * Golden tests for the DbalObjectSourceProvider write gate and the read
 * paths around it: which writes are allowed, which are refused, with which
 * exception type, status and message, and in which ORDER the refusals are
 * checked (a view without a key is refused as read-only, not as keyless).
 *
 * Pinned on the code before the parameter-object refactor, so the refactor
 * provably changes no allowed or refused outcome.
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

use Doctrine\DBAL\DriverManager;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\DbalWriteException;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * The write gate's allowed and refused outcomes.
 */
class DbalObjectSourceProviderWriteGateGoldenTest extends TestCase {

	private const READ_ONLY = 'Schema "things" is a read-only projection of object-source provider "dbal-source"; '
		. 'writes are not allowed.';

	private const KEYLESS = 'This external table has no primary key; rows cannot be updated or deleted.';

	private const NOT_A_COLUMN = 'Property "%s" is not a column of the external table.';

	/**
	 * The SQLite file each test works on, created fresh in setUp().
	 *
	 * @var string
	 */
	private string $dbPath = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dbPath = sys_get_temp_dir() . '/or-dbal-write-gate-golden.sqlite';
		if (file_exists($this->dbPath) === true) {
			unlink($this->dbPath);
		}

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
		$connection->executeStatement('CREATE TABLE things (id INTEGER PRIMARY KEY, name VARCHAR(64), kind VARCHAR(16))');
		$connection->executeStatement('CREATE TABLE logbook (line VARCHAR(64), kind VARCHAR(16))');
		foreach ([1 => 'odd', 2 => 'even', 3 => 'odd', 4 => 'even', 5 => 'odd'] as $i => $kind) {
			$connection->insert('things', ['id' => $i, 'name' => 'Thing ' . $i, 'kind' => $kind]);
		}

		$connection->close();
	}//end setUp()

	protected function tearDown(): void {
		if (file_exists($this->dbPath) === true) {
			unlink($this->dbPath);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A provider whose single source carries the given auth config.
	 *
	 * @param array<string, mixed> $authConfig The source's auth config.
	 * @param string               $type       The source type.
	 *
	 * @return DbalObjectSourceProvider
	 */
	private function provider(array $authConfig, string $type = 'database'): DbalObjectSourceProvider {
		$source = new Source();
		$source->setId(9);
		$source->setUuid('00000000-0000-0000-0000-000000000009');
		$source->setType($type);
		$source->setAuthConfig(array_merge(['driver' => 'pdo_sqlite', 'path' => $this->dbPath], $authConfig));

		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('findForSystem')->willReturn($source);

		$store = new class implements CredentialStore {
			public function put(string $uuid, string $secret, string $scope = 'personal'): void {
			}//end put()

			public function get(string $uuid, string $scope = 'personal'): ?string {
				return null;
			}//end get()

			public function delete(string $uuid, string $scope = 'personal'): void {
			}//end delete()
		};

		return new DbalObjectSourceProvider(
			sourceMapper: $sourceMapper,
			connectionFactory: new DbalConnectionFactory(credentialStore: $store, logger: new NullLogger()),
			logger: new NullLogger()
		);
	}//end provider()

	private function register(): Register {
		$register = new Register();
		$register->setId(1);
		return $register;
	}//end register()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(21);
		$schema->setSlug('things');
		$schema->setProperties(
			[
				'id' => ['type' => 'integer'],
				'name' => ['type' => 'string'],
				'kind' => ['type' => 'string'],
				'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * The schema config with some keys changed.
	 *
	 * @param array<string, mixed> $overrides Config keys to change.
	 *
	 * @return array<string, mixed>
	 */
	private function config(array $overrides = []): array {
		return array_merge(['sourceId' => '9', 'table' => 'things', 'idColumn' => 'id'], $overrides);
	}//end config()

	/**
	 * Insert through the provider.
	 *
	 * @param DbalObjectSourceProvider $provider The provider.
	 * @param array<string, mixed>     $data     The payload.
	 * @param array<string, mixed>     $config   The schema config.
	 *
	 * @return callable
	 */
	private function inserting(DbalObjectSourceProvider $provider, array $data, array $config): callable {
		return fn () => $provider->insert(register: $this->register(), schema: $this->schema(), data: $data, config: $config);
	}//end inserting()

	/**
	 * Update through the provider.
	 *
	 * @param DbalObjectSourceProvider $provider The provider.
	 * @param array<int, mixed>        $change   The id and the payload.
	 * @param array<string, mixed>     $config   The schema config.
	 *
	 * @return callable
	 */
	private function updating(DbalObjectSourceProvider $provider, array $change, array $config): callable {
		return fn () => $provider->update(
			register: $this->register(),
			schema: $this->schema(),
			id: $change[0],
			data: $change[1],
			config: $config
		);
	}//end updating()

	/**
	 * Remove through the provider.
	 *
	 * @param DbalObjectSourceProvider $provider The provider.
	 * @param string                   $id       The row id.
	 * @param array<string, mixed>     $config   The schema config.
	 *
	 * @return callable
	 */
	private function removing(DbalObjectSourceProvider $provider, string $id, array $config): callable {
		return fn () => $provider->remove(register: $this->register(), schema: $this->schema(), id: $id, config: $config);
	}//end removing()

	/**
	 * Count through the provider and describe the outcome.
	 *
	 * @param DbalObjectSourceProvider $provider The provider.
	 * @param array<string, mixed>     $query    The query.
	 * @param array<string, mixed>     $config   The schema config.
	 *
	 * @return array<int, mixed>
	 */
	private function counted(DbalObjectSourceProvider $provider, array $query, array $config): array {
		return self::outcome(
			call: fn () => $provider->count(register: $this->register(), schema: $this->schema(), query: $query, config: $config)
		);
	}//end counted()

	/**
	 * Run one call and describe its outcome as [class, status, message].
	 *
	 * @param callable $call The call.
	 *
	 * @return array<int, mixed>
	 */
	private static function outcome(callable $call): array {
		try {
			$result = $call();
		} catch (Throwable $e) {
			$status = null;
			if (method_exists($e, 'getStatusCode') === true) {
				$status = $e->getStatusCode();
			}

			return [get_class($e), $status, $e->getMessage()];
		}

		return ['ok', null, json_encode(self::shape(result: $result))];
	}//end outcome()

	/**
	 * A comparable shape of a provider result.
	 *
	 * @param mixed $result The result.
	 *
	 * @return mixed
	 */
	private static function shape(mixed $result): mixed {
		if ($result instanceof ObjectEntity) {
			return [
				'uuid' => $result->getUuid(),
				'register' => $result->getRegister(),
				'schema' => $result->getSchema(),
				'object' => $result->getObject(),
			];
		}

		return $result;
	}//end shape()

	/**
	 * Refusals of every write verb, in the order the gate checks them.
	 *
	 * Each case: source auth config, schema-config overrides, the verb, the
	 * pinned outcome.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function refusals(): array {
		$readOnly = [RuntimeException::class, null, self::READ_ONLY];
		$keyless  = [DbalWriteException::class, 400, self::KEYLESS];
		$open     = ['writable' => true];
		$closed   = ['writable' => false];
		$noKey    = ['idColumn' => null];
		return [
			'view insert, writable source' => [$open, ['isView' => true], 'insert', $readOnly],
			'keyless view update: read-only first' => [$open, ['isView' => true] + $noKey, 'update', $readOnly],
			'keyless query remove: read-only first' => [$open, ['query' => 'SELECT 1'] + $noKey, 'remove', $readOnly],
			'query-backed insert, writable source' => [$open, ['query' => 'SELECT * FROM things'], 'insert', $readOnly],
			'keyless update, read-only source: keyless first' => [$closed, $noKey, 'update', $keyless],
			'keyless remove, writable source' => [$open, ['idColumn' => null, 'idColumns' => []], 'remove', $keyless],
			'keyless insert is refused as read-only' => [$closed, ['idColumn' => null, 'table' => 'logbook'], 'insert', $readOnly],
			'no source id' => [$open, ['sourceId' => ''], 'insert', $readOnly],
			'writable string "1"' => [['writable' => '1'], [], 'insert', $readOnly],
			'writable int 1' => [['writable' => 1], [], 'update', $readOnly],
			'writable missing' => [[], [], 'remove', $readOnly],
			'driver unavailable' => [
				['writable' => true, 'driver' => 'oci8'],
				[],
				'insert',
				[DbalObjectSourceException::class, 503, 'The external database is unreachable.'],
			],
			'unknown property' => [$open, [], 'insert:bogus', [DbalWriteException::class, 400, sprintf(self::NOT_A_COLUMN, 'bogus')]],
			'array property' => [$open, [], 'insert:tags', [DbalWriteException::class, 400, sprintf(self::NOT_A_COLUMN, 'tags')]],
			'non-scalar value' => [$open, [], 'insert:nested', [DbalWriteException::class, 400, 'Property "name" must be a scalar value.']],
			'update with only the key' => [$open, [], 'update:only-id', [DbalWriteException::class, 400, 'The update contains no writable columns.']],
			'update of a missing row' => [$open, [], 'update:missing', [DoesNotExistException::class, null, 'No external row matches id 404']],
		];
	}//end refusals()

	/**
	 * Every refused write is refused the same way.
	 *
	 * @param array<string, mixed> $auth     Source auth config.
	 * @param array<string, mixed> $config   Config overrides.
	 * @param string               $verb     The write verb (and variant).
	 * @param array<int, mixed>    $expected The pinned outcome.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testARefusedWriteIsRefusedTheSameWay(array $auth, array $config, string $verb, array $expected): void {
		$provider = $this->provider(authConfig: $auth);
		$config   = $this->config(overrides: $config);
		$call     = match ($verb) {
			'insert' => $this->inserting(provider: $provider, data: ['name' => 'N', 'kind' => 'k'], config: $config),
			'insert:bogus' => $this->inserting(provider: $provider, data: ['name' => 'N', 'bogus' => 1], config: $config),
			'insert:tags' => $this->inserting(provider: $provider, data: ['tags' => ['a']], config: $config),
			'insert:nested' => $this->inserting(provider: $provider, data: ['name' => ['x']], config: $config),
			'update' => $this->updating(provider: $provider, change: ['1', ['name' => 'U']], config: $config),
			'update:only-id' => $this->updating(provider: $provider, change: ['1', ['id' => 7, '@self' => []]], config: $config),
			'update:missing' => $this->updating(provider: $provider, change: ['404', ['name' => 'U']], config: $config),
			'remove' => $this->removing(provider: $provider, id: '1', config: $config),
		};

		$this->assertSame($expected, self::outcome(call: $call));
	}//end testARefusedWriteIsRefusedTheSameWay()

	/**
	 * A source that is not of type "database" never admits a write.
	 *
	 * @return void
	 */
	public function testANonDatabaseSourceIsRefused(): void {
		$provider = $this->provider(authConfig: ['writable' => true], type: 'api');

		$this->assertSame(
			[RuntimeException::class, null, self::READ_ONLY],
			self::outcome(call: $this->inserting(provider: $provider, data: ['name' => 'N'], config: $this->config()))
		);
	}//end testANonDatabaseSourceIsRefused()

	/**
	 * The allowed writes and what they answer, verb by verb, on one row.
	 *
	 * @return void
	 */
	public function testAllowedWritesAnswerTheSameRows(): void {
		$provider = $this->provider(authConfig: ['writable' => true]);
		$config   = $this->config();

		// The id in the payload is dropped: the key is generated, never taken from it.
		$payload = ['id' => 50, 'name' => 'Fifty', 'kind' => 'x', '@self' => ['a' => 1]];
		$this->assertSame(
			['ok', null, '{"uuid":"6","register":"1","schema":"21","object":{"id":"6","name":"Fifty","kind":"x"}}'],
			self::outcome(call: $this->inserting(provider: $provider, data: $payload, config: $config))
		);
		$this->assertSame(
			['ok', null, '{"uuid":"6","register":"1","schema":"21","object":{"id":"6","name":"Fifty v2","kind":null}}'],
			self::outcome(call: $this->updating(provider: $provider, change: ['6', ['name' => 'Fifty v2', 'kind' => null]], config: $config))
		);
		$this->assertSame(['ok', null, 'true'], self::outcome(call: $this->removing(provider: $provider, id: '6', config: $config)));
		$this->assertSame(['ok', null, 'false'], self::outcome(call: $this->removing(provider: $provider, id: '6', config: $config)));

		// A blank query is no query: the table itself is written.
		$blankQuery = $this->config(overrides: ['query' => '  ']);
		$this->assertSame(
			['ok', null, '{"uuid":"6","register":"1","schema":"21","object":{"id":"6","name":"Q","kind":"q"}}'],
			self::outcome(call: $this->inserting(provider: $provider, data: ['name' => 'Q', 'kind' => 'q'], config: $blankQuery))
		);

		// A keyless table appends and echoes what it wrote, with no identity.
		$logbook = $this->config(overrides: ['idColumn' => null, 'table' => 'logbook']);
		$this->assertSame(
			['ok', null, '{"uuid":null,"register":"1","schema":"21","object":{"id":null,"kind":"log"}}'],
			self::outcome(call: $this->inserting(provider: $provider, data: ['kind' => 'log'], config: $logbook))
		);
	}//end testAllowedWritesAnswerTheSameRows()

	/**
	 * The read side the refactor touches: find, count with filters and search,
	 * the list page with sort and like, and the reads on a driverless source.
	 *
	 * @return void
	 */
	public function testReadsAnswerTheSameRows(): void {
		$provider = $this->provider(authConfig: []);
		$config   = $this->config();

		$found = $provider->find(register: $this->register(), schema: $this->schema(), id: '2', config: $config);
		$this->assertSame(
			['uuid' => '2', 'register' => '1', 'schema' => '21', 'object' => ['id' => '2', 'name' => 'Thing 2', 'kind' => 'even']],
			self::shape(result: $found)
		);
		$this->assertNull($provider->find(register: $this->register(), schema: $this->schema(), id: '77', config: $config));

		$ignored = ['filters' => ['tags' => 'a', 'name' => ['nested' => 1], 0 => 'x']];
		$this->assertSame(['ok', null, '2'], $this->counted(provider: $provider, query: ['filters' => ['kind' => 'even']], config: $config));
		$this->assertSame(['ok', null, '1'], $this->counted(provider: $provider, query: ['_search' => 'ing 3'], config: $config));
		$this->assertSame(['ok', null, '5'], $this->counted(provider: $provider, query: $ignored, config: $config));

		$nonFilterable = $this->config(overrides: ['nonFilterable' => ['name']]);
		$this->assertSame(['ok', null, '5'], $this->counted(provider: $provider, query: ['filters' => ['name' => 'Thing 1']], config: $nonFilterable));

		$noSource = $this->config(overrides: ['sourceId' => '']);
		$this->assertSame(['ok', null, '0'], $this->counted(provider: $provider, query: [], config: $noSource));
		$this->assertSame(
			[DbalObjectSourceException::class, 502, 'The external database returned an error.'],
			$this->counted(provider: $provider, query: [], config: $this->config(overrides: ['table' => 'absent_table']))
		);

		$page = $provider->findAll(
			register: $this->register(),
			schema: $this->schema(),
			query: [
				'filters' => ['name' => ['like' => 'thing']],
				'sort' => ['kind' => 'desc', 'id' => 'up', 'tags' => 'desc'],
				'limit' => 3,
				'offset' => 1,
			],
			config: $config
		);
		$this->assertSame(['3', '5', '2'], array_map(static fn ($o) => $o->getUuid(), $page));

		$driverless = $this->provider(authConfig: ['driver' => 'oci8']);
		$this->assertSame(['ok', null, '0'], $this->counted(provider: $driverless, query: [], config: $config));
		$this->assertSame([], $driverless->findAll(register: $this->register(), schema: $this->schema(), query: [], config: $config));
		$this->assertNull($driverless->find(register: $this->register(), schema: $this->schema(), id: '1', config: $config));
	}//end testReadsAnswerTheSameRows()
}//end class
