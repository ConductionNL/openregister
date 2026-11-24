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

use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\EntityFolderInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RunningNumbersInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RetentionSettingsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ExportGuardInterface;
use OCA\OpenRegister\Kernel\Contracts\ExampleSetInstallerInterface;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Kernel\Contracts\Integrations\ShareableConfigCatalogueInterface;
use OCA\OpenRegister\Kernel\Contracts\McpCatalogueInterface;
use OCA\OpenRegister\Kernel\Contracts\NotificationTemplatesInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportsInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaExplorationInterface;
use OCA\OpenRegister\Kernel\Contracts\VocabularyImporterInterface;
use OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries;
use OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineRowsInterface;
use OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViewRowsInterface;
use OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendServiceInterface;
use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidator;
use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use OCA\OpenRegister\Service\Archival\ArchivalNominationServiceInterface;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionListRepositoryInterface;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\DestructionReviewServiceInterface;
use OCA\OpenRegister\Service\Archival\ElementMappingValidator;
use OCA\OpenRegister\Service\Archival\ElementMappingValidatorInterface;
use OCA\OpenRegister\Service\Archival\SelectielijstImportService;
use OCA\OpenRegister\Service\Archival\SelectielijstImportServiceInterface;
use OCA\OpenRegister\Service\Archival\SelectielijstReferenceResolver;
use OCA\OpenRegister\Service\Archival\SelectielijstReferenceResolverInterface;
use OCA\OpenRegister\Service\Audit\AuditAggregationService;
use OCA\OpenRegister\Service\Audit\AuditAggregationServiceInterface;
use OCA\OpenRegister\Service\Audit\OrganisationContextAttribution;
use OCA\OpenRegister\Service\Audit\OrganisationContextAttributionInterface;
use OCA\OpenRegister\Service\Audit\PurposeAttribution;
use OCA\OpenRegister\Service\Audit\PurposeAttributionInterface;
use OCA\OpenRegister\Service\Audit\TokenAttribution;
use OCA\OpenRegister\Service\Audit\TokenAttributionInterface;
use OCA\OpenRegister\Service\AuditRetentionResolver;
use OCA\OpenRegister\Service\AuditRetentionResolverInterface;
use OCA\OpenRegister\Service\Bindings\AccessBindings;
use OCA\OpenRegister\Service\Bindings\FeatureBindings;
use OCA\OpenRegister\Service\Config\FederatedConfigAccess;
use OCA\OpenRegister\Service\Config\FederatedConfigAccessInterface;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\FederatedConfigServiceInterface;
use OCA\OpenRegister\Service\Config\SchemaShareableConfigScanner;
use OCA\OpenRegister\Service\Config\SchemaShareableConfigScannerInterface;
use OCA\OpenRegister\Service\Config\ShareableConfigCatalogue;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistryInterface;
use OCA\OpenRegister\Service\Config\Types\ConfigSetShareableConfigType;
use OCA\OpenRegister\Service\Config\Types\ConfigSetShareableConfigTypeInterface;
use OCA\OpenRegister\Service\Config\Types\FlowShareableConfigType;
use OCA\OpenRegister\Service\Config\Types\FlowShareableConfigTypeInterface;
use OCA\OpenRegister\Service\Config\Types\RegisterSchemaShareableConfigType;
use OCA\OpenRegister\Service\Config\Types\RegisterSchemaShareableConfigTypeInterface;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandlerInterface;
use OCA\OpenRegister\Service\Configuration\RegisterBundleBinding;
use OCA\OpenRegister\Service\Configuration\RegisterBundleBindingInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftsInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistryInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewServiceInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentServiceInterface;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenServiceInterface;
use OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrar;
use OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrarInterface;
use OCA\OpenRegister\Service\Credential\DoriathCredentialKeys;
use OCA\OpenRegister\Service\Credential\DoriathCredentialStore;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepositoryInterface;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\Credential\ProviderCatalogueInterface;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransportInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecordInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverInterface;
use OCA\OpenRegister\Service\Export\ExportAuditRecorder;
use OCA\OpenRegister\Service\Export\ExportAuditRecorderInterface;
use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Service\Export\ExportGateInterface;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportProfileServiceInterface;
use OCA\OpenRegister\Service\Export\ExportProfileWriter;
use OCA\OpenRegister\Service\Export\ExportProfileWriterInterface;
use OCA\OpenRegister\Service\Export\Odt\OdtWriter;
use OCA\OpenRegister\Service\Export\Odt\OdtWriterInterface;
use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportService;
use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportServiceInterface;
use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator;
use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidator;
use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidatorInterface;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\Handoff\HandoffServiceInterface;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCA\OpenRegister\Service\Import\ImportPreviewServiceInterface;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use OCA\OpenRegister\Service\Import\SourceRowReaderInterface;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Service\Integration\Providers\BrpPersonProvider;
use OCA\OpenRegister\Service\Integration\Providers\BrpPersonProviderInterface;
use OCA\OpenRegister\Service\Interaction\FavouriteService;
use OCA\OpenRegister\Service\Interaction\FavouriteServiceInterface;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstallerInterface;
use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
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
use OCA\OpenRegister\Service\Operations\MovedJobState;
use OCA\OpenRegister\Service\Operations\MovedJobStateInterface;
use OCA\OpenRegister\Service\Operations\OperationsJobsService;
use OCA\OpenRegister\Service\Operations\OperationsJobsServiceInterface;
use OCA\OpenRegister\Service\Operations\SupportBundleService;
use OCA\OpenRegister\Service\Operations\SupportBundleServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SchemaAccessChangeLogInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\JobRunRecordingInterface;
use OCA\OpenRegister\Kernel\Contracts\Search\HtmlPdfConverterInterface;
use OCA\OpenRegister\Service\Export\Odt\OfficeHtmlPdfConverter;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectTrailReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\DeclaredWebhookInstallerInterface;
use OCA\OpenRegister\Kernel\Contracts\Processes\TranslationProjectionInterface;
use OCA\OpenRegister\Kernel\Contracts\Processes\WholeSetExportActionInterface;
use OCA\OpenRegister\BulkAction\ExportWholeSetAction;
use OCA\OpenRegister\Service\Audit\AuditEntryWriter;
use OCA\OpenRegister\Kernel\Contracts\Processes\ObjectWriteCacheFollowerInterface;
use OCA\OpenRegister\Listener\RedactionPolicyCacheListener;
use OCA\OpenRegister\Kernel\Contracts\Core\AccessLinkUseRecorderInterface;
use OCA\OpenRegister\Service\Audit\AccessLinkUseRecorder;
use OCA\OpenRegister\Service\Audit\ObjectTrailReader;
use OCA\OpenRegister\Service\RecordTemplate\RecordTemplateService;
use OCA\OpenRegister\Service\RecordTemplate\RecordTemplateStoreInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\Integrations\TranslationBindingInterface;
use OCA\OpenRegister\Service\Translation\TranslationBindingDescription;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\RetentionSettingsReaderInterface;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineResetService;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineResetServiceInterface;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface;
use OCA\OpenRegister\Service\TenantKeyRotationInterface;
use OCA\OpenRegister\Service\TenantKeyService;
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
		ActivityFilterServiceInterface::class => ActivityFilterService::class,
		AnalyticsLinkServiceInterface::class => AnalyticsLinkService::class,
		AnalyticsSeriesServiceInterface::class => AnalyticsSeriesService::class,
		AnonymisationBackendServiceInterface::class => AnonymisationBackendService::class,
		\OCA\OpenRegister\Service\Anonymisation\AnonymiserRequestInterface::class => AnonymisationBackendService::class,
		AppConfigurationImporterInterface::class => ConfigurationService::class,
		ApplicationServiceInterface::class => ApplicationService::class,
		AppRegisterImportRunnerInterface::class => AppRegisterImportRunner::class,
		DoriathCredentialKeys::class => DoriathCredentialStore::class,
		MovedJobStateInterface::class => MovedJobState::class,
		OdtWriterInterface::class => OdtWriter::class,
		HtmlPdfConverterInterface::class => OfficeHtmlPdfConverter::class,
		AuditEntryWriterInterface::class => AuditEntryWriter::class,
		ObjectWriteCacheFollowerInterface::class => RedactionPolicyCacheListener::class,
		AccessLinkUseRecorderInterface::class => AccessLinkUseRecorder::class,
		SchemaAccessChangeLogInterface::class => AuthorizationAuditService::class,
		ObjectTrailReaderInterface::class => ObjectTrailReader::class,
		DeclaredWebhookInstallerInterface::class => NotificationsAnnotationInstaller::class,
		TranslationProjectionInterface::class => TranslationProjectionService::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\SourceLanguagesInterface::class => TranslationProjectionService::class,
		WholeSetExportActionInterface::class => ExportWholeSetAction::class,
		TenantKeyRotationInterface::class => TenantKeyService::class,
		AppSettingsInterface::class => SettingsService::class,
		ArchivalNominationServiceInterface::class => ArchivalNominationService::class,
		\OCA\OpenRegister\Service\Archival\ArchivalNominationTriggerInterface::class => ArchivalNominationService::class,
		ArchivalSettingsReaderInterface::class => ObjectRetentionHandler::class,
		\OCA\OpenRegister\Kernel\Contracts\Compliance\ArchivalSettingsInterface::class => ObjectRetentionHandler::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\FileSettingsReaderInterface::class => \OCA\OpenRegister\Service\Settings\FileSettingsHandler::class,
		\OCA\OpenRegister\Kernel\Contracts\Files\RedactionSettingsInterface::class => \OCA\OpenRegister\Service\Settings\FileSettingsHandler::class,
		\OCA\OpenRegister\Kernel\Contracts\CapacityPolicyReaderInterface::class => \OCA\OpenRegister\Service\Settings\FileSettingsHandler::class,
		TranslationBindingInterface::class => TranslationBindingDescription::class,
		\OCA\OpenRegister\Service\Audit\AuditSinkInterface::class => \OCA\OpenRegister\Service\Audit\AuditSink::class,
		\OCA\OpenRegister\Service\Audit\AuditSinkShipperInterface::class => \OCA\OpenRegister\Service\Audit\AuditSink::class,
		\OCA\OpenRegister\Service\Audit\AuditSinkStatusInterface::class => \OCA\OpenRegister\Service\Audit\AuditSinkStatus::class,
		\OCA\OpenRegister\Service\Audit\AuditTrailPageServiceInterface::class => \OCA\OpenRegister\Service\Audit\AuditTrailPageService::class,
		\OCA\OpenRegister\Service\AvgComplianceServiceInterface::class => \OCA\OpenRegister\Service\AvgComplianceService::class,
		AuditHashServiceInterface::class => AuditHashService::class,
		AuditQueryServiceInterface::class => AuditQueryService::class,
		\OCA\OpenRegister\Service\AuthenticationTokenFetcherInterface::class => \OCA\OpenRegister\Service\AuthenticationService::class,
		\OCA\OpenRegister\Service\Reference\ObjectPreviewFormatterInterface::class => \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter::class,
		\OCA\OpenRegister\Kernel\Contracts\Integrations\ObjectPreviewInterface::class => \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\RegisterSchemaLabelsInterface::class => \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\ObjectPreviewCacheInterface::class => \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter::class,
		DoriathApplicationRegistrarInterface::class => DoriathApplicationRegistrar::class,
		\OCA\OpenRegister\Service\Archival\DestructionListCreatorInterface::class => \OCA\OpenRegister\Service\Archival\DestructionListCreator::class,
		\OCA\OpenRegister\Service\Archival\DestructionServiceInterface::class => \OCA\OpenRegister\Service\Archival\DestructionService::class,
		ConfigurationDraftsInterface::class => ConfigurationDraftService::class,
		ConfigurationKeyRegistryInterface::class => ConfigurationKeyRegistry::class,
		\OCA\OpenRegister\Service\Archival\DependantRetentionIndexInterface::class => \OCA\OpenRegister\Service\Archival\DependantRetentionIndex::class,
		DeploymentPreviewServiceInterface::class => DeploymentPreviewService::class,
		DeploymentServiceInterface::class => DeploymentService::class,
		\OCA\OpenRegister\Service\Archival\RetentionEvaluatorInterface::class => \OCA\OpenRegister\Service\Archival\RetentionEvaluator::class,
		\OCA\OpenRegister\Service\Audit\ContentReportServiceInterface::class => \OCA\OpenRegister\Service\Audit\ContentReportService::class,
		\OCA\OpenRegister\Service\Connection\ConnectionReporterInterface::class => \OCA\OpenRegister\Service\Connection\ConnectionReporter::class,
		\OCA\OpenRegister\Service\DsarServiceInterface::class => \OCA\OpenRegister\Service\DsarService::class,
		\OCA\OpenRegister\Service\Edepot\EdepotTransferServiceInterface::class => \OCA\OpenRegister\Service\Edepot\EdepotTransferService::class,
		\OCA\OpenRegister\Service\Edepot\EdepotTransferAttemptInterface::class => \OCA\OpenRegister\Service\Edepot\EdepotTransferService::class,
		\OCA\OpenRegister\Service\Gdpr\DataSubjectRequestServiceInterface::class => \OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService::class,
		\OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewServiceInterface::class => \OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService::class,
		\OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStoreInterface::class => \OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore::class,
		\OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunnerInterface::class => \OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunner::class,
		\OCA\OpenRegister\Service\Object\CorrectionServiceInterface::class => \OCA\OpenRegister\Service\Object\CorrectionService::class,
		BrpPersonProviderInterface::class => BrpPersonProvider::class,
		CaseTokenServiceInterface::class => CaseTokenService::class,
		DestructionListRepositoryInterface::class => DestructionListRepository::class,
		DestructionReviewServiceInterface::class => DestructionReviewService::class,
		\OCA\OpenRegister\Service\Archival\DestructionReviewReminderInterface::class => DestructionReviewService::class,
		FederationShareServiceInterface::class => FederationShareService::class,
		ShippedBaselineResetServiceInterface::class => ShippedBaselineResetService::class,
		\OCA\OpenRegister\Service\Archival\LegalHoldServiceInterface::class => \OCA\OpenRegister\Service\Archival\LegalHoldService::class,
		MaintenanceModeServiceInterface::class => MaintenanceModeService::class,
		\OCA\OpenRegister\Service\Operations\MaintenanceModeHoldInterface::class => MaintenanceModeService::class,
		\OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilderInterface::class => \OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilder::class,
		\OCA\OpenRegister\Service\Archival\ReviewOutcomeServiceInterface::class => \OCA\OpenRegister\Service\Archival\ReviewOutcomeService::class,
		\OCA\OpenRegister\Service\Audit\ObjectHistoryForReadersInterface::class => \OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders::class,
		\OCA\OpenRegister\Service\Audit\ReadableAuditTrailListerInterface::class => \OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister::class,
		\OCA\OpenRegister\Service\Audit\PurposeGuardInterface::class => \OCA\OpenRegister\Service\Audit\PurposeGuard::class,
		\OCA\OpenRegister\Service\Audit\PurposeRegistryInterface::class => \OCA\OpenRegister\Service\Audit\PurposeRegistry::class,
		\OCA\OpenRegister\Service\Credential\OAuth2ConnectServiceInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2ConnectService::class,
		\OCA\OpenRegister\Service\Credential\OAuth2EndpointsInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2Endpoints::class,
		\OCA\OpenRegister\Service\Credential\OAuth2InstanceClientInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2InstanceClient::class,
		\OCA\OpenRegister\Service\Credential\OAuth2RelayGuardInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2RelayGuard::class,
		\OCA\OpenRegister\Service\Credential\OAuth2StateServiceInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2StateService::class,
		OAuth2ConnectionRepositoryInterface::class => OAuth2ConnectionRepository::class,
		OpenConnectorTransportInterface::class => OpenConnectorTransport::class,
		RecordTemplateStoreInterface::class => RecordTemplateService::class,
		RestApiTransportInterface::class => RestApiTransport::class,
		SelectielijstImportServiceInterface::class => SelectielijstImportService::class,
		SelectielijstReferenceResolverInterface::class => SelectielijstReferenceResolver::class,
		\OCA\OpenRegister\Service\Edepot\TransferListServiceInterface::class => \OCA\OpenRegister\Service\Edepot\TransferListService::class,
		\OCA\OpenRegister\Service\Edepot\TransferRecordServiceInterface::class => \OCA\OpenRegister\Service\Edepot\TransferRecordService::class,
		\OCA\OpenRegister\Service\Edepot\TransferListRunInterface::class => \OCA\OpenRegister\Service\Edepot\TransferListService::class,
		\OCA\OpenRegister\Service\Edepot\TransferRecordStoreInterface::class => \OCA\OpenRegister\Service\Edepot\TransferRecordService::class,
		\OCA\OpenRegister\Service\Edepot\Transport\SftpTransportInterface::class => \OCA\OpenRegister\Service\Edepot\Transport\SftpTransport::class,
		\OCA\OpenRegister\Service\ShareLinkServiceInterface::class => \OCA\OpenRegister\Service\ShareLinkService::class,
		\OCA\OpenRegister\Service\TmloServiceInterface::class => \OCA\OpenRegister\Service\TmloService::class,
		UserTaskListInterface::class => \OCA\OpenRegister\Service\TaskService::class,
		SubjectExportServiceInterface::class => SubjectExportService::class,
		\OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControlInterface::class => \OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControl::class,
		\OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessorInterface::class => \OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor::class,
		\OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolverInterface::class => \OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver::class,
		\OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestServiceInterface::class => \OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestService::class,
		\OCA\OpenRegister\Service\Gdpr\Export\ExportBundleServiceInterface::class => \OCA\OpenRegister\Service\Gdpr\Export\ExportBundleService::class,
		\OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistryInterface::class => \OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry::class,
		\OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteServiceInterface::class => \OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteService::class,
		\OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistryInterface::class => \OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry::class,
		\OCA\OpenRegister\Service\Gdpr\Export\SubjectExportAssemblerInterface::class => SubjectExportService::class,
		BookmarkLinkServiceInterface::class => BookmarkLinkService::class,
		BulkTranslationServiceInterface::class => BulkTranslationService::class,
		CalendarLinkServiceInterface::class => CalendarLinkService::class,
		CollectiveLinkServiceInterface::class => CollectiveLinkService::class,
		ConsistencyCheckServiceInterface::class => ConsistencyCheckService::class,
		ConsistencyRepairServiceInterface::class => ConsistencyRepairService::class,
		ContactServiceInterface::class => ContactService::class,
		CospendLinkServiceInterface::class => CospendLinkService::class,
		\OCA\OpenRegister\Service\Outbound\ProxySettingsInterface::class => \OCA\OpenRegister\Service\Outbound\ProxySettings::class,
		DeckCardServiceInterface::class => DeckCardService::class,
		DeckLinkServiceInterface::class => DeckLinkService::class,
		DeepLinkRegistryServiceInterface::class => DeepLinkRegistryService::class,
		DeepLinkResolverInterface::class => DeepLinkRegistryService::class,
		DemoDataServiceInterface::class => DemoDataService::class,
		EmailLinkServiceInterface::class => EmailLinkService::class,
		EndpointServiceInterface::class => EndpointService::class,
		ExampleSetInstallerInterface::class => ExampleSetInstaller::class,
		ExampleSetRecordInterface::class => ExampleSetRecord::class,
		ExampleSetRemoverInterface::class => ExampleSetRemover::class,
		ExportAuditRecorderInterface::class => ExportAuditRecorder::class,
		ExportGateInterface::class => ExportGate::class,
		ExportGuardInterface::class => ExportGate::class,
		ExportProfileServiceInterface::class => ExportProfileService::class,
		ExportProfileWriterInterface::class => ExportProfileWriter::class,
		ArchivalAnnotationValidatorInterface::class => ArchivalAnnotationValidator::class,
		ElementMappingValidatorInterface::class => ElementMappingValidator::class,
		HandoffAnnotationValidatorInterface::class => HandoffAnnotationValidator::class,
		HandoffContractBindingValidatorInterface::class => HandoffContractBindingValidator::class,
		FavouriteServiceInterface::class => FavouriteService::class,
		FederatedConfigServiceInterface::class => FederatedConfigService::class,
		FederatedConfigAccessInterface::class => FederatedConfigAccess::class,
		RegisterBundleBindingInterface::class => RegisterBundleBinding::class,
		EntityFolderInterface::class => FileService::class,
		FileFolderServiceInterface::class => FileService::class,
		FileHandlerSupportInterface::class => FileService::class,
		FileReaderInterface::class => FileService::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\FileNodeLookupInterface::class => FileService::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\ObjectFolderLookupInterface::class => FileService::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\ObjectFolderAccessInterface::class => FileService::class,
		FileServiceInterface::class => FileService::class,
		FileWriterInterface::class => FileService::class,
		FlowLinkServiceInterface::class => FlowLinkService::class,
		FormLinkServiceInterface::class => FormLinkService::class,
		\OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageStoreInterface::class
			=> \OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageStore::class,
		GitHubClientInterface::class => GitHubHandler::class,
		\OCA\OpenRegister\Service\Configuration\GitHubGuardsInterface::class
			=> \OCA\OpenRegister\Service\Configuration\GitHubGuards::class,
		\OCA\OpenRegister\Service\Configuration\GitHubRequestValidatorInterface::class
			=> \OCA\OpenRegister\Service\Configuration\GitHubRequestValidator::class,
		\OCA\OpenRegister\Service\Configuration\RateLimiterServiceInterface::class
			=> \OCA\OpenRegister\Service\Configuration\RateLimiterService::class,
		GitLabHandlerInterface::class => GitLabHandler::class,
		\OCA\OpenRegister\Service\Hardening\ElevationServiceInterface::class => \OCA\OpenRegister\Service\Hardening\ElevationService::class,
		\OCA\OpenRegister\Service\Hardening\AllowedOriginsInterface::class => \OCA\OpenRegister\Service\Hardening\HardeningPolicy::class,
		\OCA\OpenRegister\Service\Hardening\HardeningFloorsInterface::class => \OCA\OpenRegister\Service\Hardening\HardeningPolicy::class,
		\OCA\OpenRegister\Service\Hardening\HardeningReportServiceInterface::class => \OCA\OpenRegister\Service\Hardening\HardeningReportService::class,
		\OCA\OpenRegister\Service\Hardening\HardeningSettingsServiceInterface::class => \OCA\OpenRegister\Service\Hardening\HardeningSettingsService::class,
		\OCA\OpenRegister\Service\Hardening\StatementServiceInterface::class => \OCA\OpenRegister\Service\Hardening\StatementService::class,
		HandoffServiceInterface::class => HandoffService::class,
		HarvestPipelineServiceInterface::class => HarvestPipelineService::class,
		ImportPreviewServiceInterface::class => ImportPreviewService::class,
		JobAlertServiceInterface::class => JobAlertService::class,
		JobRunRecorderInterface::class => JobRunRecorder::class,
		JobRunRecordingInterface::class => JobRunRecorder::class,
		JobScheduleServiceInterface::class => JobScheduleService::class,
		LinkedEntityServiceInterface::class => LinkedEntityService::class,
		ManifestServiceInterface::class => ManifestService::class,
		MapLinkServiceInterface::class => MapLinkService::class,
		MappingServiceInterface::class => MappingService::class,
		McpCatalogueInterface::class => McpDiscoveryService::class,
		\OCA\OpenRegister\Service\MigrationPack\MigrationPackErrorResponderInterface::class
			=> \OCA\OpenRegister\Service\MigrationPack\MigrationPackErrorResponder::class,
		\OCA\OpenRegister\Service\MigrationPack\MigrationPackRequestReaderInterface::class
			=> \OCA\OpenRegister\Service\MigrationPack\MigrationPackRequestReader::class,
		MigrationPackServiceInterface::class => MigrationPackService::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\MigrationPackDefinitionsInterface::class
			=> \OCA\OpenRegister\Service\MigrationPack\MigrationPackDefinitions::class,
		MigrationServiceInterface::class => MigrationService::class,
		NotificationServiceInterface::class => NotificationService::class,
		NotificationTemplatesInterface::class => NotificationTemplateRegistry::class,
		ObjectCalendarEventsInterface::class => CalendarEventService::class,
		ObjectFilesInterface::class => FileService::class,
		ObjectExporterInterface::class => ExportService::class,
		ObjectImporterInterface::class => ImportService::class,
		ObjectRelationCleanupServiceInterface::class => ObjectRelationCleanupService::class,
		OpenProjectLinkServiceInterface::class => OpenProjectLinkService::class,
		OperationsConsoleServiceInterface::class => OperationsConsoleService::class,
		OperationsJobsServiceInterface::class => OperationsJobsService::class,
		PersonLinkServiceInterface::class => PersonLinkService::class,
		PhotoLinkServiceInterface::class => PhotoLinkService::class,
		PollLinkServiceInterface::class => PollLinkService::class,
		AuditAggregationServiceInterface::class => AuditAggregationService::class,
		OrganisationContextAttributionInterface::class => OrganisationContextAttribution::class,
		PurposeAttributionInterface::class => PurposeAttribution::class,
		TokenAttributionInterface::class => TokenAttribution::class,
		AuditRetentionResolverInterface::class => AuditRetentionResolver::class,
		RegisterDescriptorServiceInterface::class => RegisterDescriptorService::class,
		RetentionSettingsReaderInterface::class => ObjectRetentionHandler::class,
		RetentionSettingsInterface::class => ObjectRetentionHandler::class,
		ScheduledReportsInterface::class => ScheduledReportService::class,
		SchemaApplicationMigratorInterface::class => SchemaApplicationMigrator::class,
		SchemaExplorationInterface::class => SchemaService::class,
		SchemaShareableConfigScannerInterface::class => SchemaShareableConfigScanner::class,
		SearchTrailServiceInterface::class => SearchTrailService::class,
		RunningNumbersInterface::class => RunningNumbers::class,
		ShareableConfigCatalogueInterface::class => ShareableConfigCatalogue::class,
		ShareableConfigTypeRegistryInterface::class => ShareableConfigTypeRegistry::class,
		SharedSchemaDedupeServiceInterface::class => SharedSchemaDedupeService::class,
		SourceRowReaderInterface::class => SourceRowReader::class,
		SupportBundleServiceInterface::class => SupportBundleService::class,
		TalkLinkServiceInterface::class => TalkLinkService::class,
		TimeTrackerLinkServiceInterface::class => TimeTrackerLinkService::class,
		VocabularyImporterInterface::class => VocabularyImportService::class,
		WatcherServiceInterface::class => WatcherService::class,
		XwikiLinkServiceInterface::class => XwikiLinkService::class,
		SettingsServiceInterface::class => SettingsService::class,
		\OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefreshInterface::class => \OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefresh::class,
		\OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogueInterface::class => \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue::class,
		\OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyMarksInterface::class => \OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefresh::class,
		ExampleSetTimelineRowsInterface::class => ExampleSetTimelineEntries::class,
		ExampleSetViewRowsInterface::class => ExampleSetViews::class,
		\OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface::class => \OCA\OpenRegister\Service\Sync\SourceFetcherRegistry::class,
		\OCA\OpenRegister\Service\Sync\SyncScheduleInterface::class => \OCA\OpenRegister\Service\Sync\SyncScheduleService::class,
		ExternalIntegrationRouterInterface::class => ExternalIntegrationRouter::class,
		ConfigurationServiceInterface::class => ConfigurationService::class,
		ConfigurationTransferInterface::class => ConfigurationService::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\RegisterBundlesInterface::class
			=> \OCA\OpenRegister\Service\Configuration\RegisterBundles::class,
		\OCA\OpenRegister\Kernel\Contracts\Core\AppImportJobOwnersInterface::class
			=> \OCA\OpenRegister\Service\Configuration\AppImportJobRecorder::class,
		RetentionServiceInterface::class => RetentionService::class,
		\OCA\OpenRegister\Service\Archival\ArchiveReferenceReaderInterface::class => \OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator::class,
		\OCA\OpenRegister\Service\AuditSealInterface::class => \OCA\OpenRegister\Service\AuditHashService::class,
		\OCA\OpenRegister\Service\AuditRechainInterface::class => \OCA\OpenRegister\Service\AuditHashService::class,
		\OCA\OpenRegister\Service\SchemaTeardownInterface::class => \OCA\OpenRegister\Service\SchemaDeletionService::class,
		\OCA\OpenRegister\Service\AvgRetentionPassInterface::class => \OCA\OpenRegister\Service\AvgRetentionService::class,
		\OCA\OpenRegister\Service\Credential\OAuth2CredentialSweepInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2RefreshService::class,
		\OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectorInterface::class => \OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectionService::class,
		\OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepInterface::class => \OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepService::class,
		ConfigSetShareableConfigTypeInterface::class => ConfigSetShareableConfigType::class,
		FlowShareableConfigTypeInterface::class => FlowShareableConfigType::class,
		RegisterSchemaShareableConfigTypeInterface::class => RegisterSchemaShareableConfigType::class,
		// The area maps below are spread one per entry, never two in a row: PHPStan
		// skips the item after a spread in an array this size, so two adjacent
		// spreads end the analysis with an internal error.
		CredentialAppTokenServiceInterface::class => CredentialAppTokenService::class,
		ProviderCatalogueInterface::class => ProviderCatalogue::class,
		NotificationsAnnotationInstallerInterface::class => NotificationsAnnotationInstaller::class,
		...AccessBindings::BINDINGS,
		...FeatureBindings::BINDINGS,
		\OCA\OpenRegister\Service\RedactionPolicy\TermRuleSourceInterface::class => \OCA\OpenRegister\Service\RedactionPolicy\ObjectTermRuleSource::class,
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
