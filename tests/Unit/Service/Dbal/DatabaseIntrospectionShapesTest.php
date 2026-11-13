<?php

/**
 * Unit tests for the less common table and view shapes DatabaseIntrospectionService maps.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dbal
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

namespace OCA\OpenRegister\Tests\Unit\Service\Dbal;

use Doctrine\DBAL\DriverManager;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Dbal\IntrospectionTools;
use OCA\OpenRegister\Service\Dbal\SqlTypeMapper;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use ReflectionClass;
use Stringable;

/**
 * Pins the blueprint for a composite primary key, a multi-column foreign key,
 * a non-filterable column, an empty view, a view sampled for its types, a view
 * that cannot be sampled, and a source without a uuid.
 *
 * @covers \OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Service\Dbal\DbalConnectionFactory
 * @uses   \OCA\OpenRegister\Service\Dbal\IntrospectionTools
 * @uses   \OCA\OpenRegister\Service\Dbal\SourceIntrospection
 * @uses   \OCA\OpenRegister\Service\Dbal\SqlTypeMapper
 * @uses   \OCA\OpenRegister\Service\Dbal\TableForeignKey
 * @uses   \OCA\OpenRegister\Service\Dbal\TableRef
 */
final class DatabaseIntrospectionShapesTest extends TestCase {

	/**
	 * The SQLite file the fixture lives in.
	 *
	 * @var string
	 */
	private static string $dbPath;

	/**
	 * The blueprint, built once for the fixture.
	 *
	 * @var array<string, mixed>
	 */
	private static array $blueprint;

	/**
	 * The messages the service logged while building it.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private static array $logged = [];

	/**
	 * Build the fixture and its blueprint.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::$dbPath = sys_get_temp_dir() . '/or-dbal-introspection-shapes.sqlite';
		if (file_exists(self::$dbPath) === true) {
			unlink(self::$dbPath);
		}

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => self::$dbPath]);
		foreach ([
			'CREATE TABLE regions (code VARCHAR(8) NOT NULL, year INTEGER NOT NULL, name VARCHAR(64), PRIMARY KEY (code, year))',
			'CREATE TABLE permits (id INTEGER PRIMARY KEY, region_code VARCHAR(8), region_year INTEGER, scan BLOB, '
				. 'FOREIGN KEY (region_code, region_year) REFERENCES regions (code, year))',
			'CREATE TABLE gone (id INTEGER PRIMARY KEY)',
			'CREATE VIEW broken_view AS SELECT id FROM gone',
			'DROP TABLE gone',
			'CREATE VIEW empty_view AS SELECT id FROM permits WHERE 1 = 0',
			"CREATE VIEW stats_view AS SELECT 3 AS total, 2.5 AS ratio, 'x' AS label",
		] as $statement) {
			$connection->executeStatement($statement);
		}

		$connection->close();
	}//end setUpBeforeClass()

	/**
	 * Build the blueprint for each test, so its coverage is collected.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		self::$logged = [];

		$logger = new class extends AbstractLogger {
			public function log($level, string|Stringable $message, array $context = []): void {
				DatabaseIntrospectionShapesTest::record(level: (string)$level, message: (string)$message);
			}
		};

		$source = new Source();
		$source->setId(42);
		$source->setTitle('Shapes');
		$source->setType('database');
		$source->setAuthConfig(['driver' => 'pdo_sqlite', 'path' => self::$dbPath]);

		self::$blueprint = self::service(logger: $logger)->buildBlueprint(source: $source);
	}//end setUp()

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
	 * Record one log message.
	 *
	 * @param string $level   The level.
	 * @param string $message The message.
	 *
	 * @return void
	 */
	public static function record(string $level, string $message): void {
		self::$logged[] = [$level, $message];
	}//end record()

	/**
	 * The service over a real connection factory and type mapper.
	 *
	 * @param \Psr\Log\LoggerInterface $logger The service's logger.
	 *
	 * @return DatabaseIntrospectionService
	 */
	private static function service(\Psr\Log\LoggerInterface $logger): DatabaseIntrospectionService {
		$store = new class implements CredentialStore {
			public function put(string $uuid, string $secret, string $scope = 'personal'): void {
			}//end put()

			public function get(string $uuid, string $scope = 'personal'): ?string {
				return null;
			}//end get()

			public function delete(string $uuid, string $scope = 'personal'): void {
			}//end delete()
		};

		return new DatabaseIntrospectionService(
			tools: new IntrospectionTools(
				connectionFactory: new DbalConnectionFactory(credentialStore: $store, logger: new NullLogger()),
				typeMapper: new SqlTypeMapper(logger: new NullLogger()),
				diffService: new SchemaDiffService()
			),
			registerMapper: (new ReflectionClass(RegisterMapper::class))->newInstanceWithoutConstructor(),
			schemaMapper: (new ReflectionClass(SchemaMapper::class))->newInstanceWithoutConstructor(),
			logger: $logger
		);
	}//end service()

	/**
	 * The blueprint schema with a title.
	 *
	 * @param string $title The table or view name.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(string $title): array {
		foreach (self::$blueprint['schemas'] as $schema) {
			if ($schema['title'] === $title) {
				return $schema;
			}
		}

		$this->fail('No schema "' . $title . '" in the blueprint.');
	}//end schema()

	/**
	 * The object-source config of a schema.
	 *
	 * @param string $title The table or view name.
	 *
	 * @return array<string, mixed>
	 */
	private function config(string $title): array {
		return $this->schema(title: $title)['configuration']['x-openregister-object-source']['config'];
	}//end config()

	/**
	 * A source without a uuid is named by its numeric id.
	 *
	 * @return void
	 */
	public function testASourceWithoutAUuidIsNamedByItsId(): void {
		$this->assertSame('42', self::$blueprint['register']['source']);
		$this->assertSame('db-42', self::$blueprint['register']['slug']);
		$this->assertSame('42', $this->config(title: 'permits')['sourceId']);
	}//end testASourceWithoutAUuidIsNamedByItsId()

	/**
	 * A composite primary key is carried as idColumns, with no single idColumn.
	 *
	 * @return void
	 */
	public function testACompositePrimaryKeyIsCarriedAsIdColumns(): void {
		$config = $this->config(title: 'regions');

		$this->assertNull($config['idColumn']);
		$this->assertSame(['code', 'year'], $config['idColumns']);
		$this->assertSame('External primary key (presented as a string).', $this->schema(title: 'regions')['properties']['year']['description']);
	}//end testACompositePrimaryKeyIsCarriedAsIdColumns()

	/**
	 * A multi-column foreign key is skipped (and said so), leaving plain columns.
	 *
	 * @return void
	 */
	public function testAMultiColumnForeignKeyIsSkipped(): void {
		$properties = $this->schema(title: 'permits')['properties'];

		$this->assertSame(['type' => 'string', 'maxLength' => 8], $properties['region_code']);
		$this->assertSame(['type' => 'integer'], $properties['region_year']);
		$this->assertContains(
			['info', '[DbalIntrospection] skipping multi-column foreign key on "permits" (v1 maps single-column FKs only)'],
			self::$logged
		);
	}//end testAMultiColumnForeignKeyIsSkipped()

	/**
	 * A column the type mapper marks unfilterable is listed in nonFilterable, not on the property.
	 *
	 * @return void
	 */
	public function testANonFilterableColumnIsListedOnTheConfig(): void {
		$this->assertSame(['scan'], $this->config(title: 'permits')['nonFilterable']);
		$this->assertArrayNotHasKey('x-filterable', $this->schema(title: 'permits')['properties']['scan']);
		$this->assertArrayNotHasKey('nonFilterable', $this->config(title: 'regions'));
	}//end testANonFilterableColumnIsListedOnTheConfig()

	/**
	 * A view's property types are sampled from its first row.
	 *
	 * @return void
	 */
	public function testAViewIsTypedFromASampledRow(): void {
		$this->assertSame(
			['total' => ['type' => 'integer'], 'ratio' => ['type' => 'number'], 'label' => ['type' => 'string']],
			$this->schema(title: 'stats_view')['properties']
		);
		$this->assertTrue($this->config(title: 'stats_view')['isView']);
		$this->assertNull($this->config(title: 'stats_view')['idColumn']);
	}//end testAViewIsTypedFromASampledRow()

	/**
	 * An empty view has no row to sample, so no properties.
	 *
	 * @return void
	 */
	public function testAnEmptyViewHasNoProperties(): void {
		$this->assertSame([], $this->schema(title: 'empty_view')['properties']);
		$this->assertTrue($this->config(title: 'empty_view')['isView']);
	}//end testAnEmptyViewHasNoProperties()

	/**
	 * A view that cannot be sampled is still listed, without properties, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAViewThatCannotBeSampledIsListedWithoutProperties(): void {
		$this->assertSame([], $this->schema(title: 'broken_view')['properties']);

		$warnings = array_filter(
			self::$logged,
			static fn (array $entry): bool => $entry[0] === 'warning'
				&& str_starts_with($entry[1], '[DbalIntrospection] could not sample view "broken_view":') === true
		);
		$this->assertCount(1, $warnings);
	}//end testAViewThatCannotBeSampledIsListedWithoutProperties()
}//end class
