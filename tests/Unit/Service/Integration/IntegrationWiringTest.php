<?php

/**
 * How the integration registry is wired and booted: the services and
 * aliases it registers, every built-in provider added at boot with a
 * failing one dropped and logged, and the leaf loader handed to the
 * registry — each boot step skipping quietly when the registry is absent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Controller\IntegrationsController;
use OCA\OpenRegister\Controller\ObjectIntegrationsController;
use OCA\OpenRegister\Service\Integration\BuiltinIntegrationServices;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\IntegrationWiring;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCA\OpenRegister\Service\Integration\PropertyReferenceTypeValidator;
use OCA\OpenRegister\Service\Integration\PropertySemanticReferenceValidator;
use OCA\OpenRegister\Service\Integration\Providers\TimeProvider;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * IntegrationWiring.
 *
 * @covers \OCA\OpenRegister\Service\Integration\IntegrationWiring
 * @uses \OCA\OpenRegister\Controller\IntegrationControllersRegistrar
 * @uses \OCA\OpenRegister\Controller\IntegrationControllersWiring
 * @uses \OCA\OpenRegister\Service\Integration\IntegrationRegistry
 * @uses \OCA\OpenRegister\Service\Integration\PropertySemanticReferenceValidator
 * @uses \OCA\OpenRegister\Service\JsonLd\JsonLdContextService
 */
class IntegrationWiringTest extends TestCase {

	/**
	 * The factories registered, by service id.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = [];

	/**
	 * The aliases registered, alias => target.
	 *
	 * @var array<string, string>
	 */
	private array $aliases = [];

	private function registerAll(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory): void {
				$this->factories[$name] = $factory;
			}
		);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target): void {
				$this->aliases[$alias] = $target;
			}
		);

		IntegrationWiring::register($context);
	}//end registerAll()

	public function testTheRegistryRouterValidatorsLeavesControllersAndProvidersAreRegistered(): void {
		$this->registerAll();

		foreach (
			[
				IntegrationRegistry::class,
				ExternalIntegrationRouter::class,
				PropertyReferenceTypeValidator::class,
				PropertySemanticReferenceValidator::class,
				LeafRegistry::class,
				IntegrationsController::class,
				ObjectIntegrationsController::class,
			] as $service
		) {
			$this->assertArrayHasKey($service, $this->factories);
		}

		foreach (array_keys(BuiltinIntegrationServices::FACTORIES) as $provider) {
			$this->assertArrayHasKey($provider, $this->factories);
		}
	}//end testTheRegistryRouterValidatorsLeavesControllersAndProvidersAreRegistered()

	public function testEveryContractIsAliasedToItsService(): void {
		$this->registerAll();

		$this->assertSame(IntegrationWiring::BINDINGS, $this->aliases);
		$this->assertNotSame([], $this->aliases);
	}//end testEveryContractIsAliasedToItsService()

	public function testTheRegistryAndSemanticValidatorFactoriesBuildFromTheContainer(): void {
		$this->registerAll();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				['Psr\Log\LoggerInterface', $this->createMock(LoggerInterface::class)],
				[JsonLdContextService::class, new JsonLdContextService($this->createMock(IURLGenerator::class))],
			]
		);

		$this->assertInstanceOf(IntegrationRegistry::class, ($this->factories[IntegrationRegistry::class])($container));
		$this->assertInstanceOf(PropertySemanticReferenceValidator::class, ($this->factories[PropertySemanticReferenceValidator::class])($container));
	}//end testTheRegistryAndSemanticValidatorFactoriesBuildFromTheContainer()

	public function testBootSkipsQuietlyWithoutARegistry(): void {
		$server = $this->createMock(ContainerInterface::class);
		$server->expects($this->once())->method('get')->with(IntegrationRegistry::class)
			->willThrowException(new RuntimeException('not bound'));

		IntegrationWiring::boot($server);
	}//end testBootSkipsQuietlyWithoutARegistry()

	public function testBootAddsEveryBuiltinProviderAndDropsOneThatFailsToConstruct(): void {
		$registry = $this->createMock(IntegrationRegistry::class);
		$logger   = $this->createMock(LoggerInterface::class);
		$built    = [];
		$server   = $this->createMock(ContainerInterface::class);
		$server->method('get')->willReturnCallback(
			function (string $id) use ($registry, $logger, &$built): mixed {
				if ($id === IntegrationRegistry::class) {
					return $registry;
				}

				if ($id === LoggerInterface::class) {
					return $logger;
				}

				if ($id === NotesProvider::class) {
					throw new RuntimeException('notes app missing');
				}

				$built[$id] = $this->createMock(IntegrationProvider::class);
				return $built[$id];
			}
		);

		$added = [];
		$registry->method('addProvider')->willReturnCallback(
			static function (IntegrationProvider $provider) use (&$added): bool {
				$added[] = $provider;
				return true;
			}
		);
		$logger->expects($this->once())->method('warning')->with(
			'OpenRegister: integration provider failed to construct and was dropped from the registry: {provider} — {message}',
			$this->callback(
				static fn (array $context): bool => $context['provider'] === NotesProvider::class
					&& $context['message'] === 'notes app missing'
					&& $context['exception'] instanceof RuntimeException
			)
		);

		IntegrationWiring::boot($server);

		$this->assertSame(array_values($built), $added);
		$this->assertSame(FilesProvider::class, array_key_first($built));
		$this->assertSame(TimeProvider::class, array_key_last($built));
		$this->assertArrayNotHasKey(NotesProvider::class, $built);
		$this->assertCount(25, $added);
	}//end testBootAddsEveryBuiltinProviderAndDropsOneThatFailsToConstruct()

	public function testAFailingProviderIsDroppedSilentlyWithoutALogger(): void {
		$registry = $this->createMock(IntegrationRegistry::class);
		$registry->expects($this->never())->method('addProvider');
		$server = $this->createMock(ContainerInterface::class);
		$server->method('get')->willReturnCallback(
			static function (string $id) use ($registry): mixed {
				if ($id === IntegrationRegistry::class) {
					return $registry;
				}

				throw new RuntimeException('unavailable: '.$id);
			}
		);

		IntegrationWiring::boot($server);
	}//end testAFailingProviderIsDroppedSilentlyWithoutALogger()

	public function testTheLeafLoaderReadsTheLeafCatalogueWhenCalled(): void {
		$loader   = null;
		$registry = $this->createMock(IntegrationRegistry::class);
		$registry->expects($this->once())->method('setLeafLoader')->willReturnCallback(
			static function (callable $given) use (&$loader): void {
				$loader = $given;
			}
		);
		$leaves = $this->createMock(LeafRegistry::class);
		$leaves->expects($this->once())->method('getDescriptors')->willReturn([]);
		$server = $this->createMock(ContainerInterface::class);
		$server->method('get')->willReturnMap([[IntegrationRegistry::class, $registry], [LeafRegistry::class, $leaves]]);

		IntegrationWiring::bootLeafRegistry($server);

		$this->assertIsCallable($loader);
		$loader();
	}//end testTheLeafLoaderReadsTheLeafCatalogueWhenCalled()

	public function testTheLeafLoaderIsSkippedWithoutARegistry(): void {
		$server = $this->createMock(ContainerInterface::class);
		$server->expects($this->once())->method('get')->willThrowException(new RuntimeException('not bound'));

		IntegrationWiring::bootLeafRegistry($server);
	}//end testTheLeafLoaderIsSkippedWithoutARegistry()
}//end class
