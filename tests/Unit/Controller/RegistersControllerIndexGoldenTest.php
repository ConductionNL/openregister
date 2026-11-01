<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCA\OpenRegister\Service\UploadService;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for RegistersController::index().
 *
 * Every case records each collaborator call (the register search, the bulk
 * schema read, the per-register counts, the audit statistics) and the
 * response, and compares the recording with
 * fixtures/registers-index-golden.json. Anonymous callers only see registers
 * whose authorization grants read to `public`; a caller-supplied userId does
 * not make a request authenticated. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\RegistersController
 * @uses \OCA\OpenRegister\Service\Serializer\RegisterSerializer
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class RegistersControllerIndexGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/registers-index-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$stats = ['_extend' => ['schemas', '@self.stats']];
		return [
			'no parameters' => [['params' => []]],
			'limit and offset' => [['params' => ['_limit' => '5', '_offset' => '10']]],
			'page with limit wins over offset' => [['params' => ['_limit' => 4, '_offset' => 1, '_page' => '3']]],
			'page without limit' => [['params' => ['_page' => 2, '_offset' => 7]]],
			'filters' => [['params' => ['filters' => ['title' => 'x']]]],
			'extend as a string' => [['params' => ['_extend' => 'schemas']]],
			'stats without schemas falls back to the mapper' => [['params' => ['_extend' => '@self.stats']]],
			'stats with schemas uses the bulk read' => [['params' => $stats]],
			'anonymous sees only public registers' => [['params' => [], 'anonymous' => true]],
			'anonymous with a forged userId' => [['params' => ['userId' => 'alice', 'requestedBy' => 'alice'], 'anonymous' => true]],
			'anonymous with stats' => [['params' => $stats, 'anonymous' => true]],
		];
	}

	#[DataProvider('cases')]
	public function testIndexMatchesTheRecording(array $case): void {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params']);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $case['params'][$key] ?? $default);

		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if (($case['anonymous'] ?? false) === false) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$userSession->method('getUser')->willReturn($user);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findMultipleOptimized')->willReturnCallback(function (...$args) {
			$this->calls[] = ['schemaMapper.findMultipleOptimized' => $args];
			$found = [];
			foreach ($args[0] as $id) {
				if ($id < 100) {
					$found[$id] = $this->schema($id);
				}
			}

			return $found;
		});
		$schemaMapper->method('find')->willReturnCallback(function ($id) {
			$this->calls[] = ['schemaMapper.find' => $id];
			return $this->schema((int)$id);
		});

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('getStatistics')->willReturnCallback(function (...$args) {
			$this->calls[] = ['objectMapper.getStatistics' => $args];
			return ['total' => 1, 'size' => 2];
		});
		$auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$auditTrailMapper->method('getStatistics')->willReturnCallback(function (...$args) {
			$this->calls[] = ['auditTrailMapper.getStatistics' => $args];
			return ['total' => 3];
		});

		$logger = $this->createMock(LoggerInterface::class);
		$controller = new RegistersController(
			'openregister',
			$request,
			$this->registerService(),
			$objectMapper,
			$this->createMock(UploadService::class),
			$logger,
			$userSession,
			$this->createMock(ConfigurationService::class),
			$auditTrailMapper,
			$this->createMock(ObjectExporterInterface::class),
			$this->createMock(ObjectImporterInterface::class),
			$schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(GitHubClientInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(OasService::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(RegisterCacheHandler::class),
			new RegisterSerializer($schemaMapper, $logger),
			$this->createMock(MigrationPackService::class)
		);

		$response = $controller->index();
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		$this->compare($this->dataName(), $actual);
	}

	private function registerService(): RegisterService {
		$service = $this->createMock(RegisterService::class);
		$service->method('findAll')->willReturnCallback(function (...$args) {
			$this->calls[] = ['registerService.findAll' => $args];
			return [
				$this->register(1, 'Public', ['read' => ['public']], [1, 2]),
				$this->register(2, 'Private', ['read' => ['staff']], [2, 3, 500]),
				$this->register(3, 'Open read string', ['read' => 'public'], []),
				$this->register(4, 'No rules', null, null),
				$this->register(5, 'Public too', ['read' => ['staff', 'public']], [3]),
			];
		});
		$service->method('getSchemaObjectCounts')->willReturnCallback(function (...$args) {
			$this->calls[] = ['registerService.getSchemaObjectCounts' => [$args[0], array_map(fn (Schema $s) => $s->getId(), $args[1])]];
			$counts = [];
			foreach ($args[1] as $schema) {
				$counts[$schema->getId()] = ['total' => 10 * $schema->getId(), 'deleted' => 1, 'invalid' => 2, 'locked' => 0, 'size' => 100];
			}

			return $counts;
		});
		return $service;
	}

	/**
	 * @param array<string, mixed>|null $authorization
	 * @param list<int>|null            $schemas
	 */
	private function register(int $id, string $title, ?array $authorization, ?array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setTitle($title);
		$register->setAuthorization($authorization);
		$register->setSchemas($schemas);
		return $register;
	}

	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setTitle('Schema ' . $id);
		return $schema;
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
