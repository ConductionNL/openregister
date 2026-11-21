<?php

/**
 * Request metrics recording around each controller call.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\ConnectionException;
use InvalidArgumentException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Middleware\RequestMetricsMiddleware;
use OCA\OpenRegister\Service\Observability\RequestMetricsRecorder;
use OCA\OpenRegister\Tests\Unit\Middleware\Support\FakeMetricsController;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Middleware\RequestMetricsMiddleware
 */
final class RequestMetricsMiddlewareTest extends TestCase {

	/**
	 * The REQUEST_TIME_FLOAT before the test.
	 *
	 * @var mixed
	 */
	private mixed $startBefore = null;

	protected function setUp(): void {
		parent::setUp();
		$this->startBefore = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
	}

	protected function tearDown(): void {
		$_SERVER['REQUEST_TIME_FLOAT'] = $this->startBefore;
		parent::tearDown();
	}

	/**
	 * The route name without the app prefix, the method, the final status and the elapsed milliseconds are recorded, then a flush is offered.
	 *
	 * @return void
	 */
	public function testRecordsTheAnsweredRequest(): void {
		$_SERVER['REQUEST_TIME_FLOAT'] = 1000.0;
		$recorder = $this->createMock(RequestMetricsRecorder::class);
		$recorder->expects($this->once())->method('record')->with('GET', 'objects.index', 404, 250);
		$recorder->expects($this->once())->method('flushIfDue');

		$response = new JSONResponse([], 404);
		$this->assertSame(
			$response,
			$this->middleware(recorder: $recorder, route: 'openregister.objects.index')->afterController($this->createMock(Controller::class), 'index', $response)
		);
	}//end testRecordsTheAnsweredRequest()

	/**
	 * Without a route name the key is the controller's short name and the method; without a start time no duration.
	 *
	 * @return void
	 */
	public function testFallsBackToTheControllerAndMethod(): void {
		unset($_SERVER['REQUEST_TIME_FLOAT']);
		$recorder = $this->createMock(RequestMetricsRecorder::class);
		$recorder->expects($this->once())->method('record')->with('GET', 'fakeMetrics.show', 200, null);

		$this->middleware(recorder: $recorder, route: null)->afterController(new FakeMetricsController('openregister', $this->createMock(IRequest::class)), 'show', new JSONResponse([]));
	}//end testFallsBackToTheControllerAndMethod()

	/**
	 * A recorder failure never fails the request: the response is returned and the failure logged at debug.
	 *
	 * @return void
	 */
	public function testARecordingFailureIsSwallowed(): void {
		$_SERVER['REQUEST_TIME_FLOAT'] = 1000.0;
		$recorder = $this->createMock(RequestMetricsRecorder::class);
		$recorder->method('record')->willThrowException(new RuntimeException('apcu full'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with($this->stringContains('apcu full'));

		$response = new JSONResponse([]);
		$this->assertSame($response, $this->middleware(recorder: $recorder, route: 'openregister.a.b', logger: $logger)->afterController($this->createMock(Controller::class), 'b', $response));
	}//end testARecordingFailureIsSwallowed()

	/**
	 * An exception is counted by type and rethrown unchanged.
	 *
	 * @return void
	 */
	public function testAfterExceptionCountsAndRethrows(): void {
		$recorder = $this->createMock(RequestMetricsRecorder::class);
		$recorder->expects($this->once())->method('recordError')->with('validation');
		$exception = new ValidationException('bad');

		try {
			$this->middleware(recorder: $recorder, route: null)->afterException($this->createMock(Controller::class), 'x', $exception);
			$this->fail('The exception was not rethrown.');
		} catch (ValidationException $caught) {
			$this->assertSame($exception, $caught);
		}
	}//end testAfterExceptionCountsAndRethrows()

	/**
	 * Database exceptions, also wrapped, are `database`; *ValidationException names are `validation`; the rest `other`.
	 *
	 * @return void
	 */
	public function testErrorTypeOf(): void {
		$this->assertSame('database', RequestMetricsMiddleware::errorTypeOf(exception: new \OCP\DB\Exception('x')));
		$this->assertSame('database', RequestMetricsMiddleware::errorTypeOf(exception: new RuntimeException('w', 0, $this->createMock(ConnectionException::class))));
		$this->assertSame('validation', RequestMetricsMiddleware::errorTypeOf(exception: new ValidationException('v')));
		$this->assertSame('other', RequestMetricsMiddleware::errorTypeOf(exception: new InvalidArgumentException('o')));
	}//end testErrorTypeOf()

	/**
	 * Every answered request writes one INFO entry with the structured request fields and no query values.
	 *
	 * @return void
	 */
	public function testAnAnsweredRequestIsLoggedWithItsStructuredFields(): void {
		$_SERVER['REQUEST_TIME_FLOAT'] = 1000.0;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'[OpenRegister] {method} {path} answered {status_code} in {duration_ms} ms',
			[
				'request_id' => 'req-42',
				'user' => 'alice',
				'method' => 'GET',
				'path' => '/index.php/apps/openregister/api/objects/zaken/meldingen',
				'register' => 'zaken',
				'schema' => 'meldingen',
				'status_code' => 201,
				'duration_ms' => 250,
			]
		);

		$this->middleware(
			recorder: $this->createMock(RequestMetricsRecorder::class),
			route: 'openregister.objects.create',
			logger: $logger,
			params: ['register' => 'zaken', 'schema' => 'meldingen', 'bsn' => '123456789'],
			session: $session
		)->afterController($this->createMock(Controller::class), 'create', new JSONResponse([], 201));
	}//end testAnAnsweredRequestIsLoggedWithItsStructuredFields()

	/**
	 * Without a session the user is null, and a non-scalar register parameter is left out.
	 *
	 * @return void
	 */
	public function testAnAnonymousRequestHasNoUser(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->anything(),
			$this->callback(static fn (array $context): bool => $context['user'] === null && $context['register'] === null && $context['schema'] === null)
		);

		$this->middleware(recorder: $this->createMock(RequestMetricsRecorder::class), route: null, logger: $logger, params: ['register' => ['x']])
			->afterController($this->createMock(Controller::class), 'x', new JSONResponse([]));
	}//end testAnAnonymousRequestHasNoUser()

	/**
	 * A database failure is logged at ERROR with its type, a message without quoted values, a trace without arguments and the action.
	 *
	 * @return void
	 */
	public function testADatabaseFailureIsLoggedWithoutTheValuesItQuotes(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[OpenRegister] {method} {path} failed: {error_type}',
			$this->callback(
				function (array $context): bool {
					$this->assertSame('req-42', $context['request_id']);
					$this->assertSame(\OCP\DB\Exception::class, $context['error_type']);
					$this->assertSame("Duplicate entry '?' for key \"?\"", $context['error_message']);
					$this->assertStringContainsString('->testADatabaseFailureIsLoggedWithoutTheValuesItQuotes()', $context['stack_trace']);
					$this->assertSame(['register' => 'zaken', 'schema' => 'meldingen', 'action' => 'objects.create'], $context['context']);
					$this->assertArrayNotHasKey('exception', $context);
					return true;
				}
			)
		);
		$exception = new \OCP\DB\Exception("Duplicate entry '123456789' for key \"bsn\"");

		try {
			$this->middleware(
				recorder: $this->createMock(RequestMetricsRecorder::class),
				route: 'openregister.objects.create',
				logger: $logger,
				params: ['register' => 'zaken', 'schema' => 'meldingen']
			)->afterException($this->createMock(Controller::class), 'create', $exception);
			$this->fail('The exception was not rethrown.');
		} catch (\OCP\DB\Exception $caught) {
			$this->assertSame($exception, $caught);
		}
	}//end testADatabaseFailureIsLoggedWithoutTheValuesItQuotes()

	/**
	 * A validation failure is counted but not logged as an error.
	 *
	 * @return void
	 */
	public function testAValidationFailureIsNotLoggedAsAnError(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->expectException(ValidationException::class);
		$this->middleware(recorder: $this->createMock(RequestMetricsRecorder::class), route: null, logger: $logger)
			->afterException($this->createMock(Controller::class), 'x', new ValidationException('bad'));
	}//end testAValidationFailureIsNotLoggedAsAnError()

	/**
	 * Quoted literals in either quote style are masked, escaped quotes included.
	 *
	 * @return void
	 */
	public function testWithoutLiterals(): void {
		$this->assertSame("value '?' and \"?\" kept", RequestMetricsMiddleware::withoutLiterals(message: "value 'it\\'s 9' and \"a\" kept"));
		$this->assertSame('no quotes', RequestMetricsMiddleware::withoutLiterals(message: 'no quotes'));
	}//end testWithoutLiterals()

	/**
	 * The middleware under test, with a clock at 1000.25 seconds.
	 *
	 * @param RequestMetricsRecorder $recorder The recorder.
	 * @param string|null            $route    The `_route` parameter.
	 * @param LoggerInterface|null   $logger   The logger.
	 * @param array<string, mixed>   $params   Other request parameters.
	 * @param IUserSession|null      $session  The session.
	 *
	 * @return RequestMetricsMiddleware
	 */
	private function middleware(
		RequestMetricsRecorder $recorder,
		?string $route,
		?LoggerInterface $logger = null,
		array $params = [],
		?IUserSession $session = null,
	): RequestMetricsMiddleware {
		$params = ['_route' => $route, ...$params];
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('GET');
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($params[$key] ?? null));
		$request->method('getId')->willReturn('req-42');
		$request->method('getRequestUri')->willReturn('/index.php/apps/openregister/api/objects/zaken/meldingen?bsn=123456789');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('@1000.25'));

		return new RequestMetricsMiddleware(
			request: $request,
			recorder: $recorder,
			time: $time,
			logger: $logger ?? $this->createMock(LoggerInterface::class),
			session: $session
		);
	}//end middleware()
}//end class

