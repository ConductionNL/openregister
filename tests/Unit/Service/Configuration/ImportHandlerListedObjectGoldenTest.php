<?php

/**
 * Golden characterization test for ImportHandler::importListedObject().
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
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\ShippedSeedContent;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs importListedObject() over a fixed corpus and compares the result
 * counters, the saved objects, the remembered seed versions and hashes and
 * every mapper, object-service and log call with
 * `fixtures/listed-object-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @covers \OCA\OpenRegister\Service\Configuration\ListedSeed
 * @uses               \OCA\OpenRegister\Service\Configuration\SeedVersionLedger
 * @uses               \OCA\OpenRegister\Service\Configuration\ShippedSeedContent
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ImportHandlerListedObjectGoldenTest extends TestCase {

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
		return __DIR__.'/fixtures/listed-object-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => [object, app id, existing kind, stored versions, stored hashes].
	 *
	 * The stored hash `<shipped>` stands for the hash of the shipped object.
	 *
	 * @return array<string, array{0: array, 1: ?string, 2: string, 3: array, 4: array}>
	 */
	private static function corpus(): array {
		$seed = ['title' => 'Zaak', '@self' => ['register' => 'zaken', 'schema' => 'zaak', 'slug' => 'z1', 'version' => '1.2.0']];
		return [
			'register-missing' => [['@self' => ['register' => 'gone', 'schema' => 'zaak', 'slug' => 'z1']], 'app', 'none', [], []],
			'schema-missing' => [['@self' => ['register' => 'zaken', 'schema' => 'gone', 'slug' => 'z1']], 'app', 'none', [], []],
			'no-self' => [['title' => 'x'], 'app', 'none', [], []],
			'foreign-schema' => [['@self' => ['register' => 'listing', 'schema' => 'foreign', 'slug' => 'f']], 'app', 'none', [], []],
			'create' => [$seed, 'app', 'none', [], []],
			'create-no-app' => [$seed, null, 'none', [], []],
			'create-empty-app' => [$seed, '', 'none', [], []],
			'create-top-level-identity' => [['uuid' => ' u-1 ', 'slug' => 'top', 'version' => '3.0.0', 'title' => 'T', '@self' => ['register' => 'zaken', 'schema' => 'zaak', 'slug' => 'z2']], 'app', 'none', [], []],
			'create-self-uuid-wins' => [['uuid' => 'top', '@self' => ['register' => 'zaken', 'schema' => 'declares', 'slug' => 'z3', 'uuid' => 'self-uuid'], 'slug' => 'kept'], 'app', 'none', [], []],
			'update-newer' => [$seed, 'app', 'array', [], []],
			'update-newer-entity' => [$seed, 'app', 'entity', [], []],
			'update-newer-than-shipped' => [$seed, 'app', 'array-newer', ['4/9/z1' => '1.1.0'], []],
			'not-newer-than-shipped' => [$seed, 'app', 'array', ['4/9/z1' => '1.2.0'], ['4/9/z1' => '<shipped>']],
			'unchanged-first-time' => [$seed, 'app', 'array-same', [], []],
			'unchanged-no-app' => [$seed, null, 'array-same', [], []],
			'follow-fill-in' => [$seed + ['colour' => 'red'], 'app', 'array-same', ['4/9/z1' => '1.2.0'], ['4/9/z1' => '<shipped>']],
			'follow-changed-content' => [$seed, 'app', 'array-same', ['4/9/z1' => '1.2.0'], ['4/9/z1' => 'old-hash']],
			'top-level-versions' => [['title' => 'V', 'version' => '2.0.0', '@self' => ['register' => 'zaken', 'schema' => 'zaak', 'slug' => 'z1']], 'app', 'flat-version', [], []],
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
	 * Each case creates, updates, follows or skips as recorded.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testImportListedObjectMatchesGolden(string $name): void {
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
	}//end testImportListedObjectMatchesGolden()

	/**
	 * The stored object a search returns, by kind.
	 *
	 * @param string $kind The existing kind.
	 *
	 * @return mixed
	 */
	private static function existing(string $kind): mixed {
		$data = ['title' => 'Zaak', 'colour' => null, '@self' => ['id' => 'stored-uuid', 'version' => '1.0.0']];
		return match ($kind) {
			'array' => $data,
			'array-newer' => ['title' => 'Zaak', '@self' => ['id' => 'stored-uuid', 'version' => '1.3.0']],
			'array-same' => ['title' => 'Zaak', 'colour' => null, '@self' => ['id' => 'stored-uuid', 'version' => '1.2.0']],
			'flat-version' => ['title' => 'V', 'version' => '2.0.0', 'id' => 'flat-id'],
			'entity' => (static function (): ObjectEntity {
				$object = new ObjectEntity();
				$object->setUuid('entity-uuid');
				$object->setVersion('0.9.0');
				$object->setObject(['title' => 'Old']);
				return $object;
			})(),
			default => null,
		};
	}//end existing()

	/**
	 * Run one case and return what it did.
	 *
	 * @param array{0: array, 1: ?string, 2: string, 3: array, 4: array} $case The corpus entry.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		[$objectData, $appId, $existingKind, $versions, $hashes] = $case;
		foreach ($hashes as $seed => $hash) {
			if ($hash === '<shipped>') {
				$shipped = $objectData;
				$shipped['@self']['register'] = 4;
				$shipped['@self']['schema'] = 9;
				$hashes[$seed] = (new ShippedSeedContent())->hash(shipped: $shipped);
			}
		}

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function (int|string $id): Register {
				$this->calls[] = ['register.find', $id];
				if ($id === 'gone') {
					throw new \RuntimeException('no register');
				}

				$register = new Register();
				$register->setId(4);
				$register->setSlug((string)$id);
				if ($id === 'listing') {
					$register->setSchemas([99]);
				}

				return $register;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (int|string $id): Schema {
				$this->calls[] = ['schema.find', $id];
				if ($id === 'gone') {
					throw new \RuntimeException('no schema');
				}

				$schema = new Schema();
				$schema->setId(9);
				$schema->setSlug((string)$id);
				$schema->setProperties(['title' => ['type' => 'string']]);
				if ($id === 'foreign') {
					$schema->setApplication('other');
				}

				if ($id === 'declares') {
					$schema->setProperties(['slug' => ['type' => 'string']]);
				}

				return $schema;
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use ($existingKind): array {
				$this->calls[] = ['searchObjects', $query];
				$existing = self::existing(kind: $existingKind);
				return $existing === null ? [] : [$existing];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, mixed $register, mixed $schema, ?string $uuid, ...$rest): ObjectEntity {
				$rest = array_map(fn ($value) => $value instanceof IUser ? 'user' : $value, $rest);
				$this->calls[] = ['saveObject', $object, $extend, $register->getId(), $schema->getId(), $uuid, $rest];
				$saved = new ObjectEntity();
				$saved->setUuid((string)($uuid ?? 'new-uuid'));
				return $saved;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key) use ($versions, $hashes): string {
				$this->calls[] = ['appConfig.get', $key];
				return (string)json_encode(str_contains($key, 'hash') === true ? $hashes : $versions);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->calls[] = ['appConfig.set', $key, json_decode($value, true)];
				return true;
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
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			client: $this->createMock(Client::class),
			appConfig: $appConfig,
			logger: $logger,
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $objectService
		);

		$user = $this->createMock(IUser::class);
		$result = ['objects' => [], 'skipped' => ['objects' => 0], 'unchanged' => ['objects' => 0]];
		$closure = (new \ReflectionMethod(ImportHandler::class, 'importListedObject'))->getClosure($handler);
		$closure($objectData, $appId, $user, $result);

		foreach (['seedVersions', 'seedHashes'] as $ledger) {
			(new \ReflectionMethod(ImportHandler::class, $ledger))->invoke($handler)->flush();
		}

		$saved = [];
		foreach ($result['objects'] as $object) {
			$saved[] = $object->getUuid();
		}

		$result['objects'] = $saved;
		return json_decode((string)json_encode(['result' => $result, 'calls' => $this->calls]), true);
	}//end runCase()
}//end class
