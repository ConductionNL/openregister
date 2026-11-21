<?php

/**
 * AppHost MetricsEngine: the opt-in `up` gauge that follows the health status.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\HealthResult;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\OpenRegister\AppHost\Observability\MetricSources;
use OCA\OpenRegister\AppHost\Observability\MetricsRuntime;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use OCA\OpenRegister\AppHost\Observability\Source\AppConfigMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ObjectMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\AppHost\Observability\MetricsEngine
 * @uses   \OCA\OpenRegister\AppHost\Observability\HealthResponsePolicy
 * @uses   \OCA\OpenRegister\AppHost\Observability\HealthResult
 * @uses   \OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor
 * @uses   \OCA\OpenRegister\AppHost\Observability\HealthCheckTarget
 * @uses   \OCA\OpenRegister\AppHost\Observability\MetricSample
 * @uses   \OCA\OpenRegister\AppHost\Observability\MetricSources
 * @uses   \OCA\OpenRegister\AppHost\Observability\MetricsRuntime
 * @uses   \OCA\OpenRegister\AppHost\Observability\ObservabilityManifest
 * @uses   \OCA\OpenRegister\AppHost\Observability\ObservabilityProbes
 * @uses   \OCA\OpenRegister\AppHost\Observability\PrometheusRenderer
 */
final class MetricsEngineUpFollowsHealthTest extends TestCase {

	/**
	 * Without the opt-in, `up` stays 1 and health is never run.
	 *
	 * @return void
	 */
	public function testWithoutTheOptInUpIsOneAndHealthIsNotRun(): void {
		$health = $this->createMock(HealthCheckExecutor::class);
		$health->expects($this->never())->method('execute');

		$out = $this->engine(health: $health)->render($this->manifest(optIn: false));

		$this->assertStringContainsString("myapp_up 1\n", $out);
	}//end testWithoutTheOptInUpIsOneAndHealthIsNotRun()

	/**
	 * With the opt-in, `up` is 1 while health is ok.
	 *
	 * @return void
	 */
	public function testOkHealthKeepsUpAtOne(): void {
		$out = $this->engine(health: $this->health(status: 'ok'))->render($this->manifest(optIn: true));

		$this->assertStringContainsString("myapp_up 1\n", $out);
	}//end testOkHealthKeepsUpAtOne()

	/**
	 * Degraded or failed health makes `up` 0.
	 *
	 * @return void
	 */
	public function testDegradedOrFailedHealthMakesUpZero(): void {
		foreach (['degraded', 'error'] as $status) {
			$out = $this->engine(health: $this->health(status: $status))->render($this->manifest(optIn: true));
			$this->assertStringContainsString("myapp_up 0\n", $out, $status);
		}
	}//end testDegradedOrFailedHealthMakesUpZero()

	/**
	 * Health that throws makes `up` 0 and is logged.
	 *
	 * @return void
	 */
	public function testThrowingHealthMakesUpZero(): void {
		$health = $this->createMock(HealthCheckExecutor::class);
		$health->method('execute')->willThrowException(new RuntimeException('boom'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('boom'));

		$out = $this->engine(health: $health, logger: $logger)->render($this->manifest(optIn: true));

		$this->assertStringContainsString("myapp_up 0\n", $out);
	}//end testThrowingHealthMakesUpZero()

	/**
	 * Without an executor the opt-in has nothing to ask, so `up` stays 1.
	 *
	 * @return void
	 */
	public function testNoExecutorKeepsUpAtOne(): void {
		$out = $this->engine(health: null)->render($this->manifest(optIn: true));

		$this->assertStringContainsString("myapp_up 1\n", $out);
	}//end testNoExecutorKeepsUpAtOne()

	/**
	 * A parsed manifest without metrics, with or without the opt-in.
	 *
	 * @param bool $optIn Whether `health.upFollowsHealth` is set.
	 *
	 * @return ObservabilityManifest
	 */
	private function manifest(bool $optIn): ObservabilityManifest {
		$health = ['checks' => [['id' => 'database', 'type' => 'database']]];
		if ($optIn === true) {
			$health['upFollowsHealth'] = true;
		}

		return ObservabilityManifest::fromManifest('myapp', ['observability' => ['health' => $health]]);
	}//end manifest()

	/**
	 * An executor answering one status.
	 *
	 * @param string $status ok|degraded|error.
	 *
	 * @return HealthCheckExecutor
	 */
	private function health(string $status): HealthCheckExecutor {
		$health = $this->createMock(HealthCheckExecutor::class);
		$health->expects($this->once())->method('execute')->willReturn(new HealthResult(status: $status, checks: [], httpStatusCode: 200));
		return $health;
	}//end health()

	/**
	 * The engine under test.
	 *
	 * @param HealthCheckExecutor|null $health The health executor.
	 * @param LoggerInterface|null     $logger The logger.
	 *
	 * @return MetricsEngine
	 */
	private function engine(?HealthCheckExecutor $health, ?LoggerInterface $logger = null): MetricsEngine {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('33.0.0');
		$loader = $this->createMock(ManifestLoader::class);
		$loader->method('appVersion')->willReturn('1.2.3');
		$cache = $this->createMock(ICacheFactory::class);
		$cache->method('isAvailable')->willReturn(false);

		return new MetricsEngine(
			sources: new MetricSources(
				object: $this->createMock(ObjectMetricSource::class),
				table: $this->createMock(TableMetricSource::class),
				appConfig: $this->createMock(AppConfigMetricSource::class),
				provider: $this->createMock(ProviderMetricSource::class)
			),
			renderer: new PrometheusRenderer(),
			runtime: new MetricsRuntime(manifestLoader: $loader, cacheFactory: $cache, config: $config),
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			health: $health
		);
	}//end engine()
}//end class
