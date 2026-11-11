<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\TablesControllerFactory;
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for TablesController::sync() and syncAll().
 *
 * Every case records each collaborator call (register lookup, the scoped
 * schema lookup, the table sync, the logger without file and line) and the
 * response, and compares the recording with fixtures/tables-sync-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\TablesController
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 * @uses \OCA\OpenRegister\Controller\Support\TablesControllerServices
 * @uses \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 * @uses \OCA\OpenRegister\Exception\SchemaNotInRegisterException
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class TablesControllerSyncGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/tables-sync-golden.json';

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
		$full = [
			'metadataProperties' => 12, 'regularProperties' => 4, 'columnsAdded' => 2, 'columnsAddedList' => ['a', 'b'],
			'columnsDropped' => 1, 'columnsDroppedList' => ['c'], 'columnsDeRequired' => 1, 'columnsDeRequiredList' => ['d'],
			'columnsReRequired' => 1, 'columnsReRequiredList' => ['e'], 'columnsUnchanged' => 9, 'totalProperties' => 16,
		];
		return [
			'sync: numeric ids' => [['action' => 'sync', 'ids' => [3, 7], 'result' => $full]],
			'sync: numeric string ids' => [['action' => 'sync', 'ids' => ['3', '7'], 'result' => $full]],
			'sync: slugs' => [['action' => 'sync', 'ids' => ['reg', 'person'], 'result' => ['columnsAdded' => 1]]],
			'sync: empty result' => [['action' => 'sync', 'ids' => [3, 7], 'result' => []]],
			'sync: register throws' => [['action' => 'sync', 'ids' => [99, 7], 'registerThrows' => true]],
			'sync: schema not in register' => [['action' => 'sync', 'ids' => [3, 'other']]],
			'sync: unknown numeric schema' => [['action' => 'sync', 'ids' => [3, 99]]],
			'sync: sync throws' => [['action' => 'sync', 'ids' => [3, 7], 'syncThrows' => true]],
			'syncAll: mixed' => [['action' => 'syncAll', 'registers' => [[3, [7, ['id' => 8], 'other']], [4, null], [5, []]]]],
			'syncAll: sync throws' => [['action' => 'syncAll', 'registers' => [[3, [7, 8]]], 'syncThrows' => true]],
			'syncAll: findAll throws' => [['action' => 'syncAll', 'findAllThrows' => true]],
			'syncAll: none' => [['action' => 'syncAll', 'registers' => []]],
		];
	}

	#[DataProvider('cases')]
	public function testSyncMatchesTheRecording(array $case): void {
		$this->calls = [];
		$controller = TablesControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->magicMapper($case),
			$this->registerMapper($case),
			$this->schemaMapper(),
			$this->logger(),
			$this->adminSession(),
			$this->adminGroupManager()
		);

		if ($case['action'] === 'sync') {
			$response = $controller->sync(...$case['ids']);
		} else {
			$response = $controller->syncAll();
		}

		$this->compare($this->dataName(), ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()]);
	}

	private function register(int $id, ?array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setTitle('Register ' . $id);
		$register->setSlug('reg');
		$ref = new \ReflectionProperty($register, 'schemas');
		$ref->setValue($register, $schemas);
		return $register;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function registerMapper(array $case): RegisterMapper {
		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('find')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['registerMapper.find' => $args[0]];
			if (($case['registerThrows'] ?? false) === true) {
				throw new Exception('register gone');
			}

			return $this->register(3, [7, 8]);
		});
		$mapper->method('findAll')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['registerMapper.findAll' => []];
			if (($case['findAllThrows'] ?? false) === true) {
				throw new Exception('db down');
			}

			return array_map(fn (array $r) => $this->register($r[0], $r[1]), $case['registers']);
		});
		return $mapper;
	}

	private function schemaMapper(): SchemaMapper {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findInIds')->willReturnCallback(function (...$args) {
			$this->calls[] = ['schemaMapper.findInIds' => [$args[0], $args[1]]];
			if (in_array($args[0], [7, 8, '7', 'person'], true) === false) {
				return null;
			}

			$schema = new Schema();
			$schema->setId(is_numeric($args[0]) === true ? (int)$args[0] : 7);
			$schema->setTitle('Schema ' . $args[0]);
			return $schema;
		});
		$mapper->method('find')->willReturnCallback(function (...$args) {
			$this->calls[] = ['schemaMapper.find' => $args[0]];
			throw new Exception('no schema');
		});
		$mapper->method('countBySlug')->willReturn(0);
		return $mapper;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function magicMapper(array $case): MagicMapper {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('syncTableForRegisterSchema')->willReturnCallback(function (Register $register, Schema $schema) use ($case) {
			$this->calls[] = ['magicMapper.syncTableForRegisterSchema' => [$register->getId(), $schema->getId()]];
			if (($case['syncThrows'] ?? false) === true) {
				throw new Exception('ddl failed');
			}

			return $case['result'] ?? ['columnsAdded' => 0];
		});
		return $mapper;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (...$args) use ($level) {
				unset($args['context']['file'], $args['context']['line'], $args[1]['file'], $args[1]['line']);
				$this->calls[] = ['logger.' . $level => $args];
			});
		}

		return $logger;
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

	/**
	 * A signed-in session; the sync endpoints are admin-only.
	 *
	 * @return \OCP\IUserSession
	 */
	private function adminSession(): \OCP\IUserSession {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(\OCP\IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	/**
	 * A group manager that reports the caller as admin.
	 *
	 * @return \OCP\IGroupManager
	 */
	private function adminGroupManager(): \OCP\IGroupManager {
		$groupManager = $this->createMock(\OCP\IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		return $groupManager;
	}
}
