<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Listener\NotifyPushListener;
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
 * Golden test for ImportService::importFromCsv() and the CSV sheet processing behind it.
 *
 * Each case is a CSV file and scripted collaborators. Every collaborator call,
 * the notify_push frames and the summary (or the exception) are compared with
 * fixtures/import-csv-sheet-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class ImportServiceCsvSheetGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/import-csv-sheet-golden.json';

	private array $calls = [];

	private array $files = [];

	protected function setUp(): void {
		parent::setUp();
		NotifyPushListener::resetStaticState();
	}

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		NotifyPushListener::resetStaticState();
		parent::tearDown();
	}

	public static function cases(): array {
		$csv = "title,status,name,_extra,id\nOne,open,First,x,\nTwo,closed,Second,,7\n,,,,\nThree,,Third,,\n";
		$saved = ['saved' => [['@self' => ['id' => 's-1']], ['uuid' => 's-2']], 'updated' => [['id' => 'u-1']], 'unchanged' => [['@self' => ['id' => 'n-1']]], 'invalid' => []];
		return [
			'plain' => [['csv' => $csv, 'save' => $saved]],
			'nothing saved' => [['csv' => $csv, 'save' => ['saved' => [], 'updated' => [], 'unchanged' => [['id' => 'n']]]]],
			'empty save result' => [['csv' => $csv, 'save' => []]],
			'only updated' => [['csv' => $csv, 'save' => ['updated' => [['id' => 'u']]]]],
			'only created' => [['csv' => $csv, 'save' => ['saved' => [['id' => 'a']]]]],
			'unchanged without others' => [['csv' => $csv, 'save' => ['unchanged' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]]]],
			'validation errors' => [['csv' => $csv, 'validation' => true, 'save' => ['invalid' => [['object' => ['title' => 'Two'], 'error' => 'bad', 'type' => 'X'], ['title' => 'raw']]] + $saved]],
			'invalid ignored without validation' => [['csv' => $csv, 'save' => ['invalid' => [['object' => [], 'error' => 'bad']]]]],
			'save throws' => [['csv' => $csv, 'saveThrows' => true]],
			'publish flag' => [['csv' => $csv, 'publish' => true, 'save' => $saved]],
			'flags off' => [['csv' => $csv, 'rbac' => false, 'multi' => false, 'events' => true, 'enrich' => false, 'user' => true, 'save' => $saved]],
			'multitenancy off only' => [['csv' => $csv, 'multi' => false, 'validation' => true, 'save' => $saved]],
			'no queue' => [['csv' => $csv, 'noQueue' => true, 'save' => $saved]],
			'no headers' => [['csv' => ",,\n1,2,3\n"]],
			'header only' => [['csv' => "title,status\n"]],
			'only empty rows' => [['csv' => "title,status\n,\n,\n", 'save' => $saved]],
			'no schema' => [['csv' => $csv, 'schema' => false]],
			'no register' => [['csv' => $csv, 'register' => false, 'save' => $saved]],
			'dry run' => [['csv' => $csv, 'dryRun' => true]],
			'pack' => [['csv' => $csv, 'pack' => true, 'save' => $saved]],
			'pack with skipped and failing rows' => [['csv' => $csv, 'pack' => true, 'skip' => [2], 'mapErrors' => [4, 5], 'save' => $saved]],
			'pack dry run' => [['csv' => $csv, 'pack' => true, 'dryRun' => true, 'mapErrors' => [3]]],
			'pack all rows fail' => [['csv' => $csv, 'pack' => true, 'mapErrors' => [2, 3, 5]]],
			'slugless target' => [['csv' => $csv, 'slugless' => true, 'save' => $saved]],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testImportFromCsvMatchesGolden(array $case): void {
		$name = $this->dataName();
		$service = $this->buildService(case: $case);

		$path = (string)tempnam(sys_get_temp_dir(), 'or-import-csv-golden-');
		$this->files[] = $path;
		file_put_contents($path, $case['csv']);

		$register = new Register();
		$register->setId(4);
		if (($case['slugless'] ?? false) === false) {
			$register->setSlug('reg-4');
		}

		if (($case['register'] ?? true) === false) {
			$register = null;
		}

		$schema = null;
		if (($case['schema'] ?? true) === true) {
			$schema = $this->schema(id: 9, slug: (($case['slugless'] ?? false) === true) ? '' : 'things');
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
			$result = @$service->importFromCsv(
				$path,
				$register,
				$schema,
				$case['validation'] ?? false,
				$case['events'] ?? false,
				$case['rbac'] ?? true,
				$case['multi'] ?? true,
				$case['publish'] ?? false,
				$user,
				$case['enrich'] ?? true,
				$pack,
				$case['dryRun'] ?? false
			);
			$outcome = ['result' => $this->describe(value: $result)];
		} catch (\Throwable $e) {
			$message = $e->getMessage();
			if ($e instanceof \TypeError) {
				$message = preg_replace('/, called in .*$/', '', $message);
			}

			$outcome = ['threw' => get_class($e), 'message' => $message, 'code' => $e->getCode()];
		}

		$actual = json_decode(json_encode(['calls' => $this->calls] + $outcome), true);

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
	}//end testImportFromCsvMatchesGolden()

	private function buildService(array $case): ImportService {
		$this->calls = [];

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level) {
				unset($context['file'], $context['line']);
				$this->record(method: 'logger.' . $level, args: [$message, $context]);
			});
		}

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObjects')->willReturnCallback(function (array $objects) use ($case) {
			$this->record(method: 'objectService.saveObjects', args: func_get_args());
			if (($case['saveThrows'] ?? false) === true) {
				throw new Exception('Bulk save failed');
			}

			return $case['save'] ?? [];
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

			return ['data' => ['title' => 'mapped-' . ($sourceRow['title'] ?? '?'), 'status' => 'open'], 'errors' => []];
		});

		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateObject')->willReturnCallback(function (array $object) {
			$this->record(method: 'validator.validateObject', args: [$object]);
			$result = $this->createMock(ValidationResult::class);
			$result->method('isValid')->willReturn(($object['title'] ?? '') !== 'Two');
			return $result;
		});
		$validator->method('generateErrorMessage')->willReturn('title is wrong');

		$queue = new class($this) {
			public function __construct(private object $test) {
			}

			public function push(...$args): void {
				$this->test->recordPush($args);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($case, $queue) {
			if (($case['noQueue'] ?? false) === true) {
				throw new Exception('no notify_push');
			}

			return $queue;
		});

		return new ImportService(
			$this->createMock(SchemaMapper::class),
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

	public function recordPush(array $args): void {
		$this->record(method: 'queue.push', args: $args);
	}//end recordPush()

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
		if (in_array($key, ['totalTime', 'totalTimeMs', 'objectsPerSecond'], true) === true) {
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
