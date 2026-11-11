<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCA\OpenRegister\Service\ObjectSource\DbalWriteException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception as DatabaseException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Golden test for the bulk validate and vectorization routes of ObjectsController.
 *
 * Every case records each service call and log line, without file and line,
 * and the answer, and compares them with fixtures/objects-bulk-routes-golden.json.
 * The routes answer their own failures; an external source's failure leaves them for ObjectSourceErrorMiddleware.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @covers \OCA\OpenRegister\Controller\Support\ValidationWindow
 */
class ObjectsControllerBulkRoutesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-bulk-routes-golden.json';

	/** @var list<mixed> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: mixed}>
	 */
	public static function validateCases(): array {
		$full = ['processed' => 5, 'updated' => 3, 'failed' => 2, 'total' => 9, 'errors' => ['e']];
		return [
			'nothing' => [[], $full],
			'no schema' => [['register' => 1], $full],
			'no register' => [['schema' => 2], $full],
			'all defaults' => [['register' => '1', 'schema' => '2'], $full],
			'window' => [['register' => 1, 'schema' => 2, 'limit' => '10', 'offset' => '20'], $full],
			'zero window' => [['register' => 1, 'schema' => 2, 'limit' => 0, 'offset' => 0], $full],
			'text ids' => [['register' => 'r', 'schema' => 's'], $full],
			'empty result' => [['register' => 1, 'schema' => 2], []],
			'partial result' => [['register' => 1, 'schema' => 2], ['updated' => 1]],
			'database fails' => [['register' => 1, 'schema' => 2, 'limit' => 3], 'database'],
		];
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function vectorizeCases(): array {
		return [
			'defaults' => [[], false],
			'views and size' => [['views' => ['a'], 'batchSize' => '7'], false],
			'zero size' => [['batchSize' => 0], false],
			'fails' => [['views' => ['x']], true],
		];
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function statsCases(): array {
		return [
			'none' => [null, false],
			'array' => [['a'], false],
			'json' => ['["a","b"]', false],
			'bad json' => ['{nope', false],
			'fails' => [null, true],
		];
	}

	public function testAFailedValidationLogsTheDetailAndAnswersWithoutIt(): void {
		$service = $this->service();
		$service->method('validateAndSaveTable')->willThrowException(new \RuntimeException('table oc_secret is gone'));
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key) => ['register' => 1, 'schema' => 2][$key] ?? null);

		$response = $this->controller(request: $request, service: $service)->validate();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Internal server error', 'message' => 'Validation failed'], $response->getData());
		$this->assertStringContainsString('table oc_secret is gone', (string)json_encode($this->calls));
	}

	#[DataProvider('validateCases')]
	public function testValidateMatchesTheRecording(array $params, mixed $result): void {
		$service = $this->service();
		$service->method('validateAndSaveTable')->willReturnCallback(function (RegisterSchemaRef $table, ListQuery $query) use ($result): array {
			$this->calls[] = ['validateAndSaveObjectsBySchema' => [$table->register, $table->schema, $query->limitOrNull(), ($query->offset ?? 0)]];
			if ($result === 'database') {
				throw new DatabaseException('no way');
			}

			return $result;
		});
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(function (string $key) use ($params) {
			$this->calls[] = ['getParam' => $key];
			return $params[$key] ?? null;
		});
		$this->compareResponse(key: 'validate ' . $this->dataName(), response: $this->controller(request: $request, service: $service)->validate());
	}

	#[DataProvider('vectorizeCases')]
	public function testVectorizeBatchMatchesTheRecording(array $params, bool $fails): void {
		$service = $this->service();
		$service->method('vectorizeBatchObjects')->willReturnCallback(function (...$args) use ($fails): array {
			$this->calls[] = ['vectorizeBatchObjects' => $args];
			if ($fails === true) {
				throw new DatabaseException('no way');
			}

			return ['done' => 1];
		});
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$this->compareResponse(key: 'vectorize ' . $this->dataName(), response: $this->controller(request: $request, service: $service)->vectorizeBatch());
	}

	#[DataProvider('statsCases')]
	public function testVectorizationStatsMatchTheRecording(mixed $views, bool $fails): void {
		$service = $this->service();
		$service->method('getVectorizationStatistics')->willReturnCallback(function (...$args) use ($fails): array {
			$this->calls[] = ['getVectorizationStatistics' => $args];
			if ($fails === true) {
				throw new DatabaseException('no way');
			}

			return ['count' => 2];
		});
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn($views);
		$this->compareResponse(key: 'stats ' . $this->dataName(), response: $this->controller(request: $request, service: $service)->getObjectVectorizationStats());
	}

	/**
	 * @return array<string, array{0: \Throwable}>
	 */
	public static function nonDatabaseFailures(): array {
		return [
			'exception' => [new RuntimeException('secret detail')],
			'error' => [new \TypeError('secret detail')],
		];
	}

	#[DataProvider('nonDatabaseFailures')]
	public function testVectorizeBatchAnswersAnyFailure(\Throwable $failure): void {
		$service = $this->service();
		$service->method('vectorizeBatchObjects')->willThrowException($failure);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$this->assertGenericFailure($this->controller(request: $request, service: $service)->vectorizeBatch());
	}

	#[DataProvider('nonDatabaseFailures')]
	public function testVectorizationStatsAnswerAnyFailure(\Throwable $failure): void {
		$service = $this->service();
		$service->method('getVectorizationStatistics')->willThrowException($failure);

		$this->assertGenericFailure(
			$this->controller(request: $this->createMock(IRequest::class), service: $service)->getObjectVectorizationStats()
		);
	}

	public function testValidateAnswersAnotherException(): void {
		$service = $this->service();
		$service->method('validateAndSaveTable')->willThrowException(new RuntimeException('validate'));

		$response = $this->controller(request: $this->validationRequest(), service: $service)->validate();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Internal server error', 'message' => 'Validation failed'], $response->getData());
	}

	public function testValidateLeavesAnErrorToTheServer(): void {
		$service = $this->service();
		$service->method('validateAndSaveTable')->willThrowException(new \TypeError('internal'));

		$this->expectException(\TypeError::class);
		$this->controller(request: $this->validationRequest(), service: $service)->validate();
	}

	/**
	 * @return array<string, array{0: \Exception}>
	 */
	public static function externalSourceFailures(): array {
		return [
			'read' => [new DbalObjectSourceException('source down', 503)],
			'write' => [new DbalWriteException('source refused', 409)],
		];
	}

	#[DataProvider('externalSourceFailures')]
	public function testExternalSourceFailuresLeaveTheRoutes(\Exception $failure): void {
		$service = $this->service();
		$service->method('validateAndSaveTable')->willThrowException($failure);
		$service->method('vectorizeBatchObjects')->willThrowException($failure);
		$service->method('getVectorizationStatistics')->willThrowException($failure);
		$request = $this->validationRequest();
		$request->method('getParams')->willReturn([]);
		$controller = $this->controller(request: $request, service: $service);

		foreach (['validate', 'vectorizeBatch', 'getObjectVectorizationStats'] as $route) {
			try {
				$controller->$route();
				$this->fail($route . ' answered');
			} catch (\Exception $caught) {
				$this->assertSame($failure, $caught, $route);
			}
		}
	}

	private function validationRequest(): IRequest&\PHPUnit\Framework\MockObject\MockObject {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'views' ? null : 1);
		return $request;
	}

	private function assertGenericFailure(JSONResponse $response): void {
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Internal server error'], $response->getData());
		$logged = array_column($this->calls, 'error');
		$this->assertSame('[ObjectsController] Vectorization route failed', $logged[0][0] ?? null);
	}

	private function service(): ObjectOperationsInterface&\PHPUnit\Framework\MockObject\MockObject {
		return $this->createMock(ObjectOperationsInterface::class);
	}

	private function controller(IRequest $request, ObjectOperationsInterface $service): ObjectsController {
		$this->calls = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string|\Stringable $message, array $context = []) use ($level): void {
				unset($context['file'], $context['line'], $context['trace']);
				$this->calls[] = [$level => [(string)$message, $context, array_keys($context)]];
			});
		}

		$reflection = new ReflectionClass(ObjectsController::class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('request')->setValue($controller, $request);
		$reflection->getProperty('objectService')->setValue($controller, $service);
		$reflection->getProperty('logger')->setValue($controller, $logger);
		return $controller;
	}

	private function compareResponse(string $key, JSONResponse $response): void {
		$this->compare(key: $key, actual: ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData(), 'order' => array_keys($response->getData())]);
	}

	private function compare(string $key, mixed $actual): void {
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
