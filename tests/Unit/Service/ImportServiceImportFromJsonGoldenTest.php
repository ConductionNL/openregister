<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Translation\TranslationCsvCodec;
use OCP\IGroupManager;
use OCP\IUser;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for ImportService::importFromJson().
 *
 * Every case records each collaborator call (method and arguments) and the
 * outcome, and compares the recording with fixtures/import-from-json-golden.json.
 * Timings and generated UUIDs are masked, and an empty key is keyed `(empty)`. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class ImportServiceImportFromJsonGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/import-from-json-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var list<string> */
	private array $files = [];

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rows = [
			['@self' => ['id' => 'self-1'], 'name' => 'First', 'title' => 'One', 'status' => '', 'extra' => 'x', '_meta' => 1, '@type' => 't', 'id' => 'i', 'uuid' => 'u'],
			['id' => 7, 'title' => 'Two', 'undeclaredA' => 1, 'undeclaredB' => 2],
			['uuid' => 'uuid-3', 'title' => 'Three'],
			['title' => 'Four', '' => 'empty key'],
			'not an object',
			['title' => 'Fail me'],
		];
		$bundle = [
			'components' => [
				'registers' => ['reg' => ['slug' => 'reg', 'title' => 'Reg']],
				'schemas' => ['people' => ['slug' => 'people'], 'other' => ['title' => 'Other', 'slug' => 'other']],
				'objects' => [['title' => 'From bundle']],
			],
		];
		$singleBundle = ['components' => ['registers' => ['reg' => ['slug' => 'reg']], 'schemas' => ['people' => ['slug' => 'people']]]];

		return [
			'plain rows' => [['content' => $rows]],
			'plain rows as user without rbac' => [['content' => $rows, 'rbac' => false, 'multi' => false, 'user' => true]],
			'results envelope' => [['content' => ['results' => [['title' => 'Env']], 'total' => 1]]],
			'results not a list' => [['content' => ['results' => 'none', 'title' => 'Top']]],
			'objects envelope wins' => [['content' => ['components' => ['objects' => [['title' => 'Obj']]], 'results' => [['title' => 'Res']]]]],
			'publish flag warns' => [['content' => [['title' => 'P']], 'publish' => true]],
			'invalid json' => [['raw' => '{nope']],
			'json scalar' => [['raw' => '5']],
			'unreadable file' => [['raw' => null]],
			'no schema' => [['content' => [['title' => 'x']], 'schema' => false]],
			'no register, plain list' => [['content' => [['title' => 'x']], 'register' => false, 'slug' => 'missing-reg']],
			'no register, no slug' => [['content' => [['title' => 'x']], 'register' => false]],
			'no register, empty bundle' => [['content' => ['components' => ['registers' => []]], 'register' => false]],
			'no register, bundle with two schemas and hint' => [['content' => $bundle, 'register' => false]],
			'no register, bundle with two schemas, no hint' => [['content' => $bundle, 'register' => false, 'schema' => false]],
			'no register, bundle with one schema' => [['content' => $singleBundle + ['components' => []], 'register' => false, 'schema' => false]],
			'dry run' => [['content' => $rows, 'dryRun' => true]],
			'dry run with pack' => [['content' => $rows, 'dryRun' => true, 'pack' => true]],
			'pack rows' => [['content' => $rows, 'pack' => true]],
			'pack with skipped and failing rows' => [['content' => $rows, 'pack' => true, 'skip' => [2], 'mapErrors' => [3]]],
			'string keyed rows' => [['content' => ['a' => ['title' => 'A'], 'b' => ['title' => 'B']]]],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testImportFromJsonMatchesGolden(array $case): void {
		$name = $this->dataName();
		$service = $this->buildService(case: $case);

		$path = '/nonexistent/or-import-json-golden.json';
		if (array_key_exists('raw', $case) === false || $case['raw'] !== null) {
			$path = (string)tempnam(sys_get_temp_dir(), 'or-import-json-golden-');
			$this->files[] = $path;
			file_put_contents($path, $case['raw'] ?? json_encode($case['content']));
		}

		$register = null;
		if (($case['register'] ?? true) === true) {
			$register = new Register();
			$register->setId(4);
			$register->setSlug('reg-4');
		}

		$schema = null;
		if (($case['schema'] ?? true) === true) {
			$schema = $this->schema(id: 9, slug: 'things');
		}

		$user = null;
		if (($case['user'] ?? false) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('importer');
		}

		$pack = null;
		if (($case['pack'] ?? false) === true) {
			$pack = ['id' => 'pack-1', 'skipRows' => ($case['skip'] ?? [])];
		}

		try {
			$result = @$service->importFromJson(
				$path,
				$register,
				$schema,
				false,
				false,
				$case['rbac'] ?? true,
				$case['multi'] ?? true,
				$case['publish'] ?? false,
				$user,
				true,
				$pack,
				$case['dryRun'] ?? false,
				$case['slug'] ?? null
			);
			$outcome = ['result' => $this->describe(value: $result)];
		} catch (\Throwable $e) {
			$outcome = ['threw' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
		}

		$actual = $this->keyEmpty(value: json_decode(json_encode(['calls' => $this->calls] + $outcome), true));

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testImportFromJsonMatchesGolden()

	/**
	 * Key every empty key as `(empty)`, so the fixture holds no empty JSON keys.
	 *
	 * @param mixed $value A decoded recording.
	 *
	 * @return mixed
	 */
	private function keyEmpty(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$keyed = [];
		foreach ($value as $key => $item) {
			$keyed[($key === '') ? '(empty)' : $key] = $this->keyEmpty(value: $item);
		}

		return $keyed;
	}//end keyEmpty()

	/**
	 * @param array<string, mixed> $case
	 */
	private function buildService(array $case): ImportService {
		$this->calls = [];

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message) use ($level) {
				$this->record(method: 'logger.' . $level, args: [$message]);
			});
		}

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('getSlugToIdMap')->willReturnCallback(function () {
			$this->record(method: 'schemaMapper.getSlugToIdMap', args: []);
			return ['people' => 21];
		});

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(function (array $object, $extend = null, $register = null, $schema = null, ?string $uuid = null) {
			$this->record(method: 'objectService.saveObject', args: func_get_args());
			if (($object['title'] ?? null) === 'Fail me') {
				throw new Exception('Save refused');
			}

			$saved = new ObjectEntity();
			$saved->setUuid($uuid ?? 'new-' . ($object['title'] ?? 'x'));
			return $saved;
		});

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('setRequestImportJobId')->willReturnCallback(function () {
			$this->record(method: 'audit.setRequestImportJobId', args: func_get_args());
		});

		$mapping = $this->createMock(MappingEngine::class);
		$mapping->method('isRowSkipped')->willReturnCallback(function (array $pack, int $rowNumber) {
			$this->record(method: 'mapping.isRowSkipped', args: [$rowNumber]);
			return in_array($rowNumber, $pack['skipRows'] ?? [], true);
		});
		$mapping->method('mapRow')->willReturnCallback(function (array $pack, array $sourceRow, int $rowNumber) use ($case) {
			$this->record(method: 'mapping.mapRow', args: [$sourceRow, $rowNumber]);
			if (in_array($rowNumber, $case['mapErrors'] ?? [], true) === true) {
				return ['data' => [], 'errors' => [['row' => $rowNumber, 'field' => 'title', 'message' => 'missing', 'code' => 'required']]];
			}

			$data = ['title' => 'mapped-' . ($sourceRow['title'] ?? '?')];
			if ($rowNumber === 1) {
				$data['id'] = 101;
			}

			return ['data' => $data, 'errors' => []];
		});

		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateObject')->willReturnCallback(function (array $object) {
			$this->record(method: 'validator.validateObject', args: [$object]);
			$result = $this->createMock(ValidationResult::class);
			$result->method('isValid')->willReturn(($object['title'] ?? '') !== 'Two');
			return $result;
		});
		$validator->method('generateErrorMessage')->willReturn('title is wrong');

		$importHandler = $this->createMock(ImportHandler::class);
		$importHandler->method('importSchema')->willReturnCallback(function (array $data) {
			$this->record(method: 'importHandler.importSchema', args: func_get_args());
			return $this->schema(id: 30 + strlen((string)($data['slug'] ?? '')), slug: (string)($data['slug'] ?? 'none'));
		});
		$importHandler->method('importRegister')->willReturnCallback(function () {
			$this->record(method: 'importHandler.importRegister', args: func_get_args());
			$register = new Register();
			$register->setId(40);
			$register->setSlug('reg');
			return $register;
		});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => $id === ImportHandler::class ? $importHandler : null);

		return new ImportService(
			$schemaMapper,
			$objectService,
			$logger,
			$this->createMock(IGroupManager::class),
			$this->createMock(TranslationCsvCodec::class),
			$audit,
			$mapping,
			$validator,
			$container
		);
	}//end buildService()

	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle('Schema ' . $slug);
		$schema->setProperties(['title' => ['type' => 'string'], 'status' => ['type' => 'string'], 'name' => ['type' => 'string']]);
		return $schema;
	}//end schema()

	/**
	 * @param list<mixed> $args
	 */
	private function record(string $method, array $args): void {
		$this->calls[] = ['method' => $method, 'args' => array_map(fn ($arg) => $this->describe(value: $arg), $args)];
	}//end record()

	private function describe(mixed $value, ?string $key = null): mixed {
		if (in_array($key, ['totalTime', 'totalTimeMs'], true) === true) {
			return '<time>';
		}

		if ($value instanceof Schema) {
			return ['schema' => $value->getId()];
		}

		if ($value instanceof Register) {
			return ['register' => $value->getId()];
		}

		if ($value instanceof IUser) {
			return ['user' => $value->getUID()];
		}

		if (is_array($value) === true) {
			$out = [];
			foreach ($value as $itemKey => $item) {
				$out[$itemKey] = $this->describe(value: $item, key: (string)$itemKey);
			}

			return $out;
		}

		if (is_string($value) === true) {
			return preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '<uuid>', $value);
		}

		return $value;
	}//end describe()
}//end class
