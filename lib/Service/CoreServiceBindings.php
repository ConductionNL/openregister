<?php

/**
 * CoreServiceBindings: the map of the Core area's service interfaces.
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

use OCA\OpenRegister\Kernel\Contracts\Core\DerivedTranslationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RequestLanguagesInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\TranslationCompletenessInterface;
use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\MandateEvidenceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectMergerInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaWriteRightsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectActivityAuditInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectReadCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\RemoteRecipientDenialInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectUpdateRightsInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator;
use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidatorInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService;
use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesServiceInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiContractService;
use OCA\OpenRegister\Service\ApiVersion\ApiContractServiceInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogueInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiator;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiatorInterface;
use OCA\OpenRegister\Service\Authorization\DeclaredGroupInventoryInterface;
use OCA\OpenRegister\Service\Authorization\DeclaredGroupInventoryService;
use OCA\OpenRegister\Service\Authorization\GrantableRightsIndex;
use OCA\OpenRegister\Service\Authorization\GrantableRightsIndexInterface;
use OCA\OpenRegister\Service\Authorization\GroupReconciler;
use OCA\OpenRegister\Service\Authorization\GroupReconcilerInterface;
use OCA\OpenRegister\Service\Consent\ConsentAnnotationValidator;
use OCA\OpenRegister\Service\Consent\ConsentAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionServiceInterface;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactoryInterface;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkNamesInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectReadRightsInterface;
use OCA\OpenRegister\Kernel\Contracts\ReferringObjectsInterface;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolverInterface;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralInterface;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Delegation\DelegationConsentService;
use OCA\OpenRegister\Service\Delegation\DelegationConsentServiceInterface;
use OCA\OpenRegister\Service\Delegation\DelegationNotifier;
use OCA\OpenRegister\Service\Delegation\DelegationNotifierInterface;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizer;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizerInterface;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkAnnotationValidator;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkAnnotationValidatorInterface;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolverInterface;
use OCA\OpenRegister\Service\Federation\ShareGrantor;
use OCA\OpenRegister\Service\Federation\ShareGrantorInterface;
use OCA\OpenRegister\Service\Geo\PdokGeocoder;
use OCA\OpenRegister\Service\Geo\PdokGeocoderInterface;
use OCA\OpenRegister\Service\Hinge\HingeAnnotationValidator;
use OCA\OpenRegister\Service\Hinge\HingeAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Hinge\InheritedGeoCollector;
use OCA\OpenRegister\Service\Hinge\InheritedGeoCollectorInterface;
use OCA\OpenRegister\Service\Hinge\ListPresentationResolver;
use OCA\OpenRegister\Service\Hinge\ListPresentationResolverInterface;
use OCA\OpenRegister\Service\Hinge\ReferencedByService;
use OCA\OpenRegister\Service\Hinge\ReferencedByServiceInterface;
use OCA\OpenRegister\Service\History\StateHistoryProjector;
use OCA\OpenRegister\Service\History\StateHistoryProjectorInterface;
use OCA\OpenRegister\Service\History\StateHistoryRebuild;
use OCA\OpenRegister\Service\History\StateHistoryRebuildInterface;
use OCA\OpenRegister\Service\History\StateHistoryRecorderInterface;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Interaction\ReadStateServiceInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextServiceInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdSerializer;
use OCA\OpenRegister\Service\JsonLd\JsonLdSerializerInterface;
use OCA\OpenRegister\Service\Merge\MasterRecomputeInterface;
use OCA\OpenRegister\Service\Merge\MasterRecomputeService;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\Merge\ReferenceFinderInterface;
use OCA\OpenRegister\Service\Merge\ReferenceWriterInterface;
use OCA\OpenRegister\Service\Merge\RelationIndexReferenceFinder;
use OCA\OpenRegister\Service\Merge\SavePathReferenceWriter;
use OCA\OpenRegister\Service\Oas\OasETagComputer;
use OCA\OpenRegister\Service\Oas\OasETagComputerInterface;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\Oas\OasRequestValidatorInterface;
use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilder;
use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilderInterface;
use OCA\OpenRegister\Service\Object\ObjectActivityAudit;
use OCA\OpenRegister\Service\Object\ArchiveHandler;
use OCA\OpenRegister\Service\Object\ObjectReadRights;
use OCA\OpenRegister\Service\Object\ReferringObjects;
use OCA\OpenRegister\Service\Sharing\AccessLinkNames;
use OCA\OpenRegister\Service\Object\ArchiveHandlerInterface;
use OCA\OpenRegister\Service\Object\AuthorizationResolutionInterface;
use OCA\OpenRegister\Service\Object\BatchOperationStatus;
use OCA\OpenRegister\Service\Object\BatchOperationStatusInterface;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\ConflictReport;
use OCA\OpenRegister\Service\Object\ConflictReportInterface;
use OCA\OpenRegister\Service\Object\CorrectionTargets;
use OCA\OpenRegister\Service\Object\CorrectionTargetsInterface;
use OCA\OpenRegister\Service\Object\DraftActivation;
use OCA\OpenRegister\Service\Object\DraftActivationInterface;
use OCA\OpenRegister\Service\Object\DraftExpiry;
use OCA\OpenRegister\Service\Object\DraftExpiryInterface;
use OCA\OpenRegister\Service\Object\DraftRemovalFrame;
use OCA\OpenRegister\Service\Object\DraftRemovalFrameInterface;
use OCA\OpenRegister\Service\Object\FacetCacheVersion;
use OCA\OpenRegister\Service\Object\FacetCacheVersionInterface;
use OCA\OpenRegister\Service\Object\FileWriteGuard;
use OCA\OpenRegister\Service\Object\FileWriteGuardInterface;
use OCA\OpenRegister\Service\Object\MoveObject;
use OCA\OpenRegister\Service\Object\MoveObjectInterface;
use OCA\OpenRegister\Service\Object\MovedObjectLookup;
use OCA\OpenRegister\Service\Object\MovedObjectLookupInterface;
use OCA\OpenRegister\Service\Object\ObjectCacheInvalidationInterface;
use OCA\OpenRegister\Service\Object\ObjectCacheMaintenanceInterface;
use OCA\OpenRegister\Service\Object\ObjectEntityCacheInterface;
use OCA\OpenRegister\Service\Object\ObjectEventGate;
use OCA\OpenRegister\Service\Object\ObjectEventGateInterface;
use OCA\OpenRegister\Service\Object\ObjectNameWriterInterface;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\Object\ObjectReadAccessInterface;
use OCA\OpenRegister\Service\Object\ObjectUpdateRights;
use OCA\OpenRegister\Service\Object\ObjectReadersInterface;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillService;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillServiceInterface;
use OCA\OpenRegister\Service\Object\ObjectValidatorInterface;
use OCA\OpenRegister\Service\Object\PermissionCacheInterface;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Object\SchemaWriteRights;
use OCA\OpenRegister\Service\Object\PermissionEnforcementInterface;
use OCA\OpenRegister\Service\Object\PermissionExplanationInterface;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\PermissionScopeInterface;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\QueryHandlerInterface;
use OCA\OpenRegister\Service\Object\RelationAddressRewrite;
use OCA\OpenRegister\Service\Object\RelationAddressRewriteInterface;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RenderObjectInterface;
use OCA\OpenRegister\Service\Object\RetentionDestruction;
use OCA\OpenRegister\Service\Object\RetentionDestructionInterface;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\RevertHandlerInterface;
use OCA\OpenRegister\Service\Object\RunLockRegistry;
use OCA\OpenRegister\Service\Object\RunLockReleaseInterface;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjectInterface;
use OCA\OpenRegister\Service\Object\SaveObjectRelatedUpdateGuardInterface;
use OCA\OpenRegister\Service\Object\SaveObjectRelationScannerInterface;
use OCA\OpenRegister\Service\Object\SaveObjectUpdaterInterface;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugTargetInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\Object\SchemaUriResolverInterface;
use OCA\OpenRegister\Service\Object\TransitionAuthorizationInterface;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandlerInterface;
use OCA\OpenRegister\Service\Object\UpsertOnKeyHandler;
use OCA\OpenRegister\Service\Object\UpsertOnKeyHandlerInterface;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;
use OCA\OpenRegister\Service\Object\ValidationErrorResponseInterface;
use OCA\OpenRegister\Service\Object\WriteConstraintValidatorInterface;
use OCA\OpenRegister\Service\Organisation\OrganisationEditAuditorInterface;
use OCA\OpenRegister\Service\Organisation\OrganisationLifecycleAuditor;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use OCA\OpenRegister\Service\Quality\QualityScorerInterface;
use OCA\OpenRegister\Service\Quality\QualityStatisticsService;
use OCA\OpenRegister\Service\Quality\QualityStatisticsServiceInterface;
use OCA\OpenRegister\Service\Quality\UniqueHintWarnings;
use OCA\OpenRegister\Service\Quality\UniqueHintWarningsInterface;
use OCA\OpenRegister\Service\Rbac\AccessReviewGate;
use OCA\OpenRegister\Service\Rbac\AccessReviewGateInterface;
use OCA\OpenRegister\Service\Rbac\AdminGate;
use OCA\OpenRegister\Service\Rbac\AdminGateInterface;
use OCA\OpenRegister\Service\Rbac\AnonymousEnvelope;
use OCA\OpenRegister\Service\Rbac\AnonymousEnvelopeInterface;
use OCA\OpenRegister\Service\Rbac\DefinitionWriteRights;
use OCA\OpenRegister\Service\Rbac\DefinitionWriteRightsInterface;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementModeInterface;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\DenyResolverInterface;
use OCA\OpenRegister\Service\Rbac\DerivedGrantLoginInterface;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStoreInterface;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealInterface;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMaskerInterface;
use OCA\OpenRegister\Service\Rbac\ExternalGrantLapseNotifier;
use OCA\OpenRegister\Service\Rbac\ExternalGrantLapseNotifierInterface;
use OCA\OpenRegister\Service\Rbac\GrantChangeAuditor;
use OCA\OpenRegister\Service\Rbac\GrantChangeAuditorInterface;
use OCA\OpenRegister\Service\Rbac\MandateEvidence;
use OCA\OpenRegister\Service\Rbac\MatrixRowKeys;
use OCA\OpenRegister\Service\Rbac\MatrixRowWhere;
use OCA\OpenRegister\Service\Rbac\ObjectAccessReport;
use OCA\OpenRegister\Service\Rbac\ObjectAccessReportInterface;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolverInterface;
use OCA\OpenRegister\Service\Rbac\ObjectGrantsReport;
use OCA\OpenRegister\Service\Rbac\ObjectGrantsReportInterface;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipServiceInterface;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolverInterface;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCA\OpenRegister\Service\Rbac\ObjectSharingServiceInterface;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRightsInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\PermissionReportScope;
use OCA\OpenRegister\Service\Rbac\PermissionReportScopeInterface;
use OCA\OpenRegister\Service\Rbac\PrincipalReachListerInterface;
use OCA\OpenRegister\Service\Rbac\PrincipalReachService;
use OCA\OpenRegister\Service\Rbac\ProcessRevealAudit;
use OCA\OpenRegister\Service\Rbac\ProcessRevealAuditInterface;
use OCA\OpenRegister\Service\Rbac\ReachRevocationService;
use OCA\OpenRegister\Service\Rbac\ReachRevocationServiceInterface;
use OCA\OpenRegister\Service\Rbac\RevealFlusher;
use OCA\OpenRegister\Service\Rbac\RevealFlusherInterface;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;
use OCA\OpenRegister\Service\Rbac\SchemaManageGateInterface;
use OCA\OpenRegister\Service\Rbac\ScopeAudit;
use OCA\OpenRegister\Service\Rbac\ScopeAuditInterface;
use OCA\OpenRegister\Service\Rbac\ViewerReachCheckInterface;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolverInterface;
use OCA\OpenRegister\Service\Retention\ExpiredObjectStore;
use OCA\OpenRegister\Service\Retention\ExpiredObjectStoreInterface;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandlerInterface;
use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidator;
use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionLifecycleInterface;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionService;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionServiceInterface;
use OCA\OpenRegister\Service\Relation\LinkExposure;
use OCA\OpenRegister\Service\Relation\LinkExposureInterface;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\ObjectRelationServiceInterface;
use OCA\OpenRegister\Service\Relation\ObjectTreeService;
use OCA\OpenRegister\Service\Relation\ObjectTreeServiceInterface;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;
use OCA\OpenRegister\Service\Relation\RelationAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Relation\RelationGraphService;
use OCA\OpenRegister\Service\Relation\RelationGraphServiceInterface;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use OCA\OpenRegister\Service\Relation\RelationTypeResolverInterface;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportServiceInterface;
use OCA\OpenRegister\Service\Schema\PropertyConversionService;
use OCA\OpenRegister\Service\Schema\PropertyConversionServiceInterface;
use OCA\OpenRegister\Service\Schema\SchemaBatchMigratorInterface;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
use OCA\OpenRegister\Service\Schema\SchemaMigrationServiceInterface;
use OCA\OpenRegister\Service\Schema\SchemaObjectReader;
use OCA\OpenRegister\Service\Schema\SchemaObjectReaderInterface;
use OCA\OpenRegister\Service\Schema\SchemaRevalidationService;
use OCA\OpenRegister\Service\Schema\SchemaRevalidatorInterface;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclarationInterface;
use OCA\OpenRegister\Service\Schemas\ExtendingFormValidatorInterface;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearerInterface;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandlerInterface;
use OCA\OpenRegister\Service\Schemas\PropertyVocabulary;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyInterface;
use OCA\OpenRegister\Service\Schemas\ReferenceOptionsReader;
use OCA\OpenRegister\Service\Schemas\ReferenceOptionsReaderInterface;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandlerInterface;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandlerInterface;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluatorInterface;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatterInterface;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCA\OpenRegister\Service\Serializer\RegisterSerializerInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkActs;
use OCA\OpenRegister\Service\Sharing\AccessLinkActsInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuard;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuardInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkService;
use OCA\OpenRegister\Service\Sharing\AccessLinkServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectAccessLinksInterface;
use OCA\OpenRegister\Service\Sharing\ObjectAccessLinks;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubject;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubjectInterface;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriverInterface;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolverInterface;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidator;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolverInterface;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolverInterface;
use OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategy;
use OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategyInterface;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategy;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategyInterface;
use OCA\OpenRegister\Service\View\ViewObjectQuery;
use OCA\OpenRegister\Service\View\ViewObjectQueryInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilder;
use OCA\OpenRegister\Service\Vocabulary\CodedOptionsBuilderInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuard;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuardInterface;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuardInterface;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepositoryInterface;
use OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuardInterface;
use OCA\OpenRegister\Service\Vocabulary\VocabularyDeclarationResolver;
use OCA\OpenRegister\Service\Vocabulary\VocabularyDeclarationResolverInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FilterPlaceholdersInterface;
use OCA\OpenRegister\Kernel\Contracts\PropertySearchProfileInterface;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolverInterface;
use OCA\OpenRegister\Search\Service\Search\PropertySearchProfile;

/**
 * Interface => service map of the Core area, registered by ServiceRegistrar.
 *
 * Every entry is an alias, so a caller that injects the interface and one
 * that injects the class by name get the same shared instance. Only classes
 * that count to Core are listed here; each other area binds its own classes
 * in its own map.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class CoreServiceBindings {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		\OCA\OpenRegister\Kernel\Contracts\SchemaReadRightInterface::class => \OCA\OpenRegister\Service\Object\SchemaReadRight::class,
		ExpiredObjectStoreInterface::class => ExpiredObjectStore::class,
		DerivedTranslationsInterface::class => TranslationStatusService::class,
		RequestLanguagesInterface::class => LanguageService::class,
		TranslationCompletenessInterface::class => TranslationStatusService::class,
		EntityEventsInterface::class => EntityEventPublisher::class,
		ObjectMergerInterface::class => MergeService::class,
		ObjectNameCacheInterface::class => CacheHandler::class,
		ObjectOperationsInterface::class => ObjectService::class,
		ObjectActivityAuditInterface::class => ObjectActivityAudit::class,
		\OCA\OpenRegister\Kernel\Contracts\OperatorActionAuditInterface::class => \OCA\OpenRegister\Service\Audit\OperatorActionAudit::class,
		MandateEvidenceInterface::class => MandateEvidence::class,
		AggregationRunnerInterface::class => AggregationRunner::class,
		WidgetAnnotationValidatorInterface::class => WidgetAnnotationValidator::class,
		ApiCapabilitiesServiceInterface::class => ApiCapabilitiesService::class,
		ApiContractServiceInterface::class => ApiContractService::class,
		ApiVersionCatalogueInterface::class => ApiVersionCatalogue::class,
		ApiVersionNegotiatorInterface::class => ApiVersionNegotiator::class,
		DeclaredGroupInventoryInterface::class => DeclaredGroupInventoryService::class,
		GrantableRightsIndexInterface::class => GrantableRightsIndex::class,
		GroupReconcilerInterface::class => GroupReconciler::class,
		\OCA\OpenRegister\Kernel\Contracts\DeclaredGroupsReconcileInterface::class => GroupReconciler::class,
		\OCA\OpenRegister\Kernel\Contracts\DeclaredGroupRightsInterface::class
			=> \OCA\OpenRegister\Service\Authorization\DeclaredGroupRights::class,
		ConsentAnnotationValidatorInterface::class => ConsentAnnotationValidator::class,
		DateTimeNormalizerInterface::class => DateTimeNormalizer::class,
		DatabaseIntrospectionServiceInterface::class => DatabaseIntrospectionService::class,
		DbalConnectionFactoryInterface::class => DbalConnectionFactory::class,
		DeferredEntryObjectResolverInterface::class => DeferredEntryObjectResolver::class,
		AccessLinkNamesInterface::class => AccessLinkNames::class,
		ObjectReadRightsInterface::class => ObjectReadRights::class,
		ReferringObjectsInterface::class => ReferringObjects::class,
		ListenerDeferralInterface::class => ListenerDeferralService::class,
		DelegationConsentServiceInterface::class => DelegationConsentService::class,
		DelegationNotifierInterface::class => DelegationNotifier::class,
		DeletedObjectAuthorizerInterface::class => DeletedObjectAuthorizer::class,
		ExternalLinkAnnotationValidatorInterface::class => ExternalLinkAnnotationValidator::class,
		ExternalLinkResolverInterface::class => ExternalLinkResolver::class,
		ShareGrantorInterface::class => ShareGrantor::class,
		FieldEncryptionHandlerInterface::class => FieldEncryptionHandler::class,
		PdokGeocoderInterface::class => PdokGeocoder::class,
		HingeAnnotationValidatorInterface::class => HingeAnnotationValidator::class,
		InheritedGeoCollectorInterface::class => InheritedGeoCollector::class,
		ListPresentationResolverInterface::class => ListPresentationResolver::class,
		ReferencedByServiceInterface::class => ReferencedByService::class,
		StateHistoryProjectorInterface::class => StateHistoryProjector::class,
		StateHistoryRebuildInterface::class => StateHistoryRebuild::class,
		StateHistoryRecorderInterface::class => StateHistoryProjector::class,
		InboundAuthRateLimitInterface::class => SecurityService::class,
		ReadStateServiceInterface::class => ReadStateService::class,
		JsonLdContextServiceInterface::class => JsonLdContextService::class,
		JsonLdSerializerInterface::class => JsonLdSerializer::class,
		LoginSecurityInterface::class => SecurityService::class,
		MdiIconSvgInterface::class => MdiIconSvg::class,
		MasterRecomputeInterface::class => MasterRecomputeService::class,
		// Merge relinking (mdm-merge-relinks-every-reference): the relation index finds each reference, the save path moves it.
		ReferenceFinderInterface::class => RelationIndexReferenceFinder::class,
		ReferenceWriterInterface::class => SavePathReferenceWriter::class,
		OasETagComputerInterface::class => OasETagComputer::class,
		OasRequestValidatorInterface::class => OasRequestValidator::class,
		ProblemDetailsBuilderInterface::class => ProblemDetailsBuilder::class,
		OasServiceInterface::class => OasService::class,
		ArchiveHandlerInterface::class => ArchiveHandler::class,
		AuthorizationResolutionInterface::class => PermissionHandler::class,
		BatchOperationStatusInterface::class => BatchOperationStatus::class,
		CacheHandlerInterface::class => CacheHandler::class,
		ConflictReportInterface::class => ConflictReport::class,
		CorrectionTargetsInterface::class => CorrectionTargets::class,
		DraftActivationInterface::class => DraftActivation::class,
		DraftExpiryInterface::class => DraftExpiry::class,
		DraftRemovalFrameInterface::class => DraftRemovalFrame::class,
		FacetCacheVersionInterface::class => FacetCacheVersion::class,
		FileWriteGuardInterface::class => FileWriteGuard::class,
		MovedObjectLookupInterface::class => MovedObjectLookup::class,
		MoveObjectInterface::class => MoveObject::class,
		ObjectCacheInvalidationInterface::class => CacheHandler::class,
		ObjectCacheMaintenanceInterface::class => CacheHandler::class,
		ObjectEntityCacheInterface::class => CacheHandler::class,
		ObjectEventGateInterface::class => ObjectEventGate::class,
		ObjectNameWriterInterface::class => CacheHandler::class,
		ObjectReadAccessInterface::class => ObjectReadAccess::class,
		ObjectReadCheckInterface::class => ObjectReadAccess::class,
		ObjectUpdateRightsInterface::class => ObjectUpdateRights::class,
		ObjectReadersInterface::class => PermissionHandler::class,
		ObjectSlugBackfillServiceInterface::class => ObjectSlugBackfillService::class,
		ObjectValidatorInterface::class => ValidateObject::class,
		PermissionCacheInterface::class => PermissionHandler::class,
		PermissionCheckInterface::class => PermissionHandler::class,
		\OCA\OpenRegister\Kernel\Contracts\SchemaReadCheckInterface::class => \OCA\OpenRegister\Service\Object\SchemaReadCheck::class,
		PermissionEnforcementInterface::class => PermissionHandler::class,
		PermissionExplanationInterface::class => PermissionHandler::class,
		PermissionHandlerInterface::class => PermissionHandler::class,
		PermissionScopeInterface::class => PermissionHandler::class,
		QueryHandlerInterface::class => QueryHandler::class,
		RelationAddressRewriteInterface::class => RelationAddressRewrite::class,
		RemoteRecipientDenialInterface::class => PermissionHandler::class,
		RenderObjectInterface::class => RenderObject::class,
		RetentionDestructionInterface::class => RetentionDestruction::class,
		SchemaWriteRightsInterface::class => SchemaWriteRights::class,
		RevertHandlerInterface::class => RevertHandler::class,
		RunLockReleaseInterface::class => RunLockRegistry::class,
		ObjectSlugTargetInterface::class => ObjectSlugHandler::class,
		SaveObjectInterface::class => SaveObject::class,
		SaveObjectRelatedUpdateGuardInterface::class => SaveObject::class,
		SaveObjectRelationScannerInterface::class => SaveObject::class,
		SaveObjectUpdaterInterface::class => SaveObject::class,
		SaveObjectWriterInterface::class => SaveObject::class,
		SchemaTypeConverterInterface::class => SchemaTypeConverter::class,
		SchemaUriResolverInterface::class => ValidateObject::class,
		TransitionAuthorizationInterface::class => PermissionHandler::class,
		TranslationHandlerInterface::class => TranslationHandler::class,
		UpsertOnKeyHandlerInterface::class => UpsertOnKeyHandler::class,
		ValidateObjectInterface::class => ValidateObject::class,
		ValidationErrorResponseInterface::class => ValidateObject::class,
		WriteConstraintValidatorInterface::class => ValidateObject::class,
		ObjectHandlerAccessInterface::class => ObjectService::class,
		ObjectStreamingSaveInterface::class => ObjectService::class,
		OrganisationEditAuditorInterface::class => OrganisationLifecycleAuditor::class,
		OrganisationServiceInterface::class => OrganisationService::class,
		OrganisationUsageServiceInterface::class => OrganisationUsageService::class,
		PropertyRbacHandlerInterface::class => PropertyRbacHandler::class,
		QualityScorerInterface::class => QualityScorer::class,
		QualityStatisticsServiceInterface::class => QualityStatisticsService::class,
		UniqueHintWarningsInterface::class => UniqueHintWarnings::class,
		RateLimitResetInterface::class => SecurityService::class,
		AccessReviewGateInterface::class => AccessReviewGate::class,
		AdminGateInterface::class => AdminGate::class,
		AnonymousEnvelopeInterface::class => AnonymousEnvelope::class,
		DefinitionWriteRightsInterface::class => DefinitionWriteRights::class,
		DenyEnforcementModeInterface::class => DenyEnforcementMode::class,
		DenyResolverInterface::class => DenyResolver::class,
		DerivedGrantLoginInterface::class => DerivedGrantStore::class,
		DerivedGrantStoreInterface::class => DerivedGrantStore::class,
		ExplicitRevealInterface::class => ExplicitReveal::class,
		ExplicitRevealTrailMaskerInterface::class => ExplicitRevealTrailMasker::class,
		ExternalGrantLapseNotifierInterface::class => ExternalGrantLapseNotifier::class,
		GrantChangeAuditorInterface::class => GrantChangeAuditor::class,
		MatrixRowKeys::class => MatrixRowWhere::class,
		ObjectAccessReportInterface::class => ObjectAccessReport::class,
		ObjectGrantResolverInterface::class => ObjectGrantResolver::class,
		ObjectGrantsReportInterface::class => ObjectGrantsReport::class,
		ObjectOwnershipServiceInterface::class => ObjectOwnershipService::class,
		ObjectScopeResolverInterface::class => ObjectScopeResolver::class,
		ObjectSharingServiceInterface::class => ObjectSharingService::class,
		OrganisationSpecialRightsInterface::class => OrganisationSpecialRights::class,
		PermissionCatalogueInterface::class => PermissionCatalogue::class,
		PermissionReportScopeInterface::class => PermissionReportScope::class,
		PrincipalReachListerInterface::class => PrincipalReachService::class,
		ProcessRevealAuditInterface::class => ProcessRevealAudit::class,
		ReachRevocationServiceInterface::class => ReachRevocationService::class,
		RevealFlusherInterface::class => RevealFlusher::class,
		SchemaManageGateInterface::class => SchemaManageGate::class,
		ScopeAuditInterface::class => ScopeAudit::class,
		ViewerReachCheckInterface::class => ViewerReachResolver::class,
		ViewerReachResolverInterface::class => ViewerReachResolver::class,
		RegisterResolverServiceInterface::class => RegisterResolverService::class,
		RegisterCacheHandlerInterface::class => RegisterCacheHandler::class,
		RegisterSchemaLinkageRepairServiceInterface::class => RegisterSchemaLinkageRepairService::class,
		RegisterScopedSchemaResolverInterface::class => RegisterScopedSchemaResolver::class,
		RegisterServiceInterface::class => RegisterService::class,
		RegistryAnnotationValidatorInterface::class => RegistryAnnotationValidator::class,
		RegistrySubscriptionLifecycleInterface::class => RegistrySubscriptionService::class,
		RegistrySubscriptionServiceInterface::class => RegistrySubscriptionService::class,
		LinkExposureInterface::class => LinkExposure::class,
		ObjectRelationServiceInterface::class => ObjectRelationService::class,
		ObjectTreeServiceInterface::class => ObjectTreeService::class,
		RelationAnnotationValidatorInterface::class => RelationAnnotationValidator::class,
		RelationGraphServiceInterface::class => RelationGraphService::class,
		RelationTypeResolverInterface::class => RelationTypeResolver::class,
		PropertyConversionServiceInterface::class => PropertyConversionService::class,
		SchemaBatchMigratorInterface::class => SchemaMigrationService::class,
		SchemaMigrationServiceInterface::class => SchemaMigrationService::class,
		SchemaObjectReaderInterface::class => SchemaObjectReader::class,
		SchemaRevalidatorInterface::class => SchemaRevalidationService::class,
		SchemaImportServiceInterface::class => SchemaImportService::class,
		ExtendingFormDeclarationInterface::class => ExtendingFormDeclaration::class,
		ExtendingFormValidatorInterface::class => ExtendingFormDeclaration::class,
		FacetResultCacheClearerInterface::class => FacetResultCacheClearer::class,
		PropertyValidatorHandlerInterface::class => PropertyValidatorHandler::class,
		PropertyVocabularyInterface::class => PropertyVocabulary::class,
		ReferenceOptionsReaderInterface::class => ReferenceOptionsReader::class,
		SchemaCacheHandlerInterface::class => SchemaCacheHandler::class,
		SemanticRoleHandlerInterface::class => SemanticRoleHandler::class,
		UniqueConstraintEvaluatorInterface::class => UniqueConstraintEvaluator::class,
		ObjectSearchResultFormatterInterface::class => ObjectSearchResultFormatter::class,
		SecurityHeadersInterface::class => SecurityService::class,
		SemanticTypeResolverInterface::class => SemanticTypeResolver::class,
		RegisterSerializerInterface::class => RegisterSerializer::class,
		SharedMasterDataServiceInterface::class => SharedMasterDataService::class,
		AccessLinkActsInterface::class => AccessLinkActs::class,
		AccessLinkMintGuardInterface::class => AccessLinkMintGuard::class,
		AccessLinkServiceInterface::class => AccessLinkService::class,
		ObjectAccessLinksInterface::class => ObjectAccessLinks::class,
		AccessLinkSubjectInterface::class => AccessLinkSubject::class,
		SharePrincipalDeriverInterface::class => SharePrincipalDeriver::class,
		SourceRecordResolverInterface::class => SourceRecordResolver::class,
		SurvivorshipAnnotationValidatorInterface::class => SurvivorshipAnnotationValidator::class,
		SurvivorshipResolverInterface::class => SurvivorshipResolver::class,
		TrustTierResolverInterface::class => TrustTierResolver::class,
		TenantLifecycleServiceInterface::class => TenantLifecycleService::class,
		TenantLogRedactorInterface::class => TenantLogRedactor::class,
		UrnServiceInterface::class => UrnService::class,
		UserServiceInterface::class => UserService::class,
		FileVectorizationStrategyInterface::class => FileVectorizationStrategy::class,
		ObjectVectorizationStrategyInterface::class => ObjectVectorizationStrategy::class,
		ViewObjectQueryInterface::class => ViewObjectQuery::class,
		CodedOptionsBuilderInterface::class => CodedOptionsBuilder::class,
		CodedValueGuardInterface::class => CodedValueGuard::class,
		ConceptDeleteGuardInterface::class => ConceptDeleteGuard::class,
		ConceptRepositoryInterface::class => ConceptRepository::class,
		ConceptShapeGuardInterface::class => ConceptShapeGuard::class,
		VocabularyDeclarationResolverInterface::class => VocabularyDeclarationResolver::class,
		PlaceholderResolverInterface::class => PlaceholderResolver::class,
		FilterPlaceholdersInterface::class => PlaceholderResolver::class,
		PropertySearchProfileInterface::class => PropertySearchProfile::class,
		\OCA\OpenRegister\Kernel\Contracts\SemanticProvidersInterface::class => SemanticProviderCatalogue::class,
	];
}//end class
