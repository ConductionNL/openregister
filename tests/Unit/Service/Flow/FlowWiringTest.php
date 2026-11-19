<?php

/**
 * Unit tests for FlowWiring.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStoreInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\CurrentFlowRunInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ExpressionDefaultsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FieldRulesSourceInterface;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowConsentParking;
use OCA\OpenRegister\Service\Flow\FlowConsentSweepInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowRegistrar;
use OCA\OpenRegister\Service\Flow\FlowRunAsScope;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorization;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunSignalInterface;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalKinds;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalKindsInterface;
use OCA\OpenRegister\Service\Flow\RegistryStepDispatcher;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecompute;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecomputeInterface;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnosticInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinitionsInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendars;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Rules\ExpressionDefaults;
use OCA\OpenRegister\Service\Party\PartySearchInterface;
use OCA\OpenRegister\Service\Party\PartySearchService;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\Task\TaskCalendarDocument;
use OCA\OpenRegister\Service\Task\TaskCalendarDocumentInterface;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\TaskService;
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
	 * Aliases registered, keyed by the contract.
	 *
	 * @var array<string, string>
	 */
	private array $aliases = [];

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
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target): void {
				$this->aliases[$alias] = $target;
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
	 * Exactly these services are registered, in order.
	 *
	 * @return void
	 */
	public function testItRegistersTheServicesInOrder(): void {
		self::assertSame(
			expected: [
				FlowRunAuthorization::class,
				FlowRunnableGuard::class,
				FlowRunContext::class,
				FlowStepDispatcher::class,
				LifecycleActionContext::class,
				AutoTransitionPass::class,
				TaskInboxService::class,
				PortalPartyResolver::class,
				TaskService::class,
			],
			actual: array_keys($this->factories)
		);
	}//end testItRegistersTheServicesInOrder()

	/**
	 * The run authorization is built on the container's flow access.
	 *
	 * @return void
	 */
	public function testTheFieldRulesPortIsAnsweredByTheStateResolver(): void {
		self::assertSame(expected: StateFieldRuleResolver::class, actual: ($this->aliases[FieldRulesSourceInterface::class] ?? null));
		self::assertTrue(condition: is_a(StateFieldRuleResolver::class, FieldRulesSourceInterface::class, true));
	}//end testTheFieldRulesPortIsAnsweredByTheStateResolver()

	/**
	 * The Core current-run port is an alias of the one shared attribution stack, never a second instance.
	 *
	 * @return void
	 */
	public function testTheCurrentRunPortIsTheSharedRunContext(): void {
		self::assertSame(expected: FlowRunContext::class, actual: ($this->aliases[CurrentFlowRunInterface::class] ?? null));
		self::assertTrue(condition: is_a(FlowRunContext::class, CurrentFlowRunInterface::class, true));
		self::assertArrayHasKey(key: FlowRunContext::class, array: $this->factories);
	}//end testTheCurrentRunPortIsTheSharedRunContext()

	/**
	 * The Core expression-defaults port is answered by the rules engine's implementation.
	 *
	 * @return void
	 */
	public function testTheExpressionDefaultsPortIsAnsweredByTheRulesEngine(): void {
		self::assertSame(expected: ExpressionDefaults::class, actual: ($this->aliases[ExpressionDefaultsInterface::class] ?? null));
		self::assertTrue(condition: is_a(ExpressionDefaults::class, ExpressionDefaultsInterface::class, true));
	}//end testTheExpressionDefaultsPortIsAnsweredByTheRulesEngine()

	/**
	 * The schedule flow store and the area's own interfaces are bound, and only these aliases are registered.
	 *
	 * @return void
	 */
	public function testTheScheduleFlowStoreIsBound(): void {
		self::assertSame(
			expected: [
				CurrentFlowRunInterface::class             => FlowRunContext::class,
				FieldRulesSourceInterface::class           => StateFieldRuleResolver::class,
				ScheduleFlowStoreInterface::class          => ScheduleFlowStore::class,
				FlowRunSignalInterface::class              => FlowRunSignalService::class,
				LockObjectNodeInterface::class             => LockObjectNode::class,
				PrincipalKindsInterface::class             => PrincipalKinds::class,
				CalendarRecomputeInterface::class          => CalendarRecompute::class,
				TermDiagnosticInterface::class             => TermDiagnostic::class,
				WorkingCalendarDefinitionsInterface::class => WorkingCalendars::class,
				TaskCalendarDocumentInterface::class       => TaskCalendarDocument::class,
				PartySearchInterface::class                => PartySearchService::class,
				FlowConsentSweepInterface::class           => FlowConsentParking::class,
				ExpressionDefaultsInterface::class         => ExpressionDefaults::class,
			],
			actual: $this->aliases
		);
		self::assertSame(expected: FlowWiring::BINDINGS, actual: FlowRegistrar::BINDINGS);
	}//end testTheScheduleFlowStoreIsBound()

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
