<?php

/**
 * The integration registrar registers its services in a fixed order, and each factory builds its service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistrar;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

class IntegrationRegistrarTest extends TestCase {

	/**
	 * register() registers the integration services, in this order.
	 *
	 * A later registration of the same name replaces an earlier one, so the
	 * order is part of what the container hands out.
	 *
	 * @return void
	 */
	public function testRegistersTheServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		IntegrationRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Service\Integration\IntegrationRegistry',
				'OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter',
				'OCA\OpenRegister\Service\Integration\PropertyReferenceTypeValidator',
				'OCA\OpenRegister\Service\Integration\PropertySemanticReferenceValidator',
				'OCA\OpenRegister\Repair\LogDanglingLinkedTypes',
				'OCA\OpenRegister\Service\Integration\LeafRegistry',
				'OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider',
				'OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider',
				'OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider',
				'OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider',
				'OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider',
				'OCA\OpenRegister\Service\Integration\Providers\XwikiProvider',
				'OCA\OpenRegister\Service\XwikiLinkService',
				'OCA\OpenRegister\Service\Integration\Providers\KvkProvider',
				'OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider',
				'OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider',
				'OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider',
				'OCA\OpenRegister\Service\OpenProjectLinkService',
				'OCA\OpenRegister\Service\Integration\Providers\CalendarProvider',
				'OCA\OpenRegister\Service\Integration\Providers\ContactsProvider',
				'OCA\OpenRegister\Service\Integration\Providers\DeckProvider',
				'OCA\OpenRegister\Service\Integration\Providers\EmailProvider',
				'OCA\OpenRegister\Service\Integration\Providers\ActivityProvider',
				'OCA\OpenRegister\Service\Integration\Providers\FormsProvider',
				'OCA\OpenRegister\Service\Integration\Providers\SharesProvider',
				'OCA\OpenRegister\Service\ShareLinkService',
				'OCA\OpenRegister\Service\CaseTokenService',
				'OCA\OpenRegister\Controller\CaseTokenController',
				'OCA\OpenRegister\Service\AnalyticsSeriesService',
				'OCA\OpenRegister\Controller\AnalyticsSeriesController',
				'OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider',
				'OCA\OpenRegister\Service\Integration\Providers\TalkProvider',
				'OCA\OpenRegister\Service\TalkLinkService',
				'OCA\OpenRegister\Service\Integration\Providers\PollsProvider',
				'OCA\OpenRegister\Service\Integration\Providers\FlowProvider',
				'OCA\OpenRegister\Service\ActivityFilterService',
				'OCA\OpenRegister\Service\FlowLinkService',
				'OCA\OpenRegister\Service\Integration\Providers\MapsProvider',
				'OCA\OpenRegister\Service\MapLinkService',
				'OCA\OpenRegister\Service\Integration\Providers\PhotosProvider',
				'OCA\OpenRegister\Service\PhotoLinkService',
				'OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider',
				'OCA\OpenRegister\Service\Integration\Providers\AnalyticsProvider',
				'OCA\OpenRegister\Service\Integration\Providers\CospendProvider',
				'OCA\OpenRegister\Service\CospendLinkService',
				'OCA\OpenRegister\Service\CollectiveLinkService',
				'OCA\OpenRegister\Service\AnalyticsLinkService',
				'OCA\OpenRegister\Service\Integration\Providers\TimeProvider',
				'OCA\OpenRegister\Service\TimeTrackerLinkService',
				'OCA\OpenRegister\Service\Schema\SchemaDiffService',
				'OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner',
				'OCA\OpenRegister\Service\Schema\SchemaVersioningService',
				'OCA\OpenRegister\Service\Schema\SchemaRevalidationService',
				'OCA\OpenRegister\Service\Schema\SchemaMigrationService',
				'OCA\OpenRegister\Controller\IntegrationsController',
				'OCA\OpenRegister\Controller\ObjectIntegrationsController',
			],
			actual: $context->services
		);
		$this->assertCount(expectedCount: 0, haystack: $context->listeners);
	}//end testRegistersTheServicesInOrder()

	/**
	 * Every factory builds an instance of the class it is registered under.
	 *
	 * The container hands each factory a test double for every service it asks
	 * for; see serviceFor().
	 *
	 * @return void
	 */
	public function testEveryFactoryBuildsItsService(): void {
		$context = new RecordingRegistrationContext();
		IntegrationRegistrar::register(context: $context);

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
	 * boot() adds every built-in provider in order and logs and skips one that fails to resolve.
	 *
	 * @return void
	 */
	public function testBootAddsTheProvidersAndSkipsOneThatFails(): void {
		$registry = $this->createMock(originalClassName: IntegrationRegistry::class);
		$logger   = $this->createMock(originalClassName: LoggerInterface::class);
		$provider = $this->createMock(originalClassName: IntegrationProvider::class);

		$asked  = [];
		$server = $this->createMock(originalClassName: ContainerInterface::class);
		$server->method('get')->willReturnCallback(
			function (string $id) use (&$asked, $registry, $logger, $provider) {
				if ($id === IntegrationRegistry::class) {
					return $registry;
				}

				if ($id === LoggerInterface::class) {
					return $logger;
				}

				$asked[] = $id;
				if ($id === 'OCA\OpenRegister\Service\Integration\Providers\DeckProvider') {
					throw new RuntimeException(message: 'deck is not installed');
				}

				return $provider;
			}
		);

		$registry->expects($this->exactly(25))->method('addProvider')->with($provider);
		$logger->expects($this->once())->method('warning');

		IntegrationRegistrar::boot(server: $server);

		$this->assertCount(expectedCount: 26, haystack: $asked);
		$this->assertSame(expected: 'OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider', actual: $asked[0]);
		$this->assertSame(expected: 'OCA\OpenRegister\Service\Integration\Providers\TimeProvider', actual: $asked[25]);
	}//end testBootAddsTheProvidersAndSkipsOneThatFails()

	/**
	 * boot() does nothing when the registry cannot be resolved.
	 *
	 * @return void
	 */
	public function testBootStopsWithoutARegistry(): void {
		$server = $this->createMock(originalClassName: ContainerInterface::class);
		$server->expects($this->once())->method('get')
			->with(IntegrationRegistry::class)
			->willThrowException(new RuntimeException(message: 'no registry'));

		IntegrationRegistrar::boot(server: $server);
	}//end testBootStopsWithoutARegistry()

	/**
	 * bootLeafRegistry() installs a loader that reads the leaf catalogue only when it runs.
	 *
	 * @return void
	 */
	public function testBootLeafRegistryInstallsALazyLoader(): void {
		$leaves = $this->createMock(originalClassName: LeafRegistry::class);
		$leaves->expects($this->once())->method('getDescriptors')->willReturn([]);

		$loader   = null;
		$registry = $this->createMock(originalClassName: IntegrationRegistry::class);
		$registry->method('setLeafLoader')->willReturnCallback(
			function (callable $installed) use (&$loader): void {
				$loader = $installed;
			}
		);

		$server = $this->createMock(originalClassName: ContainerInterface::class);
		$server->method('get')->willReturnMap(
			[
				[IntegrationRegistry::class, $registry],
				[LeafRegistry::class, $leaves],
			]
		);

		IntegrationRegistrar::bootLeafRegistry(server: $server);
		$this->assertIsCallable(actual: $loader);

		$loader();
	}//end testBootLeafRegistryInstallsALazyLoader()

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
