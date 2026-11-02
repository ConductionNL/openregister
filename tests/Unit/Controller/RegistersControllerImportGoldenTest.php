<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Controller;

use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCA\OpenRegister\Service\UploadService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for RegistersController::import().
 *
 * Every case records each collaborator call (method and arguments) and the
 * response, and compares the recording with fixtures/registers-import-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class RegistersControllerImportGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/registers-import-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var list<string> */
	private array $tempFiles = [];

	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			@unlink($path);
		}

		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$excel = ['name' => 'data.xlsx', 'content' => 'xlsx-bytes'];
		$csv = ['name' => 'data.csv', 'content' => "a,b\n1,2\n"];
		$jsonList = ['name' => 'export.json', 'content' => json_encode([['@self' => ['schema' => '7'], 'a' => 1]])];
		$jsonEnvelope = ['name' => 'export.json', 'content' => json_encode(['results' => [['@self' => ['schema' => 'people'], 'a' => 1]]])];
		$jsonBundle = ['name' => 'bundle.json', 'content' => json_encode(['components' => ['registers' => ['r' => ['slug' => 'r']], 'schemas' => []]])];
		$jsonEmptyBundle = ['name' => 'bundle.json', 'content' => json_encode(['components' => ['registers' => []]])];
		$jsonConfig = ['name' => 'config.json', 'content' => json_encode(['openapi' => '3.0.0', 'components' => ['schemas' => []]])];
		$configResult = [
			'registers' => ['r1'],
			'schemas' => [],
			'objects' => [['id' => 42, 'uuid' => 'uuid-42'], ['id' => 43, 'uuid' => 'uuid-43']],
		];

		$cases = [
			'no file' => ['file' => null],
			'member without manage rule' => ['file' => $excel, 'user' => 'member', 'params' => ['type' => 'excel']],
			'member with manage group string' => ['file' => $excel, 'user' => 'member', 'manage' => ['editors'], 'params' => ['type' => 'excel']],
			'member with manage group object' => ['file' => $excel, 'user' => 'member', 'manage' => [['group' => 'editors']], 'params' => ['type' => 'excel']],
			'member with other manage group' => ['file' => $excel, 'user' => 'member', 'manage' => ['others'], 'params' => ['type' => 'excel']],
			'anonymous' => ['file' => $excel, 'user' => 'none', 'params' => ['type' => 'excel']],
			'excel explicit' => ['file' => $excel, 'params' => ['type' => 'excel']],
			'excel string booleans' => [
				'file' => $excel,
				'params' => ['type' => 'excel', 'validation' => 'yes', 'events' => ' TRUE ', 'publish' => '0', 'enrich' => 'off', 'includeObjects' => 1],
			],
			'excel numeric and odd booleans' => [
				'file' => $excel,
				'params' => ['type' => 'excel', 'validation' => 1, 'events' => 0, 'publish' => [], 'enrich' => 0.0],
			],
			'excel as member with manage rule' => ['file' => $excel, 'user' => 'member', 'manage' => ['editors'], 'params' => ['type' => 'excel']],
			'excel with pack' => ['file' => $excel, 'params' => ['type' => 'excel', 'packId' => 'pack-a']],
			'unknown pack' => ['file' => $csv, 'params' => ['type' => 'csv', 'packId' => 'missing', 'schema' => '5']],
			'empty pack id' => ['file' => $csv, 'params' => ['type' => 'csv', 'packId' => '', 'schema' => '5']],
			'auto xlsx' => ['file' => $excel],
			'auto xls upper' => ['file' => ['name' => 'DATA.XLS', 'content' => 'x']],
			'auto csv without schema' => ['file' => $csv],
			'auto csv with schema' => ['file' => $csv, 'params' => ['schema' => '5']],
			'csv with pack and dry run' => ['file' => $csv, 'params' => ['type' => 'csv', 'schema' => '5', 'packId' => 'pack-a', 'dryRun' => 'true']],
			'csv empty schema' => ['file' => $csv, 'params' => ['type' => 'csv', 'schema' => '']],
			'csv unknown schema' => ['file' => $csv, 'params' => ['type' => 'csv', 'schema' => 'missing']],
			'type empty string falls back to detection' => ['file' => $csv, 'params' => ['type' => '', 'schema' => '5']],
			'auto json list' => ['file' => $jsonList],
			'auto json envelope' => ['file' => $jsonEnvelope],
			'auto json bundle' => ['file' => $jsonBundle],
			'auto json bundle missing register admin' => ['file' => $jsonBundle, 'register' => 'missing', 'id' => 'products'],
			'auto json bundle missing register member' => ['file' => $jsonBundle, 'register' => 'missing', 'user' => 'member', 'id' => 'products'],
			'auto json empty bundle' => ['file' => $jsonEmptyBundle, 'params' => ['schema' => '5']],
			'auto json empty bundle no schema' => ['file' => $jsonEmptyBundle],
			'auto json config object' => ['file' => $jsonConfig, 'configResult' => $configResult],
			'auto json unreadable' => ['file' => ['name' => 'gone.json', 'content' => null], 'configResult' => $configResult],
			'auto json invalid' => ['file' => ['name' => 'bad.json', 'content' => '{not json'], 'configResult' => $configResult],
			'auto json list without schema' => ['file' => ['name' => 'x.json', 'content' => json_encode([['a' => 1]])]],
			'json explicit schema' => ['file' => $jsonList, 'params' => ['type' => 'json', 'schema' => '9', 'packId' => 'pack-a', 'dryRun' => true]],
			'json unknown schema' => ['file' => $jsonList, 'params' => ['type' => 'json', 'schema' => 'missing']],
			'json member' => ['file' => $jsonEnvelope, 'user' => 'member', 'manage' => ['editors']],
			'auto unknown extension' => ['file' => ['name' => 'data.xml', 'content' => '<a/>'], 'configResult' => $configResult],
			'auto no name' => ['file' => ['content' => '{}'], 'configResult' => $configResult],
			'missing register excel' => ['file' => $excel, 'register' => 'missing'],
			'missing register csv admin' => ['file' => $csv, 'register' => 'missing', 'params' => ['schema' => '5']],
			'missing register configuration' => ['file' => $jsonConfig, 'register' => 'missing'],
			'configuration with pack' => ['file' => $jsonConfig, 'params' => ['packId' => 'pack-a']],
			'configuration uploaded json error' => ['file' => $jsonConfig, 'uploadedJson' => 'error'],
			'configuration with objects' => [
				'file' => $jsonConfig,
				'force' => true,
				'params' => ['owner' => 'me', 'appId' => 'app', 'version' => '1.2.3'],
				'configResult' => $configResult,
			],
			'configuration merges created schemas' => [
				'file' => $jsonConfig,
				'configResult' => ['registers' => [], 'schemas' => [10, 20, 30], 'objects' => []],
			],
			'configuration merges into register without schemas' => [
				'file' => $jsonConfig,
				'registerSchemas' => null,
				'configResult' => ['registers' => [], 'schemas' => [20]],
			],
			'configuration explicit unknown type' => ['file' => $csv, 'params' => ['type' => 'yaml'], 'configResult' => $configResult],
			'configuration objects not a list' => ['file' => $jsonConfig, 'configResult' => ['registers' => ['r'], 'schemas' => [], 'objects' => 'none']],
			'row errors become a csv' => ['file' => $excel, 'summary' => ['Sheet' => ['errors' => [['row' => 2, 'error' => 'bad']]]]],
			'import service throws' => ['file' => $excel, 'throw' => 'importFromExcel'],
			'configuration service throws' => ['file' => $jsonConfig, 'throw' => 'importFromJsonConfig'],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testImportMatchesGolden(array $case): void {
		$name = $this->dataName();
		$controller = $this->buildController(case: $case);

		$response = $controller->import($case['id'] ?? 1, $case['force'] ?? false);

		$actual = [
			'calls' => $this->calls,
			'status' => $response->getStatus(),
			'data' => $response->getData(),
		];
		$actual = json_decode(json_encode($actual), true);

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
	}//end testImportMatchesGolden()

	/**
	 * @param array<string, mixed> $case
	 */
	private function buildController(array $case): RegistersController {
		$this->calls = [];
		$params = ($case['params'] ?? []);
		$userKind = ($case['user'] ?? 'admin');

		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturnCallback(fn () => $this->uploadedFile(case: $case));
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => array_key_exists($key, $params) ? $params[$key] : $default
		);
		$request->method('getParams')->willReturn(['fromRequest' => true] + $params);

		$user = null;
		if ($userKind !== 'none') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userKind);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid) => $uid === 'admin');
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $u) => $u->getUID() === 'admin' ? ['admin'] : ['editors']
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => $id === IGroupManager::class ? $groupManager : null
		);

		$register = $this->register(id: 1, case: $case);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(function ($id) use ($case, $register) {
			$this->record(method: 'registerMapper.find', args: func_get_args());
			if (($case['register'] ?? 'exists') === 'missing') {
				throw new DoesNotExistException('Register not found');
			}

			return $register;
		});

		$registerService = $this->createMock(RegisterService::class);
		$registerService->method('find')->willReturnCallback(function () use ($register) {
			$this->record(method: 'registerService.find', args: func_get_args());
			return $register;
		});
		$registerService->method('updateFromArray')->willReturnCallback(function () use ($register) {
			$this->record(method: 'registerService.updateFromArray', args: func_get_args());
			return $register;
		});

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(function ($id) {
			$this->record(method: 'schemaMapper.find', args: func_get_args());
			if ($id === 'missing') {
				throw new DoesNotExistException('Schema not found');
			}

			return $this->schema(id: (int)$id);
		});

		$packService = $this->createMock(MigrationPackService::class);
		$packService->method('findByPackSlug')->willReturnCallback(function (string $slug) {
			$this->record(method: 'packService.findByPackSlug', args: func_get_args());
			if ($slug === 'missing') {
				throw new DoesNotExistException('Pack not found');
			}

			$pack = $this->createMock(MigrationPack::class);
			$pack->method('getDefinitionArray')->willReturn(['slug' => $slug, 'mappings' => ['a' => 'b']]);
			return $pack;
		});

		$importService = $this->createMock(ImportService::class);
		foreach (['importFromExcel', 'importFromCsv', 'importFromJson'] as $method) {
			$importService->method($method)->willReturnCallback(function () use ($method, $case) {
				$this->record(method: 'importService.' . $method, args: func_get_args());
				if (($case['throw'] ?? null) === $method) {
					throw new \Exception('Import failed hard');
				}

				return ($case['summary'] ?? ['Sheet' => ['created' => [1], 'errors' => []]]);
			});
		}

		$importService->method('serializeErrorsToCsv')->willReturnCallback(function (array $summary) {
			$this->record(method: 'importService.serializeErrorsToCsv', args: func_get_args());
			foreach ($summary as $sheet) {
				if (empty($sheet['errors']) === false) {
					return "row,error\n2,bad\n";
				}
			}

			return '';
		});

		$configurationService = $this->createMock(ConfigurationService::class);
		$configurationService->method('getUploadedJson')->willReturnCallback(function () use ($case) {
			$this->record(method: 'configurationService.getUploadedJson', args: func_get_args());
			if (($case['uploadedJson'] ?? null) === 'error') {
				return new JSONResponse(data: ['error' => 'Bad upload'], statusCode: 422);
			}

			return ['parsed' => true];
		});
		$configurationService->method('importFromJson')->willReturnCallback(function () use ($case) {
			$this->record(method: 'configurationService.importFromJson', args: func_get_args());
			if (($case['throw'] ?? null) === 'importFromJsonConfig') {
				throw new \Exception('Configuration import failed');
			}

			return $this->configResult(case: $case);
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level) {
				unset($context['file'], $context['line']);
				$this->record(method: 'logger.' . $level, args: [$message, $context]);
			});
		}

		return new RegistersController(
			'openregister',
			$request,
			$registerService,
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$logger,
			$userSession,
			$configurationService,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ExportService::class),
			$importService,
			$schemaMapper,
			$registerMapper,
			$this->createMock(GitHubHandler::class),
			$this->createMock(IAppManager::class),
			$this->createMock(OasService::class),
			$container,
			$groupManager,
			$this->createMock(RegisterCacheHandler::class),
			new RegisterSerializer($schemaMapper, $logger),
			$packService
		);
	}//end buildController()

	/**
	 * @param array<string, mixed> $case
	 *
	 * @return array<string, mixed>|null
	 */
	private function uploadedFile(array $case): ?array {
		$file = $case['file'];
		if ($file === null) {
			return null;
		}

		$path = sys_get_temp_dir() . '/or-import-golden-missing-file';
		if ($file['content'] !== null) {
			$path = (string)tempnam(sys_get_temp_dir(), 'or-import-golden-');
			file_put_contents($path, $file['content']);
			$this->tempFiles[] = $path;
		}

		$upload = ['tmp_name' => $path, 'size' => 10];
		if (array_key_exists('name', $file) === true) {
			$upload['name'] = $file['name'];
		}

		return $upload;
	}//end uploadedFile()

	/**
	 * @param array<string, mixed> $case
	 *
	 * @return array<string, mixed>
	 */
	private function configResult(array $case): array {
		$result = ($case['configResult'] ?? ['registers' => ['r'], 'schemas' => [], 'objects' => []]);
		if (isset($result['schemas']) === true) {
			$result['schemas'] = array_map(fn (int $id) => $this->schema(id: $id), $result['schemas']);
		}

		if (isset($result['objects']) === true && is_array($result['objects']) === true) {
			$result['objects'] = array_map(
				static function (array $row): ObjectEntity {
					$object = new ObjectEntity();
					$object->setId($row['id']);
					$object->setUuid($row['uuid']);
					return $object;
				},
				$result['objects']
			);
		}

		return $result;
	}//end configResult()

	/**
	 * @param array<string, mixed> $case
	 */
	private function register(int $id, array $case): Register {
		$register = new Register();
		$register->setId($id);
		$register->setTitle('Register ' . $id);
		$register->setSlug('register-' . $id);
		$schemas = array_key_exists('registerSchemas', $case) ? $case['registerSchemas'] : [10, 11];
		$ref = new \ReflectionProperty($register, 'schemas');
		$ref->setValue($register, $schemas);
		if (isset($case['manage']) === true) {
			$register->setAuthorization(['manage' => $case['manage']]);
		}

		return $register;
	}//end register()

	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setTitle('Schema ' . $id);
		return $schema;
	}//end schema()

	/**
	 * @param list<mixed> $args
	 */
	private function record(string $method, array $args): void {
		$this->calls[] = ['method' => $method, 'args' => array_map(fn ($arg) => $this->describe(value: $arg), $args)];
	}//end record()

	private function describe(mixed $value): mixed {
		if ($value instanceof Register) {
			return ['register' => $value->getId(), 'schemas' => $value->getSchemas()];
		}

		if ($value instanceof Schema) {
			return ['schema' => $value->getId()];
		}

		if ($value instanceof IUser) {
			return ['user' => $value->getUID()];
		}

		if (is_string($value) === true && str_starts_with($value, sys_get_temp_dir()) === true) {
			return '<tmp file>';
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->describe(value: $item), $value);
		}

		if (is_object($value) === true) {
			return ['object' => get_class($value)];
		}

		return $value;
	}//end describe()
}//end class
