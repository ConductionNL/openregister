<?php

/**
 * Unit tests for FlowWiring.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowRunAsScope;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorization;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Flow\RegistryStepDispatcher;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowWiring
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunAuthorization
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunnableGuard
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunContext
 * @uses   \OCA\OpenRegister\Service\Flow\RegistryStepDispatcher
 */
final class FlowWiringTest extends TestCase {

	/**
	 * The factories registered, by service name.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = [];

	/**
	 * The container the factories resolve from.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * The services the container hands out, by name.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * Register the wiring against a recording context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory, bool $shared = true): void {
				$this->factories[$name] = $factory;
			}
		);

		$this->services = [
			FlowAccess::class => $this->createMock(FlowAccess::class),
			FlowService::class => $this->createMock(FlowService::class),
			FlowNodeRegistryInterface::class => $this->createMock(FlowNodeRegistryInterface::class),
			FlowRunAsScope::class => $this->createMock(FlowRunAsScope::class),
		];
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(fn (string $id): object => $this->services[$id]);

		FlowWiring::register($context);
	}//end setUp()

	/**
	 * Read a private property of an object.
	 *
	 * @param object $object The object.
	 * @param string $name   The property name.
	 *
	 * @return mixed The value.
	 */
	private static function field(object $object, string $name): mixed {
		return (new ReflectionProperty($object, $name))->getValue($object);
	}//end field()

	/**
	 * Exactly the four services are registered, in order.
	 *
	 * @return void
	 */
	public function testItRegistersTheFourServices(): void {
		self::assertSame(
			[FlowRunAuthorization::class, FlowRunnableGuard::class, FlowRunContext::class, FlowStepDispatcher::class],
			array_keys($this->factories)
		);
	}//end testItRegistersTheFourServices()

	/**
	 * The run authorization is built on the container's flow access.
	 *
	 * @return void
	 */
	public function testTheRunAuthorizationGetsTheFlowAccess(): void {
		$built = ($this->factories[FlowRunAuthorization::class])($this->container);

		self::assertInstanceOf(FlowRunAuthorization::class, $built);
		self::assertSame($this->services[FlowAccess::class], self::field($built, 'access'));
	}//end testTheRunAuthorizationGetsTheFlowAccess()

	/**
	 * The runnable guard is built on the container's flow service and flow access.
	 *
	 * @return void
	 */
	public function testTheRunnableGuardGetsFlowsAndAccess(): void {
		$built = ($this->factories[FlowRunnableGuard::class])($this->container);

		self::assertInstanceOf(FlowRunnableGuard::class, $built);
		self::assertSame($this->services[FlowService::class], self::field($built, 'flows'));
		self::assertSame($this->services[FlowAccess::class], self::field($built, 'access'));
	}//end testTheRunnableGuardGetsFlowsAndAccess()

	/**
	 * The run context factory needs nothing from the container.
	 *
	 * @return void
	 */
	public function testTheRunContextIsBuiltWithoutTheContainer(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');

		self::assertInstanceOf(FlowRunContext::class, ($this->factories[FlowRunContext::class])($container));
	}//end testTheRunContextIsBuiltWithoutTheContainer()

	/**
	 * The step dispatcher is a registry dispatcher with the registry and scope, and no guard.
	 *
	 * @return void
	 */
	public function testTheStepDispatcherGetsRegistryAndScopeButNoGuard(): void {
		$built = ($this->factories[FlowStepDispatcher::class])($this->container);

		self::assertInstanceOf(RegistryStepDispatcher::class, $built);
		self::assertSame($this->services[FlowNodeRegistryInterface::class], self::field($built, 'registry'));
		self::assertSame($this->services[FlowRunAsScope::class], self::field($built, 'scope'));
		self::assertNull(self::field($built, 'guard'));
	}//end testTheStepDispatcherGetsRegistryAndScopeButNoGuard()
}//end class
