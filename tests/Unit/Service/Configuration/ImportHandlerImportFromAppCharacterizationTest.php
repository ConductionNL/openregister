<?php

/**
 * Characterization tests for ImportHandler::importFromApp().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
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
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\File\RegisterFolderProvisioner;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what importFromApp() asks the configuration mapper, what Configuration
 * it inserts and updates, what it hands the JSON import, the registers it
 * provisions folders and magic tables for, what it logs, and what it returns
 * or throws. Expected calls live in `fixtures/import-from-app-golden.json`;
 * set `OR_GOLDEN_WRITE=1` to regenerate it.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Configuration\ImportHandler
 */
class ImportHandlerImportFromAppCharacterizationTest extends TestCase {

	/**
	 * Calls recorded for the running case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/import-from-app-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => case.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function corpus(): array {
		$full = [
			'info'           => ['title' => 'Info title', 'description' => 'Info desc'],
			'x-openregister' => [
				'title'        => 'X title',
				'description'  => 'X desc',
				'type'         => 'library',
				'openregister' => '^2.0',
				'sourceType'   => 'github',
				'sourceUrl'    => 'https://example.test/config.json',
				'github'       => ['repo' => 'org/repo', 'branch' => 'main', 'path' => 'config.json'],
			],
		];
		$flat = [
			'title'          => 'Top title',
			'x-openregister' => [
				'title'        => 'X title',
				'description'  => 'X desc',
				'github'       => 'not-an-array',
				'githubRepo'   => 'org/flat',
				'githubBranch' => 'dev',
				'githubPath'   => 'flat.json',
			],
		];

		return [
			'new-minimal'               => ['data' => []],
			'new-top-level-fallback'    => ['data' => ['title' => 'T', 'description' => 'D', 'type' => 'custom']],
			'new-full-nested'           => ['data' => $full, 'sourceUrl' => 'throws', 'byApp' => 'throws', 'result' => 'mixed'],
			'new-flat-github'           => ['data' => $flat, 'result' => 'schemas'],
			'new-nested-no-values'      => ['data' => ['x-openregister' => ['github' => []]], 'result' => 'mixed'],
			'existing-by-source-url'    => ['data' => $full, 'sourceUrl' => 'found', 'result' => 'mixed'],
			'source-url-null-then-app'  => ['data' => $full, 'sourceUrl' => 'null', 'byApp' => 'found', 'version' => '1.0.0'],
			'existing-by-app-older'     => ['data' => [], 'byApp' => 'found', 'version' => '1.0.0'],
			'existing-by-app-forced'    => ['data' => $flat, 'byApp' => 'found', 'version' => '1.0.0', 'force' => true, 'result' => 'mixed'],
			'existing-by-app-newer'     => ['data' => ['info' => ['title' => 'New']], 'byApp' => 'found', 'version' => '3.0.0', 'result' => 'objects'],
			'existing-update-x-only'    => ['data' => ['x-openregister' => ['title' => 'Only X', 'description' => 'Only X desc']], 'byApp' => 'found', 'result' => 'registers'],
			'existing-no-version'       => ['data' => [], 'byApp' => 'found-no-version', 'version' => '0.0.0'],
			'import-throws'             => ['data' => [], 'importThrows' => true],
			'folders-throw'             => ['data' => [], 'result' => 'registers', 'foldersThrow' => true],
			'magic-register-lookup'     => ['data' => [], 'result' => 'schemas', 'magic' => true],
			'std-class-data'            => ['data' => ['info' => (object)['title' => 'Obj']]],
		];
	}//end corpus()

	/**
	 * Data provider over the corpus names.
	 *
	 * @return array<string, array{string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(self::corpus()) as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * Each case makes exactly the recorded calls.
	 *
	 * @param string $name The corpus entry.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testImportFromAppMatchesGolden(string $name): void {
		$actual = json_decode((string)json_encode($this->runCase(case: self::corpus()[$name])), true);

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
	}//end testImportFromAppMatchesGolden()

	/**
	 * Record one call.
	 *
	 * @param string $what The collaborator and method.
	 * @param mixed  $args The arguments, normalised.
	 *
	 * @return void
	 */
	private function record(string $what, mixed $args): void {
		$this->calls[] = [$what, $this->normalise(value: $args)];
	}//end record()

	/**
	 * Make a value JSON-stable.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		if ($value instanceof Configuration) {
			$out = $value->jsonSerialize();
			unset($out['created'], $out['updated'], $out['uuid'], $out['lastChecked'], $out['lastSyncDate']);
			return $this->normalise(value: $out);
		}

		if ($value instanceof Register || $value instanceof Schema || $value instanceof ObjectEntity) {
			return (new \ReflectionClass($value))->getShortName().':'.$value->getId();
		}

		if (is_array($value) === true) {
			unset($value['line'], $value['file']);
			return array_map(fn ($v) => $this->normalise(value: $v), $value);
		}

		if ($value instanceof \DateTimeInterface) {
			return 'date';
		}

		if (is_object($value) === true) {
			return 'obj:'.get_class($value);
		}

		return $value;
	}//end normalise()

	/**
	 * Build an entity with an id.
	 *
	 * @param string $class The entity class.
	 * @param int    $id    The id.
	 *
	 * @return Register|Schema|ObjectEntity
	 */
	private static function entity(string $class, int $id): Register|Schema|ObjectEntity {
		$entity = new $class();
		$entity->setId($id);
		if ($entity instanceof Schema) {
			$entity->setSlug('s'.$id);
		}

		return $entity;
	}//end entity()

	/**
	 * The canned importFromJson() result for a case.
	 *
	 * @param string $kind The result kind.
	 *
	 * @return array<string, mixed>
	 */
	private static function cannedResult(string $kind): array {
		$result = ['registers' => [], 'schemas' => [], 'objects' => [], 'endpoints' => [], 'sources' => [], 'mappings' => [], 'jobs' => [], 'synchronizations' => [], 'rules' => []];
		if ($kind === 'mixed' || $kind === 'registers') {
			$result['registers'] = [self::entity(Register::class, 1), self::entity(Register::class, 2), self::entity(Register::class, 2)];
		}

		if ($kind === 'mixed' || $kind === 'schemas') {
			$result['schemas'] = [self::entity(Schema::class, 5), self::entity(Schema::class, 6)];
		}

		if ($kind === 'mixed' || $kind === 'objects') {
			$result['objects'] = [self::entity(ObjectEntity::class, 7), 'not-an-object', self::entity(ObjectEntity::class, 7)];
		}

		return $result;
	}//end cannedResult()

	/**
	 * Run one case and return what it did.
	 *
	 * @param array<string, mixed> $case The corpus entry.
	 *
	 * @return array<int, mixed>
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	private function runCase(array $case): array {
		$this->calls = [];

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->record(what: 'logger.'.$level, args: [(string)$message, $context]);
				}
			);
		}

		$existing = new Configuration();
		$existing->setId(40);
		$existing->setTitle('Existing');
		$existing->setApp('myapp');
		if (($case['byApp'] ?? '') !== 'found-no-version') {
			$existing->setVersion('2.0.0');
		}

		$existing->setRegisters([1]);
		$existing->setSchemas([5]);
		$existing->setObjects([]);

		$configMapper = $this->createMock(ConfigurationMapper::class);
		$configMapper->method('findBySourceUrl')->willReturnCallback(
			function (string $url) use ($case, $existing): ?Configuration {
				$this->record(what: 'config.findBySourceUrl', args: [$url]);
				$mode = ($case['sourceUrl'] ?? 'null');
				if ($mode === 'throws') {
					throw new Exception('not found');
				}

				if ($mode === 'found') {
					return $existing;
				}

				return null;
			}
		);
		$configMapper->method('findByApp')->willReturnCallback(
			function (string $app) use ($case, $existing): array {
				$this->record(what: 'config.findByApp', args: [$app]);
				$mode = ($case['byApp'] ?? 'none');
				if ($mode === 'throws') {
					throw new Exception('lookup failed');
				}

				if ($mode === 'found' || $mode === 'found-no-version') {
					return [$existing];
				}

				return [];
			}
		);
		$configMapper->method('insert')->willReturnCallback(
			function (Configuration $configuration): Configuration {
				$this->record(what: 'config.insert', args: [$configuration]);
				$configuration->setId(41);
				return $configuration;
			}
		);
		$configMapper->method('update')->willReturnCallback(
			function (Configuration $configuration): Configuration {
				$this->record(what: 'config.update', args: [$configuration]);
				return $configuration;
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getAllRegisterIdsWithSchema')->willReturnCallback(
			function (int $schemaId): array {
				$this->record(what: 'registers.getAllRegisterIdsWithSchema', args: [$schemaId]);
				if ($schemaId === 6) {
					throw new Exception('no registers');
				}

				return [1];
			}
		);
		$registerMapper->method('find')->willReturnCallback(
			function (int|string $id): Register {
				$this->record(what: 'registers.find', args: [$id]);
				return self::entity(Register::class, (int)$id);
			}
		);

		$handler = new class (
			schemaMapper: $this->createMock(SchemaMapper::class),
			registerMapper: $registerMapper,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $configMapper,
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger,
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class)
		) extends ImportHandler {

			/**
			 * Stands in for the JSON import.
			 *
			 * @var \Closure
			 */
			public \Closure $onImport;

			/**
			 * Record the JSON import and return the canned result.
			 *
			 * @param mixed ...$args The import arguments.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromJson(mixed ...$args): array {
				return ($this->onImport)($args);
			}//end importFromJson()
		};

		$handler->onImport = function (array $args) use ($case): array {
			$this->record(what: 'importFromJson', args: $args);
			if (($case['importThrows'] ?? false) === true) {
				throw new Exception('json import failed');
			}

			return self::cannedResult(kind: ($case['result'] ?? 'none'));
		};

		if (($case['magic'] ?? false) === true) {
			$magic = $this->createMock(MagicMapper::class);
			$magic->method('ensureTableForRegisterSchema')->willReturnCallback(
				function (...$args): bool {
					$this->record(what: 'magic.ensureTableForRegisterSchema', args: $args);
					return true;
				}
			);
			$handler->setMagicMapper($magic);
		}

		$folders = $this->createMock(RegisterFolderProvisioner::class);
		$folders->method('ensureFolders')->willReturnCallback(
			function (iterable $registers) use ($case): array {
				$this->record(what: 'folders.ensureFolders', args: [is_array($registers) ? $registers : iterator_to_array($registers)]);
				if (($case['foldersThrow'] ?? false) === true) {
					throw new RuntimeException('disk full');
				}

				return ['provisioned' => 0, 'present' => 0, 'failed' => 0];
			}
		);
		$handler->setRegisterFolderProvisioner($folders);

		try {
			$result = $handler->importFromApp(
				appId: 'myapp',
				data: $case['data'],
				version: ($case['version'] ?? '2.5.0'),
				force: ($case['force'] ?? false)
			);
			$this->calls[] = ['returned', $this->normalise(value: $result)];
		} catch (\Throwable $e) {
			$this->calls[] = ['threw', get_class($e), $e->getMessage()];
		}

		return $this->calls;
	}//end runCase()
}//end class
