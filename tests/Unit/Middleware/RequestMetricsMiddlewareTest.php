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
	 * The middleware under test, with a clock at 1000.25 seconds.
	 *
	 * @param RequestMetricsRecorder $recorder The recorder.
	 * @param string|null            $route    The `_route` parameter.
	 * @param LoggerInterface|null   $logger   The logger.
	 *
	 * @return RequestMetricsMiddleware
	 */
	private function middleware(RequestMetricsRecorder $recorder, ?string $route, ?LoggerInterface $logger = null): RequestMetricsMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('GET');
		$request->method('getParam')->with('_route')->willReturn($route);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('@1000.25'));

		return new RequestMetricsMiddleware(
			request: $request,
			recorder: $recorder,
			time: $time,
			logger: $logger ?? $this->createMock(LoggerInterface::class)
		);
	}//end middleware()
}//end class

