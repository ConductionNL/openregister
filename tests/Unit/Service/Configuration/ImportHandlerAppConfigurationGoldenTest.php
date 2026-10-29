<?php

/**
 * Golden characterization test for ImportHandler::createOrUpdateConfiguration().
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
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs createOrUpdateConfiguration() over a fixed corpus and compares the
 * stored configuration (its fields and the order they were set in), the
 * thrown message and every mapper and log call with
 * `fixtures/app-configuration-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses \OCA\OpenRegister\Db\Configuration
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ImportHandlerAppConfigurationGoldenTest extends TestCase {

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
		return __DIR__.'/fixtures/app-configuration-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => [data, existing kind, result kind, owner].
	 *
	 * @return array<string, array{0: array, 1: string, 2: string, 3: ?string}>
	 */
	private static function corpus(): array {
		$full = [
			'info' => ['title' => 'Info title', 'description' => 'Info description'],
			'x-openregister' => [
				'title' => 'X title',
				'description' => 'X description',
				'type' => 'app',
				'openregister' => '^1.0',
				'sourceType' => 'github',
				'sourceUrl' => 'https://example.org/c.json',
				'github' => ['repo' => 'org/repo', 'branch' => 'main', 'path' => 'lib/c.json'],
				'githubRepo' => 'ignored/repo',
			],
			'title' => 'Data title',
			'type' => 'data-type',
		];
		return [
			'new-full' => [$full, 'none', 'mixed', 'alice'],
			'new-empty' => [[], 'none', 'empty', null],
			'new-x-only' => [['x-openregister' => ['title' => 'X', 'description' => 'XD', 'githubRepo' => 'o/r', 'githubBranch' => 'dev', 'githubPath' => 'p.json']], 'none', 'empty', null],
			'new-data-only' => [['title' => 'T', 'description' => 'D', 'type' => 't'], 'none', 'mixed', null],
			'new-github-partial' => [['x-openregister' => ['github' => ['branch' => 'b']]], 'none', 'empty', null],
			'new-github-string' => [['x-openregister' => ['github' => 'org/repo', 'githubRepo' => 'flat/repo', 'githubPath' => 'f.json']], 'none', 'empty', null],
			'new-github-null-values' => [['x-openregister' => ['github' => null, 'githubBranch' => 'only']], 'none', 'empty', 'bob'],
			'new-object-data' => [['info' => (object)['title' => 'From object']], 'none', 'empty', null],
			'update' => [$full, 'existing', 'mixed', 'ignored-owner'],
			'update-empty-ids' => [[], 'existing-empty', 'mixed', null],
			'update-nothing-new' => [['info' => ['title' => 'Same']], 'existing', 'empty', null],
			'find-throws' => [['info' => ['title' => 'T']], 'find-throws', 'empty', null],
			'insert-throws' => [['info' => ['title' => 'T']], 'insert-throws', 'empty', null],
			'update-throws' => [['info' => ['title' => 'T']], 'update-throws', 'empty', null],
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
	 * Each case stores, returns or throws as recorded.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testCreateOrUpdateConfigurationMatchesGolden(string $name): void {
		$actual = $this->runCase(case: self::corpus()[$name]);

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
	}//end testCreateOrUpdateConfigurationMatchesGolden()

	/**
	 * A configuration as stored: its fields, and the fields in the order they were set.
	 *
	 * @param Configuration $configuration The configuration.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(Configuration $configuration): array {
		$fields = $configuration->jsonSerialize();
		unset($fields['created'], $fields['updated'], $fields['uuid'], $fields['lastChecked'], $fields['lastSyncDate']);
		return ['fields' => $fields, 'set' => array_keys($configuration->getUpdatedFields())];
	}//end stored()

	/**
	 * The import result for a kind.
	 *
	 * @param string $kind The result kind.
	 *
	 * @return array<string, array>
	 */
	private static function importResult(string $kind): array {
		if ($kind === 'empty') {
			return ['registers' => [], 'schemas' => [], 'objects' => []];
		}

		$entities = [];
		foreach ([[Register::class, [3, 1, 3]], [Schema::class, [5, 6]], [ObjectEntity::class, [9]]] as [$class, $ids]) {
			$list = [];
			foreach ($ids as $id) {
				$entity = new $class();
				$entity->setId($id);
				$list[] = $entity;
			}

			$list[] = 'not-an-entity';
			$entities[] = $list;
		}

		return ['registers' => $entities[0], 'schemas' => $entities[1], 'objects' => $entities[2]];
	}//end importResult()

	/**
	 * Run one case and return what it did.
	 *
	 * @param array{0: array, 1: string, 2: string, 3: ?string} $case The corpus entry.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		[$data, $existingKind, $resultKind, $owner] = $case;

		$configurationMapper = $this->createMock(ConfigurationMapper::class);
		$configurationMapper->method('findByApp')->willReturnCallback(
			function (string $appId, ...$rest) use ($existingKind): array {
				$this->calls[] = ['findByApp', $appId, $rest];
				if ($existingKind === 'find-throws') {
					throw new \Exception('lookup failed');
				}

				if (str_starts_with($existingKind, 'existing') === false && $existingKind !== 'update-throws') {
					return [];
				}

				$existing = new Configuration();
				$existing->setId(40);
				$existing->setTitle('Old');
				$existing->setApp('myapp');
				$existing->setOwner('old-owner');
				if ($existingKind !== 'existing-empty') {
					$existing->setRegisters([1, 2]);
					$existing->setSchemas([5]);
					$existing->setObjects([8]);
				}

				$existing->resetUpdatedFields();
				$second = new Configuration();
				$second->setId(41);
				return [$existing, $second];
			}
		);
		$configurationMapper->method('insert')->willReturnCallback(
			function (Configuration $configuration) use ($existingKind): Configuration {
				$this->calls[] = ['insert', self::stored(configuration: $configuration)];
				if ($existingKind === 'insert-throws') {
					throw new \Exception('insert failed');
				}

				return $configuration;
			}
		);
		$configurationMapper->method('update')->willReturnCallback(
			function (Configuration $configuration) use ($existingKind): Configuration {
				$this->calls[] = ['update', self::stored(configuration: $configuration)];
				if ($existingKind === 'update-throws') {
					throw new \Exception('update failed');
				}

				return $configuration;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->calls[] = ['log.'.$level, (string)$message];
				}
			);
		}

		$handler = new ImportHandler(
			schemaMapper: $this->createMock(SchemaMapper::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $configurationMapper,
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger,
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class)
		);

		try {
			$out = ['returned' => self::stored(
				configuration: $handler->createOrUpdateConfiguration($data, 'myapp', '2.1.0', self::importResult(kind: $resultKind), $owner)
			)];
		} catch (\Throwable $e) {
			$out = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return json_decode((string)json_encode($out + ['calls' => $this->calls]), true);
	}//end runCase()
}//end class
