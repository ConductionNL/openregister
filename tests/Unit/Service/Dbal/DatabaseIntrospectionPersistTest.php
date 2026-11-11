<?php

/**
 * Characterisation tests for DatabaseIntrospectionService::introspect().
 *
 * Pins the persistence pass over the real SQLite permits fixture: which tables
 * are created and which updated, the drift entries and their log lines, the
 * schema-id list written back onto the register, and the order of the mapper
 * calls — for a first run (no register yet) and a re-run (register and some
 * schemas exist).
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

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Dbal\IntrospectionTools;
use OCA\OpenRegister\Service\Dbal\SqlTypeMapper;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Stringable;

/**
 * Test class for the persistence pass of DatabaseIntrospectionService.
 */
class DatabaseIntrospectionPersistTest extends TestCase {

	/**
	 * Path to the generated SQLite permits fixture.
	 *
	 * @var string
	 */
	private static string $fixturePath;

	/**
	 * The mapper calls in the order they were made.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The info lines the service logged.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Build the permits fixture once for the class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		include_once __DIR__ . '/../../../fixtures/dbal/build-permits-sqlite.php';
		self::$fixturePath = sys_get_temp_dir() . '/or-dbal-introspection-persist-test.sqlite';
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
	 * A first run creates the register and every schema, logs no drift and
	 * writes the created ids back onto the register.
	 *
	 * @return void
	 */
	public function testFirstRunCreatesRegisterAndEverySchema(): void {
		$register = new Register();
		$register->setId(3);
		$register->setSchemas([]);

		$registerMapper = $this->registerMapper(existing: [], register: $register);
		$schemaMapper   = $this->schemaMapper(existing: []);

		$result = $this->service(registerMapper: $registerMapper, schemaMapper: $schemaMapper)
			->introspect(source: $this->source());

		$this->assertSame(
			[
				'register' => 3,
				'created' => ['applicants', 'permit_types', 'permits', 'active_permits'],
				'updated' => [],
				'drift' => [],
			],
			$result
		);
		$this->assertSame([21, 22, 23, 24], $register->getSchemas());
		$this->assertSame(['[DbalIntrospection] table "active_permits" has no primary key — schema is read-list-only'], $this->logged);
		$this->assertSame(
			[
				'registers.findAll',
				'registers.createFromArray',
				'schemas.createFromArray:applicants',
				'schemas.createFromArray:permit_types',
				'schemas.createFromArray:permits',
				'schemas.createFromArray:active_permits',
				'registers.update',
			],
			$this->calls
		);
	}//end testFirstRunCreatesRegisterAndEverySchema()

	/**
	 * A re-run updates the tables it already knows, creates the rest, reports
	 * drift only where the structure changed, and keeps the register's
	 * schema-id list in blueprint order.
	 *
	 * @return void
	 */
	public function testRerunUpdatesKnownTablesAndReportsDrift(): void {
		$blueprint = $this->service(
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class)
		)->buildBlueprint(source: $this->source());
		$byTable = [];
		foreach ($blueprint['schemas'] as $definition) {
			$byTable[$definition['configuration']['x-openregister-object-source']['config']['table']] = $definition;
		}

		$this->logged = [];

		// Applicants lost its email column since the last run: drift.
		$drifted = $byTable['applicants'];
		unset($drifted['properties']['email']);
		$applicants = $this->existingSchema(id: 11, definition: $drifted);
		// Permits is unchanged: updated without drift.
		$permits = $this->existingSchema(id: 12, definition: $byTable['permits']);
		// A schema without a bound table is ignored.
		$unbound = new Schema();
		$unbound->setId(13);

		$register = new Register();
		$register->setId(5);
		$register->setSchemas([11, 12, 13]);

		$registerMapper = $this->registerMapper(existing: [$register], register: $register);
		$schemaMapper   = $this->schemaMapper(existing: [$applicants, $permits, $unbound]);

		$result = $this->service(registerMapper: $registerMapper, schemaMapper: $schemaMapper)
			->introspect(source: $this->source());

		$expectedChanges = (new SchemaDiffService())->diff(
			old: ['properties' => $applicants->getProperties(), 'required' => $applicants->getRequired()],
			new: ['properties' => $byTable['applicants']['properties'], 'required' => ($byTable['applicants']['required'] ?? [])]
		)->getChanges();
		$this->assertNotSame([], $expectedChanges);

		$this->assertSame(
			[
				'register' => 5,
				'created' => ['permit_types', 'active_permits'],
				'updated' => ['applicants', 'permits'],
				'drift' => [['table' => 'applicants', 'changes' => $expectedChanges]],
			],
			$result
		);
		$this->assertSame([11, 21, 12, 22], $register->getSchemas());
		$this->assertSame(
			[
				'[DbalIntrospection] table "active_permits" has no primary key — schema is read-list-only',
				sprintf('[DbalIntrospection] drift on table "applicants": %d change(s)', count($expectedChanges)),
			],
			$this->logged
		);
		$this->assertSame(
			[
				'registers.findAll',
				'schemas.findMultiple:11,12,13',
				'schemas.updateFromArray:11',
				'schemas.createFromArray:permit_types',
				'schemas.updateFromArray:12',
				'schemas.createFromArray:active_permits',
				'registers.update',
			],
			$this->calls
		);
	}//end testRerunUpdatesKnownTablesAndReportsDrift()

	/**
	 * An existing schema shaped like a blueprint definition.
	 *
	 * @param int                  $id         The schema id.
	 * @param array<string, mixed> $definition The blueprint definition it was saved from.
	 *
	 * @return Schema The schema.
	 */
	private function existingSchema(int $id, array $definition): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setConfiguration($definition['configuration']);
		$schema->setProperties($definition['properties']);
		$schema->setRequired($definition['required'] ?? []);

		return $schema;
	}//end existingSchema()

	/**
	 * A register mapper that records its calls.
	 *
	 * @param array<int, Register> $existing What findAll() answers.
	 * @param Register             $register What createFromArray() and update() answer.
	 *
	 * @return RegisterMapper&MockObject The mapper.
	 */
	private function registerMapper(array $existing, Register $register): RegisterMapper {
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (...$args) use ($existing): array {
				$this->assertSame(['source' => '00000000-0000-0000-0000-000000000000'], $args[2]);
				$this->calls[] = 'registers.findAll';
				return $existing;
			}
		);
		$mapper->method('createFromArray')->willReturnCallback(
			function (array $object) use ($register): Register {
				$this->assertSame('Permits demo', $object['title']);
				$this->calls[] = 'registers.createFromArray';
				return $register;
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (Register $entity) use ($register): Register {
				$this->assertSame($register, $entity);
				$this->calls[] = 'registers.update';
				return $entity;
			}
		);

		return $mapper;
	}//end registerMapper()

	/**
	 * A schema mapper that records its calls; created schemas get ids from 21 up.
	 *
	 * @param array<int, Schema> $existing What findMultiple() answers.
	 *
	 * @return SchemaMapper&MockObject The mapper.
	 */
	private function schemaMapper(array $existing): SchemaMapper {
		$nextId = 21;
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findMultiple')->willReturnCallback(
			function (array $ids) use ($existing): array {
				$this->calls[] = 'schemas.findMultiple:' . implode(',', $ids);
				return $existing;
			}
		);
		$mapper->method('createFromArray')->willReturnCallback(
			function (array $object) use (&$nextId): Schema {
				$this->calls[] = 'schemas.createFromArray:' . $object['configuration']['x-openregister-object-source']['config']['table'];
				$schema = new Schema();
				$schema->setId($nextId++);
				return $schema;
			}
		);
		$mapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $object): Schema {
				$this->assertArrayHasKey('properties', $object);
				$this->calls[] = 'schemas.updateFromArray:' . $id;
				$schema = new Schema();
				$schema->setId($id);
				return $schema;
			}
		);

		return $mapper;
	}//end schemaMapper()

	/**
	 * Build the service over the given mappers, logging info lines into $logged.
	 *
	 * @param RegisterMapper $registerMapper The register mapper.
	 * @param SchemaMapper   $schemaMapper   The schema mapper.
	 *
	 * @return DatabaseIntrospectionService The service under test.
	 */
	private function service(RegisterMapper $registerMapper, SchemaMapper $schemaMapper): DatabaseIntrospectionService {
		$store = new class implements CredentialStore {
			/**
			 * {@inheritDoc}
			 *
			 * @param string $uuid   The credential UUID.
			 * @param string $secret The secret.
			 * @param string $scope  The scope.
			 *
			 * @return void
			 */
			public function put(string $uuid, string $secret, string $scope = 'personal'): void {
			}//end put()

			/**
			 * {@inheritDoc}
			 *
			 * @param string $uuid  The credential UUID.
			 * @param string $scope The scope.
			 *
			 * @return string|null Always null (no credential configured).
			 */
			public function get(string $uuid, string $scope = 'personal'): ?string {
				return null;
			}//end get()

			/**
			 * {@inheritDoc}
			 *
			 * @param string $uuid  The credential UUID.
			 * @param string $scope The scope.
			 *
			 * @return void
			 */
			public function delete(string $uuid, string $scope = 'personal'): void {
			}//end delete()
		};

		$test   = $this;
		$logger = new class ($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param DatabaseIntrospectionPersistTest $test The test collecting the lines.
			 */
			public function __construct(private DatabaseIntrospectionPersistTest $test) {
			}//end __construct()

			/**
			 * Collect info lines.
			 *
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->collect(level: (string)$level, message: (string)$message);
			}//end log()
		};

		return new DatabaseIntrospectionService(
			tools: new IntrospectionTools(
				connectionFactory: new DbalConnectionFactory(credentialStore: $store, logger: new NullLogger()),
				typeMapper: new SqlTypeMapper(logger: new NullLogger()),
				diffService: new SchemaDiffService()
			),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			logger: $logger
		);
	}//end service()

	/**
	 * Collect one log line from the service's logger.
	 *
	 * @param string $level   The level.
	 * @param string $message The message.
	 *
	 * @return void
	 */
	public function collect(string $level, string $message): void {
		if ($level === 'info') {
			$this->logged[] = $message;
			return;
		}

		$this->logged[] = $level . ':' . $message;
	}//end collect()

	/**
	 * The database source over the permits fixture.
	 *
	 * @return Source The source.
	 */
	private function source(): Source {
		$source = new Source();
		$source->setId(7);
		$source->setUuid('00000000-0000-0000-0000-000000000000');
		$source->setTitle('Permits demo');
		$source->setType('database');
		$source->setAuthConfig(['driver' => 'pdo_sqlite', 'path' => self::$fixturePath]);

		return $source;
	}//end source()
}//end class
