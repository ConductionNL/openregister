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
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionProperty;

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

	/**
	 * Every factory builds a service, of the registered class where the name is a class.
	 *
	 * The container hands each factory a test double for every service it asks
	 * for; see serviceFor().
	 *
	 * @return void
	 */
	public function testEveryFactoryBuildsItsService(): void {
		$context = new RecordingRegistrationContext();
		ObservabilityRegistrar::register(context: $context, appId: 'openregister');

		$doubles   = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$doubles, &$container, $context) {
				if (isset($doubles[$id]) === false) {
					$doubles[$id] = $this->serviceFor(id: $id, factories: $context->factories, container: $container);
				}

				return $doubles[$id];
			}
		);

		foreach ($context->factories as $name => $factory) {
			$service = $factory($container);
			if (class_exists(class: $name) === true) {
				$this->assertInstanceOf(expected: $name, actual: $service, message: $name);
				continue;
			}

			$this->assertIsObject(actual: $service, message: $name);
		}
	}//end testEveryFactoryBuildsItsService()

	/**
	 * A test double for a service, or, for a final class, a real instance.
	 *
	 * A final class registered here is built by its own factory; any other
	 * final class is created without running its constructor.
	 *
	 * @param string                  $id        The service id the factory asks for.
	 * @param array<string, callable> $factories The registered factories by name.
	 * @param IAppContainer           $container The container to hand a factory.
	 *
	 * @return object
	 */
	private function serviceFor(string $id, array $factories, IAppContainer $container): object {
		$class = new ReflectionClass(objectOrClass: $id);
		if ($class->isFinal() === false) {
			return $this->createMock(originalClassName: $id);
		}

		if (isset($factories[$id]) === true) {
			return $factories[$id]($container);
		}

		return $class->newInstanceWithoutConstructor();
	}//end serviceFor()
}//end class
