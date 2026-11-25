<?php

/**
 * ProcessesServiceBindings: the map of the Processes area's service interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Kernel\Contracts\IdempotentAnswerStoreInterface;
use OCA\OpenRegister\Kernel\Contracts\ContactMatcherInterface;
use OCA\OpenRegister\Kernel\Contracts\FlowTimerControlInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use OCA\OpenRegister\Service\BulkJob\BulkJobReversal;
use OCA\OpenRegister\Service\BulkJob\BulkJobReversalInterface;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCA\OpenRegister\Service\BulkJob\BulkJobServiceInterface;
use OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidator;
use OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolverInterface;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilderInterface;
use OCA\OpenRegister\Service\Calculation\CalculationTrialService;
use OCA\OpenRegister\Service\Calculation\CalculationTrialServiceInterface;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogue;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogueInterface;
use OCA\OpenRegister\Service\Calculation\PropertyCalculations;
use OCA\OpenRegister\Service\Calculation\PropertyCalculationsInterface;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Calculation\ReferenceResolverInterface;
use OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresher;
use OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresherInterface;
use OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepService;
use OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepServiceInterface;
use OCA\OpenRegister\Service\Calendar\AppointmentAttendeeService;
use OCA\OpenRegister\Service\Calendar\AppointmentAttendeeServiceInterface;
use OCA\OpenRegister\Service\Calendar\CalendarFeedTokenService;
use OCA\OpenRegister\Service\Calendar\CalendarFeedTokenServiceInterface;
use OCA\OpenRegister\Service\Case\CasePlanAuthorizationService;
use OCA\OpenRegister\Service\Case\CasePlanAuthorizationServiceInterface;
use OCA\OpenRegister\Service\Case\CasePlanItemTimers;
use OCA\OpenRegister\Service\Case\CasePlanItemTimersInterface;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Case\CasePlanServiceInterface;
use OCA\OpenRegister\Service\Case\CasePlanTimerRearm;
use OCA\OpenRegister\Service\Case\CasePlanTimerRearmInterface;
use OCA\OpenRegister\Service\Case\Nodes\CaseAdvanceNode;
use OCA\OpenRegister\Service\Case\Nodes\CaseAdvanceNodeInterface;
use OCA\OpenRegister\Service\Case\Nodes\CaseOpenNode;
use OCA\OpenRegister\Service\Case\Nodes\CaseOpenNodeInterface;
use OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapper;
use OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapperInterface;
use OCA\OpenRegister\Service\Decision\DecisionVisibility;
use OCA\OpenRegister\Service\Decision\DecisionVisibilityInterface;
use OCA\OpenRegister\Service\Decision\RelationDecisionService;
use OCA\OpenRegister\Service\Decision\RelationDecisionServiceInterface;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidatorInterface;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnInterchange;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnInterchangeInterface;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\EventCatalogServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowAccessInterface;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPinInterface;
use OCA\OpenRegister\Service\Flow\FlowDryRunService;
use OCA\OpenRegister\Service\Flow\FlowDryRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowLocatorInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeCatalogueInterface;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodePreflightInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuard;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuardInterface;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraphInterface;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancerInterface;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorization;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorizationInterface;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunContextInterface;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationService;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowRunSubjects;
use OCA\OpenRegister\Service\Flow\FlowRunSubjectsInterface;
use OCA\OpenRegister\Service\Flow\FlowRunVersionPin;
use OCA\OpenRegister\Service\Flow\FlowRunVersionPinInterface;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuardInterface;
use OCA\OpenRegister\Service\Flow\FlowScheduleService;
use OCA\OpenRegister\Service\Flow\FlowScheduleServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersion;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersionInterface;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\FlowTaskBridgeInterface;
use OCA\OpenRegister\Service\Flow\FlowTenantMembership;
use OCA\OpenRegister\Service\Flow\FlowTenantMembershipInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndexInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use OCA\OpenRegister\Service\Flow\FlowTriggerServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerSlugs;
use OCA\OpenRegister\Service\Flow\FlowTriggerSlugsInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidator;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidatorInterface;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ActionInputsNode;
use OCA\OpenRegister\Service\Flow\Nodes\ActionInputsNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode;
use OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNode;
use OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\EndNode;
use OCA\OpenRegister\Service\Flow\Nodes\EndNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode;
use OCA\OpenRegister\Service\Flow\Nodes\ExplodeNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\FilterNode;
use OCA\OpenRegister\Service\Flow\Nodes\FilterNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNode;
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\IterateNode;
use OCA\OpenRegister\Service\Flow\Nodes\IterateNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\LinkObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\LinkObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNode;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\MapNode;
use OCA\OpenRegister\Service\Flow\Nodes\MapNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\MergeNode;
use OCA\OpenRegister\Service\Flow\Nodes\MergeNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\RouterNode;
use OCA\OpenRegister\Service\Flow\Nodes\RouterNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNode;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TransitionNode;
use OCA\OpenRegister\Service\Flow\Nodes\TransitionNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\UnlockObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\UnlockObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNode;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNodeInterface;
use OCA\OpenRegister\Service\Flow\Oversight\KillSwitchCheck;
use OCA\OpenRegister\Service\Flow\Oversight\KillSwitchCheckInterface;
use OCA\OpenRegister\Service\Flow\Oversight\OrganisationHaltCheck;
use OCA\OpenRegister\Service\Flow\Oversight\OrganisationHaltCheckInterface;
use OCA\OpenRegister\Service\Flow\Oversight\OrganisationHaltService;
use OCA\OpenRegister\Service\Flow\Oversight\OrganisationHaltServiceInterface;
use OCA\OpenRegister\Service\Flow\Principal\AgentPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\AgentPrincipalResolverInterface;
use OCA\OpenRegister\Service\Flow\Principal\GroupPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\GroupPrincipalResolverInterface;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistryInterface;
use OCA\OpenRegister\Service\Flow\Principal\UserPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\UserPrincipalResolverInterface;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweep;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweepInterface;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarWriteGuard;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarWriteGuardInterface;
use OCA\OpenRegister\Service\Form\FormDefinitionResolver;
use OCA\OpenRegister\Service\Form\FormDefinitionResolverInterface;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCA\OpenRegister\Service\Form\FormDependentsCheckInterface;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormDestinationValidatorInterface;
use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCA\OpenRegister\Service\Form\FormIdempotencyStoreInterface;
use OCA\OpenRegister\Service\Form\IdempotentAnswerStore;
use OCA\OpenRegister\Service\Form\FormSubmitService;
use OCA\OpenRegister\Service\Form\FormSubmitServiceInterface;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Service\Form\FormUploadStoreInterface;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluator;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluatorInterface;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPassInterface;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelector;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelectorInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContextInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutorInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluatorInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistryInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolver;
use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolverInterface;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluatorInterface;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolverInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulk;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulkInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitionInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitionsInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionHistory;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionHistoryInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionListInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunnerInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidatorInterface;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuardInterface;
use OCA\OpenRegister\Service\Party\PartyRoleService;
use OCA\OpenRegister\Service\Party\PartyRoleServiceInterface;
use OCA\OpenRegister\Service\Party\PartySchemaCatalogueInterface;
use OCA\OpenRegister\Service\Party\PartySendRefusalInterface;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use OCA\OpenRegister\Service\Party\TrustedPartyReaderInterface;
use OCA\OpenRegister\Service\Portal\PortalSubjectAssertion;
use OCA\OpenRegister\Service\Portal\PortalSubjectAssertionInterface;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryRequestInterface;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryServiceInterface;
use OCA\OpenRegister\Service\Portal\PortalTaskReminderInterface;
use OCA\OpenRegister\Service\Portal\PortalTaskService;
use OCA\OpenRegister\Service\Portal\PortalTaskServiceInterface;
use OCA\OpenRegister\Service\Quota\ObjectQuotaService;
use OCA\OpenRegister\Service\Quota\ObjectQuotaServiceInterface;
use OCA\OpenRegister\Service\Rules\AdministeredValidationEnforcer;
use OCA\OpenRegister\Service\Rules\AdministeredValidationEnforcerInterface;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCA\OpenRegister\Service\Rules\ConditionTracerInterface;
use OCA\OpenRegister\Service\Rules\DependentValueTable;
use OCA\OpenRegister\Service\Rules\DependentValueTableInterface;
use OCA\OpenRegister\Service\Rules\DependentValueValidator;
use OCA\OpenRegister\Service\Rules\DependentValueValidatorInterface;
use OCA\OpenRegister\Service\Rules\RuleEnablementService;
use OCA\OpenRegister\Service\Rules\RuleEnablementServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleInventoryService;
use OCA\OpenRegister\Service\Rules\RuleInventoryServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleReplayService;
use OCA\OpenRegister\Service\Rules\RuleReplayServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;
use OCA\OpenRegister\Service\Rules\RuleTrialService;
use OCA\OpenRegister\Service\Rules\RuleTrialServiceInterface;
use OCA\OpenRegister\Service\Task\TaskFormCompletion;
use OCA\OpenRegister\Service\Task\TaskFormCompletionInterface;
use OCA\OpenRegister\Service\Task\TaskFormResolver;
use OCA\OpenRegister\Service\Task\TaskFormResolverInterface;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskInboxServiceInterface;
use OCA\OpenRegister\Service\Task\TaskMetricsProvider;
use OCA\OpenRegister\Service\Task\TaskMetricsProviderInterface;
use OCA\OpenRegister\Service\Task\TaskProjectionService;
use OCA\OpenRegister\Service\Task\TaskProjectionServiceInterface;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCA\OpenRegister\Service\Task\TaskSequenceServiceInterface;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCA\OpenRegister\Service\Task\TaskSubjectLocator;
use OCA\OpenRegister\Service\Task\TaskSubjectLocatorInterface;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGateInterface;

/**
 * Interface => service map of the Processes area, behind ProcessesServiceRegistrar.
 *
 * Every entry binds a class that counts to Processes: the flow, task, case,
 * approval, appointment and portal services, and the lower-area contracts
 * those classes implement. An alias resolves to the class's own shared
 * instance, so a caller that injects the interface and one that injects the
 * class by name get the same object.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class ProcessesServiceBindings {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		PortalTaskServiceInterface::class                => PortalTaskService::class,
		PortalTaskDeliveryRequestInterface::class        => PortalTaskDeliveryService::class,
		AdministeredValidationEnforcerInterface::class   => AdministeredValidationEnforcer::class,
		AggregateReferenceResolverInterface::class       => AggregateReferenceResolver::class,
		AppointmentAttendeeServiceInterface::class       => AppointmentAttendeeService::class,
		FlowRunSubjectsInterface::class                  => FlowRunSubjects::class,
		FlowDryRunServiceInterface::class                => FlowDryRunService::class,
		FlowPublishedGraphInterface::class               => FlowPublishedGraph::class,
		WorkingCalendarInterface::class                  => WorkingCalendar::class,
		AwaitSignalNodeInterface::class                  => AwaitSignalNode::class,
		TaskMetricsProviderInterface::class              => TaskMetricsProvider::class,
		FlowBpmnInterchangeInterface::class              => FlowBpmnInterchange::class,
		EventCatalogServiceInterface::class              => EventCatalogService::class,
		FlowRunAuthorizationInterface::class             => FlowRunAuthorization::class,
		FlowRunnableGuardInterface::class                => FlowRunnableGuard::class,
		FlowRunVersionPinInterface::class                => FlowRunVersionPin::class,
		CalendarFeedTokenServiceInterface::class         => CalendarFeedTokenService::class,
		CasePlanAuthorizationServiceInterface::class     => CasePlanAuthorizationService::class,
		FlowStateNodeInterface::class                    => FlowStateNode::class,
		IterateNodeInterface::class                      => IterateNode::class,
		LinkObjectNodeInterface::class                   => LinkObjectNode::class,
		LoopNodeInterface::class                         => LoopNode::class,
		MapNodeInterface::class                          => MapNode::class,
		MergeNodeInterface::class                        => MergeNode::class,
		RouterNodeInterface::class                       => RouterNode::class,
		PartyIndicatorGuardInterface::class              => PartyIndicatorGuard::class,
		PartySendRefusalInterface::class                 => PartyIndicatorGuard::class,
		PortalSubjectAssertionInterface::class           => PortalSubjectAssertion::class,
		PortalTaskDeliveryServiceInterface::class        => PortalTaskDeliveryService::class,
		PortalTaskReminderInterface::class               => PortalTaskDeliveryService::class,
		PrincipalResolverRegistryInterface::class        => PrincipalResolverRegistry::class,
		SetFieldsNodeInterface::class                    => SetFieldsNode::class,
		SubFlowNodeInterface::class                      => SubFlowNode::class,
		WaitNodeInterface::class                         => WaitNode::class,
		AutoTransitionSelectorInterface::class           => AutoTransitionSelector::class,
		BulkActionRegistryInterface::class               => BulkActionRegistry::class,
		BulkJobReversalInterface::class                  => BulkJobReversal::class,
		BulkJobServiceInterface::class                   => BulkJobService::class,
		CalculationPayloadBuilderInterface::class        => CalculationPayloadBuilder::class,
		CalculationTrialServiceInterface::class          => CalculationTrialService::class,
		CaseAdvanceNodeInterface::class                  => CaseAdvanceNode::class,
		CaseOpenNodeInterface::class                     => CaseOpenNode::class,
		CasePlanItemTimersInterface::class               => CasePlanItemTimers::class,
		CasePlanServiceInterface::class                  => CasePlanService::class,
		CasePlanTimerRearmInterface::class               => CasePlanTimerRearm::class,
		ConditionTracerInterface::class                  => ConditionTracer::class,
		ContactMatcherInterface::class                   => ContactMatchingService::class,
		DecisionTableNodeInterface::class                => DecisionTableNode::class,
		DecisionVisibilityInterface::class               => DecisionVisibility::class,
		RelationDecisionServiceInterface::class          => RelationDecisionService::class,
		DependentValueTableInterface::class              => DependentValueTable::class,
		EndNodeInterface::class                          => EndNode::class,
		ExplodeNodeInterface::class                      => ExplodeNode::class,
		ReversibilityAnnotationValidatorInterface::class => ReversibilityAnnotationValidator::class,
		CalculationAnnotationValidatorInterface::class   => CalculationAnnotationValidator::class,
		PropertyCalculationsInterface::class             => PropertyCalculations::class,
		ReviewAnnotationValidatorInterface::class        => ReviewAnnotationValidator::class,
		LifecycleAnnotationValidatorInterface::class     => LifecycleAnnotationValidator::class,
		DependentValueValidatorInterface::class          => DependentValueValidator::class,
		FilterNodeInterface::class                       => FilterNode::class,
		FlowLocatorInterface::class                      => FlowLocator::class,
		FlowNodeCatalogueInterface::class                => FlowNodeRegistry::class,
		FlowNodePreflightInterface::class                => FlowNodePreflight::class,
		FlowNodeRegistryInterface::class                 => FlowNodeRegistry::class,
		FlowRunMigrationServiceInterface::class          => FlowRunMigrationService::class,
		FlowScheduleServiceInterface::class              => FlowScheduleService::class,
		FlowServiceInterface::class                      => FlowService::class,
		FlowTimerControlInterface::class                 => FlowTimerService::class,
		FlowTimerSweepInterface::class                   => FlowTimerSweep::class,
		FlowTriggerValidatorInterface::class             => FlowTriggerValidator::class,
		FlowVersionServiceInterface::class               => FlowVersionService::class,
		FormDefinitionResolverInterface::class           => FormDefinitionResolver::class,
		FormDestinationValidatorInterface::class         => FormDestinationValidator::class,
		FormIdempotencyStoreInterface::class             => FormIdempotencyStore::class,
		IdempotentAnswerStoreInterface::class            => IdempotentAnswerStore::class,
		FormSubmitServiceInterface::class                => FormSubmitService::class,
		FormUploadStoreInterface::class                  => FormUploadStore::class,
		OrganisationHaltServiceInterface::class          => OrganisationHaltService::class,
		TaskInboxServiceInterface::class                 => TaskInboxService::class,
		LifecycleActionExecutorInterface::class          => LifecycleActionExecutor::class,
		LifecycleGuardRegistryInterface::class           => LifecycleGuardRegistry::class,
		LifecycleConditionEvaluatorInterface::class      => LifecycleConditionEvaluator::class,
		LifecycleTransitionResolverInterface::class      => LifecycleTransitionResolver::class,
		ObjectActionBulkInterface::class                 => ObjectActionBulk::class,
		ObjectActionHistoryInterface::class              => ObjectActionHistory::class,
		ObjectActionDefinitionsInterface::class          => ObjectActionDefinitions::class,
		ObjectActionDefinitionInterface::class           => ObjectActionDefinition::class,
		ObjectActionListInterface::class                 => ObjectActionList::class,
		ObjectActionRunnerInterface::class               => ObjectActionRunner::class,
		ObjectActionValidatorInterface::class            => ObjectActionValidator::class,
		ObjectCalendarTasksInterface::class              => TaskService::class,
		ObjectReadNodeInterface::class                   => ObjectReadNode::class,
		ObjectWriteNodeInterface::class                  => ObjectWriteNode::class,
		OperatorCatalogueInterface::class                => OperatorCatalogue::class,
		PartyRoleServiceInterface::class                 => PartyRoleService::class,
		PartySchemaCatalogueInterface::class             => PartyService::class,
		PartyServiceInterface::class                     => PartyService::class,
		PortalTaskNodeInterface::class                   => PortalTaskNode::class,
		LifecycleActionContextInterface::class           => LifecycleActionContext::class,
		ReferencedCalculationRefresherInterface::class   => ReferencedCalculationRefresher::class,
		ReferenceResolverInterface::class                => ReferenceResolver::class,
		RuleEnablementServiceInterface::class            => RuleEnablementService::class,
		RuleInventoryServiceInterface::class             => RuleInventoryService::class,
		RuleReplayServiceInterface::class                => RuleReplayService::class,
		RuleRunRecorderInterface::class                  => RuleRunRecorder::class,
		RuleTrialServiceInterface::class                 => RuleTrialService::class,
		FlowTenantMembershipInterface::class             => FlowTenantMembership::class,
		SendEmailNodeInterface::class                    => SendEmailNode::class,
		SendNotificationNodeInterface::class             => SendNotificationNode::class,
		SendTalkMessageNodeInterface::class              => SendTalkMessageNode::class,
		SlaCalculatorInterface::class                    => SlaCalculator::class,
		StateConditionEvaluatorInterface::class          => StateConditionEvaluator::class,
		StateFieldRuleResolverInterface::class           => StateFieldRuleResolver::class,
		SubstantiveChangeEvaluatorInterface::class       => SubstantiveChangeEvaluator::class,
		TaskFormCompletionInterface::class               => TaskFormCompletion::class,
		TaskFormResolverInterface::class                 => TaskFormResolver::class,
		TaskManagerInterface::class                      => \OCA\OpenRegister\Service\Task\TaskService::class,
		TaskProjectionServiceInterface::class            => TaskProjectionService::class,
		TaskSequenceServiceInterface::class              => TaskSequenceService::class,
		TaskSubjectLocatorInterface::class               => TaskSubjectLocator::class,
		TaskVtodoWriteBackGateInterface::class           => TaskVtodoWriteBackGate::class,
		TemporalCalculationSweepServiceInterface::class  => TemporalCalculationSweepService::class,
		TransitionNodeInterface::class                   => TransitionNode::class,
		TrustedPartyReaderInterface::class               => PartyService::class,
		TriggerManualNodeInterface::class                => TriggerManualNode::class,
		TriggerObjectNodeInterface::class                => TriggerObjectNode::class,
		TriggerScheduleNodeInterface::class              => TriggerScheduleNode::class,
		UserTaskNodeInterface::class                     => UserTaskNode::class,
		ZaaktypeCaseSkeletonMapperInterface::class       => ZaaktypeCaseSkeletonMapper::class,
		CalculationEvaluatorInterface::class             => CalculationEvaluator::class,
		TaskServiceInterface::class                      => \OCA\OpenRegister\Service\Task\TaskService::class,
		FlowRunServiceInterface::class                   => FlowRunService::class,
		AgentPrincipalResolverInterface::class           => AgentPrincipalResolver::class,
		DecisionTableValidatorInterface::class           => DecisionTableValidator::class,
		FlowTaskBridgeInterface::class                   => FlowTaskBridge::class,
		GroupPrincipalResolverInterface::class           => GroupPrincipalResolver::class,
		KillSwitchCheckInterface::class                  => KillSwitchCheck::class,
		ObjectQuotaServiceInterface::class               => ObjectQuotaService::class,
		OrganisationHaltCheckInterface::class            => OrganisationHaltCheck::class,
		UserPrincipalResolverInterface::class            => UserPrincipalResolver::class,
		WorkingCalendarWriteGuardInterface::class        => WorkingCalendarWriteGuard::class,
		ActionInputsNodeInterface::class                 => ActionInputsNode::class,
		FlowAccessInterface::class                       => FlowAccess::class,
		FlowNodeRightsGuardInterface::class              => FlowNodeRightsGuard::class,
		FlowDefinitionPinInterface::class                => FlowDefinitionPin::class,
		FlowRunAdvancerInterface::class                  => FlowRunAdvancer::class,
		FlowSemanticVersionInterface::class              => FlowSemanticVersion::class,
		FlowTriggerIndexInterface::class                 => FlowTriggerIndex::class,
		FlowTriggerServiceInterface::class               => FlowTriggerService::class,
		FlowTriggerSlugsInterface::class                 => FlowTriggerSlugs::class,
		FormDependentsCheckInterface::class              => FormDependentsCheck::class,
		SwitchNodeInterface::class                       => SwitchNode::class,
		UnlockObjectNodeInterface::class                 => UnlockObjectNode::class,
		AutoTransitionPassInterface::class               => AutoTransitionPass::class,
		FlowRunContextInterface::class                   => FlowRunContext::class,
		\OCA\OpenRegister\Kernel\Contracts\FileExtractionQueueInterface::class => \OCA\OpenRegister\BackgroundJob\FileExtractionQueue::class,
	];
}//end class
