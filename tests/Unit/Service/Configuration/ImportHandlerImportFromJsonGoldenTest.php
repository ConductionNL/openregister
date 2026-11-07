<?php

/**
 * Golden characterization test for ImportHandler::importFromJson().
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
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Runs importFromJson() over a fixed corpus and compares the result summary,
 * every log line, every app-config write and every register and schema write
 * with `fixtures/import-from-json-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses \OCA\OpenRegister\Service\Configuration\ImportReferenceMap
 * @uses \OCA\OpenRegister\Service\Configuration\SchemaImportOptions
 * @uses \OCA\OpenRegister\Service\Configuration\ImportRun
 * @uses \OCA\OpenRegister\Service\Configuration\ImportComponent
 * @uses \OCA\OpenRegister\Service\Configuration\SeedReferenceIndex
 * @uses \OCA\OpenRegister\Service\Configuration\SeedVersionLedger
 * @uses \OCA\OpenRegister\Service\Configuration\ShippedSeedContent
 * @uses \OCA\OpenRegister\Service\Configuration\ListedSeed
 * @uses \OCA\OpenRegister\Db\Configuration
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Mapping
 * @uses \OCA\OpenRegister\Service\Configuration\SeedRelatedItems
 * @uses \OCA\OpenRegister\Service\Configuration\ImportDuplicates
 * @uses \OCA\OpenRegister\Service\Configuration\ShippedSchemaBaseline
 * @uses \OCA\OpenRegister\Service\Configuration\AppConfigurationRecord
 * @uses \OCA\OpenRegister\Service\Configuration\ImportedSchemaShape
 * @uses \OCA\OpenRegister\Service\Configuration\RejectedSchemaReport
 * @uses \OCA\OpenRegister\Service\Configuration\ForeignSeedSchema
 * @uses \OCA\OpenRegister\Service\Configuration\ArrayStructure
 */
class ImportHandlerImportFromJsonGoldenTest extends TestCase {

	/**
	 * Calls recorded during one case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Next schema id to hand out.
	 *
	 * @var integer
	 */
	private int $nextId = 100;

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/import-from-json-golden.json';
	}//end goldenPath()

	/**
	 * The configuration every case imports, with one rejected schema, a register naming
	 * a missing schema, a mapping and one listed object declared in both locations.
	 *
	 * @return array
	 */
	private static function configuration(): array {
		$object = ['@self' => ['uuid' => 'u-1', 'register' => 'reg', 'schema' => 'alpha', 'slug' => 'one'], 'name' => 'One'];
		$second = ['@self' => ['register' => 'reg', 'schema' => 'alpha', 'slug' => 'two'], 'name' => 'Two'];
		return [
			'x-openregister' => ['type' => 'mock'],
			'components' => [
				'schemas' => [
					'alpha' => ['title' => 'Alpha', 'version' => '1.0.0', 'properties' => ['name' => ['type' => 'string']]],
					'broken' => ['slug' => 'broken', 'version' => '1.0.0', 'properties' => [], 'configuration' => ['objectDescriptionField' => 'x']],
					'gamma' => ['slug' => 'gamma', 'title' => 'Gamma', 'version' => '1.0.0', 'properties' => []],
				],
				'registers' => [
					'Reg' => ['slug' => 'reg', 'title' => 'Reg', 'version' => '1.0.0', 'schemas' => ['alpha', 'broken', 'missing']],
					'Typed' => ['slug' => 'typed', 'title' => 'Typed', 'version' => '1.0.0', 'type' => 'own'],
				],
				'mappings' => [
					'map-one' => ['slug' => 'map-one', 'version' => '1.0.0', 'mapping' => []],
				],
				'objects' => [$object, $second, ['@self' => ['register' => 'reg']]],
			],
			'objects' => [$object, 'not-an-object'],
		];
	}//end configuration()

	/**
	 * The corpus: name => [appId, version, force, stored hash kind].
	 *
	 * @return array<string, array{0: ?string, 1: ?string, 2: bool, 3: string}>
	 */
	private static function corpus(): array {
		return [
			'app-version' => ['app', '1.0.0', false, 'none'],
			'app-version-forced' => ['app', '1.0.0', true, 'same'],
			'unchanged' => ['app', '1.0.0', false, 'same'],
			'changed-hash' => ['app', '1.0.0', false, 'other'],
			'from-data' => [null, null, false, 'none'],
			'no-version' => ['app', null, true, 'none'],
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
	 * Each case imports, logs and stores as recorded.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testImportFromJsonMatchesGolden(string $name): void {
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
	}//end testImportFromJsonMatchesGolden()

	/**
	 * Run one case and return what it recorded.
	 *
	 * @param array $case [appId, version, force, stored hash kind].
	 *
	 * @return array
	 */
	private function runCase(array $case): array {
		[$appId, $version, $force, $stored] = $case;
		$this->calls = [];
		$data = self::configuration();
		if ($appId === null) {
			$data['appId'] = 'data-app';
			$data['version'] = '2.0.0';
		}

		$handler = $this->handler(stored: ['kind' => $stored, 'data' => $data]);
		try {
			$result = $handler->importFromJson(
				data: $data,
				configuration: new Configuration(),
				owner: 'owner',
				appId: $appId,
				version: $version,
				force: $force
			);
			$this->calls[] = ['result' => $this->summary(result: $result)];
		} catch (\Throwable $e) {
			$this->calls[] = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return $this->calls;
	}//end runCase()

	/**
	 * The result, with entities reduced to their slugs.
	 *
	 * @param array $result The import result.
	 *
	 * @return array
	 */
	private function summary(array $result): array {
		$out = [];
		foreach ($result as $key => $value) {
			if (is_array($value) === true && in_array($key, ['registers', 'schemas', 'mappings', 'objects'], true) === true) {
				$value = array_map(fn ($entity) => is_object($entity) === true ? get_class($entity).':'.$entity->getSlug() : $entity, $value);
			}

			$out[$key] = $value;
		}

		return $out;
	}//end summary()

	/**
	 * The handler under test, its collaborators recording into $this->calls.
	 *
	 * @param array $stored `kind`: none, same or other stored hash; `data`: the configuration.
	 *
	 * @return ImportHandler
	 */
	private function handler(array $stored): ImportHandler {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$mappingMapper = $this->createMock(MappingMapper::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);
		$objectService = $this->createMock(ObjectService::class);

		$schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$registerMapper->method('getSlugToIdMap')->willReturn([]);
		$mappingMapper->method('getSlugToIdMap')->willReturn([]);
		$registerMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$schemaMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$schemaMapper->method('findBySlugInIds')->willReturn(null);
		$schemaMapper->method('findByApplicationAndSlug')->willReturn(null);
		$schemaMapper->method('createFromArray')->willReturnCallback(fn (array $d): Schema => $this->schema(op: 'schema.create', data: $d));
		$schemaMapper->method('updateFromArray')->willReturnCallback(fn (int $id, array $d): Schema => $this->schema(op: 'schema.update', data: $d + ['id' => $id]));
		$schemaMapper->method('update')->willReturnArgument(0);
		$registerMapper->method('createFromArray')->willReturnCallback(function (array $d): Register {
			$this->calls[] = ['register.create' => $d];
			$register = new Register();
			$register->hydrate(['slug' => $d['slug'], 'version' => '1.0.0']);
			$register->setId(1);
			return $register;
		});
		$registerMapper->method('update')->willReturnArgument(0);
		$mappingMapper->method('createFromArray')->willReturnCallback(function (array $d): Mapping {
			$this->calls[] = ['mapping.create' => $d];
			$mapping = new Mapping();
			$mapping->hydrate(['slug' => $d['slug'] ?? null, 'version' => '1.0.0']);
			$mapping->setId(7);
			return $mapping;
		});
		$objectService->method('saveObject')->willThrowException(new Exception('save refused'));

		$handler = new ImportHandler(
			schemaMapper: $schemaMapper,
			registerMapper: $registerMapper,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $mappingMapper,
			appConfig: $appConfig,
			logger: $logger,
			appDataPath: '/tmp',
			objectService: $objectService
		);

		$hash = (new ReflectionMethod($handler, 'computeDefinitionHash'))->invoke($handler, $stored['data']);
		$hashes = ['none' => '', 'same' => $hash, 'other' => 'other-hash'];
		$appConfig->method('getValueString')->willReturnCallback(function (string $app, string $key) use ($hashes, $stored): string {
			$this->calls[] = ['config.get' => $key];
			if (str_ends_with($key, '_hash') === true) {
				return $hashes[$stored['kind']];
			}

			return '';
		});
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) use ($hash): bool {
			$this->calls[] = ['config.set' => $key, 'value' => ($value === $hash) ? '<hash>' : $value];
			return true;
		});
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level): void {
				unset($context['file'], $context['line'], $context['trace']);
				$this->calls[] = ['log' => $level, 'message' => $message, 'context' => $this->plain(value: $context)];
			});
		}

		return $handler;
	}//end handler()

	/**
	 * A schema the mapper writes, rejecting an `objectDescriptionField` that names no property.
	 *
	 * @param string $op   The recorded operation.
	 * @param array  $data The schema data.
	 *
	 * @return Schema
	 *
	 * @throws Exception When the description field names no property.
	 */
	private function schema(string $op, array $data): Schema {
		$this->calls[] = [$op => $this->plain(value: $data)];
		$field = ($data['configuration']['objectDescriptionField'] ?? '');
		if ($field !== '' && isset($data['properties'][$field]) === false) {
			throw new Exception("The value for objectDescriptionField ('{$field}') does not exist as a property in the schema.");
		}

		$schema = new Schema();
		$schema->hydrate(['slug' => $data['slug'], 'title' => $data['title'] ?? $data['slug'], 'version' => '1.0.0', 'properties' => []]);
		$schema->setId($data['id'] ?? $this->nextId++);
		return $schema;
	}//end schema()

	/**
	 * A value with objects reduced to their class name, so it can be stored as JSON.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function plain(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->plain(value: $item), $value);
		}

		if (is_object($value) === true) {
			return get_class($value);
		}

		return $value;
	}//end plain()
}//end class
