<?php

/**
 * The object source registrar registers its services in a fixed order and adds the providers at boot.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistrar;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ObjectSourceRegistrarTest extends TestCase {

	/**
	 * register() registers the registry, the providers and the DBAL services, in this order.
	 *
	 * A later registration of the same name replaces an earlier one, so the
	 * order is part of what the container hands out.
	 *
	 * @return void
	 */
	public function testRegistersTheServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		ObjectSourceRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry',
				'OCA\OpenRegister\Service\ObjectSource\FederatedObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\CalDavVtodoObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\UserDirectoryObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\GroupObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\ContactsObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\CalendarEventObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\FilesObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\DeckObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\TalkObjectSourceProvider',
				'OCA\OpenRegister\Service\Dbal\DbalConnectionFactory',
				'OCA\OpenRegister\Service\Dbal\SqlTypeMapper',
				'OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService',
				'OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver',
				'OCA\OpenRegister\Service\ObjectSource\TablesTableReader',
				'OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper',
				'OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService',
				'OCA\OpenRegister\Service\ObjectSource\TablesObjectSourceProvider',
			],
			actual: $context->services
		);
		$this->assertCount(expectedCount: 0, haystack: $context->listeners);
	}//end testRegistersTheServicesInOrder()

	/**
	 * Every factory builds an instance of the name it is registered under.
	 *
	 * The container hands each factory a test double for every service it asks
	 * for; see serviceFor().
	 *
	 * @return void
	 */
	public function testEveryFactoryBuildsItsService(): void {
		$context = new RecordingRegistrationContext();
		ObjectSourceRegistrar::register(context: $context);

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
			$this->assertInstanceOf(expected: $name, actual: $factory($container), message: $name);
		}
	}//end testEveryFactoryBuildsItsService()

	/**
	 * boot() adds every built-in provider to the registry, in order, and skips one that fails to resolve.
	 *
	 * @return void
	 */
	public function testBootAddsTheProvidersAndSkipsOneThatFails(): void {
		$registry = $this->createMock(originalClassName: ObjectSourceRegistry::class);
		$logger   = $this->createMock(originalClassName: LoggerInterface::class);
		$provider = $this->createMock(originalClassName: ObjectSourceProvider::class);

		$asked = [];
		$server = $this->createMock(originalClassName: ContainerInterface::class);
		$server->method('get')->willReturnCallback(
			function (string $id) use (&$asked, $registry, $logger, $provider) {
				if ($id === ObjectSourceRegistry::class) {
					return $registry;
				}

				if ($id === LoggerInterface::class) {
					return $logger;
				}

				$asked[] = $id;
				if ($id === 'OCA\OpenRegister\Service\ObjectSource\DeckObjectSourceProvider') {
					throw new RuntimeException(message: 'deck is not installed');
				}

				return $provider;
			}
		);

		$registry->expects($this->exactly(12))->method('addProvider')->with($provider);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains(string: 'DeckObjectSourceProvider')
		);

		ObjectSourceRegistrar::boot(server: $server);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Service\ObjectSource\CalDavVtodoObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\UserDirectoryObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\GroupObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\ContactsObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\CalendarEventObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\FilesObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\DeckObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\TalkObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\FederatedObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\TablesObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider',
				'OCA\OpenRegister\Service\ObjectSource\ViewObjectSourceProvider',
			],
			actual: $asked
		);
	}//end testBootAddsTheProvidersAndSkipsOneThatFails()

	/**
	 * boot() keeps adding providers when a provider fails and no logger can be resolved either.
	 *
	 * @return void
	 */
	public function testBootSkipsAFailingProviderWithoutALogger(): void {
		$registry = $this->createMock(originalClassName: ObjectSourceRegistry::class);
		$provider = $this->createMock(originalClassName: ObjectSourceProvider::class);

		$server = $this->createMock(originalClassName: ContainerInterface::class);
		$server->method('get')->willReturnCallback(
			function (string $id) use ($registry, $provider) {
				if ($id === ObjectSourceRegistry::class) {
					return $registry;
				}

				if ($id === LoggerInterface::class) {
					throw new RuntimeException(message: 'no logger');
				}

				if ($id === 'OCA\OpenRegister\Service\ObjectSource\CalDavVtodoObjectSourceProvider') {
					throw new RuntimeException(message: 'caldav is not installed');
				}

				return $provider;
			}
		);

		$registry->expects($this->exactly(12))->method('addProvider')->with($provider);

		ObjectSourceRegistrar::boot(server: $server);
	}//end testBootSkipsAFailingProviderWithoutALogger()

	/**
	 * boot() does nothing when the registry itself cannot be resolved.
	 *
	 * @return void
	 */
	public function testBootStopsWithoutARegistry(): void {
		$server = $this->createMock(originalClassName: ContainerInterface::class);
		$server->expects($this->once())->method('get')
			->with(ObjectSourceRegistry::class)
			->willThrowException(new RuntimeException(message: 'no registry'));

		ObjectSourceRegistrar::boot(server: $server);
	}//end testBootStopsWithoutARegistry()

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
