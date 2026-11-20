<?php

/**
 * Unit tests for FlowWiring.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Kernel\Contracts\Core\LifecycleStatesKeeperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectQuotaInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\PartyParentInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleStatesKeeper;
use OCA\OpenRegister\Service\Party\PartyParent;
use OCA\OpenRegister\Kernel\Contracts\Core\UnreadCountsInterface;
use OCA\OpenRegister\Service\Interaction\UnreadCountCalculator;
use OCA\OpenRegister\Service\Quota\ObjectQuotaService;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStoreInterface;
use OCA\OpenRegister\Kernel\Contracts\Compliance\TransitionInFlightInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\CurrentFlowRunInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ExpressionDefaultsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ExpressionEvaluatorInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FieldRulesSourceInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\UserCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\Integrations\TaskGaugeInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\WriteElevationInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\PartyRecipientsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\PublicationRefusalInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SchemaFormDependentsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SchemaRenameWarningsInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\NotificationRunRecorderInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\ObjectWatchersInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\TaskRowSourceInterface;
use OCA\OpenRegister\Service\AutomationRecordWriter;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Kernel\Contracts\Integrations\ObjectCalendarLinkInterface;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Kernel\Contracts\Integrations\BuiltInToolProvidersInterface;
use OCA\OpenRegister\Mcp\BuiltIn\FlowBuiltInToolProviders;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
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
use OCA\OpenRegister\Search\FlowTasksProvider;
use OCA\OpenRegister\Service\Flow\FlowTriggerRenameCheck;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalKinds;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalKindsInterface;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCA\OpenRegister\Service\Flow\RegistryStepDispatcher;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecompute;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecomputeInterface;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnosticInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinitionsInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendars;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionWriteElevation;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Party\PartyRecipients;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Rules\ExpressionDefaults;
use OCA\OpenRegister\Service\Party\PartySearchInterface;
use OCA\OpenRegister\Service\Party\PartySearchService;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\Task\TaskCalendarDocument;
use OCA\OpenRegister\Service\Task\TaskCalendarDocumentInterface;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskMetricsProvider;
use OCA\OpenRegister\Service\TaskService;
use OCA\OpenRegister\Service\FlowLinkService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Integrations\ObjectDateEventsSourceInterface;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use OCA\OpenRegister\Kernel\Contracts\Compliance\LifecycleTransitionInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;

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
	 * Search providers registered, in order.
	 *
	 * @var array<int, string>
	 */
	private array $searchProviders = [];

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
		$context->method('registerSearchProvider')->willReturnCallback(
			function (string $class): void {
				$this->searchProviders[] = $class;
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
				FlowLinkService::class,
			],
			actual: array_keys($this->factories)
		);
	}//end testItRegistersTheServicesInOrder()

	/**
	 * The task row port of the notifier (Notifications) is answered by the task inbox (Processes).
	 *
	 * @return void
	 */
	public function testTheTaskRowPortIsAnsweredByTheTaskInbox(): void {
		self::assertSame(expected: TaskInboxService::class, actual: ($this->aliases[TaskRowSourceInterface::class] ?? null));
		self::assertTrue(condition: is_a(TaskInboxService::class, TaskRowSourceInterface::class, true));
	}//end testTheTaskRowPortIsAnsweredByTheTaskInbox()

	/**
	 * The watcher and run-record ports of the notification dispatcher (Notifications) are answered by Processes.
	 *
	 * @return void
	 */
	public function testTheNotificationPortsAreAnsweredByProcesses(): void {
		self::assertSame(expected: WatcherService::class, actual: ($this->aliases[ObjectWatchersInterface::class] ?? null));
		self::assertSame(expected: AutomationRecordWriter::class, actual: ($this->aliases[NotificationRunRecorderInterface::class] ?? null));
		self::assertTrue(condition: is_a(WatcherService::class, ObjectWatchersInterface::class, true));
		self::assertTrue(condition: is_a(AutomationRecordWriter::class, NotificationRunRecorderInterface::class, true));
	}//end testTheNotificationPortsAreAnsweredByProcesses()

	/**
	 * The flow tasks search provider is registered here, by the component that owns it.
	 *
	 * @return void
	 */
	public function testItRegistersTheFlowTasksSearchProvider(): void {
		self::assertSame(expected: [FlowTasksProvider::class], actual: $this->searchProviders);
	}//end testItRegistersTheFlowTasksSearchProvider()

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
	 * The render's expression port is answered by the calculation evaluator, through its own interface.
	 *
	 * @return void
	 */
	public function testTheExpressionPortIsAnsweredByTheCalculationEvaluator(): void {
		self::assertSame(expected: CalculationEvaluator::class, actual: (FlowWiring::BINDINGS[ExpressionEvaluatorInterface::class] ?? null));
		self::assertTrue(condition: is_a(CalculationEvaluatorInterface::class, ExpressionEvaluatorInterface::class, true));
		self::assertTrue(condition: is_a(CalculationEvaluator::class, ExpressionEvaluatorInterface::class, true));
	}//end testTheExpressionPortIsAnsweredByTheCalculationEvaluator()

	/**
	 * The Core current-run port is answered by the shared run context, so the delete guard reads the one ambient stack.
	 *
	 * @return void
	 */
	public function testTheCurrentRunPortIsAnsweredByTheSharedRunContext(): void {
		self::assertSame(expected: FlowRunContext::class, actual: ($this->aliases[CurrentFlowRunInterface::class] ?? null));
		self::assertArrayHasKey(key: FlowRunContext::class, array: $this->factories);
		self::assertTrue(condition: is_a(FlowRunContext::class, CurrentFlowRunInterface::class, true));
	}//end testTheCurrentRunPortIsAnsweredByTheSharedRunContext()

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
	 * The schedule flow store, the current-run port, the calendar link, user calendar events, task gauge, transition-in-flight, write elevation,
	 * date events, task row, watcher and run-record ports, the schema rename and form dependents checks, the built-in tool providers,
	 * the lifecycle transition and expression-defaults ports and the area's own interfaces are bound, and only these aliases are registered.
	 *
	 * @return void
	 */
	public function testTheScheduleFlowStoreIsBound(): void {
		self::assertSame(
			expected: [
				FieldRulesSourceInterface::class           => StateFieldRuleResolver::class,
				ObjectCalendarLinkInterface::class         => CalendarEventService::class,
				CurrentFlowRunInterface::class             => FlowRunContext::class,
				ScheduleFlowStoreInterface::class          => ScheduleFlowStore::class,
				UserCalendarEventsInterface::class         => CalendarEventService::class,
				TaskGaugeInterface::class                  => TaskMetricsProvider::class,
				TransitionInFlightInterface::class         => LifecycleActionContext::class,
				WriteElevationInterface::class             => ObjectActionWriteElevation::class,
				ObjectDateEventsSourceInterface::class     => ObjectCalendarFeedService::class,
				TaskRowSourceInterface::class              => TaskInboxService::class,
				ObjectWatchersInterface::class             => WatcherService::class,
				NotificationRunRecorderInterface::class    => AutomationRecordWriter::class,
				SchemaRenameWarningsInterface::class       => FlowTriggerRenameCheck::class,
				SchemaFormDependentsInterface::class       => FormDependentsCheck::class,
				BuiltInToolProvidersInterface::class       => FlowBuiltInToolProviders::class,
				ExpressionEvaluatorInterface::class        => CalculationEvaluator::class,
				PartyRecipientsInterface::class            => PartyRecipients::class,
				PublicationRefusalInterface::class         => PartyIndicatorGuard::class,
				FlowRunSignalInterface::class              => FlowRunSignalService::class,
				LockObjectNodeInterface::class             => LockObjectNode::class,
				PrincipalKindsInterface::class             => PrincipalKinds::class,
				CalendarRecomputeInterface::class          => CalendarRecompute::class,
				TermDiagnosticInterface::class             => TermDiagnostic::class,
				WorkingCalendarDefinitionsInterface::class => WorkingCalendars::class,
				TaskCalendarDocumentInterface::class       => TaskCalendarDocument::class,
				PartySearchInterface::class                => PartySearchService::class,
				FlowConsentSweepInterface::class           => FlowConsentParking::class,
				LifecycleTransitionInterface::class        => TransitionEngine::class,
				ExpressionDefaultsInterface::class         => ExpressionDefaults::class,
				ObjectQuotaInterface::class                => ObjectQuotaService::class,
				PartyParentInterface::class                => PartyParent::class,
				LifecycleStatesKeeperInterface::class      => LifecycleStatesKeeper::class,
				UnreadCountsInterface::class               => UnreadCountCalculator::class,
			],
			actual: $this->aliases
		);
		self::assertSame(expected: FlowWiring::BINDINGS, actual: FlowRegistrar::BINDINGS);
	}//end testTheScheduleFlowStoreIsBound()

	/**
	 * The MCP discovery's port for contributed built-in tool providers is answered by the flow tools.
	 *
	 * @return void
	 */
	public function testTheBuiltInToolPortIsAnsweredByTheFlowTools(): void {
		self::assertSame(expected: FlowBuiltInToolProviders::class, actual: ($this->aliases[BuiltInToolProvidersInterface::class] ?? null));
		self::assertTrue(condition: is_a(FlowBuiltInToolProviders::class, BuiltInToolProvidersInterface::class, true));
	}//end testTheBuiltInToolPortIsAnsweredByTheFlowTools()

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
