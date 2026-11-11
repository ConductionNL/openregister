<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\EndpointsController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Db\EndpointMapper;
use OCA\OpenRegister\Service\EndpointService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\EndpointsControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the answers of every EndpointsController action.
 *
 * Every case records the admin check with the acting user, each mapper and
 * service call, the logger messages and the response, and compares the
 * recording with fixtures/endpoints-answers-golden.json. Only the session user
 * `alice` is an administrator. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\EndpointsController
 * @uses \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses \OCA\OpenRegister\Db\Endpoint
 */
class EndpointsControllerAnswersGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/endpoints-answers-golden.json';

	/** @var list<mixed> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		$actions = ['index', 'show', 'create', 'update', 'patch', 'destroy', 'test', 'logs', 'logStats', 'allLogs'];
		foreach ($actions as $action) {
			$cases[$action . ' succeeds'] = [['action' => $action]];
			$cases[$action . ' not found'] = [['action' => $action, 'fail' => 'missing']];
			$cases[$action . ' fails'] = [['action' => $action, 'fail' => 'boom']];
			$cases[$action . ' by a non-admin'] = [['action' => $action, 'user' => 'bob']];
		}

		$cases['create without a name'] = [['action' => 'create', 'params' => ['endpoint' => '/x']]];
		$cases['test with a failing endpoint'] = [['action' => 'test', 'testResult' => ['success' => false, 'statusCode' => 502, 'response' => 'bad', 'error' => 'upstream']]];
		$cases['create without an endpoint path'] = [['action' => 'create', 'params' => ['name' => 'Ep']]];
		$cases['allLogs with endpoint 0'] = [['action' => 'allLogs', 'params' => ['endpoint_id' => '0']]];
		$cases['allLogs with an empty endpoint'] = [['action' => 'allLogs', 'params' => ['endpoint_id' => '']]];
		$cases['test with a truthy non-boolean success'] = [['action' => 'test', 'testResult' => ['success' => 1, 'statusCode' => 201, 'response' => 'r']]];
		$cases['allLogs for one endpoint'] = [['action' => 'allLogs', 'params' => ['endpoint_id' => '5']]];
		$cases['allLogs for one endpoint fails'] = [['action' => 'allLogs', 'params' => ['endpoint_id' => '5'], 'fail' => 'boom']];
		return $cases;
	}

	#[DataProvider('cases')]
	public function testAnswerMatchesTheRecording(array $case): void {
		$this->calls = [];
		$params = $case['params'] ?? ['name' => 'Ep', 'endpoint' => '/ep', 'id' => 9, '_route' => 'x', 'data' => ['q' => 1]];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $params[$key] ?? $default);

		$fail = $case['fail'] ?? null;
		$endpoint = new Endpoint();
		$endpoint->setId(5);
		$endpoint->setName('Ep');
		$endpoint->setEndpoint('/ep');

		$mapper = $this->createMock(EndpointMapper::class);
		foreach (['find', 'findAll', 'createFromArray', 'updateFromArray', 'delete'] as $method) {
			$mapper->method($method)->willReturnCallback(function (...$args) use ($method, $fail, $endpoint) {
				$this->calls[] = ['endpointMapper.' . $method => $this->plain(args: $args)];
				$this->failOn(fail: $fail);
				return $method === 'findAll' ? [$endpoint] : $endpoint;
			});
		}

		$logs = $this->createMock(EndpointLogMapper::class);
		foreach (['findByEndpoint', 'findPageWithin', 'findAll', 'getStatistics', 'getStatisticsForEndpoint', 'countByEndpoint', 'count', 'countAll'] as $method) {
			if (method_exists(EndpointLogMapper::class, $method) === false) {
				continue;
			}

			$logs->method($method)->willReturnCallback(function (...$args) use ($method, $fail) {
				if ($method === 'findPageWithin') {
					[$scope, $query] = $args;
					$args = [$scope->visibleIds, $scope->endpointId, $query->limit, $query->offset];
				}

				$this->calls[] = ['endpointLogMapper.' . $method => $this->plain(args: $args)];
				$this->failOn(fail: $fail);
				if ($method === 'findPageWithin') {
					return [[], 0];
				}

				return str_starts_with($method, 'count') === true ? 1 : (str_starts_with($method, 'get') === true ? ['total' => 1] : []);
			});
		}

		$service = $this->createMock(EndpointService::class);
		$service->method('testEndpoint')->willReturnCallback(function (...$args) use ($case, $fail) {
			$this->calls[] = ['endpointService.testEndpoint' => $this->plain(args: $args)];
			$this->failOn(fail: $fail);
			return $case['testResult'] ?? ['success' => true, 'statusCode' => 200, 'response' => ['ok' => true]];
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				unset($context['file'], $context['line'], $context['trace']);
				$this->calls[] = ['logger.' . $level => [$message, $this->plain(args: $context)]];
			});
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($case['user'] ?? 'alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(function (string $uid) {
			$this->calls[] = ['groupManager.isAdmin' => $uid];
			return $uid === 'alice';
		});

		$controller = EndpointsControllerFactory::create(
			'openregister',
			$request,
			$mapper,
			$logs,
			$service,
			new ControllerSession($session, $groups, $logger)
		);

		$action = $case['action'];
		$response = in_array($action, ['index', 'create', 'allLogs'], true) === true ? $controller->{$action}() : $controller->{$action}(5);
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $this->plain(args: (array)$response->getData())];
		$this->compare(key: $this->dataName(), actual: $actual);
	}

	private function failOn(?string $fail): void {
		if ($fail === 'missing') {
			throw new DoesNotExistException('none');
		}

		if ($fail === 'boom') {
			throw new Exception('boom');
		}
	}

	/**
	 * @param array<mixed> $args
	 *
	 * @return array<mixed>
	 */
	private function plain(array $args): array {
		return array_map(fn ($arg) => $arg instanceof Endpoint ? ['endpoint' => $arg->getId()] : (is_object($arg) === true ? get_class($arg) : $arg), $args);
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded ??= [];
			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
