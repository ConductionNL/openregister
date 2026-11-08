<?php

/**
 * Binds the contracts in lib/Contract to the services that implement them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\Kernel\Contracts\AppContainerFinderInterface;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Kernel\Contracts\ContactMatcherInterface;
use OCA\OpenRegister\Kernel\Contracts\ExampleSetInstallerInterface;
use OCA\OpenRegister\Kernel\Contracts\FlowTimerControlInterface;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Kernel\Contracts\McpCatalogueInterface;
use OCA\OpenRegister\Kernel\Contracts\NotificationTemplatesInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectMergerInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportsInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaExplorationInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use OCA\OpenRegister\Kernel\Contracts\VocabularyImporterInterface;
use OCA\OpenRegister\Service\BulkJob\BulkJobReversal;
use OCA\OpenRegister\Service\BulkJob\BulkJobReversalInterface;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCA\OpenRegister\Service\BulkJob\BulkJobServiceInterface;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolverInterface;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilderInterface;
use OCA\OpenRegister\Service\Calculation\CalculationTrialService;
use OCA\OpenRegister\Service\Calculation\CalculationTrialServiceInterface;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogue;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogueInterface;
use OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresher;
use OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresherInterface;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Calculation\ReferenceResolverInterface;
use OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepService;
use OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepServiceInterface;
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
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\FederatedConfigServiceInterface;
use OCA\OpenRegister\Service\Config\SchemaShareableConfigScanner;
use OCA\OpenRegister\Service\Config\SchemaShareableConfigScannerInterface;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistryInterface;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandlerInterface;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionServiceInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecordInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverInterface;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowLocatorInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeCatalogueInterface;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodePreflightInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationService;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowScheduleService;
use OCA\OpenRegister\Service\Flow\FlowScheduleServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidator;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidatorInterface;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
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
use OCA\OpenRegister\Service\Flow\Nodes\TransitionNode;
use OCA\OpenRegister\Service\Flow\Nodes\TransitionNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNode;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNodeInterface;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweep;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweepInterface;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Form\FormDefinitionResolver;
use OCA\OpenRegister\Service\Form\FormDefinitionResolverInterface;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormDestinationValidatorInterface;
use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCA\OpenRegister\Service\Form\FormIdempotencyStoreInterface;
use OCA\OpenRegister\Service\Form\FormSubmitService;
use OCA\OpenRegister\Service\Form\FormSubmitServiceInterface;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Service\Form\FormUploadStoreInterface;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\Handoff\HandoffServiceInterface;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCA\OpenRegister\Service\Import\ImportPreviewServiceInterface;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use OCA\OpenRegister\Service\Import\SourceRowReaderInterface;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Service\Interaction\FavouriteService;
use OCA\OpenRegister\Service\Interaction\FavouriteServiceInterface;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluator;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluatorInterface;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextServiceInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdSerializer;
use OCA\OpenRegister\Service\JsonLd\JsonLdSerializerInterface;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelector;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelectorInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutorInterface;
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
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulk;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulkInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitionsInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionListInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunnerInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidatorInterface;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckServiceInterface;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairService;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairServiceInterface;
use OCA\OpenRegister\Service\Operations\JobAlertService;
use OCA\OpenRegister\Service\Operations\JobAlertServiceInterface;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCA\OpenRegister\Service\Operations\JobScheduleService;
use OCA\OpenRegister\Service\Operations\JobScheduleServiceInterface;
use OCA\OpenRegister\Service\Operations\MaintenanceModeService;
use OCA\OpenRegister\Service\Operations\MaintenanceModeServiceInterface;
use OCA\OpenRegister\Service\Operations\OperationsJobsService;
use OCA\OpenRegister\Service\Operations\OperationsJobsServiceInterface;
use OCA\OpenRegister\Service\Operations\SupportBundleService;
use OCA\OpenRegister\Service\Operations\SupportBundleServiceInterface;
use OCA\OpenRegister\Service\Party\PartyRoleService;
use OCA\OpenRegister\Service\Party\PartyRoleServiceInterface;
use OCA\OpenRegister\Service\Party\PartySchemaCatalogueInterface;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use OCA\OpenRegister\Service\Party\TrustedPartyReaderInterface;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use OCA\OpenRegister\Service\Quality\QualityScorerInterface;
use OCA\OpenRegister\Service\Quality\QualityStatisticsService;
use OCA\OpenRegister\Service\Quality\QualityStatisticsServiceInterface;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\DenyResolverInterface;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolverInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\ObjectRelationServiceInterface;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Service\Relation\RelationGraphServiceInterface;
use OCA\OpenRegister\Service\Rules\AdministeredValidationEnforcer;
use OCA\OpenRegister\Service\Rules\AdministeredValidationEnforcerInterface;
use OCA\OpenRegister\Service\Rules\ConditionTracer;
use OCA\OpenRegister\Service\Rules\ConditionTracerInterface;
use OCA\OpenRegister\Service\Rules\DependentValueTable;
use OCA\OpenRegister\Service\Rules\DependentValueTableInterface;
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
use OCA\OpenRegister\Service\Schema\PropertyConversionService;
use OCA\OpenRegister\Service\Schema\PropertyConversionServiceInterface;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclarationInterface;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearerInterface;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandlerInterface;
use OCA\OpenRegister\Service\Schemas\PropertyVocabulary;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyInterface;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandlerInterface;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandlerInterface;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluatorInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\RetentionSettingsReaderInterface;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolverInterface;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolverInterface;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface;
use OCA\OpenRegister\Service\Task\TaskFormCompletion;
use OCA\OpenRegister\Service\Task\TaskFormCompletionInterface;
use OCA\OpenRegister\Service\Task\TaskFormResolver;
use OCA\OpenRegister\Service\Task\TaskFormResolverInterface;
use OCA\OpenRegister\Service\Task\TaskProjectionService;
use OCA\OpenRegister\Service\Task\TaskProjectionServiceInterface;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCA\OpenRegister\Service\Task\TaskSequenceServiceInterface;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCA\OpenRegister\Service\Task\TaskSubjectLocator;
use OCA\OpenRegister\Service\Task\TaskSubjectLocatorInterface;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGateInterface;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuardInterface;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepositoryInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers each contract as an alias of the service that implements it.
 *
 * An alias resolves to the service's own shared instance, so a class that
 * injects the contract and one that injects the service by its class name
 * get the same object. Other apps keep resolving the services by class name.
 */
final class ContractBindings {

	/**
	 * Contract => the service that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		AdministeredValidationEnforcerInterface::class => AdministeredValidationEnforcer::class,
		AggregateReferenceResolverInterface::class => AggregateReferenceResolver::class,
		\OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface::class => \OCA\OpenRegister\Service\Aggregation\AggregationRunner::class,
		ActivityFilterServiceInterface::class => ActivityFilterService::class,
		AnalyticsLinkServiceInterface::class => AnalyticsLinkService::class,
		AppContainerFinderInterface::class => AppContainerLocator::class,
		ApplicationServiceInterface::class => ApplicationService::class,
		AppRegisterImportRunnerInterface::class => AppRegisterImportRunner::class,
		AppSettingsInterface::class => SettingsService::class,
		ArchivalSettingsReaderInterface::class => ObjectRetentionHandler::class,
		AwaitSignalNodeInterface::class => AwaitSignalNode::class,
		\OCA\OpenRegister\Service\Object\BatchOperationStatusInterface::class => \OCA\OpenRegister\Service\Object\BatchOperationStatus::class,
		\OCA\OpenRegister\Service\Flow\EventCatalogServiceInterface::class => \OCA\OpenRegister\Service\Flow\EventCatalogService::class,
		\OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnInterchangeInterface::class => \OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnInterchange::class,
		\OCA\OpenRegister\Service\Flow\FlowRunAuthorizationInterface::class => \OCA\OpenRegister\Service\Flow\FlowRunAuthorization::class,
		\OCA\OpenRegister\Service\Flow\FlowRunnableGuardInterface::class => \OCA\OpenRegister\Service\Flow\FlowRunnableGuard::class,
		\OCA\OpenRegister\Service\Flow\FlowRunVersionPinInterface::class => \OCA\OpenRegister\Service\Flow\FlowRunVersionPin::class,
		FlowStateNodeInterface::class => FlowStateNode::class,
		IterateNodeInterface::class => IterateNode::class,
		LinkObjectNodeInterface::class => LinkObjectNode::class,
		LoopNodeInterface::class => LoopNode::class,
		MaintenanceModeServiceInterface::class => MaintenanceModeService::class,
		MapNodeInterface::class => MapNode::class,
		MergeNodeInterface::class => MergeNode::class,
		\OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistryInterface::class => \OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry::class,
		RouterNodeInterface::class => RouterNode::class,
		SetFieldsNodeInterface::class => SetFieldsNode::class,
		SubFlowNodeInterface::class => SubFlowNode::class,
		\OCA\OpenRegister\Service\Flow\Timer\TermDiagnosticInterface::class => \OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic::class,
		WaitNodeInterface::class => WaitNode::class,
		AutoTransitionSelectorInterface::class => AutoTransitionSelector::class,
		BookmarkLinkServiceInterface::class => BookmarkLinkService::class,
		BulkActionRegistryInterface::class => BulkActionRegistry::class,
		BulkJobReversalInterface::class => BulkJobReversal::class,
		BulkJobServiceInterface::class => BulkJobService::class,
		BulkTranslationServiceInterface::class => BulkTranslationService::class,
		CalculationPayloadBuilderInterface::class => CalculationPayloadBuilder::class,
		CalculationTrialServiceInterface::class => CalculationTrialService::class,
		CalendarLinkServiceInterface::class => CalendarLinkService::class,
		CaseAdvanceNodeInterface::class => CaseAdvanceNode::class,
		CaseOpenNodeInterface::class => CaseOpenNode::class,
		CasePlanItemTimersInterface::class => CasePlanItemTimers::class,
		CasePlanServiceInterface::class => CasePlanService::class,
		CasePlanTimerRearmInterface::class => CasePlanTimerRearm::class,
		CollectiveLinkServiceInterface::class => CollectiveLinkService::class,
		ConceptDeleteGuardInterface::class => ConceptDeleteGuard::class,
		ConceptRepositoryInterface::class => ConceptRepository::class,
		ConditionTracerInterface::class => ConditionTracer::class,
		ConsistencyCheckServiceInterface::class => ConsistencyCheckService::class,
		ConsistencyRepairServiceInterface::class => ConsistencyRepairService::class,
		ContactMatcherInterface::class => ContactMatchingService::class,
		ContactServiceInterface::class => ContactService::class,
		CospendLinkServiceInterface::class => CospendLinkService::class,
		DatabaseIntrospectionServiceInterface::class => DatabaseIntrospectionService::class,
		DateTimeNormalizerInterface::class => DateTimeNormalizer::class,
		DecisionTableNodeInterface::class => DecisionTableNode::class,
		DeckCardServiceInterface::class => DeckCardService::class,
		DeckLinkServiceInterface::class => DeckLinkService::class,
		DeepLinkRegistryServiceInterface::class => DeepLinkRegistryService::class,
		DemoDataServiceInterface::class => DemoDataService::class,
		DenyResolverInterface::class => DenyResolver::class,
		DependentValueTableInterface::class => DependentValueTable::class,
		\OCA\OpenRegister\Service\Object\DraftActivationInterface::class => \OCA\OpenRegister\Service\Object\DraftActivation::class,
		\OCA\OpenRegister\Service\Object\DraftExpiryInterface::class => \OCA\OpenRegister\Service\Object\DraftExpiry::class,
		EmailLinkServiceInterface::class => EmailLinkService::class,
		EndNodeInterface::class => EndNode::class,
		EndpointServiceInterface::class => EndpointService::class,
		ExampleSetInstallerInterface::class => ExampleSetInstaller::class,
		ExampleSetRecordInterface::class => ExampleSetRecord::class,
		ExampleSetRemoverInterface::class => ExampleSetRemover::class,
		ExplodeNodeInterface::class => ExplodeNode::class,
		ExtendingFormDeclarationInterface::class => ExtendingFormDeclaration::class,
		\OCA\OpenRegister\Service\Object\FacetCacheVersionInterface::class => \OCA\OpenRegister\Service\Object\FacetCacheVersion::class,
		FacetResultCacheClearerInterface::class => FacetResultCacheClearer::class,
		FavouriteServiceInterface::class => FavouriteService::class,
		FederatedConfigServiceInterface::class => FederatedConfigService::class,
		FileServiceInterface::class => FileService::class,
		FilterNodeInterface::class => FilterNode::class,
		FlowLinkServiceInterface::class => FlowLinkService::class,
		FlowLocatorInterface::class => FlowLocator::class,
		FlowNodeCatalogueInterface::class => FlowNodeRegistry::class,
		FlowNodePreflightInterface::class => FlowNodePreflight::class,
		FlowNodeRegistryInterface::class => FlowNodeRegistry::class,
		FlowRunMigrationServiceInterface::class => FlowRunMigrationService::class,
		FlowScheduleServiceInterface::class => FlowScheduleService::class,
		FlowServiceInterface::class => FlowService::class,
		FlowTimerControlInterface::class => FlowTimerService::class,
		FlowTimerSweepInterface::class => FlowTimerSweep::class,
		FlowTriggerValidatorInterface::class => FlowTriggerValidator::class,
		FlowVersionServiceInterface::class => FlowVersionService::class,
		FormDefinitionResolverInterface::class => FormDefinitionResolver::class,
		FormDestinationValidatorInterface::class => FormDestinationValidator::class,
		FormIdempotencyStoreInterface::class => FormIdempotencyStore::class,
		FormLinkServiceInterface::class => FormLinkService::class,
		FormSubmitServiceInterface::class => FormSubmitService::class,
		FormUploadStoreInterface::class => FormUploadStore::class,
		GitHubClientInterface::class => GitHubHandler::class,
		GitLabHandlerInterface::class => GitLabHandler::class,
		HandoffServiceInterface::class => HandoffService::class,
		HarvestPipelineServiceInterface::class => HarvestPipelineService::class,
		ImportPreviewServiceInterface::class => ImportPreviewService::class,
		JobAlertServiceInterface::class => JobAlertService::class,
		JobRunRecorderInterface::class => JobRunRecorder::class,
		JobScheduleServiceInterface::class => JobScheduleService::class,
		JsonLdContextServiceInterface::class => JsonLdContextService::class,
		JsonLdSerializerInterface::class => JsonLdSerializer::class,
		LanguageServiceInterface::class => LanguageService::class,
		LifecycleActionExecutorInterface::class => LifecycleActionExecutor::class,
		LifecycleGuardRegistryInterface::class => LifecycleGuardRegistry::class,
		LifecycleConditionEvaluatorInterface::class => LifecycleConditionEvaluator::class,
		LifecycleTransitionResolverInterface::class => LifecycleTransitionResolver::class,
		LinkedEntityServiceInterface::class => LinkedEntityService::class,
		ManifestServiceInterface::class => ManifestService::class,
		MapLinkServiceInterface::class => MapLinkService::class,
		MappingServiceInterface::class => MappingService::class,
		McpCatalogueInterface::class => McpDiscoveryService::class,
		MigrationPackServiceInterface::class => MigrationPackService::class,
		MigrationServiceInterface::class => MigrationService::class,
		NotificationServiceInterface::class => NotificationService::class,
		NotificationTemplatesInterface::class => NotificationTemplateRegistry::class,
		OasServiceInterface::class => OasService::class,
		ObjectActionBulkInterface::class => ObjectActionBulk::class,
		ObjectActionDefinitionsInterface::class => ObjectActionDefinitions::class,
		ObjectActionListInterface::class => ObjectActionList::class,
		ObjectActionRunnerInterface::class => ObjectActionRunner::class,
		ObjectActionValidatorInterface::class => ObjectActionValidator::class,
		ObjectCalendarEventsInterface::class => CalendarEventService::class,
		ObjectCalendarTasksInterface::class => \OCA\OpenRegister\Service\TaskService::class,
		\OCA\OpenRegister\Service\Object\ObjectEventGateInterface::class => \OCA\OpenRegister\Service\Object\ObjectEventGate::class,
		ObjectExporterInterface::class => ExportService::class,
		ObjectImporterInterface::class => ImportService::class,
		ObjectMergerInterface::class => MergeService::class,
		ObjectNameCacheInterface::class => CacheHandler::class,
		ObjectOperationsInterface::class => ObjectService::class,
		\OCA\OpenRegister\Service\Object\QueryHandlerInterface::class => \OCA\OpenRegister\Service\Object\QueryHandler::class,
		ObjectReadNodeInterface::class => ObjectReadNode::class,
		ObjectRelationCleanupServiceInterface::class => ObjectRelationCleanupService::class,
		\OCA\OpenRegister\Service\Object\ObjectSlugBackfillServiceInterface::class => \OCA\OpenRegister\Service\Object\ObjectSlugBackfillService::class,
		ObjectRelationServiceInterface::class => ObjectRelationService::class,
		ObjectScopeResolverInterface::class => ObjectScopeResolver::class,
		ObjectStreamingSaveInterface::class => ObjectService::class,
		ObjectWriteNodeInterface::class => ObjectWriteNode::class,
		OpenProjectLinkServiceInterface::class => OpenProjectLinkService::class,
		OperationsConsoleServiceInterface::class => OperationsConsoleService::class,
		\OCA\OpenRegister\Service\Object\RenderObjectInterface::class => \OCA\OpenRegister\Service\Object\RenderObject::class,
		OperationsJobsServiceInterface::class => OperationsJobsService::class,
		OperatorCatalogueInterface::class => OperatorCatalogue::class,
		PartyRoleServiceInterface::class => PartyRoleService::class,
		PartySchemaCatalogueInterface::class => PartyService::class,
		PartyServiceInterface::class => PartyService::class,
		PermissionCatalogueInterface::class => PermissionCatalogue::class,
		PersonLinkServiceInterface::class => PersonLinkService::class,
		PhotoLinkServiceInterface::class => PhotoLinkService::class,
		PollLinkServiceInterface::class => PollLinkService::class,
		PortalTaskNodeInterface::class => PortalTaskNode::class,
		PropertyConversionServiceInterface::class => PropertyConversionService::class,
		PropertyRbacHandlerInterface::class => PropertyRbacHandler::class,
		PropertyValidatorHandlerInterface::class => PropertyValidatorHandler::class,
		PropertyVocabularyInterface::class => PropertyVocabulary::class,
		QualityScorerInterface::class => QualityScorer::class,
		QualityStatisticsServiceInterface::class => QualityStatisticsService::class,
		ReferencedCalculationRefresherInterface::class => ReferencedCalculationRefresher::class,
		ReferenceResolverInterface::class => ReferenceResolver::class,
		RuleEnablementServiceInterface::class => RuleEnablementService::class,
		RuleInventoryServiceInterface::class => RuleInventoryService::class,
		RuleReplayServiceInterface::class => RuleReplayService::class,
		RuleRunRecorderInterface::class => RuleRunRecorder::class,
		RuleTrialServiceInterface::class => RuleTrialService::class,
		RegisterDescriptorServiceInterface::class => RegisterDescriptorService::class,
		RegisterResolverServiceInterface::class => RegisterResolverService::class,
		RegisterSchemaLinkageRepairServiceInterface::class => RegisterSchemaLinkageRepairService::class,
		RegisterScopedSchemaResolverInterface::class => RegisterScopedSchemaResolver::class,
		RegisterServiceInterface::class => RegisterService::class,
		RelationGraphServiceInterface::class => RelationGraphService::class,
		RetentionSettingsReaderInterface::class => ObjectRetentionHandler::class,
		ScheduledReportsInterface::class => ScheduledReportService::class,
		SchemaApplicationMigratorInterface::class => SchemaApplicationMigrator::class,
		SchemaCacheHandlerInterface::class => SchemaCacheHandler::class,
		SchemaExplorationInterface::class => SchemaService::class,
		SchemaTypeConverterInterface::class => SchemaTypeConverter::class,
		SchemaShareableConfigScannerInterface::class => SchemaShareableConfigScanner::class,
		SearchTrailServiceInterface::class => SearchTrailService::class,
		SemanticRoleHandlerInterface::class => SemanticRoleHandler::class,
		SemanticTypeResolverInterface::class => SemanticTypeResolver::class,
		SendEmailNodeInterface::class => SendEmailNode::class,
		SendNotificationNodeInterface::class => SendNotificationNode::class,
		SendTalkMessageNodeInterface::class => SendTalkMessageNode::class,
		SequenceServiceInterface::class => SequenceService::class,
		ShareableConfigTypeRegistryInterface::class => ShareableConfigTypeRegistry::class,
		SharedSchemaDedupeServiceInterface::class => SharedSchemaDedupeService::class,
		SlaCalculatorInterface::class => SlaCalculator::class,
		SourceRecordResolverInterface::class => SourceRecordResolver::class,
		SourceRowReaderInterface::class => SourceRowReader::class,
		StateConditionEvaluatorInterface::class => StateConditionEvaluator::class,
		StateFieldRuleResolverInterface::class => StateFieldRuleResolver::class,
		SubstantiveChangeEvaluatorInterface::class => SubstantiveChangeEvaluator::class,
		SupportBundleServiceInterface::class => SupportBundleService::class,
		SurvivorshipResolverInterface::class => SurvivorshipResolver::class,
		TalkLinkServiceInterface::class => TalkLinkService::class,
		TaskFormCompletionInterface::class => TaskFormCompletion::class,
		TaskFormResolverInterface::class => TaskFormResolver::class,
		TaskManagerInterface::class => TaskService::class,
		TaskProjectionServiceInterface::class => TaskProjectionService::class,
		TaskSequenceServiceInterface::class => TaskSequenceService::class,
		TaskSubjectLocatorInterface::class => TaskSubjectLocator::class,
		TaskVtodoWriteBackGateInterface::class => TaskVtodoWriteBackGate::class,
		TemporalCalculationSweepServiceInterface::class => TemporalCalculationSweepService::class,
		TimeTrackerLinkServiceInterface::class => TimeTrackerLinkService::class,
		TransitionNodeInterface::class => TransitionNode::class,
		TrustedPartyReaderInterface::class => PartyService::class,
		\OCA\OpenRegister\Service\Object\TranslationHandlerInterface::class => \OCA\OpenRegister\Service\Object\TranslationHandler::class,
		TranslationProjectionServiceInterface::class => TranslationProjectionService::class,
		TranslationStatusServiceInterface::class => TranslationStatusService::class,
		TriggerManualNodeInterface::class => TriggerManualNode::class,
		TriggerObjectNodeInterface::class => TriggerObjectNode::class,
		TriggerScheduleNodeInterface::class => TriggerScheduleNode::class,
		UniqueConstraintEvaluatorInterface::class => UniqueConstraintEvaluator::class,
		UrnServiceInterface::class => UrnService::class,
		UserTaskListInterface::class => \OCA\OpenRegister\Service\TaskService::class,
		UserTaskNodeInterface::class => UserTaskNode::class,
		VocabularyImporterInterface::class => VocabularyImportService::class,
		WatcherServiceInterface::class => WatcherService::class,
		XwikiLinkServiceInterface::class => XwikiLinkService::class,
		ZaaktypeCaseSkeletonMapperInterface::class => ZaaktypeCaseSkeletonMapper::class,
		CalculationEvaluatorInterface::class => CalculationEvaluator::class,
		SettingsServiceInterface::class => SettingsService::class,
		ExternalIntegrationRouterInterface::class => ExternalIntegrationRouter::class,
		OrganisationServiceInterface::class => OrganisationService::class,
		TaskServiceInterface::class => TaskService::class,
		FlowRunServiceInterface::class => FlowRunService::class,
		ConfigurationServiceInterface::class => ConfigurationService::class,
		RetentionServiceInterface::class => RetentionService::class,
		\OCA\OpenRegister\Service\Object\CacheHandlerInterface::class => \OCA\OpenRegister\Service\Object\CacheHandler::class,
		\OCA\OpenRegister\Service\Object\ValidateObjectInterface::class => \OCA\OpenRegister\Service\Object\ValidateObject::class,
		\OCA\OpenRegister\Service\Object\SaveObjectInterface::class => \OCA\OpenRegister\Service\Object\SaveObject::class,
		\OCA\OpenRegister\Service\Object\SaveObjectRelationScannerInterface::class => \OCA\OpenRegister\Service\Object\SaveObject::class,
		\OCA\OpenRegister\Service\Object\SaveObjectWriterInterface::class => \OCA\OpenRegister\Service\Object\SaveObject::class,
		\OCA\OpenRegister\Service\Object\PermissionHandlerInterface::class => \OCA\OpenRegister\Service\Object\PermissionHandler::class,
	];

	/**
	 * Register every binding with the app container.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 */
	public static function register(IRegistrationContext $context): void {
		foreach (self::BINDINGS as $contract => $service) {
			$context->registerServiceAlias($contract, $service);
		}
	}//end register()
}//end class
