<?php

/**
 * Golden characterization test for the seed-data pass of ImportHandler.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
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

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SeedImport;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Runs importSeedData() over a fixed corpus and compares the result, every
 * log line, every lookup and every inserted entity with
 * `fixtures/seed-data-golden.json`. Set `OR_GOLDEN_WRITE=1` to regenerate it.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses \OCA\OpenRegister\Service\Configuration\SeedImport
 * @uses \OCA\OpenRegister\Service\Configuration\SeedDataObject
 * @uses \OCA\OpenRegister\Service\Configuration\ShippedSeedContent
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Db\Configuration
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Configuration\SeedRelatedItems
 * @uses \OCA\OpenRegister\Service\Configuration\SeedRelatedTarget
 * @uses \OCA\OpenRegister\Service\Configuration\ImportDuplicates
 * @uses \OCA\OpenRegister\Service\Configuration\ShippedSchemaBaseline
 * @uses \OCA\OpenRegister\Service\Configuration\AppConfigurationRecord
 * @uses \OCA\OpenRegister\Service\Configuration\ImportedSchemaShape
 * @uses \OCA\OpenRegister\Service\Configuration\RejectedSchemaReport
 * @uses \OCA\OpenRegister\Service\Configuration\ForeignSeedSchema
 * @uses \OCA\OpenRegister\Service\Configuration\ArrayStructure
 */
class ImportHandlerSeedDataGoldenTest extends TestCase {

	/**
	 * Calls recorded during one case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/seed-data-golden.json';
	}//end goldenPath()

	/**
	 * The seed objects every case imports, by schema slug.
	 *
	 * @return array
	 */
	private static function objects(): array {
		$external = fn (string $register, string $schema) => ['@self' => ['configuration' => 'https://example.com/c.json', 'register' => $register, 'schema' => $schema], 'slug' => "x-$register-$schema", 'title' => 'External'];
		return [
			'main' => [
				['uuid' => 'u-new', 'slug' => 'plain', 'title' => 'Plain', 'name' => 'n'],
				['title' => 'Title only'],
				['name' => 'nameless'],
				['uuid' => 'u-exists', 'slug' => 'exists'],
				['uuid' => 'u-multi', 'slug' => 'multi'],
				['uuid' => 'u-boom', 'slug' => 'boom'],
				['slug' => 'related', '_relatedItems' => ['notes' => [['message' => 'hi']], 'tasks' => [['summary' => 't']]]],
				$external('ext-reg', 'ext-schema'),
				$external('missing-reg', 'missing-schema'),
				$external('boom-reg', 'boom-schema'),
				['@self' => ['register' => 'ext-reg'], 'slug' => 'self-without-configuration'],
			],
			'from-db' => [['slug' => 'db-object']],
			'unknown' => [['slug' => 'lost']],
			'foreign' => [['slug' => 'f1'], ['slug' => 'f2']],
		];
	}//end objects()

	/**
	 * The corpus: name => [register source, user logged in, magic table fails].
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool}>
	 */
	private static function corpus(): array {
		return [
			'fresh-register' => ['fresh', false, false],
			'configuration-register' => ['configuration', true, false],
			'no-register' => ['none', false, false],
			'magic-table-fails' => ['fresh', true, true],
			'linked-register' => ['linked', false, false],
		];
	}//end corpus()

	/**
	 * Case names for the data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::corpus()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * Each case seeds, looks up and logs as recorded.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testSeedDataMatchesGolden(string $name): void {
		$actual = $this->runCase(case: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testSeedDataMatchesGolden()

	/**
	 * Run one case and return what it recorded.
	 *
	 * @param array $case [register source, user logged in, magic table fails].
	 *
	 * @return array
	 */
	private function runCase(array $case): array {
		[$registerSource, $loggedIn, $magicFails] = $case;
		$this->calls = [];
		$handler = $this->handler(magicFails: $magicFails);
		$main = $this->register(id: 10, slug: 'main', schemas: []);
		if ($registerSource === 'linked') {
			$main = $this->register(id: 10, slug: 'main', schemas: [40]);
		}

		$configuration = new Configuration();
		if ($registerSource === 'configuration') {
			$configuration->setRegisters([10]);
		}

		$ref = new ReflectionClass($handler);
		$ref->getProperty('schemasMap')->setValue($handler, ['main' => $this->schema(id: 40, slug: 'main', app: 'app'), 'foreign' => $this->schema(id: 41, slug: 'foreign', app: 'other-app')]);
		if (in_array($registerSource, ['fresh', 'linked'], true) === true) {
			$ref->getProperty('registersMap')->setValue($handler, ['main' => $main]);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn === true ? $this->createMock(IUser::class) : null);
		$handler->setUserSession($session);

		$configData = ['info' => ['title' => 'Seed test'], 'x-openregister' => ['seedData' => ['description' => 'Seeds', 'objects' => self::objects()]]];
		$result = ['objects' => []];
		try {
			$seed = new SeedImport(configData: $configData, result: $result);
			$seed->appId = 'app';
			$ref->getMethod('importSeedData')->invokeArgs($handler, [$seed, $configuration]);
		} catch (\Throwable $e) {
			$this->calls[] = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$this->calls[] = ['result' => $result];
		return $this->withoutGeneratedUuids(value: $this->calls);
	}//end runCase()

	/**
	 * A value with every generated v4 uuid replaced by `<v4>`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function withoutGeneratedUuids(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->withoutGeneratedUuids(value: $item), $value);
		}

		if (is_string($value) === true) {
			return (string)preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/', '<v4>', $value);
		}

		return $value;
	}//end withoutGeneratedUuids()

	/**
	 * The handler under test, its collaborators recording into $this->calls.
	 *
	 * @param bool $magicFails Whether creating the magic table fails.
	 *
	 * @return ImportHandler
	 */
	private function handler(bool $magicFails): ImportHandler {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$registerMapper->method('find')->willReturnCallback(function ($id): Register {
			$this->calls[] = ['register.find' => $id];
			if ($id === 66) {
				throw new Exception('register lookup failed');
			}

			return $this->register(id: (int)$id, slug: 'reg-'.$id, schemas: []);
		});
		$registerMapper->method('getSlugToIdMap')->willReturn(['ext-reg' => 20, 'boom-reg' => 66]);
		$schemaMapper->method('find')->willReturnCallback(function ($id): Schema {
			$this->calls[] = ['schema.find' => $id];
			if ($id === 'from-db') {
				return $this->schema(id: 50, slug: 'from-db', app: null);
			}

			throw new DoesNotExistException('no schema '.$id);
		});
		$schemaMapper->method('findBySlug')->willReturnCallback(function (string $slug): array {
			$this->calls[] = ['schema.findBySlug' => $slug];
			if ($slug === 'boom-schema') {
				throw new Exception('schema lookup failed');
			}

			return $slug === 'ext-schema' ? [$this->schema(id: 30, slug: 'ext-schema', app: null)] : [];
		});

		$handler = new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $registerMapper,
				schemas: $schemaMapper,
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger: $logger,
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);
		$handler->setObjectMapper($this->routingMapper());
		$magic = $this->createMock(MagicMapper::class);
		$magic->method('ensureTableForRegisterSchema')->willReturnCallback(function ($register, $schema) use ($magicFails): bool {
			$this->calls[] = ['magic.ensureTable' => [$register->getId(), $schema->getId()]];
			if ($magicFails === true) {
				throw new Exception('table failed');
			}

			return true;
		});
		$handler->setMagicMapper($magic);

		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level): void {
				unset($context['file'], $context['line'], $context['trace']);
				$this->calls[] = ['log' => $level, 'message' => $message, 'context' => $context];
			});
		}

		return $handler;
	}//end handler()

	/**
	 * The routing mapper: finds `u-exists`, finds two `u-multi`, fails inserting `u-boom`.
	 *
	 * @return MagicMapper
	 */
	private function routingMapper(): MagicMapper {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(function ($identifier, $register, $schema): ObjectEntity {
			$this->calls[] = ['object.find' => [$identifier, $register->getId(), $schema->getId()]];
			if ($identifier === 'u-multi') {
				throw new MultipleObjectsReturnedException('two');
			}

			if ($identifier !== 'u-exists') {
				throw new DoesNotExistException('none');
			}

			$found = new ObjectEntity();
			$found->setId(900);
			return $found;
		});
		$mapper->method('findSlugsByPrefix')->willReturn([]);
		$mapper->method('insert')->willReturnCallback(function (ObjectEntity $entity): ObjectEntity {
			$uuid = (string)$entity->getUuid();
			$this->calls[] = ['object.insert' => [
				'uuid' => str_starts_with($uuid, 'u-') === true ? $uuid : '<v4>',
				'register' => $entity->getRegister(),
				'schema' => $entity->getSchema(),
				'slug' => $entity->getSlug(),
				'object' => $entity->getObject(),
			]];
			if ($uuid === 'u-boom') {
				throw new Exception('insert failed');
			}

			$entity->setId(count($this->calls));
			return $entity;
		});
		return $mapper;
	}//end routingMapper()

	/**
	 * A register.
	 *
	 * @param int   $id      The id.
	 * @param string $slug    The slug.
	 * @param array $schemas The schema ids it lists.
	 *
	 * @return Register
	 */
	private function register(int $id, string $slug, array $schemas): Register {
		$register = new Register();
		$register->hydrate(['slug' => $slug, 'title' => strtoupper($slug), 'schemas' => $schemas, 'application' => 'app']);
		$register->setId($id);
		return $register;
	}//end register()

	/**
	 * A schema.
	 *
	 * @param int         $id   The id.
	 * @param string      $slug The slug.
	 * @param string|null $app  The owning app.
	 *
	 * @return Schema
	 */
	private function schema(int $id, string $slug, ?string $app): Schema {
		$schema = new Schema();
		$schema->hydrate(['slug' => $slug, 'title' => ucfirst($slug), 'properties' => ['name' => ['type' => 'string']], 'application' => $app]);
		$schema->setId($id);
		return $schema;
	}//end schema()
}//end class
