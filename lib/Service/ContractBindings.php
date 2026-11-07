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
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
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
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodePreflightInterface;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowScheduleService;
use OCA\OpenRegister\Service\Flow\FlowScheduleServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNode;
use OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNodeInterface;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweep;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweepInterface;
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
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluatorInterface;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolverInterface;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\ObjectRelationServiceInterface;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Service\Relation\RelationGraphServiceInterface;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolverInterface;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolverInterface;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCA\OpenRegister\Service\Task\TaskSequenceServiceInterface;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
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
		\OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface::class => \OCA\OpenRegister\Service\Aggregation\AggregationRunner::class,
		ActivityFilterServiceInterface::class => ActivityFilterService::class,
		AnalyticsLinkServiceInterface::class => AnalyticsLinkService::class,
		AppContainerFinderInterface::class => AppContainerLocator::class,
		AppRegisterImportRunnerInterface::class => AppRegisterImportRunner::class,
		AppSettingsInterface::class => SettingsService::class,
		AutoTransitionSelectorInterface::class => AutoTransitionSelector::class,
		BookmarkLinkServiceInterface::class => BookmarkLinkService::class,
		BulkJobReversalInterface::class => BulkJobReversal::class,
		BulkJobServiceInterface::class => BulkJobService::class,
		BulkTranslationServiceInterface::class => BulkTranslationService::class,
		CalendarLinkServiceInterface::class => CalendarLinkService::class,
		CollectiveLinkServiceInterface::class => CollectiveLinkService::class,
		ConceptDeleteGuardInterface::class => ConceptDeleteGuard::class,
		ConceptRepositoryInterface::class => ConceptRepository::class,
		ContactMatcherInterface::class => ContactMatchingService::class,
		CospendLinkServiceInterface::class => CospendLinkService::class,
		DatabaseIntrospectionServiceInterface::class => DatabaseIntrospectionService::class,
		DecisionTableNodeInterface::class => DecisionTableNode::class,
		DeckCardServiceInterface::class => DeckCardService::class,
		DemoDataServiceInterface::class => DemoDataService::class,
		EmailLinkServiceInterface::class => EmailLinkService::class,
		EndpointServiceInterface::class => EndpointService::class,
		ExampleSetInstallerInterface::class => ExampleSetInstaller::class,
		ExampleSetRecordInterface::class => ExampleSetRecord::class,
		ExampleSetRemoverInterface::class => ExampleSetRemover::class,
		\OCA\OpenRegister\Service\Object\FacetCacheVersionInterface::class => \OCA\OpenRegister\Service\Object\FacetCacheVersion::class,
		FlowNodePreflightInterface::class => FlowNodePreflight::class,
		FlowScheduleServiceInterface::class => FlowScheduleService::class,
		FlowTimerControlInterface::class => FlowTimerService::class,
		FlowTimerSweepInterface::class => FlowTimerSweep::class,
		FlowVersionServiceInterface::class => FlowVersionService::class,
		FormDefinitionResolverInterface::class => FormDefinitionResolver::class,
		FormDestinationValidatorInterface::class => FormDestinationValidator::class,
		FormIdempotencyStoreInterface::class => FormIdempotencyStore::class,
		FormSubmitServiceInterface::class => FormSubmitService::class,
		FormUploadStoreInterface::class => FormUploadStore::class,
		GitHubClientInterface::class => GitHubHandler::class,
		GitLabHandlerInterface::class => GitLabHandler::class,
		HandoffServiceInterface::class => HandoffService::class,
		HarvestPipelineServiceInterface::class => HarvestPipelineService::class,
		ImportPreviewServiceInterface::class => ImportPreviewService::class,
		JsonLdContextServiceInterface::class => JsonLdContextService::class,
		JsonLdSerializerInterface::class => JsonLdSerializer::class,
		LifecycleActionExecutorInterface::class => LifecycleActionExecutor::class,
		LifecycleConditionEvaluatorInterface::class => LifecycleConditionEvaluator::class,
		LinkedEntityServiceInterface::class => LinkedEntityService::class,
		McpCatalogueInterface::class => McpDiscoveryService::class,
		MigrationPackServiceInterface::class => MigrationPackService::class,
		MigrationServiceInterface::class => MigrationService::class,
		NotificationServiceInterface::class => NotificationService::class,
		NotificationTemplatesInterface::class => NotificationTemplateRegistry::class,
		OasServiceInterface::class => OasService::class,
		ObjectCalendarEventsInterface::class => CalendarEventService::class,
		ObjectCalendarTasksInterface::class => \OCA\OpenRegister\Service\TaskService::class,
		\OCA\OpenRegister\Service\Object\ObjectEventGateInterface::class => \OCA\OpenRegister\Service\Object\ObjectEventGate::class,
		ObjectExporterInterface::class => ExportService::class,
		ObjectImporterInterface::class => ImportService::class,
		ObjectMergerInterface::class => MergeService::class,
		ObjectNameCacheInterface::class => CacheHandler::class,
		ObjectOperationsInterface::class => ObjectService::class,
		ObjectReadNodeInterface::class => ObjectReadNode::class,
		ObjectRelationCleanupServiceInterface::class => ObjectRelationCleanupService::class,
		\OCA\OpenRegister\Service\Object\ObjectSlugBackfillServiceInterface::class => \OCA\OpenRegister\Service\Object\ObjectSlugBackfillService::class,
		\OCA\OpenRegister\Service\Object\QueryHandlerInterface::class => \OCA\OpenRegister\Service\Object\QueryHandler::class,
		ObjectRelationServiceInterface::class => ObjectRelationService::class,
		ObjectStreamingSaveInterface::class => ObjectService::class,
		ObjectWriteNodeInterface::class => ObjectWriteNode::class,
		OperationsConsoleServiceInterface::class => OperationsConsoleService::class,
		\OCA\OpenRegister\Service\Object\QueryHandlerInterface::class => \OCA\OpenRegister\Service\Object\QueryHandler::class,
		PersonLinkServiceInterface::class => PersonLinkService::class,
		PhotoLinkServiceInterface::class => PhotoLinkService::class,
		PollLinkServiceInterface::class => PollLinkService::class,
		PortalTaskNodeInterface::class => PortalTaskNode::class,
		RegisterSchemaLinkageRepairServiceInterface::class => RegisterSchemaLinkageRepairService::class,
		RegisterServiceInterface::class => RegisterService::class,
		RelationGraphServiceInterface::class => RelationGraphService::class,
		ScheduledReportsInterface::class => ScheduledReportService::class,
		SchemaApplicationMigratorInterface::class => SchemaApplicationMigrator::class,
		SchemaExplorationInterface::class => SchemaService::class,
		\OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface::class => \OCA\OpenRegister\Service\Object\SchemaTypeConverter::class,
		SearchTrailServiceInterface::class => SearchTrailService::class,
		SemanticTypeResolverInterface::class => SemanticTypeResolver::class,
		SequenceServiceInterface::class => SequenceService::class,
		SharedSchemaDedupeServiceInterface::class => SharedSchemaDedupeService::class,
		SourceRecordResolverInterface::class => SourceRecordResolver::class,
		SourceRowReaderInterface::class => SourceRowReader::class,
		StateConditionEvaluatorInterface::class => StateConditionEvaluator::class,
		StateFieldRuleResolverInterface::class => StateFieldRuleResolver::class,
		SurvivorshipResolverInterface::class => SurvivorshipResolver::class,
		TalkLinkServiceInterface::class => TalkLinkService::class,
		TaskManagerInterface::class => TaskService::class,
		TaskSequenceServiceInterface::class => TaskSequenceService::class,
		TimeTrackerLinkServiceInterface::class => TimeTrackerLinkService::class,
		\OCA\OpenRegister\Service\Object\TranslationHandlerInterface::class => \OCA\OpenRegister\Service\Object\TranslationHandler::class,
		UserTaskNodeInterface::class => UserTaskNode::class,
		VocabularyImporterInterface::class => VocabularyImportService::class,
		XwikiLinkServiceInterface::class => XwikiLinkService::class,
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
