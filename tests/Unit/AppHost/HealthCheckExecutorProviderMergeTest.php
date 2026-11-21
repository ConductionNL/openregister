<?php

declare(strict_types=1);

/**
 * Characterisation of how HealthCheckExecutor merges IHealthCheckProvider
 * results into the declared checks: ok defaults, unknown severities, the
 * generic message, the worst status and the id collision with a declared check.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\IHealthCheckProvider;
use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\AppHost\Observability\AppProviderResolver;
use OCA\OpenRegister\AppHost\Observability\AppStateHealthChecks;
use OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor;
use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\HealthResponsePolicy;
use OCA\OpenRegister\AppHost\Observability\LocalHealthChecks;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCA\OpenRegister\AppHost\Observability\ObservabilityProbes;
use OCA\OpenRegister\AppHost\Observability\StorageHealthChecks;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the provider merge of HealthCheckExecutor::execute().
 */
class HealthCheckExecutorProviderMergeTest extends TestCase {

	public function testEachProviderResultIsMergedAndTheWorstStatusWins(): void {
		$result = $this->execute(
			providerResults: [
				'fine' => ['ok' => true],
				'truthy' => ['ok' => 1, 'severity' => 'degraded'],
				'slow' => ['ok' => false, 'severity' => 'degraded', 'message' => 'backlog'],
			],
			declaredOk: true
		);

		$this->assertSame(['db', 'fine', 'truthy', 'slow'], array_keys($result->checks));
		$this->assertSame('ok', $result->checks['fine']);
		$this->assertStringStartsWith('failed', $result->checks['truthy']);
		$this->assertSame('degraded', $result->status);
		$this->assertSame(200, $result->httpStatusCode);
	}//end testEachProviderResultIsMergedAndTheWorstStatusWins()

	public function testAnUnknownOrMissingSeverityCountsAsCritical(): void {
		$unknown = $this->execute(providerResults: ['q' => ['ok' => false, 'severity' => 'meh']], declaredOk: true);
		$missing = $this->execute(providerResults: ['q' => ['ok' => false]], declaredOk: true);

		$this->assertSame([$unknown->status, $unknown->httpStatusCode], [$missing->status, $missing->httpStatusCode]);
		$this->assertSame(503, $unknown->httpStatusCode);
		$this->assertNotSame('degraded', $unknown->status);
	}//end testAnUnknownOrMissingSeverityCountsAsCritical()

	public function testAProviderResultReplacesADeclaredCheckOfTheSameId(): void {
		$result = $this->execute(providerResults: ['db' => ['ok' => true]], declaredOk: false);

		$this->assertSame(['db'], array_keys($result->checks));
		$this->assertSame('ok', $result->checks['db']);
		$this->assertSame(503, $result->httpStatusCode);
	}//end testAProviderResultReplacesADeclaredCheckOfTheSameId()

	/**
	 * Run one declared database check plus the given provider results.
	 *
	 * @param array<string, array<string, mixed>> $providerResults What the provider reports.
	 * @param bool                                $declaredOk      Whether the declared check passes.
	 *
	 * @return \OCA\OpenRegister\AppHost\Observability\HealthResult
	 */
	private function execute(array $providerResults, bool $declaredOk) {
		$db = $this->createMock(IDBConnection::class);
		if ($declaredOk === true) {
			$db->method('executeQuery')->willReturn($this->createMock(IResult::class));
		} else {
			$db->method('executeQuery')->willThrowException(new \RuntimeException('down'));
		}

		$provider = new class($providerResults) implements IHealthCheckProvider {
			/**
			 * @param array<string, array<string, mixed>> $results The results.
			 */
			public function __construct(private array $results) {
			}

			public function check(): array {
				return $this->results;
			}
		};

		$alias     = IHealthCheckProvider::class . '::myapp';
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnMap([[$alias, true]]);
		$container->method('get')->willReturnMap([[$alias, $provider]]);

		$locator = $this->createMock(AppContainerLocator::class);
		$locator->method('find')->willReturn(null);
		$locator->method('findOr')->willReturnCallback(static fn (string $appId, ContainerInterface $fallback): ContainerInterface => $fallback);

		$executor = new HealthCheckExecutor(
			new LocalHealthChecks(
				new StorageHealthChecks($db, $this->createMock(ITempManager::class)),
				new AppStateHealthChecks($this->createMock(IAppManager::class), $this->createMock(IAppConfig::class))
			),
			new AppProviderResolver($container, $locator),
			$this->createMock(LoggerInterface::class)
		);

		return $executor->execute(
			new ObservabilityManifest(
				'myapp',
				new ObservabilityProbes([new HealthCheckDescriptor('db', 'database', 'critical')], []),
				new HealthResponsePolicy(ObservabilityManifest::POLICY_ADR006)
			)
		);
	}//end execute()
}//end class
