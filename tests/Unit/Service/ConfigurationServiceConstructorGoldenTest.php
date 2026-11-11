<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\Configuration\ConfigurationServiceRegistrar;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Golden test for the ConfigurationService constructor: which injected dependency ends up in which
 * property, compared with fixtures/configuration-service-constructor-properties-golden.json,
 * and which container id the registrar's factory puts in which property, compared with
 * fixtures/configuration-service-registrar-properties-golden.json. Set UPDATE_GOLDEN=1
 * to rewrite them.
 *
 * Every dependency is a double named after its constructor parameter; a final class
 * among the parameters (a dependency bundle) is built for real from doubles in turn.
 */
class ConfigurationServiceConstructorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/configuration-service-constructor-properties-golden.json';

	private const REGISTRAR_FIXTURE = __DIR__ . '/fixtures/configuration-service-registrar-properties-golden.json';

	/** @var array<string, object> */
	private array $doubles = [];

	public function testEveryDependencyLandsInTheSameProperty(): void {
		$service = new ConfigurationService(...$this->argumentsFor(ConfigurationService::class));

		$this->assertMatchesFixture(self::FIXTURE, $this->landedIn($service));
	}//end testEveryDependencyLandsInTheSameProperty()

	public function testTheRegistrarFactoryPutsEveryContainerServiceInTheSameProperty(): void {
		$context = new RecordingRegistrationContext();
		ConfigurationServiceRegistrar::register(context: $context);

		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				$this->doubles[$id] ??= $this->createMock($id);
				return $this->doubles[$id];
			}
		);
		$this->doubles['(the container itself)'] = $container;

		$service = $context->factories[ConfigurationService::class]($container);

		$this->assertMatchesFixture(self::REGISTRAR_FIXTURE, $this->landedIn($service));
	}//end testTheRegistrarFactoryPutsEveryContainerServiceInTheSameProperty()

	/**
	 * Which double, by name, each initialised property of the service holds.
	 *
	 * @param ConfigurationService $service The service.
	 *
	 * @return array<string, string>
	 */
	private function landedIn(ConfigurationService $service): array {
		$landed = [];
		foreach ((new ReflectionClass(ConfigurationService::class))->getProperties() as $property) {
			if ($property->isStatic() === true || $property->isInitialized($service) === false) {
				continue;
			}

			$source = array_search($property->getValue($service), $this->doubles, true);
			if ($source !== false) {
				$landed[$property->getName()] = $source;
			}
		}

		ksort($landed);
		return $landed;
	}//end landedIn()

	/**
	 * Compare a recording with its fixture, or rewrite the fixture when asked.
	 *
	 * @param string                $fixture The fixture path.
	 * @param array<string, string> $actual  The recording.
	 *
	 * @return void
	 */
	private function assertMatchesFixture(string $fixture, array $actual): void {
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents($fixture), true), $actual);
	}//end assertMatchesFixture()

	/**
	 * The constructor arguments of a class, bundles built for real and the rest doubled.
	 *
	 * @param string $class The class to build arguments for.
	 *
	 * @return array<int, object>
	 */
	private function argumentsFor(string $class): array {
		$arguments = [];
		foreach ((new ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			if (class_exists($type) === true && (new ReflectionClass($type))->isFinal() === true) {
				$arguments[] = new $type(...$this->argumentsFor($type));
				continue;
			}

			$double = $this->createMock(ltrim($type, '?'));
			$this->doubles[$parameter->getName()] = $double;
			$arguments[] = $double;
		}

		return $arguments;
	}//end argumentsFor()
}//end class
