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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
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
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNodeInterface;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
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
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCA\OpenRegister\Service\Object\CacheHandler;
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
		AppContainerFinderInterface::class => AppContainerLocator::class,
		AppSettingsInterface::class => SettingsService::class,
		BulkJobReversalInterface::class => BulkJobReversal::class,
		BulkJobServiceInterface::class => BulkJobService::class,
		ConceptDeleteGuardInterface::class => ConceptDeleteGuard::class,
		ConceptRepositoryInterface::class => ConceptRepository::class,
		ContactMatcherInterface::class => ContactMatchingService::class,
		CospendLinkServiceInterface::class => CospendLinkService::class,
		ExampleSetInstallerInterface::class => ExampleSetInstaller::class,
		ExampleSetRecordInterface::class => ExampleSetRecord::class,
		ExampleSetRemoverInterface::class => ExampleSetRemover::class,
		FlowNodePreflightInterface::class => FlowNodePreflight::class,
		FlowScheduleServiceInterface::class => FlowScheduleService::class,
		FlowTimerControlInterface::class => FlowTimerService::class,
		FormDefinitionResolverInterface::class => FormDefinitionResolver::class,
		FormDestinationValidatorInterface::class => FormDestinationValidator::class,
		FormIdempotencyStoreInterface::class => FormIdempotencyStore::class,
		FormSubmitServiceInterface::class => FormSubmitService::class,
		FormUploadStoreInterface::class => FormUploadStore::class,
		GitHubClientInterface::class => GitHubHandler::class,
		HandoffServiceInterface::class => HandoffService::class,
		HarvestPipelineServiceInterface::class => HarvestPipelineService::class,
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
		ObjectStreamingSaveInterface::class => ObjectService::class,
		ObjectWriteNodeInterface::class => ObjectWriteNode::class,
		OperationsConsoleServiceInterface::class => OperationsConsoleService::class,
		ScheduledReportsInterface::class => ScheduledReportService::class,
		SchemaExplorationInterface::class => SchemaService::class,
		SemanticTypeResolverInterface::class => SemanticTypeResolver::class,
		SequenceServiceInterface::class => SequenceService::class,
		SourceRecordResolverInterface::class => SourceRecordResolver::class,
		SurvivorshipResolverInterface::class => SurvivorshipResolver::class,
		TalkLinkServiceInterface::class => TalkLinkService::class,
		TaskManagerInterface::class => TaskService::class,
		TaskSequenceServiceInterface::class => TaskSequenceService::class,
		UserTaskNodeInterface::class => UserTaskNode::class,
		VocabularyImporterInterface::class => VocabularyImportService::class,
		CalculationEvaluatorInterface::class => CalculationEvaluator::class,
		SettingsServiceInterface::class => SettingsService::class,
		ExternalIntegrationRouterInterface::class => ExternalIntegrationRouter::class,
		SchemaMapperInterface::class => SchemaMapper::class,
		AuditTrailMapperInterface::class => AuditTrailMapper::class,
		OrganisationServiceInterface::class => OrganisationService::class,
		TaskServiceInterface::class => TaskService::class,
		FlowRunServiceInterface::class => FlowRunService::class,
		OrganisationMapperInterface::class => OrganisationMapper::class,
		ConfigurationServiceInterface::class => ConfigurationService::class,
		RetentionServiceInterface::class => RetentionService::class,
		FlowRunMapperInterface::class => FlowRunMapper::class,
		EntityRelationMapperInterface::class => EntityRelationMapper::class,
		RegisterMapperInterface::class => RegisterMapper::class,
		\OCA\OpenRegister\Db\MagicMapperInterface::class => \OCA\OpenRegister\Db\MagicMapper::class,
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
