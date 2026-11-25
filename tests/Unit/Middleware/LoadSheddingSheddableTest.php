<?php

/**
 * Under database pressure the middleware refuses #[Sheddable] methods with
 * 503 and `Retry-After: 30` while every other method passes, and it feeds the
 * pressure meter with each request's outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use Doctrine\DBAL\Driver\Exception as DriverError;
use Doctrine\DBAL\Exception\DriverException;
use OCA\OpenRegister\Controller\ObjectExportController;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Middleware\LoadSheddingMiddleware;
use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilder;
use OCA\OpenRegister\Service\Resilience\DatabasePressureMeter;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
use OCA\OpenRegister\Tests\Unit\Service\Resilience\FakeMemcache;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

require_once __DIR__ . '/../Service/Resilience/FakeMemcache.php';

/**
 * @covers \OCA\OpenRegister\Middleware\LoadSheddingMiddleware
 * @uses   \OCA\OpenRegister\Service\Resilience\DatabasePressureMeter
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 * @uses   \OCA\OpenRegister\Service\Resilience\Sheddable
 * @uses   \OCA\OpenRegister\Service\Oas\ProblemDescription
 * @uses   \OCA\OpenRegister\Service\Oas\ProblemDetailsBuilder
 * @uses   \OCA\OpenRegister\Service\Oas\ProblemStatement
 */
class LoadSheddingSheddableTest extends TestCase {

	private bool $enabled = true;

	private DatabasePressureMeter $meter;

	private ObjectsController $controller;

	private ObjectExportController $exporter;

	protected function setUp(): void {
		parent::setUp();
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(1_000_000);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default): int => $default);
		$appConfig->method('getValueBool')->willReturnCallback(fn (): bool => $this->enabled);

		$cache   = new FakeMemcache();
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);

		$this->meter = new DatabasePressureMeter(cacheFactory: $factory, appConfig: $appConfig, clock: $clock, logger: new NullLogger());

		// The real controller class, so its attributes are the real ones; nothing in it runs.
		$this->controller = (new ReflectionClass(ObjectsController::class))->newInstanceWithoutConstructor();
		$this->exporter   = (new ReflectionClass(ObjectExportController::class))->newInstanceWithoutConstructor();
	}

	private function middleware(): LoadSheddingMiddleware {
		return new LoadSheddingMiddleware(problems: new ProblemDetailsBuilder(), logger: new NullLogger(), pressure: $this->meter);
	}

	private function pressure(): void {
		for ($i = 0; $i < 50; $i++) {
			$this->meter->record(seconds: 3.0, databaseError: false);
		}

		$this->assertTrue($this->meter->underPressure());
	}

	public function testAnExportIsRefusedUnderPressureWithRetryAfterThirty(): void {
		$this->pressure();
		$middleware = $this->middleware();

		try {
			$middleware->beforeController($this->exporter, 'export');
			$this->fail('A sheddable method must be refused under pressure.');
		} catch (DependencyUnavailableException $refused) {
			$response = $middleware->afterException($this->exporter, 'export', $refused);
		}

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(503, $response->getStatus());
		$this->assertSame('30', $response->getHeaders()['Retry-After']);
		$this->assertSame('database', $response->getData()['dependency']);
		$this->assertSame(50, $this->meter->state()['requests'], 'A refused request is not counted as a request the database served.');
	}

	public function testASingleObjectReadPassesUnderPressure(): void {
		$this->pressure();
		$middleware = $this->middleware();

		$middleware->beforeController($this->controller, 'show');
		$response = $middleware->afterController($this->controller, 'show', new JSONResponse(['ok' => true]));

		$this->assertSame(['ok' => true], $response->getData());
		$this->assertSame(51, $this->meter->state()['requests']);
	}

	public function testAnExportPassesWithoutPressure(): void {
		$middleware = $this->middleware();
		$middleware->beforeController($this->exporter, 'export');

		$this->assertSame(0, $this->meter->state()['requests']);
	}

	public function testAnExportPassesUnderPressureWhenTheAdministratorTurnedSheddingOff(): void {
		$this->pressure();
		$this->enabled = false;

		$this->middleware()->beforeController($this->exporter, 'export');
		$this->addToAssertionCount(1);
	}

	public function testADatabaseErrorIsCountedEvenWhenNextcloudWrappedIt(): void {
		$middleware = $this->middleware();
		$driverError = new DriverException($this->createMock(DriverError::class), null);

		$middleware->beforeController($this->controller, 'show');
		try {
			$middleware->afterException($this->controller, 'show', new RuntimeException('query failed', 0, $driverError));
		} catch (RuntimeException $rethrown) {
			$this->assertSame('query failed', $rethrown->getMessage());
		}

		$state = $this->meter->state();
		$this->assertSame(1, $state['requests']);
		$this->assertSame(1, $state['errors']);
	}

	public function testAnotherErrorIsCountedAsARequestButNotADatabaseError(): void {
		$middleware = $this->middleware();
		$middleware->beforeController($this->controller, 'show');
		try {
			$middleware->afterException($this->controller, 'show', new RuntimeException('not found'));
		} catch (RuntimeException) {
		}

		$state = $this->meter->state();
		$this->assertSame(1, $state['requests']);
		$this->assertSame(0, $state['errors']);
	}
}
