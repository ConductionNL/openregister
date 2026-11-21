<?php

/**
 * The integration controllers are registered with factories that build them
 * from the container, each collaborator fetched under its own id.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\IntegrationControllersWiring;
use OCA\OpenRegister\Controller\IntegrationsController;
use OCA\OpenRegister\Controller\ObjectIntegrationsController;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use OCA\OpenRegister\Service\Integration\IntegrationCatalogueInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for IntegrationControllersWiring.
 *
 * @covers \OCA\OpenRegister\Controller\IntegrationControllersWiring
 * @uses   \OCA\OpenRegister\Controller\IntegrationsController
 * @uses   \OCA\OpenRegister\Controller\ObjectIntegrationsController
 * @uses   \OCA\OpenRegister\Controller\Support\IntegrationsControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectIntegrationsControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectIntegrationsDomain
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectIntegrationsAccess
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses   \OCA\OpenRegister\Controller\Support\RelationsObjectLookup
 */
final class IntegrationControllersWiringTest extends TestCase {

	/**
	 * The services each id resolves to.
	 *
	 * @return array<string, class-string> The interface to mock per id.
	 */
	private static function served(): array {
		return [
			'OCP\IRequest' => IRequest::class,
			'OCP\IUserSession' => IUserSession::class,
			'OCP\IGroupManager' => IGroupManager::class,
			'Psr\Log\LoggerInterface' => LoggerInterface::class,
			IntegrationCatalogueInterface::class => IntegrationCatalogueInterface::class,
			ObjectOperationsInterface::class => ObjectOperationsInterface::class,
			SchemaMapperInterface::class => SchemaMapperInterface::class,
			TimelineVisibilityServiceInterface::class => TimelineVisibilityServiceInterface::class,
			PermissionHandlerInterface::class => PermissionHandlerInterface::class,
		];
	}//end served()

	/**
	 * Register the wiring and return the factories by service name.
	 *
	 * @return array<string, callable> The factories.
	 */
	private function factories(): array {
		$factories = [];
		$context   = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		IntegrationControllersWiring::register(context: $context);

		return $factories;
	}//end factories()

	/**
	 * A container serving a mock per known id, recording what was asked.
	 *
	 * @param list<string> $asked Receives each id asked.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(array &$asked): ContainerInterface {
		$mocks = [];
		foreach (self::served() as $id => $interface) {
			$mocks[$id] = $this->createMock($interface);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($mocks, &$asked): object {
				$asked[] = $id;
				return $mocks[$id];
			}
		);

		return $container;
	}//end container()

	public function testBothControllersAreRegistered(): void {
		$this->assertSame(
			[IntegrationsController::class, ObjectIntegrationsController::class],
			array_keys($this->factories())
		);
	}//end testBothControllersAreRegistered()

	public function testTheIntegrationsControllerIsBuiltFromTheContainer(): void {
		$asked      = [];
		$controller = $this->factories()[IntegrationsController::class]($this->container(asked: $asked));

		$this->assertInstanceOf(IntegrationsController::class, $controller);
		$this->assertSame(
			['OCP\IRequest', IntegrationCatalogueInterface::class, 'OCP\IUserSession', 'OCP\IGroupManager', 'Psr\Log\LoggerInterface'],
			$asked
		);
	}//end testTheIntegrationsControllerIsBuiltFromTheContainer()

	public function testTheObjectIntegrationsControllerIsBuiltFromTheContainer(): void {
		$asked      = [];
		$controller = $this->factories()[ObjectIntegrationsController::class]($this->container(asked: $asked));

		$this->assertInstanceOf(ObjectIntegrationsController::class, $controller);
		$this->assertSame(
			[
				'OCP\IRequest',
				IntegrationCatalogueInterface::class,
				ObjectOperationsInterface::class,
				SchemaMapperInterface::class,
				TimelineVisibilityServiceInterface::class,
				PermissionHandlerInterface::class,
				'Psr\Log\LoggerInterface',
			],
			$asked
		);
	}//end testTheObjectIntegrationsControllerIsBuiltFromTheContainer()
}//end class
