<?php

/**
 * The flow service registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStoreInterface;
use OCA\OpenRegister\Contract\ObjectTaskReaderInterface;
use OCA\OpenRegister\Db\FlowLinkMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\FacetWeekNumberingInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\BulkRowRefusalsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\DependentValueCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ContactMatchCacheInterface;
use OCA\OpenRegister\Kernel\Contracts\Compliance\LifecycleFinalStatesInterface;
use OCA\OpenRegister\Kernel\Contracts\Compliance\LifecycleTransitionInterface;
use OCA\OpenRegister\Kernel\Contracts\Compliance\TransitionInFlightInterface;
use OCA\OpenRegister\Service\Object\FacetWeekNumbering;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use OCA\OpenRegister\Kernel\Contracts\Core\ApprovalPositionFormCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\CurrentFlowRunInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ExpressionDefaultsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ExpressionEvaluatorInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FieldRulesSourceInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectUnreadMarkerInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectWatchMarkersInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RunnableActionsInterface;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCA\OpenRegister\Kernel\Contracts\Core\NodeRightsMapInterface;
use OCA\OpenRegister\Kernel\Contracts\Integrations\LinkedFlowOperationsInterface;
use OCA\OpenRegister\Service\FlowLinkServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ProcessingReadsInterface;
use OCA\OpenRegister\Listener\DependentValueListener;
use OCA\OpenRegister\Service\Dmn\DecisionTableBulkGuard;
use OCA\OpenRegister\Service\FlowLinkPlatform;
use OCA\OpenRegister\Service\FlowLinkService;
use OCA\OpenRegister\Service\Task\ObjectTaskReader;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Kernel\Contracts\Core\UserCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\Integrations\TaskGaugeInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ReadHistoryInterface;
use OCA\OpenRegister\Kernel\Contracts\RecentLensInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SaveBoundaryInterface;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Kernel\Contracts\Core\WriteElevationInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionWriteElevation;
use OCA\OpenRegister\Kernel\Contracts\Compliance\ElevationAttributionInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationAttribution;
use OCA\OpenRegister\Kernel\Contracts\Integrations\ObjectDateEventsSourceInterface;
use OCA\OpenRegister\Search\FlowTasksProvider;
use OCA\OpenRegister\Kernel\Contracts\Core\PublicationRefusalInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SchemaFormDependentsInterface;
use OCA\OpenRegister\Kernel\Contracts\FlowNamingDocumentsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SchemaRenameWarningsInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\NotificationRunRecorderInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\ObjectWatchersInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\TaskRowSourceInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\OverdueTaskSourceInterface;
use OCA\OpenRegister\Service\Task\OverdueOpenTasks;
use OCA\OpenRegister\Service\AutomationRecordWriter;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCA\OpenRegister\Kernel\Contracts\Integrations\ObjectCalendarLinkInterface;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Kernel\Contracts\Integrations\BuiltInToolProvidersInterface;
use OCA\OpenRegister\Mcp\BuiltIn\FlowBuiltInToolProviders;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner;
use OCA\OpenRegister\Service\Flow\Nodes\CodeNode;
use OCA\OpenRegister\Service\Flow\Nodes\CodeNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\AuditTrailNode;
use OCA\OpenRegister\Service\Flow\Nodes\AuditTrailNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ExtendInputNode;
use OCA\OpenRegister\Service\Flow\Nodes\ExtendInputNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SurveyInviteNode;
use OCA\OpenRegister\Service\Flow\Nodes\SurveyInviteNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TagObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\TagObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalKinds;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalKindsInterface;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecompute;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecomputeInterface;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnosticInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinitionsInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendars;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleFinalStateResolver;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Party\PartySearchInterface;
use OCA\OpenRegister\Service\Party\PartySearchService;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\Rules\ExpressionDefaults;
use OCA\OpenRegister\Service\SequenceService;
use OCA\OpenRegister\Service\SequenceServiceInterface;
use OCA\OpenRegister\Service\Task\ApprovalPositionFormGuard;
use OCA\OpenRegister\Service\Task\TaskCalendarDocument;
use OCA\OpenRegister\Service\Task\TaskCalendarDocumentInterface;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskMetricsProvider;
use OCA\OpenRegister\Service\TaskService;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\App\IAppManager;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectQuotaInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\UnreadCountsInterface;
use OCA\OpenRegister\Service\Interaction\UnreadCountCalculator;
use OCA\OpenRegister\Service\Quota\ObjectQuotaService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The wiring behind FlowRegistrar.
 *
 * FlowRegistrar keeps the public entry points and delegates each of them here.
 *
 * Registers the flow run guards, the shared run context, the loop step dispatcher,
 * the lifecycle's answer to property-level access, the store of the flows
 * compiled from application schedules, the object date events the platform
 * calendar reads, the party indicators' answer to file publishing, the
 * lifecycle's request state and the task services that are built by hand.
 * compiled from application schedules, the party indicators' answer to file
 * publishing, the lifecycle's request state, the task services that are
 * built by hand and the flow link service.
 *
 * Application::register() calls register() once.
 */
final class FlowWiring {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * These are the Processes area's own interfaces: an area binds its own
	 * classes, so no lower area's map names them.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		ObjectCalendarLinkInterface::class => CalendarEventService::class,
		CurrentFlowRunInterface::class => FlowRunContext::class,
		ScheduleFlowStoreInterface::class => ScheduleFlowStore::class,
		SequenceServiceInterface::class => SequenceService::class,
		NodeRightsMapInterface::class => FlowNodeRightsGuard::class,
		ProcessingReadsInterface::class => ReadHistoryService::class,
		UserCalendarEventsInterface::class => CalendarEventService::class,
		TaskGaugeInterface::class => TaskMetricsProvider::class,
		LifecycleFinalStatesInterface::class => LifecycleFinalStateResolver::class,
		ReadHistoryInterface::class       => ReadHistoryService::class,
		RecentLensInterface::class        => ReadHistoryService::class,
		TransitionInFlightInterface::class => LifecycleActionContext::class,
		WriteElevationInterface::class    => ObjectActionWriteElevation::class,
		ObjectDateEventsSourceInterface::class => ObjectCalendarFeedService::class,
		TaskRowSourceInterface::class => TaskInboxService::class,
		OverdueTaskSourceInterface::class => OverdueOpenTasks::class,
		ObjectWatchersInterface::class => WatcherService::class,
		NotificationRunRecorderInterface::class => AutomationRecordWriter::class,
		SchemaRenameWarningsInterface::class => FlowTriggerRenameCheck::class,
		FlowNamingDocumentsInterface::class => FlowNamingDocuments::class,
		SchemaFormDependentsInterface::class => FormDependentsCheck::class,
		ApprovalPositionFormCheckInterface::class => ApprovalPositionFormGuard::class,
		BuiltInToolProvidersInterface::class => FlowBuiltInToolProviders::class,
		ExpressionEvaluatorInterface::class => CalculationEvaluator::class,
		\OCA\OpenRegister\Kernel\Contracts\Notifications\PartyRecipientsInterface::class => \OCA\OpenRegister\Service\Party\PartyRecipients::class,
		PublicationRefusalInterface::class => PartyIndicatorGuard::class,
		FlowRunSignalInterface::class => FlowRunSignalService::class,
		LockObjectNodeInterface::class => LockObjectNode::class,
		CodeNodeInterface::class => CodeNode::class,
		CodeRunnerClientInterface::class => CodeRunnerClient::class,
		TagObjectNodeInterface::class => TagObjectNode::class,
		SurveyInviteNodeInterface::class => SurveyInviteNode::class,
		AuditTrailNodeInterface::class => AuditTrailNode::class,
		ExtendInputNodeInterface::class => ExtendInputNode::class,
		PrincipalKindsInterface::class => PrincipalKinds::class,
		CalendarRecomputeInterface::class => CalendarRecompute::class,
		FacetWeekNumberingInterface::class => FacetWeekNumbering::class,
		TermDiagnosticInterface::class => TermDiagnostic::class,
		WorkingCalendarDefinitionsInterface::class => WorkingCalendars::class,
		TaskCalendarDocumentInterface::class => TaskCalendarDocument::class,
		PartySearchInterface::class => PartySearchService::class,
		FlowConsentSweepInterface::class => FlowConsentParking::class,
		LinkedFlowOperationsInterface::class => FlowLinkServiceInterface::class,
		LifecycleTransitionInterface::class => TransitionEngine::class,
		ExpressionDefaultsInterface::class => ExpressionDefaults::class,
		ObjectWatchMarkersInterface::class => WatcherService::class,
		ObjectUnreadMarkerInterface::class => ReadStateService::class,
		RunnableActionsInterface::class => ObjectActionList::class,
		BulkRowRefusalsInterface::class => DecisionTableBulkGuard::class,
		DependentValueCheckInterface::class => DependentValueListener::class,
		ContactMatchCacheInterface::class => ContactMatchingService::class,
		ObjectQuotaInterface::class => ObjectQuotaService::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\PartyParentInterface::class => \OCA\OpenRegister\Service\Party\PartyParent::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\LifecycleStatesKeeperInterface::class => \OCA\OpenRegister\Service\Lifecycle\LifecycleStatesKeeper::class,
		UnreadCountsInterface::class => UnreadCountCalculator::class,
		SaveBoundaryInterface::class => AutoTransitionPass::class,
		ElevationAttributionInterface::class => ObjectActionElevationAttribution::class,
		\OCA\OpenRegister\Kernel\Contracts\BusinessTimeInterface::class => \OCA\OpenRegister\Service\Flow\Timer\BusinessTime::class,
		\OCA\OpenRegister\Kernel\Contracts\SaveWarningsInterface::class => \OCA\OpenRegister\Service\Rules\SaveWarnings::class,
		\OCA\OpenRegister\Service\Flow\Nodes\TriggerCalendarNodeInterface::class => \OCA\OpenRegister\Service\Flow\Nodes\TriggerCalendarNode::class,
		// The published read of the tasks on an object (lib/Contract), for consuming apps.
		ObjectTaskReaderInterface::class => ObjectTaskReader::class,
	];

	/**
	 * Register the flow run guards, the run context, the step dispatcher, the field rules source, the bindings and the flow tasks search provider.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		self::registerRunAuthorization(context: $context);
		self::registerRunnableGuard(context: $context);
		self::registerRunContext(context: $context);
		self::registerStepDispatcher(context: $context);
		self::registerFieldRulesSource(context: $context);
		foreach (self::BINDINGS as $contract => $service) {
			$context->registerServiceAlias($contract, $service);
		}

		self::registerLifecycleState(context: $context);
		self::registerTaskServices(context: $context);
		$context->registerService(FlowLinkService::class, self::buildFlowLinkService(...));

		// The flow tasks the searcher would see in their inbox (flow-task-search-provider).
		$context->registerSearchProvider(FlowTasksProvider::class);
	}//end register()

	/**
	 * Register the run authorization explicitly, so a container that cannot build it fails loudly.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerRunAuthorization(IRegistrationContext $context): void {
		// 🔴 THE RUN AUTHORIZATION IS REGISTERED EXPLICITLY, because its
		// failure mode is total. `FlowService` takes it as a NULLABLE argument
		// and an absent one is UNDECIDABLE, which refuses every run — correct
		// for a security control, and an outage if the container quietly
		// declined to build it. A named registration turns that into a loud
		// container error instead of a fleet of refusals nobody can explain
		// (change `flow-runs-honour-their-declaration`).
		$context->registerService(
			FlowRunAuthorization::class,
			static function ($c) {
				return new FlowRunAuthorization(
					access: $c->get(\OCA\OpenRegister\Service\Flow\FlowAccess::class),
				);
			}
		);
	}//end registerRunAuthorization()

	/**
	 * Register the run and edit guard explicitly, so a container that cannot build it fails loudly.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerRunnableGuard(IRegistrationContext $context): void {
		// 🔴 THE RUN AND EDIT GUARD IS REGISTERED EXPLICITLY, for the reason
		// directly above. Both of its collaborators are nullable and both
		// absences fail CLOSED, so a container that quietly declined to build
		// one of them would refuse every run and every test run with no error
		// anywhere saying why. A named registration turns that into a loud
		// container error instead.
		$context->registerService(
			FlowRunnableGuard::class,
			static function ($c) {
				return new FlowRunnableGuard(
					flows: $c->get(\OCA\OpenRegister\Service\Flow\FlowService::class),
					access: $c->get(\OCA\OpenRegister\Service\Flow\FlowAccess::class),
				);
			}
		);
	}//end registerRunnableGuard()

	/**
	 * Register the flow-attribution stack as one shared instance.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerRunContext(IRegistrationContext $context): void {
		// The ambient flow-attribution stack MUST be shared. Nextcloud's
		// container builds an auto-wired class fresh at every injection point,
		// so without this registration the engine would push frames onto one
		// instance and the audit mapper would read an empty stack on another —
		// attributing nothing at all, silently and with no error anywhere.
		$context->registerService(
			FlowRunContext::class,
			function () {
				return new FlowRunContext();
			}
		);
	}//end registerRunContext()

	/**
	 * Bind the loop node's step dispatcher to a factory.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerStepDispatcher(IRegistrationContext $context): void {
		// The loop node's step dispatcher. `IterateNode` resolves the INTERFACE
		// — correctly, it should not know which dispatcher runs its body — but
		// nothing ever bound it, and an interface cannot be instantiated. So
		// every repeat step failed the moment it executed:
		//
		// > Could not resolve …\FlowStepDispatcher! Class can not be instantiated
		//
		// The node validated, appeared in the palette and drew on the canvas
		// throughout, which is why this survived: nothing short of RUNNING a
		// flow that contains one can see it, and no flow on the instance did.
		// Registered as an explicit FACTORY, not an alias. An alias makes the
		// container autowire RegistryStepDispatcher, and its second parameter is
		// a FlowRunGuard whose own constructor takes a `string $runUuid` — which
		// the container cannot invent. Autowiring it therefore died with
		// "Could not resolve runUuid!" on the first repeat step that ran, and a
		// nullable default does not help: the container resolves a typed
		// parameter before it considers the default.
		//
		// The guard is per-RUN state and cannot come from the container at all.
		// A container-built dispatcher gets none, and picks one up from the run
		// context at dispatch time instead.
		$context->registerService(
			\OCA\OpenRegister\Service\Flow\FlowStepDispatcher::class,
			static function ($c) {
				return new RegistryStepDispatcher(
					registry: $c->get(\OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface::class),
					// The acting-identity scope IS container state, unlike the
					// guard: a loop's body steps must scope a contributed node
					// to the run's runAs exactly as top-level steps do.
					scope: $c->get(\OCA\OpenRegister\Service\Flow\FlowRunAsScope::class)
				);
			}
		);
	}//end registerStepDispatcher()

	/**
	 * Answer property-level access's field rules port with the lifecycle state resolver.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private static function registerFieldRulesSource(IRegistrationContext $context): void {
		$context->registerServiceAlias(FieldRulesSourceInterface::class, StateFieldRuleResolver::class);
	}//end registerFieldRulesSource()

	/**
	 * Register the lifecycle's request state: the named-transition context and the automatic-transition pass.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function registerLifecycleState(IRegistrationContext $context): void {
		// The named-transition context MUST be shared, like every piece of
		// request state: TransitionEngine declares the action on one instance and the
		// lifecycle listeners read it on another. Unshared, every named call
		// would silently fall back to matching by from/to value.
		$context->registerService(
			LifecycleActionContext::class,
			function () {
				return new LifecycleActionContext();
			}
		);

		// The automatic-transition pass MUST be shared, and for a sharper
		// reason: it IS the request's state. The listener records
		// into it, ObjectService and TransitionEngine open and close boundaries
		// on it, and the drain reads the lineage the boundary counted. Auto-
		// wired fresh at each injection point, every one of those would hold its
		// own counter: nothing would ever reach depth zero with a record in it,
		// so no automatic transition would ever fire, silently and with no
		// error anywhere.
		$context->registerService(AutoTransitionPass::class, self::buildAutoTransitionPass(...));
	}//end registerLifecycleState()

	/**
	 * Register the task services that are built by hand.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function registerTaskServices(IRegistrationContext $context): void {
		// The task inbox and the portal party resolver read the object store
		// through AbstractObjectMapper, which the autowirer cannot build (it is
		// abstract) and would silently default to null: an inbox row without
		// subject context, and a portal task that can match nobody. Both are
		// wired to the MagicMapper explicitly for that reason.
		$context->registerService(TaskInboxService::class, self::buildTaskInboxService(...));

		$context->registerService(
			PortalPartyResolver::class,
			function (ContainerInterface $container) {
				return new PortalPartyResolver(
					objects: $container->get(MagicMapperInterface::class)
				);
			}
		);

		$context->registerService(TaskService::class, self::buildTaskService(...));
	}//end registerTaskServices()

	/**
	 * Build the AutoTransitionPass service.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return AutoTransitionPass
	 */
	private static function buildAutoTransitionPass(ContainerInterface $container): AutoTransitionPass {
			return new AutoTransitionPass(
				runner: $container->get(AutoTransitionRunner::class),
				logger: $container->get(LoggerInterface::class)
			);
	}//end buildAutoTransitionPass()

	/**
	 * Build the TaskInboxService service.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return TaskInboxService
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 *       (the blocker mapper answers the derived `blocked` flag of each row)
	 */
	private static function buildTaskInboxService(ContainerInterface $container): TaskInboxService {
			return new TaskInboxService(
				tasks: $container->get(\OCA\OpenRegister\Db\TaskMapper::class),
				logger: $container->get('Psr\Log\LoggerInterface'),
				objects: $container->get(MagicMapperInterface::class),
				deliveries: $container->get(\OCA\OpenRegister\Db\PortalTaskDeliveryMapper::class),
				// Reads a located task's subject from its own table instead
				// of searching every magic table for it.
				locator: $container->get(\OCA\OpenRegister\Service\Task\TaskSubjectLocator::class),
				// Answers which blockers are still open, for the derived
				// `blocked` flag (a-task-may-wait-on-another-task 2.1).
				// Without it every row read blocked: false.
				blockers: $container->get(\OCA\OpenRegister\Db\TaskBlockerMapperInterface::class),
				// The per-row `can` list (record-tasks-tab D-4), asked of the
				// same authorization service every verb's endpoint asks.
				verbs: $container->get(\OCA\OpenRegister\Service\Task\TaskVerbList::class),
				// Marks rows whose user assignee is away (assigneeAbsent,
				// absentUntil), tasks-delegation-and-substitution task 3.4.
				substitution: $container->get(\OCA\OpenRegister\Service\Task\TaskSubstitution::class)
			);
	}//end buildTaskInboxService()

	/**
	 * Build the FlowLinkService with its platform bundle.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return FlowLinkService
	 *
	 * @spec openspec/specs/integration-flow/spec.md
	 */
	private static function buildFlowLinkService(ContainerInterface $container): FlowLinkService {
		return new FlowLinkService(
			platform: new FlowLinkPlatform(
				flowLinkMapper: $container->get(FlowLinkMapperInterface::class),
				db: $container->get(IDBConnection::class),
				appManager: $container->get(IAppManager::class)
			),
			userSession: $container->get(IUserSession::class),
			groupManager: $container->get(IGroupManager::class),
			logger: $container->get(LoggerInterface::class)
		);
	}//end buildFlowLinkService()

	/**
	 * Build the TaskService service.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return TaskService
	 */
	private static function buildTaskService(ContainerInterface $container): TaskService {
			// The write-back gate is resolved lazily and best-effort: without
			// it a PROJECTED VTODO's update is refused (fail closed), and
			// standalone VTODOs are unaffected either way.
			$gate = null;
			try {
				$gate = $container->get(\OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate::class);
			} catch (\Throwable $e) {
				$container->get('Psr\Log\LoggerInterface')->debug(
					'[Application] TaskVtodoWriteBackGate unavailable for TaskService: ' . $e->getMessage()
				);
			}

			return new TaskService(
				calDavBackend: $container->get('OCA\DAV\CalDAV\CalDavBackend'),
				userSession: $container->get('OCP\IUserSession'),
				logger: $container->get('Psr\Log\LoggerInterface'),
				urlGenerator: $container->get('OCP\IURLGenerator'),
				gate: $gate
			);
	}//end buildTaskService()
}//end class
