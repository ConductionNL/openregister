<?php

/**
 * Golden characterization test for ImportHandler::autoCreateRegisterIfApplication().
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
 * Runs autoCreateRegisterIfApplication() over a fixed corpus and compares
 * the result registers, the configuration's register ids and every mapper,
 * magic-table and log call with `fixtures/application-register-golden.json`.
 * Set `OR_GOLDEN_WRITE=1` to regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses \OCA\OpenRegister\Db\Configuration
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ImportHandlerApplicationRegisterGoldenTest extends TestCase {

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
		return __DIR__.'/fixtures/application-register-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => [data, existing register kind, magic mapper, result registers, configuration register ids].
	 *
	 * @return array<string, array{0: array, 1: string, 2: bool, 3: string, 4: array}>
	 */
	private static function corpus(): array {
		$app = ['info' => ['title' => 'Info title', 'description' => 'Info description'], 'x-openregister' => ['type' => 'application', 'app' => 'zaken']];
		return [
			'not-application' => [['x-openregister' => ['type' => 'app']], 'none', true, 'empty', []],
			'no-extension' => [[], 'none', true, 'empty', []],
			'slug-empty' => [['x-openregister' => ['type' => 'application', 'app' => '']], 'none', true, 'empty', []],
			'slug-not-string' => [['x-openregister' => ['type' => 'application', 'app' => 5]], 'none', true, 'empty', []],
			'insert' => [$app, 'none', true, 'empty', [3]],
			'insert-app-id-slug' => [['x-openregister' => ['type' => 'application', 'title' => 'X title']], 'none', false, 'other', []],
			'insert-no-description' => [['x-openregister' => ['type' => 'application', 'app' => 'zaken']], 'none', true, 'empty', [77]],
			'reconcile' => [$app, 'existing', true, 'empty', [3]],
			'reconcile-no-description' => [['x-openregister' => ['type' => 'application', 'app' => 'zaken', 'description' => null]], 'existing', false, 'same', [77]],
			'reconcile-no-schemas' => [['x-openregister' => ['type' => 'application', 'app' => 'zaken', 'description' => 'X']], 'existing-empty', true, 'empty', []],
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
	 * Each case creates or reconciles the register as recorded.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testAutoCreateRegisterMatchesGolden(string $name): void {
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
	}//end testAutoCreateRegisterMatchesGolden()

	/**
	 * A schema with an id and a slug.
	 *
	 * @param int|null    $id   The id.
	 * @param string|null $slug The slug.
	 *
	 * @return Schema
	 */
	private static function schema(?int $id, ?string $slug): Schema {
		$schema = new Schema();
		if ($id !== null) {
			$schema->setId($id);
		}

		$schema->setSlug($slug);
		return $schema;
	}//end schema()

	/**
	 * A register as recorded: id, slug, title, description and schema ids.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<string, mixed>
	 */
	private static function described(Register $register): array {
		return [
			'id' => $register->getId(),
			'slug' => $register->getSlug(),
			'title' => $register->getTitle(),
			'description' => $register->getDescription(),
			'schemas' => $register->getSchemas(),
		];
	}//end described()

	/**
	 * Run one case and return what it did.
	 *
	 * @param array{0: array, 1: string, 2: bool, 3: string, 4: array} $case The corpus entry.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		[$data, $existingKind, $withMagic, $resultKind, $configRegisterIds] = $case;

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturnCallback(
			function (...$args) use ($existingKind): array {
				$this->calls[] = ['register.findAll', $args];
				if ($existingKind === 'none') {
					return [];
				}

				$register = new Register();
				$register->setId(77);
				$register->setSlug('zaken');
				$register->setTitle('Old');
				$register->setDescription('Old description');
				$register->setSchemas($existingKind === 'existing-empty' ? [] : [11, '12', 13, 14, 99]);
				return [$register];
			}
		);
		$registerMapper->method('update')->willReturnCallback(
			function (Register $register): Register {
				$this->calls[] = ['register.update', self::described(register: $register)];
				return $register;
			}
		);
		$registerMapper->method('createFromArray')->willReturnCallback(
			function (array $object): Register {
				$this->calls[] = ['register.createFromArray', $object];
				$register = new Register();
				$register->setId(88);
				$register->setSlug($object['slug']);
				$register->setSchemas($object['schemas']);
				return $register;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (int|string $id): Schema {
				$this->calls[] = ['schema.find', $id];
				return match ((int)$id) {
					11 => self::schema(id: 11, slug: 'Zaak'),
					12 => self::schema(id: 12, slug: 'besluit'),
					13 => self::schema(id: 13, slug: null),
					14 => self::schema(id: 14, slug: 'other'),
					default => throw new \RuntimeException('gone'),
				};
			}
		);

		$configurationMapper = $this->createMock(ConfigurationMapper::class);
		$configurationMapper->method('update')->willReturnCallback(
			function (Configuration $configuration): Configuration {
				$this->calls[] = ['configuration.update', $configuration->getRegisters()];
				return $configuration;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->calls[] = ['log.'.$level, (string)$message, $context];
				}
			);
		}

		$handler = new ImportHandler(
			schemaMapper: $schemaMapper,
			registerMapper: $registerMapper,
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

		if ($withMagic === true) {
			$magicMapper = $this->createMock(MagicMapper::class);
			$magicMapper->method('ensureTableForRegisterSchema')->willReturnCallback(
				function (Register $register, Schema $schema): bool {
					$this->calls[] = ['magic.ensureTable', $register->getId(), $schema->getId()];
					if ($schema->getSlug() === 'broken') {
						throw new \Exception('ddl failed');
					}

					return true;
				}
			);
			$handler->setMagicMapper($magicMapper);
		}

		$schemas = [self::schema(id: 21, slug: 'zaak'), self::schema(id: 12, slug: 'Besluit'), self::schema(id: null, slug: 'unsaved'), 'not-a-schema', self::schema(id: 22, slug: 'broken'), self::schema(id: 23, slug: null)];

		$result = ['registers' => []];
		if ($resultKind === 'other') {
			$other = new Register();
			$other->setId(5);
			$result['registers'] = [$other, 'not-a-register'];
		}

		if ($resultKind === 'same') {
			$same = new Register();
			$same->setId(77);
			$result['registers'] = [$same];
		}

		$configuration = new Configuration();
		$configuration->setRegisters($configRegisterIds);

		$closure = (new \ReflectionMethod(ImportHandler::class, 'autoCreateRegisterIfApplication'))->getClosure($handler);
		$closure($data, 'myapp', $schemas, $configuration, $result);

		$registers = [];
		foreach ($result['registers'] as $register) {
			$registers[] = $register instanceof Register ? self::described(register: $register) : $register;
		}

		return json_decode((string)json_encode(['registers' => $registers, 'configurationRegisters' => $configuration->getRegisters(), 'calls' => $this->calls]), true);
	}//end runCase()
}//end class
