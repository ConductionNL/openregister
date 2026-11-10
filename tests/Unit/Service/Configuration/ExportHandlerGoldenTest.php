<?php

/**
 * Golden characterization test for ExportHandler::exportConfig() and exportSchema().
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

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ExportHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs exportConfig() over a fixed corpus of inputs and compares the
 * exported document and every collaborator call with
 * `fixtures/export-config-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ExportHandler
 * @covers \OCA\OpenRegister\Service\Configuration\ExportSlugMaps
 * @uses \OCA\OpenRegister\Db\Configuration
 * @uses \OCA\OpenRegister\Db\Mapping
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Authorization\RbacGroupCollector
 */
class ExportHandlerGoldenTest extends TestCase {

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
		return __DIR__.'/fixtures/export-config-golden.json';
	}//end goldenPath()

	/**
	 * Case names for the data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = [
			'configuration', 'configuration-with-objects', 'configuration-open-connector', 'register',
			'register-with-objects', 'array-with-extension', 'array-plain', 'array-empty-registers',
			'configuration-null-registers', 'configuration-mappings', 'stdclass-items', 'stdclass-object-configuration',
		];
		$cases = [];
		foreach ($names as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * Each case exports the recorded document and makes the recorded calls.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testExportConfigMatchesGolden(string $name): void {
		$actual = $this->runCase(name: $name);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testExportConfigMatchesGolden()

	/**
	 * Schema properties that exercise every reference rewrite.
	 *
	 * @return array<string, mixed>
	 */
	private static function properties(): array {
		return [
			'plain' => ['type' => 'string'],
			'ref-numeric' => ['type' => 'object', '$ref' => 'https://host/api/schemas/12'],
			'ref-trailing-slash' => ['$ref' => '/schemas/11/'],
			'ref-unknown' => ['$ref' => 'https://host/api/schemas/77'],
			'ref-slug' => ['$ref' => 'person'],
			'ref-and-oc' => ['$ref' => 'https://host/api/schemas/11', 'objectConfiguration' => ['register' => 1, 'schema' => '11']],
			'items-ref' => ['type' => 'array', 'items' => ['$ref' => '/schemas/11']],
			'items-ref-and-register' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/12/', 'register' => 'https://host/api/registers/2']],
			'oc-numeric' => ['objectConfiguration' => ['register' => 1, 'schema' => 12]],
			'oc-strings' => ['objectConfiguration' => ['register' => '2', 'schema' => '11']],
			'oc-unknown' => ['objectConfiguration' => ['register' => 9, 'schema' => 99]],
			'oc-slugs' => ['objectConfiguration' => ['register' => 'reg-one', 'schema' => 'item']],
			'oc-register-only' => ['objectConfiguration' => ['register' => 2]],
			'items-oc' => ['type' => 'array', 'items' => ['objectConfiguration' => ['register' => 1, 'schema' => 11]]],
			'items-oc-schema-only' => ['type' => 'array', 'items' => ['objectConfiguration' => ['schema' => '12']]],
			'legacy-register' => ['register' => 'https://host/api/registers/1'],
			'legacy-register-unknown' => ['register' => 'https://host/api/registers/8'],
			'legacy-register-int' => ['register' => 2],
			'legacy-register-slug' => ['register' => 'reg-two'],
			'items-legacy-register' => ['type' => 'array', 'items' => ['register' => '/registers/2/']],
			'items-legacy-register-int' => ['type' => 'array', 'items' => ['register' => 1]],
			'null-values' => ['$ref' => null, 'register' => null, 'objectConfiguration' => null, 'items' => null],
		];
	}//end properties()

	/**
	 * Build a register.
	 *
	 * @param int    $id   The id.
	 * @param string $slug The slug.
	 *
	 * @return Register
	 */
	private static function register(int $id, string $slug): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		$register->setTitle('Register '.$id);
		$register->setDescription('Description '.$id);
		$register->setVersion('1.'.$id.'.0');
		$register->setOrganisation('org');
		$register->setAuthorization(['read' => ['group-'.$id], 'create' => ['public']]);
		return $register;
	}//end register()

	/**
	 * Build a schema.
	 *
	 * @param int    $id   The id.
	 * @param string $slug The slug.
	 *
	 * @return Schema
	 */
	private static function schema(int $id, string $slug, string $case=''): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle('Schema '.$id);
		if ($id === 12 && str_starts_with($case, 'stdclass-') === true) {
			$nested = new \stdClass();
			$nested->register = 1;
			$nested->{'$ref'} = '/schemas/11';
			$property = ['objectConfiguration' => $nested];
			if ($case === 'stdclass-items') {
				$property = ['items' => $nested];
			}

			$schema->setProperties(['nested' => $property]);
		}

		if ($id === 11) {
			$schema->setProperties(self::properties());
			$schema->setAuthorization(['update' => ['editors']]);
		}

		return $schema;
	}//end schema()

	/**
	 * Build the configuration for a case.
	 *
	 * @param string $name The case name.
	 *
	 * @return Configuration
	 */
	private static function configuration(string $name): Configuration {
		$configuration = new Configuration();
		$configuration->setTitle('Config');
		$configuration->setDescription('Config description');
		$configuration->setVersion('2.0.0');
		$configuration->setType('app');
		$configuration->setApp('myapp');
		$configuration->setSourceType('github');
		$configuration->setSourceUrl('https://example.org/c.json');
		$configuration->setGithubRepo('org/repo');
		$configuration->setGithubBranch('main');
		$configuration->setGithubPath('lib/Settings/c.json');
		$configuration->setRegisters([1, 2]);
		if ($name === 'configuration-null-registers' || $name === 'array-empty-registers') {
			$configuration->setRegisters([]);
		}

		$configuration->setMappings([]);
		if ($name === 'configuration-mappings' || $name === 'array-plain') {
			$configuration->setMappings([42, 404, 43]);
		}

		return $configuration;
	}//end configuration()

	/**
	 * Run one case and return what it did.
	 *
	 * @param string $name The case name.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(string $name): array {
		$this->calls = [];
		$registers = [1 => self::register(1, 'reg-one'), 2 => self::register(2, 'reg-two')];
		$schemas = [1 => [self::schema(11, 'item'), self::schema(12, 'person', $name)], 2 => [self::schema(12, 'person')]];

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function (int|string $id) use ($registers): Register {
				$this->calls[] = ['register.find', $id];
				return $registers[$id];
			}
		);
		$registerMapper->method('getSchemasByRegisterId')->willReturnCallback(
			function (int $id) use ($schemas): array {
				$this->calls[] = ['register.getSchemasByRegisterId', $id];
				return $schemas[$id];
			}
		);
		$registerMapper->method('getIdToSlugMap')->willReturnCallback(
			function (): array {
				$this->calls[] = ['register.getIdToSlugMap'];
				return ['1' => 'reg-one', '2' => 'reg-two'];
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('getIdToSlugMap')->willReturnCallback(
			function (): array {
				$this->calls[] = ['schema.getIdToSlugMap'];
				return ['11' => 'item', '12' => 'person'];
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findAll')->willReturnCallback(
			function (...$args): array {
				$this->calls[] = ['objects.findAll', $args];
				$register = $args[2]['register'];
				$objects = [];
				foreach ([[$register, 11], [$register, 12], [7, 99]] as $index => [$registerId, $schemaId]) {
					$object = $this->createMock(ObjectEntity::class);
					$object->method('jsonSerialize')->willReturn(
						['@self' => ['id' => 'o'.$index, 'register' => (string)$registerId, 'schema' => $schemaId], 'name' => 'n'.$index]
					);
					$objects[] = $object;
				}

				return $objects;
			}
		);

		$configurationMapper = $this->createMock(ConfigurationMapper::class);
		$configurationMapper->method('find')->willReturnCallback(
			function (int|string|null $id) use ($name): Configuration {
				$this->calls[] = ['configuration.find', $id];
				return self::configuration(name: $name);
			}
		);

		$mappingMapper = $this->createMock(MappingMapper::class);
		$mappingMapper->method('find')->willReturnCallback(
			function (int|string $id): Mapping {
				$this->calls[] = ['mapping.find', $id];
				if ($id === 404) {
					throw new \Exception('mapping gone');
				}

				$mapping = new Mapping();
				$mapping->setId((int)$id);
				$mapping->setSlug('mapping-'.$id);
				$mapping->setName('Mapping '.$id);
				$mapping->setOrganisation('org');
				return $mapping;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->calls[] = ['warning', (string)$message, $context];
			}
		);

		$handler = new ExportHandler(
			schemaMapper: $schemaMapper,
			registerMapper: $registerMapper,
			objectEntityMapper: $objectMapper,
			configurationMapper: $configurationMapper,
			mappingMapper: $mappingMapper,
			logger: $logger
		);

		$openConnector = null;
		if ($name === 'configuration-open-connector') {
			$openConnector = new class($this->calls) {
				public function __construct(private array &$calls) {
				}

				public function exportRegister(int $id): array {
					$this->calls[] = ['openConnector.exportRegister', $id];
					return [
						'openapi' => '9.9.9',
						'components' => ['sources' => ['src-'.$id => ['title' => 'Source '.$id]], 'objects' => [5 => ['kept' => $id]], 'mappings' => ['oc-map' => []]],
						'x-openconnector' => ['register' => $id],
					];
				}
			};
		}

		$input = match ($name) {
			'register', 'register-with-objects' => self::register(1, 'reg-one'),
			'array-with-extension' => ['id' => 5, 'title' => 'Arr', 'x-openregister' => ['type' => 'custom', 'app' => 'a']],
			'array-plain' => ['id' => 6, 'title' => 'Arr', 'description' => 'D', 'type' => 'app', 'app' => 'b', 'version' => '4.0.0'],
			'array-empty-registers' => ['id' => 7],
			default => self::configuration(name: $name),
		};
		$includeObjects = in_array($name, ['configuration-with-objects', 'register-with-objects'], true);

		try {
			$out = $handler->exportConfig($input, $includeObjects, $openConnector);
		} catch (\Throwable $e) {
			$out = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return json_decode((string)json_encode(['out' => $out, 'calls' => $this->calls]), true);
	}//end runCase()
}//end class
