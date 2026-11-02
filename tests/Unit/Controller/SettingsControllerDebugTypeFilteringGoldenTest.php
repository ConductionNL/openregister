<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for SettingsController::debugTypeFiltering().
 *
 * Every case records each collaborator call (container lookups, the object
 * service's context and searches, the query builder) and the response, and
 * compares the recording with fixtures/settings-debug-type-filtering-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\SettingsController
 */
class SettingsControllerDebugTypeFilteringGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/settings-debug-type-filtering-golden.json';

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
		return [
			'register absent' => [['register' => null]],
			'no results anywhere' => [['register' => 'stackiq']],
			'every search answers its own set' => [[
				'register' => 'voorzieningen',
				'search' => [
					'none' => [[1, 'Alpha', ['type' => 'samenwerking', 'x' => 1]], [2, null, ['name' => 'untyped']]],
					'samenwerking' => [[3, 'Beta', ['type' => 'samenwerking']]],
					'community' => [[4, 'Gamma', []], [5, 'Delta', ['type' => 'community']]],
					'samenwerking,community' => [[6, 'Eps', ['type' => ['a', 'b']]]],
				],
				'rows' => [
					['id' => 7, 'name' => 'Samenwerking A', 'object' => '{"type":"samenwerking"}'],
					['id' => '8', 'name' => 'Community B', 'object' => '{"name":"b"}'],
					['id' => 9, 'name' => 'Broken', 'object' => 'not json'],
				],
			]],
			'the search throws' => [['register' => 'stackiq', 'searchThrows' => 'search exploded']],
			'the database lookup throws' => [['register' => 'stackiq', 'dbThrows' => 'no connection']],
			'the slug resolver throws' => [['resolverThrows' => 'resolver down']],
		];
	}

	#[DataProvider('cases')]
	public function testDebugTypeFilteringMatchesTheRecording(array $case): void {
		$this->calls = [];
		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->objectService($case);
		$resolver = $this->createMock(RegisterSlugResolverInterface::class);
		$resolver->method('slugOrNull')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['resolver.slugOrNull' => $args];
			if (isset($case['resolverThrows']) === true) {
				throw new Exception($case['resolverThrows']);
			}

			return $case['register'];
		});
		$connection = $this->connection($case);

		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $resolver, $connection, $case) {
				$this->calls[] = ['container.get' => $id];
				if ($id === IDBConnection::class && isset($case['dbThrows']) === true) {
					throw new Exception($case['dbThrows']);
				}

				return match ($id) {
					ObjectService::class => $objectService,
					RegisterSlugResolverInterface::class => $resolver,
					IDBConnection::class => $connection,
				};
			}
		);

		$controller = new SettingsController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IDBConnection::class),
			$container,
			$this->createMock(IAppManager::class),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class)
		);

		$response = $controller->debugTypeFiltering();
		$data = $response->getData();
		if (isset($data['trace']) === true) {
			$data['trace'] = is_string($data['trace']) === true ? '(trace string)' : '(not a string)';
		}

		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $data];
		$this->compare($this->name() . ' ' . $this->dataName(), $actual);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function objectService(array $case): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnCallback(function (...$args) use ($objectService) {
			$this->calls[] = ['objectService.setRegister' => $args];
			return $objectService;
		});
		$objectService->method('setSchema')->willReturnCallback(function (...$args) use ($objectService) {
			$this->calls[] = ['objectService.setSchema' => $args];
			return $objectService;
		});
		$objectService->method('searchObjectsPaginated')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['objectService.searchObjectsPaginated' => $args];
			if (isset($case['searchThrows']) === true) {
				throw new Exception($case['searchThrows']);
			}

			$key = 'none';
			if (isset($args[0]['type']) === true) {
				$key = implode(',', $args[0]['type']);
			}

			$results = [];
			foreach ($case['search'][$key] ?? [] as [$id, $name, $object]) {
				$results[] = $this->entity($id, $name, $object);
			}

			return ['results' => $results, 'total' => count($results)];
		});
		return $objectService;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function entity(int $id, ?string $name, array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->addMethods(['getId', 'getName'])
			->onlyMethods(['getObject'])
			->getMock();
		$entity->method('getId')->willReturn($id);
		$entity->method('getName')->willReturn($name);
		$entity->method('getObject')->willReturn($object);
		return $entity;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function connection(array $case): IDBConnection {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('like')->willReturnCallback(function (...$args) {
			$this->calls[] = ['expr.like' => $args];
			return 'like(' . implode(',', $args) . ')';
		});

		$rows = $case['rows'] ?? [];
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(function () use (&$rows) {
			$this->calls[] = ['result.fetch' => []];
			$row = array_shift($rows);
			return $row ?? false;
		});
		$result->method('closeCursor')->willReturnCallback(function () {
			$this->calls[] = ['result.closeCursor' => []];
			return true;
		});

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'where', 'orWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$args) use ($qb, $method) {
				$this->calls[] = ['qb.' . $method => $args];
				return $qb;
			});
		}

		$qb->method('createNamedParameter')->willReturnCallback(function (...$args) {
			$this->calls[] = ['qb.createNamedParameter' => $args];
			return ':p' . count($this->calls);
		});
		$qb->method('executeQuery')->willReturnCallback(function () use ($result) {
			$this->calls[] = ['qb.executeQuery' => []];
			return $result;
		});

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturnCallback(function () use ($qb) {
			$this->calls[] = ['connection.getQueryBuilder' => []];
			return $qb;
		});
		return $connection;
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
