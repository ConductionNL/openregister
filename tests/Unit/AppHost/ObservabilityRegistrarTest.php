<?php

/**
 * The observability registrar registers its services in a fixed order, under the hosting app's id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\OpenRegister\AppHost\ObservabilityRegistrar;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\AppHost\ObservabilityRegistrar
 * @uses   \OCA\OpenRegister\AppHost\Controller\GenericHealthController
 * @uses   \OCA\OpenRegister\AppHost\Controller\GenericMetricsController
 */
class ObservabilityRegistrarTest extends TestCase {

	/**
	 * register() registers the observability services, in this order.
	 *
	 * A later registration of the same name replaces an earlier one, so the
	 * order is part of what the container hands out.
	 *
	 * @return void
	 */
	public function testRegistersTheServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		ObservabilityRegistrar::register(context: $context, appId: 'openregister');

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\AppHost\Observability\ManifestLoader',
				'OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor',
				'OCA\OpenRegister\AppHost\Observability\Source\ObjectMetricSource',
				'OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource',
				'OCA\OpenRegister\AppHost\Observability\Source\AppConfigMetricSource',
				'OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource',
				'OCA\OpenRegister\AppHost\Observability\PrometheusRenderer',
				'OCA\OpenRegister\AppHost\IMetricsProvider::openregister',
				'OCA\OpenRegister\AppHost\Observability\MetricsEngine',
				'AppHost\Controller\GenericHealthController',
				'AppHost\Controller\GenericMetricsController',
			],
			actual: $context->services
		);
		$this->assertCount(expectedCount: 0, haystack: $context->listeners);
	}//end testRegistersTheServicesInOrder()

	/**
	 * The health and metrics controllers are built for the app id passed in.
	 *
	 * @return void
	 */
	public function testTheControllersCarryTheAppId(): void {
		$context = new RecordingRegistrationContext();
		ObservabilityRegistrar::register(context: $context, appId: 'openregister');

		$services = [
			'OCP\IRequest' => $this->createMock(originalClassName: IRequest::class),
			ManifestLoader::class => $this->createMock(originalClassName: ManifestLoader::class),
			HealthCheckExecutor::class => $this->createMock(originalClassName: HealthCheckExecutor::class),
			MetricsEngine::class => $this->createMock(originalClassName: MetricsEngine::class),
		];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => $services[$id]
		);

		$health  = ($context->factories['AppHost\Controller\GenericHealthController'])($container);
		$metrics = ($context->factories['AppHost\Controller\GenericMetricsController'])($container);

		$this->assertInstanceOf(expected: GenericHealthController::class, actual: $health);
		$this->assertInstanceOf(expected: GenericMetricsController::class, actual: $metrics);
		$appName = new ReflectionProperty(class: \OCP\AppFramework\Controller::class, property: 'appName');
		$this->assertSame(expected: 'openregister', actual: $appName->getValue(object: $health));
		$this->assertSame(expected: 'openregister', actual: $appName->getValue(object: $metrics));
	}//end testTheControllersCarryTheAppId()
}//end class
