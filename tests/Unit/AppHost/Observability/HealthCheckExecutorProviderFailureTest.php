<?php

/**
 * HealthCheckExecutor around the app's own health provider: no provider
 * leaves only the declared checks, a provider that throws is logged and
 * contributes nothing, and a degraded failure never softens an error.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Observability;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\AppHost\IHealthCheckProvider;
use OCA\OpenRegister\AppHost\Observability\AppProviderResolver;
use OCA\OpenRegister\AppHost\Observability\AppStateHealthChecks;
use OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor;
use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\HealthCheckTarget;
use OCA\OpenRegister\AppHost\Observability\HealthResponsePolicy;
use OCA\OpenRegister\AppHost\Observability\HealthResult;
use OCA\OpenRegister\AppHost\Observability\LocalHealthChecks;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCA\OpenRegister\AppHost\Observability\ObservabilityProbes;
use OCA\OpenRegister\AppHost\Observability\StorageHealthChecks;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * HealthCheckExecutor provider failures and severity escalation.
 *
 * @covers \OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor
 * @uses \OCA\OpenRegister\AppHost\Observability\AppProviderResolver
 * @uses \OCA\OpenRegister\AppHost\Observability\AppStateHealthChecks
 * @uses \OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor
 * @uses \OCA\OpenRegister\AppHost\Observability\HealthCheckTarget
 * @uses \OCA\OpenRegister\AppHost\Observability\HealthResponsePolicy
 * @uses \OCA\OpenRegister\AppHost\Observability\HealthResult
 * @uses \OCA\OpenRegister\AppHost\Observability\LocalHealthChecks
 * @uses \OCA\OpenRegister\AppHost\Observability\ObservabilityManifest
 * @uses \OCA\OpenRegister\AppHost\Observability\ObservabilityProbes
 * @uses \OCA\OpenRegister\AppHost\Observability\StorageHealthChecks
 */
class HealthCheckExecutorProviderFailureTest extends TestCase {

	/**
	 * Run the given declared checks against an app whose provider is $provider.
	 *
	 * @param list<HealthCheckDescriptor> $checks
	 */
	private function health(array $checks, ?object $provider, LoggerInterface $logger, ?IAppManager $appManager = null): HealthResult {
		$alias     = IHealthCheckProvider::class.'::myapp';
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnMap([[$alias, $provider !== null]]);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($provider): mixed {
				if ($provider instanceof RuntimeException) {
					throw $provider;
				}

				return $provider;
			}
		);
		$locator = $this->createMock(AppContainerLocator::class);
		$locator->method('findOr')->willReturnCallback(static fn (string $appId, ContainerInterface $fallback): ContainerInterface => $fallback);

		$executor = new HealthCheckExecutor(
			new LocalHealthChecks(
				new StorageHealthChecks($this->createMock(IDBConnection::class), $this->createMock(ITempManager::class)),
				new AppStateHealthChecks(($appManager ?? $this->createMock(IAppManager::class)), $this->createMock(IAppConfig::class))
			),
			new AppProviderResolver($container, $locator),
			$logger
		);

		return $executor->execute(
			new ObservabilityManifest('myapp', new ObservabilityProbes($checks, []), new HealthResponsePolicy(ObservabilityManifest::POLICY_ADR006))
		);
	}//end health()

	public function testWithoutAProviderOnlyTheDeclaredChecksRun(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$result = $this->health([new HealthCheckDescriptor('db', 'database', 'critical')], null, $logger);

		$this->assertSame(['db' => 'ok'], $result->checks);
		$this->assertSame('ok', $result->status);
		$this->assertSame(200, $result->httpStatusCode);
	}//end testWithoutAProviderOnlyTheDeclaredChecksRun()

	public function testAProviderThatThrowsIsLoggedAndContributesNothing(): void {
		$provider = new class implements IHealthCheckProvider {
			public function check(): array {
				throw new RuntimeException('queue unreachable');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[AppHost\Health] Provider for app "myapp" threw: queue unreachable',
			$this->callback(static fn (array $context): bool => $context['app'] === 'myapp')
		);

		$result = $this->health([new HealthCheckDescriptor('db', 'database', 'critical')], $provider, $logger);

		$this->assertSame(['db' => 'ok'], $result->checks);
		$this->assertSame('ok', $result->status);
	}//end testAProviderThatThrowsIsLoggedAndContributesNothing()

	public function testADegradedFailureAfterACriticalOneLeavesTheStatusAtError(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$checks = [
			new HealthCheckDescriptor('core', 'appEnabled', 'critical', new HealthCheckTarget(app: 'core')),
			new HealthCheckDescriptor('extra', 'appEnabled', 'degraded', new HealthCheckTarget(app: 'extra')),
		];

		$result = $this->health($checks, null, $this->createMock(LoggerInterface::class), $appManager);

		$this->assertSame('error', $result->status);
		$this->assertSame(503, $result->httpStatusCode);
	}//end testADegradedFailureAfterACriticalOneLeavesTheStatusAtError()

	public function testADegradedFailureAloneDegrades(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$checks = [new HealthCheckDescriptor('extra', 'appEnabled', 'degraded', new HealthCheckTarget(app: 'extra'))];

		$result = $this->health($checks, null, $this->createMock(LoggerInterface::class), $appManager);

		$this->assertSame('degraded', $result->status);
		$this->assertSame(200, $result->httpStatusCode);
	}//end testADegradedFailureAloneDegrades()
}//end class
