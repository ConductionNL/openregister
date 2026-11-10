<?php

/**
 * OpenRegister SaveObject Handler
 *
 * Handler class responsible for persisting objects to the database.
 * This handler provides methods for:
 * - Creating and updating object entities
 * - Managing object metadata (creation/update timestamps, UUIDs)
 * - Handling object relations and nested objects
 * - Processing file attachments and uploads
 * - Maintaining audit trails (user tracking)
 * - Setting default values and properties
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedPass;
use OCA\OpenRegister\Service\RecordUpdate;
use OCA\OpenRegister\Service\Object\SaveObject\SlugRequest;
use Adbar\Dot;
use DateTime;
use Exception;
use OCA\OpenRegister\Service\ProtectedCarryForward;
use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\PropertyWriteCheck;
use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use OCA\OpenRegister\Db\AuditTrail\NamedActor;
use OCA\OpenRegister\Db\AuditTrail\SharedMasterDataRefusal;
use OCA\OpenRegister\Db\AuditTrail\SharedMasterDataRefusalOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Formats\UuidFormat;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\DeclaredRow;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCA\OpenRegister\Service\Object\SaveObject\CascadeRun;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\CascadeItem;
use OCA\OpenRegister\Service\Object\SaveObject\CascadeRules;
use OCA\OpenRegister\Service\Object\SaveObject\DefaultValueContext;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\EmptyValues;
use OCA\OpenRegister\Service\Object\SaveObject\InverseRelations;
use OCA\OpenRegister\Service\Object\SaveObject\InverseWriteBack;
use OCA\OpenRegister\Service\Object\SaveObject\InverseRelationWrite;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\PropertyDefaults;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectFileProperties;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectFileWrite;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectImage;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveCall;
use OCA\OpenRegister\Service\WriteCause;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveFlags;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectPayload;
use OCA\OpenRegister\Service\Object\SaveObject\SelfSubmission;
use OCA\OpenRegister\Service\Object\SaveObject\StreamingSave;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectWriteTarget;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectUpdateRun;
use OCA\OpenRegister\Service\Object\SaveObject\OutdatedTranslations;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceAdminBypass;
use OCA\OpenRegister\Service\Rbac\AdminGate;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator;
use OCA\OpenRegister\Service\Object\SaveObject\SaveCallFrame;
use OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack;
use OCA\OpenRegister\Service\Object\SaveObject\SaveLookups;
use OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LifecycleSeed;
use OCA\OpenRegister\Service\Object\SaveObject\SharedWriteAttempt;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSourceChange;
use OCA\OpenRegister\Service\Object\SaveObject\FolderBind;
use OCA\OpenRegister\Service\Object\SaveObject\NestedObjectTarget;
use OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun;
use OCA\OpenRegister\Service\Object\SaveObject\RelationScanRun;
use OCA\OpenRegister\Service\Object\SaveObject\RelationScanner;
use OCA\OpenRegister\Service\Object\SaveObject\StreamRun;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\Rbac\OmittedWriteOnly;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicyInterface;
use OCA\OpenRegister\Service\Quality\UniqueHintChecker;
use OCA\OpenRegister\Service\Quality\UniqueHintCandidate;
use OCA\OpenRegister\Service\Quality\UniqueHintScope;
use OCA\OpenRegister\Search\Service\Quality\CreateCandidate;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultException;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolvers;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCA\OpenRegister\Service\TenantLogRedactor;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\File\FolderAccessCheck;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Symfony\Component\Uid\Uuid;

/**
 * Individual Object Save/Create/Update Handler
 *
 * SPECIALIZED HANDLER OVERVIEW:
 * This handler is responsible for the detailed business logic of saving individual objects.
 * It handles complex object relationships, cascading operations, validation coordination,
 * file processing, and metadata hydration for single object operations.
 *
 * KEY RESPONSIBILITIES:
 * - Individual object creation and updates with full relationship handling
 * - Pre-validation cascading for inversedBy properties (nested object creation)
 * - Post-save writeBack operations for bidirectional relations
 * - Object metadata hydration (name, description, summary, image extraction)
 * - File property processing and validation
 * - Schema-based default value assignment and slug generation
 * - Audit trail creation and lifecycle event handling
 *
 * RELATIONSHIP HANDLING:
 * - Handles inversedBy properties by creating related objects before main object validation
 * - Manages writeBack operations to maintain bidirectional relationship integrity
 * - Supports both single object relations and array-based relations
 * - Resolves schema references and creates related objects automatically
 *
 * INTEGRATION WITH ObjectService:
 * - Called by ObjectService for individual object operations (createFromArray, updateFromArray)
 * - Used by bulk operations for complex relation handling and cascading
 * - Provides hydrateObjectMetadata for bulk metadata processing
 * - Handles individual object preparation in bulk scenarios
 *
 * ⚠️ IMPORTANT: Do NOT confuse with ObjectService!
 * - SaveObject = Individual object detailed business logic and relations
 * - ObjectService = High-level orchestration, bulk operations, context management
 *
 * PERFORMANCE CONSIDERATIONS:
 * - Optimized for individual object processing with full feature set
 * - For bulk operations, ObjectService uses optimized paths + selective SaveObject integration
 * - Metadata hydration methods are designed for both individual and bulk use
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Service\Objects
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 1.0.0 Initial SaveObject implementation
 * @since 1.3.0 Added relationship cascading and writeBack operations
 * @since 1.8.0 Enhanced metadata hydration and file processing
 * @since 2.0.0 Optimized for integration with bulk operations
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Object save logic requires comprehensive relationship handling
 * @SuppressWarnings(PHPMD.TooManyMethods)           Many methods required for full object save functionality
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex cascading and relation logic
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Requires many service and mapper dependencies
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.LongVariable)
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declared-initial-lifecycle-state-applied-on-create
 * @spec openspec/specs/objects-crud/spec.md#requirement-partial-object-updates-are-protected-against-lost-updates
 */
class SaveObject implements SaveObjectInterface {
	private const URL_PATH_IDENTIFIER = 'openregister.objects.show';

	/**
	 * Stamps `@self.version` on create and bumps it on update.
	 *
	 * Constructed here rather than injected: it is stateless, dependency-free
	 * arithmetic over one string, and adding a required constructor parameter
	 * to a signature this wide costs every manual construction in the test
	 * suite for no isolation gained.
	 *
	 * @var ObjectVersionHandler
	 */
	private ObjectVersionHandler $versionHandler;

	/**
	 * The saves in flight, for the cycle check on references; built on first use.
	 *
	 * @var SaveCallStack|null
	 */
	private ?SaveCallStack $saveCalls = null;

	/**
	 * Finds and keeps the schemas and registers of a save; built on first use.
	 *
	 * @var SaveLookups|null
	 */
	private ?SaveLookups $lookups = null;

	/**
	 * Decides object slugs (#4037), built from the mappers already passed in.
	 *
	 * @var ObjectSlugHandler
	 */
	private ObjectSlugHandler $slugHandler;

	/**
	 * Cache for sub-objects created during cascade operations.
	 *
	 * Stores created sub-objects indexed by their UUID for inclusion in @self.objects.
	 * This allows the parent object response to include the full sub-object data.
	 *
	 * @var array<string, array>
	 */
	private array $createdSubObjects = [];

	/**
	 * The reference existence check, built on first use.
	 *
	 * @var ReferenceExistence|null
	 */
	private ?ReferenceExistence $referenceExistence = null;

	/**
	 * The reference validator, built on first use.
	 *
	 * @var ReferenceValidator|null
	 */
	private ?ReferenceValidator $referenceValidator = null;

	/**
	 * How the most recently completed `saveObject()` actually resolved.
	 *
	 * One of `created`, `updated`, `unchanged`, or null before the first save.
	 * `saveObject()` returns an ObjectEntity, which carries no verdict — an
	 * updated row and a created one are indistinguishable in the return value —
	 * so a batching caller had to GUESS from whether the input carried a uuid.
	 * That guess is wrong in both directions: a row supplying a uuid for an
	 * object that does not exist yet is a CREATE reported as an update, and a
	 * write that changed nothing is an UPDATE reported as a change.
	 *
	 * Both terminal paths already hold the answer at no extra cost —
	 * `handleObjectUpdate()` clones the pre-update state for the audit trail —
	 * so this records it instead of re-deriving it. It is deliberately written
	 * at the very END of each path: a cascade descendant runs INSIDE that path
	 * and sets its own verdict first, so the outermost frame's write is the one
	 * that survives, which is the frame the caller asked about.
	 *
	 * Per-request, single-slot and read immediately after the call that set it.
	 * Nothing may hold it across saves.
	 *
	 * @var string|null
	 */
	private ?string $lastSaveOutcome = null;

	/**
	 * Permission mode of each save whose cascades are running, outermost first.
	 *
	 * @var list<bool>
	 */
	private array $cascadeScopes = [];

	/**
	 * Orphans found while a parent is prepared, per parent uuid, settled after its write.
	 *
	 * @var array<string, list<array{0: string[], 1: array}>>
	 */
	private array $pendingOrphans = [];

	/**
	 * Finds the relations in object data; built on first use.
	 *
	 * @var RelationScanner|null
	 */
	private ?RelationScanner $relationScanner = null;

	/**
	 * Applies the schema's defaults; built on first use.
	 *
	 * @var PropertyDefaults|null
	 */
	private ?PropertyDefaults $propertyDefaults = null;

	/**
	 * Decides whether orphan cleanup may delete a row; built on first use.
	 *
	 * @var OrphanDeletionGuard|null
	 */
	private ?OrphanDeletionGuard $orphanGuard = null;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $objectEntityMapper;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $unifiedObjectMapper;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var MetadataHydrationHandler
	 */
	private readonly MetadataHydrationHandler $metaHydrationHandler;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var FilePropertyHandler
	 */
	private readonly FilePropertyHandler $filePropertyHandler;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var LinkedEntityPropertyHandler
	 */
	private readonly LinkedEntityPropertyHandler $linkedEntityHandler;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var IURLGenerator
	 */
	private readonly IURLGenerator $urlGenerator;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var OrganisationServiceInterface
	 */
	private readonly OrganisationServiceInterface $organisationService;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var CacheHandlerInterface
	 */
	private readonly CacheHandlerInterface $cacheHandler;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var SettingsServiceInterface
	 */
	private readonly SettingsServiceInterface $settingsService;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var ComputedFieldHandler
	 */
	private readonly ComputedFieldHandler $computedFieldHandler;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var TranslationHandler
	 */
	private readonly TranslationHandler $translationHandler;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var TranslationProjectionService
	 */
	private readonly TranslationProjectionService $translationProjectionService;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var TranslationStatusService
	 */
	private readonly TranslationStatusService $translationStatusService;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var TmloService
	 */
	private readonly TmloService $tmloService;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var IGroupManager|null
	 */
	private readonly ?IGroupManager $groupManager;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var IAppConfig|null
	 */
	private readonly ?IAppConfig $appConfig;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var IEventDispatcher|null
	 */
	private readonly ?IEventDispatcher $eventDispatcher;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var \OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry|null
	 */
	private readonly ?\OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry $objectSourceRegistry;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var FieldEncryptionHandler|null
	 */
	private readonly ?FieldEncryptionHandler $fieldEncryptionHandler;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var \OCA\OpenRegister\Service\Flow\FlowRunContext|null
	 */
	private readonly ?\OCA\OpenRegister\Service\Flow\FlowRunContext $runContext;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var ContainerInterface|null
	 */
	private readonly ?ContainerInterface $container;

	/**
	 * Collaborator, taken from the services bundle.
	 *
	 * @var UserActivityStamper|null
	 */
	private readonly ?UserActivityStamper $userActivity;

	/**
	 * Constructor for SaveObject handler.
	 *
	 * The access guards are taken directly; every other collaborator comes in
	 * the services bundle and is kept on its own typed property.
	 *
	 * @param SaveObjectServices         $services                Mappers, records, runtime, handlers, platform and tenancy.
	 * @param PropertyRbacHandler        $propertyRbacHandler     Property-level RBAC handler.
	 * @param PermissionHandlerInterface $permissionHandler       Checks the caller's create rights for each nested object a cascade creates.
	 * @param FolderManagementHandler    $folderManagementHandler Folder access-control gateway.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
	 */
	public function __construct(
		SaveObjectServices $services,
		private readonly PropertyRbacHandler $propertyRbacHandler,
		private readonly PermissionHandlerInterface $permissionHandler,
		private readonly FolderManagementHandler $folderManagementHandler,
	) {
		$mappers = $services->persistence->mappers;
		$records = $services->persistence->records;
		$runtime = $services->persistence->runtime;
		$properties = $services->handlers->properties;
		$values = $services->handlers->values;
		$platform = $services->platform;
		$tenancy = $services->tenancy;
		$this->objectEntityMapper = $mappers->catalog->tables();
		$this->unifiedObjectMapper = $mappers->unifiedObjectMapper;
		$this->schemaMapper = $mappers->catalog->schemas();
		$this->registerMapper = $mappers->catalog->registers();
		$this->auditTrailMapper = $records->auditTrailMapper;
		$this->settingsService = $records->settingsService;
		$this->tmloService = $records->tmloService;
		$this->userActivity = $records->userActivity;
		$this->cacheHandler = $runtime->cacheHandler;
		$this->objectSourceRegistry = $runtime->objectSourceRegistry;
		$this->runContext = $runtime->runContext;
		$this->container = $runtime->container;
		$this->metaHydrationHandler = $properties->metaHydrationHandler;
		$this->filePropertyHandler = $properties->filePropertyHandler;
		$this->linkedEntityHandler = $properties->linkedEntityHandler;
		$this->computedFieldHandler = $properties->computedFieldHandler;
		$this->translationHandler = $values->translationHandler;
		$this->translationProjectionService = $values->translationProjectionService;
		$this->translationStatusService = $values->translationStatusService;
		$this->fieldEncryptionHandler = $values->fieldEncryptionHandler;
		$this->userSession = $platform->userSession;
		$this->urlGenerator = $platform->urlGenerator;
		$this->logger = $platform->logger;
		$this->eventDispatcher = $platform->eventDispatcher;
		$this->organisationService = $tenancy->organisationService;
		$this->groupManager = $tenancy->groupManager;
		$this->appConfig = $tenancy->appConfig;

		$this->versionHandler = new ObjectVersionHandler();
		$slugLog = new ObjectSlugLog(logger: $this->logger);
		$this->slugHandler = new ObjectSlugHandler(
			store: new ObjectSlugStore(magicMapper: $this->objectEntityMapper, registerMapper: $this->registerMapper, log: $slugLog),
			source: new ObjectSlugSource(hydrator: $this->metaHydrationHandler),
			log: $slugLog
		);
	}//end __construct()

	/**
	 * Refuse a write into a register or schema this organisation only consumes.
	 *
	 * Design D-2 is the argument: read-only to the consumer is a property of
	 * the resolution, not of a screen. The read path hands a consumer the
	 * holder's code list, so the write path is where "and you cannot change it"
	 * has to be true, and the message names the holder so the caseworker knows
	 * who to ask.
	 *
	 * 🔴 THE REFUSAL IS RECORDED. A cross-entity write attempt on a shared back
	 * office is exactly the fact an auditor asks about later, and a 403 that
	 * leaves no trace cannot answer them. It goes on the audit trail of the
	 * organisation that attempted it, and the log line that accompanies it
	 * carries the organisation UUID and a pseudonymous actor reference
	 * (REQ-SLE-003), never a name.
	 *
	 * The recording is best-effort and the refusal is not: an audit write that
	 * fails still leaves the write refused. A refusal that could be turned off
	 * by breaking the audit table would be no refusal at all.
	 *
	 * @param ObjectWriteTarget $target The register and schema being written, and the uuid when the caller named one.
	 *
	 * @return void
	 *
	 * @throws SharedMasterDataWriteException When the acting organisation only consumes the resource.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	private function refuseSharedMasterDataWrite(ObjectWriteTarget $target): void {
		$resolver = $this->resolveSharedMasterDataService();
		if ($resolver === null) {
			return;
		}

		$activeOrgUuids = [];
		try {
			$activeOrgUuids = $this->organisationService->getUserActiveOrganisations();
		} catch (\Throwable $e) {
			// No resolvable organisation means no share can be consumed, so
			// there is nothing for this guard to refuse. The ordinary tenancy
			// checks downstream still apply.
			return;
		}

		if ($activeOrgUuids === []) {
			return;
		}

		try {
			$resolver->assertWritable(
				row: new DeclaredRow(table: SharedMasterDataService::REGISTERS, id: $target->registerId),
				activeOrgUuids: $activeOrgUuids
			);
			$resolver->assertWritable(
				row: new DeclaredRow(table: SharedMasterDataService::SCHEMAS, id: $target->schemaId),
				activeOrgUuids: $activeOrgUuids
			);
		} catch (SharedMasterDataWriteException $refusal) {
			$this->recordSharedMasterDataRefusal(
				refusal: $refusal,
				attempt: new SharedWriteAttempt(target: $target, sourceOrganisation: ($activeOrgUuids[0] ?? null))
			);

			throw $refusal;
		}//end try
	}//end refuseSharedMasterDataWrite()

	/**
	 * Record one refused cross-entity write, on the audit trail and in the log.
	 *
	 * @param SharedMasterDataWriteException $refusal The refusal.
	 * @param SharedWriteAttempt             $attempt Where the write was aimed, and the organisation that attempted it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	private function recordSharedMasterDataRefusal(
		SharedMasterDataWriteException $refusal,
		SharedWriteAttempt $attempt,
	): void {
		$target        = $attempt->target;
		$activeOrgUuid = $attempt->sourceOrganisation;
		$registerId = $target->registerId;
		$schemaId   = $target->schemaId;
		$uuid       = $target->uuid;
		$redactor = new TenantLogRedactor(appConfig: $this->appConfig);

		$actorId = null;
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$actorId = $user->getUID();
		}

		$context = [
			'type' => 'shared_master_data_write_denied',
			'sourceOrganisation' => $activeOrgUuid,
			'holderOrganisation' => $refusal->getHolderUuid(),
			'resourceType' => $refusal->getResourceType(),
			'register' => $registerId,
			'schema' => $schemaId,
			'objectUuid' => $uuid,
			'actor' => $redactor->pseudonym(userId: $actorId),
		];

		$line = $redactor->line(context: $context);
		if ($line !== null) {
			$this->logger->warning(
				'[SaveObject] Refused a write to shared master data held by another organisation',
				$line
			);
		}

		try {
			$options = new SharedMasterDataRefusalOptions(
				context: $redactor->redact(context: $context),
				target: new AuditedObject(register: $registerId, schema: $schemaId, objectUuid: $uuid),
				actor: new NamedActor(id: $actorId, name: $redactor->pseudonym(userId: $actorId))
			);
			$this->auditTrailMapper->recordSharedMasterDataRefusal(
				refusal: new SharedMasterDataRefusal(
					holderOrganisation: $refusal->getHolderUuid(),
					sourceOrganisation: ($activeOrgUuid ?? ''),
					resourceType: $refusal->getResourceType(),
					options: $options
				)
			);
		} catch (\Throwable $e) {
			// Best effort by design: see the docblock on the caller. The write
			// is refused whether or not the record could be written, and the
			// log line above already carries the fact.
			$this->logger->warning(
				'[SaveObject] Could not record a shared master data refusal on the audit trail',
				['file' => __FILE__, 'line' => __LINE__, 'cause' => $e::class]
			);
		}//end try
	}//end recordSharedMasterDataRefusal()

	/**
	 * A service from the injected app container, or null when there is no container,
	 * it cannot provide the service (logged at debug), or it answers something else.
	 *
	 * @param class-string<T> $id    The class or interface to ask for.
	 * @param string          $label The name the debug line gives the service.
	 *
	 * @return T|null
	 *
	 * @template T of object
	 */
	private function containerService(string $id, string $label): ?object {
		try {
			$service = $this->container?->get($id);
		} catch (\Throwable $e) {
			$this->logger->debug('[SaveObject] ' . $label . ' not available: ' . $e->getMessage());
			return null;
		}

		if ($service instanceof $id) {
			return $service;
		}

		return null;
	}//end containerService()

	/**
	 * Resolve the shared master data service from the app container, or null.
	 *
	 * Lazily, through the INJECTED container and never the global server, for
	 * the reason spelled out on resolveRetentionService() below: a global
	 * lookup here autowires a mapper cycle that ate 19 GB in a unit-test run.
	 *
	 * @return SharedMasterDataService|null The resolver, or null when unavailable.
	 */
	private function resolveSharedMasterDataService(): ?SharedMasterDataService {
		return $this->containerService(id: SharedMasterDataService::class, label: 'SharedMasterDataService');
	}//end resolveSharedMasterDataService()

	/**
	 * Resolve the retention service from the app container, or null when there is none.
	 *
	 * The retention service is resolved lazily instead of being constructor-injected
	 * because its dependency graph reaches back into the object mappers this handler
	 * already owns. It goes through the injected app container, never the global
	 * server: the global container knows nothing about this app's registrations
	 * outside a booted Nextcloud, so a lookup there autowires an unbounded
	 * MagicMapper -> SettingsService -> ValidationOperationsHandler -> ValidateObject
	 * cycle. That cycle ate 19 GB in a unit-test run on 2026-09-08.
	 *
	 * @return \OCA\OpenRegister\Service\RetentionServiceInterface|null The service, or null when unavailable
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-objects-must-carry-mdto-compliant-archival-metadata-in-the-retention-field
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-calculate-archiefactiedatum-using-configurable-afleidingswijzen
	 */
	private function resolveRetentionService(): ?\OCA\OpenRegister\Service\RetentionServiceInterface {
		return $this->containerService(id: \OCA\OpenRegister\Service\RetentionServiceInterface::class, label: 'RetentionService');
	}//end resolveRetentionService()

	/**
	 * Get sub-objects created during cascade operations.
	 *
	 * Returns an array of sub-objects indexed by their UUID, suitable for
	 * inclusion in the parent object's @self.objects property.
	 *
	 * @return array<string, array> Sub-objects indexed by UUID
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function getCreatedSubObjects(): array {
		return $this->createdSubObjects;
	}//end getCreatedSubObjects()

	/**
	 * Clear the created sub-objects cache.
	 *
	 * Should be called before processing a new parent object to ensure
	 * sub-objects from previous operations are not included.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function clearCreatedSubObjects(): void {
		$this->createdSubObjects = [];
	}//end clearCreatedSubObjects()

	/**
	 * Clear all request-scoped caches.
	 *
	 * Should be called at the start of a new top-level save operation to ensure
	 * caches from previous operations don't interfere. This clears:
	 * - Created sub-objects cache
	 * - Schema entity cache
	 * - Register entity cache
	 * - Schema reference cache
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function clearAllCaches(): void {
		$this->createdSubObjects = [];
		$this->lookups()->clear();
	}//end clearAllCaches()

	/**
	 * The saves in flight on this handler.
	 *
	 * @return SaveCallStack
	 */
	private function saveCalls(): SaveCallStack {
		return $this->saveCalls ??= new SaveCallStack();
	}//end saveCalls()

	/**
	 * The schema and register lookups of this save handler.
	 *
	 * @return SaveLookups
	 */
	private function lookups(): SaveLookups {
		return $this->lookups ??= new SaveLookups(schemaMapper: $this->schemaMapper, registerMapper: $this->registerMapper);
	}//end lookups()

	/**
	 * The inverse relation writer of this save handler.
	 *
	 * @return InverseRelations
	 */
	private function inverseRelations(): InverseRelations {
		return new InverseRelations(objects: $this->objectEntityMapper, schemas: $this->schemaMapper, logger: $this->logger);
	}//end inverseRelations()

	/**
	 * The write-back of this save handler.
	 *
	 * @return InverseWriteBack
	 */
	private function inverseWriteBack(): InverseWriteBack {
		return new InverseWriteBack(objects: $this->objectEntityMapper, saver: $this, lookups: $this->lookups(), urls: $this->urlGenerator);
	}//end inverseWriteBack()

	/**
	 * What counts as empty for this save handler.
	 *
	 * @return EmptyValues
	 */
	private function emptyValues(): EmptyValues {
		return new EmptyValues(urls: $this->urlGenerator);
	}//end emptyValues()

	/**
	 * The outdated-translation check of this save handler.
	 *
	 * @return OutdatedTranslations
	 */
	private function outdatedTranslations(): OutdatedTranslations {
		return new OutdatedTranslations(
			translations: $this->translationHandler,
			projection: $this->translationProjectionService,
			status: $this->translationStatusService,
			logger: $this->logger
		);
	}//end outdatedTranslations()

	/**
	 * The file properties of this save handler.
	 *
	 * @return ObjectFileProperties
	 */
	private function objectFiles(): ObjectFileProperties {
		return new ObjectFileProperties(files: $this->filePropertyHandler, objects: $this->objectEntityMapper, logger: $this->logger);
	}//end objectFiles()

	/**
	 * Store the file properties of a created object; the call's data gets the file ids.
	 *
	 * @param ObjectSaveCall $call        The save call.
	 * @param ObjectEntity   $savedEntity The created object.
	 *
	 * @return ObjectEntity The object, updated when a file property was processed.
	 */
	private function storeCreatedFileProperties(ObjectSaveCall $call, ObjectEntity $savedEntity): ObjectEntity {
		$write = new ObjectFileWrite();
		$write->entity = $savedEntity;
		$write->data = $call->data;
		$write->register = $call->register;
		$write->schema = $call->schema;

		try {
			return $this->objectFiles()->storeForCreate(write: $write);
		} finally {
			$call->data = $write->data;
		}
	}//end storeCreatedFileProperties()

	/**
	 * The relation scanner of this save handler.
	 *
	 * @return RelationScanner
	 */
	private function relationScanner(): RelationScanner {
		return $this->relationScanner ??= new RelationScanner(urls: $this->urlGenerator);
	}//end relationScanner()

	/**
	 * The schema defaults of this save handler.
	 *
	 * @return PropertyDefaults
	 */
	private function propertyDefaults(): PropertyDefaults {
		return $this->propertyDefaults ??= new PropertyDefaults(hydration: $this->metaHydrationHandler, urls: $this->urlGenerator);
	}//end propertyDefaults()

	/**
	 * The reference existence check of this save handler, with its request-long verdicts.
	 *
	 * @return ReferenceExistence
	 */
	private function referenceExistence(): ReferenceExistence {
		return $this->referenceExistence ??= new ReferenceExistence(
			lookups: $this->lookups(),
			saveCalls: $this->saveCalls(),
			mapper: $this->unifiedObjectMapper,
			events: new ReferenceEvents(dispatcher: $this->eventDispatcher, logger: $this->logger)
		);
	}//end referenceExistence()

	/**
	 * The reference validator of this save handler.
	 *
	 * @return ReferenceValidator
	 */
	private function referenceValidator(): ReferenceValidator {
		return $this->referenceValidator ??= new ReferenceValidator(
			existence: $this->referenceExistence(),
			filter: new ReferenceFilterGuard(lookups: $this->lookups(), mapper: $this->unifiedObjectMapper),
			events: new ReferenceEvents(dispatcher: $this->eventDispatcher, logger: $this->logger),
			logger: $this->logger
		);
	}//end referenceValidator()

	/**
	 * Get a cached schema by ID, or fetch and cache it.
	 *
	 * @param int|string $schemaId The schema ID to look up
	 *
	 * @return Schema The schema entity
	 *
	 * @throws DoesNotExistException If schema not found
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getCachedSchema(int|string $schemaId): Schema {
		return $this->lookups()->schema(schemaId: $schemaId);
	}//end getCachedSchema()

	/**
	 * Get a cached register by ID, or fetch and cache it.
	 *
	 * @param int|string $registerId The register ID to look up
	 *
	 * @return Register The register entity
	 *
	 * @throws DoesNotExistException If register not found
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getCachedRegister(int|string $registerId): Register {
		return $this->lookups()->register(registerId: $registerId);
	}//end getCachedRegister()

	/**
	 * Track a created sub-object for inclusion in @self.objects.
	 *
	 * This method is called by CascadingHandler when creating related objects
	 * during pre-validation cascading.
	 *
	 * @param string $uuid The UUID of the created sub-object
	 * @param array $objectData The serialized object data
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function trackCreatedSubObject(string $uuid, array $objectData): void {
		$this->createdSubObjects[$uuid] = $objectData;
	}//end trackCreatedSubObject()

	/**
	 * Resolves a schema reference to a schema ID.
	 *
	 * This method handles various types of schema references:
	 * - Direct ID/UUID: "34", "21aab6e0-2177-4920-beb0-391492fed04b"
	 * - JSON Schema path references: "#/components/schemas/Contactgegevens"
	 * - URL references: "http://example.com/api/schemas/34"
	 * - Slug references: "contactgegevens"
	 *
	 * For path and URL references, it extracts the last part and matches against schema slugs (case-insensitive).
	 *
	 * @param string $reference The schema reference to resolve
	 *
	 * @return null|numeric-string The resolved schema ID or null if not found
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function resolveSchemaReference(string $reference): ?string {
		return $this->lookups()->schemaId(reference: $reference);
	}//end resolveSchemaReference()

	/**
	 * Scans an object for relations (UUIDs and URLs) and returns them in dot notation
	 *
	 * This method now also checks schema properties for relation types:
	 * - Properties with type 'text' and format 'uuid', 'uri', or 'url'
	 * - Properties with type 'object' that contain string values (always treated as relations)
	 * - Properties with type 'array' of objects that contain string values
	 *
	 * @param array $data The object data to scan
	 * @param string $prefix The current prefix for dot notation (used in recursion)
	 * @param Schema|null $schema The schema to check property definitions against
	 *
	 * @return array Array of relations with dot notation paths as keys and UUIDs/URLs as values
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function scanForRelations(array $data, string $prefix = '', ?Schema $schema = null): array {
		return $this->relationScanner()->scan(data: $data, scan: new RelationScanRun(prefix: $prefix, schema: $schema));
	}//end scanForRelations()

	/**
	 * Hydrates object metadata fields based on schema configuration.
	 *
	 * This method uses the schema configuration to set metadata fields on the object entity
	 * based on the object data. It supports:
	 * - Simple field mapping using dot notation paths (e.g., 'contact.email', 'title')
	 * - Twig-like concatenation for combining multiple fields (e.g., '{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}')
	 * - All metadata fields: name, description, summary, image, slug
	 *
	 * Schema configuration example:
	 * ```json
	 * {
	 *   "objectNameField": "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}",
	 *   "objectDescriptionField": "beschrijving",
	 *   "objectSummaryField": "beschrijvingKort",
	 *   "objectImageField": "afbeelding",
	 *   "objectSlugField": "naam"
	 * }
	 * ```
	 *
	 * This method is public to support both individual saves and bulk save operations.
	 * During bulk imports, it's called from SaveObjects for each object to ensure consistent
	 * metadata extraction across all import paths.
	 *
	 * @param ObjectEntity $entity The entity to hydrate
	 * @param Schema $schema The schema containing the configuration
	 *
	 * @see website/docs/developers/import-flow.md for complete import flow documentation
	 * @see website/docs/core/schema.md for schema configuration details
	 *
	 * @return void
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex metadata extraction from multiple sources
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple field types and formats require branching
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive metadata hydration logic
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function hydrateObjectMetadata(ObjectEntity $entity, Schema $schema): void {
		$field = ($schema->getConfiguration()['objectImageField'] ?? null);
		$objectData = $entity->getObject();

		// Delegate simple metadata extraction (name, description, summary, slug) to handler.
		$this->metaHydrationHandler->hydrateObjectMetadata(entity: $entity, schema: $schema);

		if ($field !== null) {
			$value = $this->metaHydrationHandler->getValueFromPath(data: $objectData, path: $field);
			(new ObjectImage(logger: $this->logger))->apply(object: $entity, value: $value);
		}
	}//end hydrateObjectMetadata()

	/**
	 * Applies defaults with defaultBehavior: "always" BEFORE validation.
	 *
	 * This method is called from ObjectService before validation to ensure that
	 * computed/derived properties with defaultBehavior: "always" are set before
	 * validation runs. This allows properties like "dienstType" to be automatically
	 * populated from "type" even when the payload contains an invalid value.
	 *
	 * @param Schema $schema The schema containing property definitions.
	 * @param array $data The object data to transform.
	 *
	 * @return array The transformed data with "always" defaults applied.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function applyAlwaysDefaults(Schema $schema, array $data): array {
		return $this->propertyDefaults()->always(schema: $schema, data: $data);
	}//end applyAlwaysDefaults()

	/**
	 * Evaluate the schema's expression defaults for an object being CREATED.
	 *
	 * Called from ObjectService beside {@see self::applyAlwaysDefaults()} and
	 * for the same reason: a derived value has to exist before validation, or
	 * a property that is both required and derived can never be created.
	 *
	 * CREATE ONLY. A default is what a value starts as. Re-deriving it on every
	 * update would silently overwrite what a caseworker typed, which is a
	 * calculation and is a different annotation.
	 *
	 * The resolver is built here from this handler's own session rather than
	 * injected, because adding a constructor parameter to this class would
	 * change the positional signature ten unit tests construct it by. It holds
	 * no state between calls, so building it per create costs an object.
	 *
	 * @param Schema $schema The schema being written against.
	 * @param array<string, mixed> $data The object being created.
	 *
	 * @return array<string, mixed> The object with the derived defaults written onto it.
	 *
	 * @throws ExpressionDefaultException When an expression cannot be evaluated.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function applyExpressionDefaults(Schema $schema, array $data): array {
		$properties = ($schema->getProperties() ?? []);
		if (is_array($properties) === false || $properties === []) {
			return $data;
		}

		$resolver = new ExpressionDefaultResolver(
			evaluator: new CalculationEvaluator(
				placeholders: PlaceholderResolvers::forSession(userSession: $this->userSession)
			)
		);

		if ($resolver->declaresAny(properties: $properties) === false) {
			return $data;
		}

		return $resolver->apply(properties: $properties, data: $data);
	}//end applyExpressionDefaults()

	/**
	 * Auto-seed the lifecycle field from the parent on the CREATE path.
	 *
	 * When the schema declares an object-form `x-openregister-lifecycle.initial`
	 * (`{ from, field }`) and the lifecycle field is absent/null/empty, this
	 * resolves the parent reference from `data[initial.from]`, loads the parent
	 * through the standard read path (RBAC + multitenancy apply), and sets the
	 * lifecycle field to the parent's `initial.field` value. It runs BEFORE
	 * schema validation so a `required` lifecycle `$ref` field passes on a
	 * seeded create. Called from ObjectService only on the create path — never
	 * on update.
	 *
	 * Rules (design: `initial` object-form + auto-seed on create):
	 * - Empty-field-only: an explicitly provided value is NEVER overwritten.
	 * - Fail-soft no-op (debug log) when the parent ref is empty, the parent
	 *   cannot be loaded, or the parent's `initial.field` value is empty.
	 * - The legacy literal-string `initial` form is NOT auto-seeded (no static
	 *   behaviour change).
	 * - Dispatches no `ObjectTransitionedEvent` — this is initialisation.
	 *
	 * @param Schema $schema The schema whose annotation drives the seed.
	 * @param array $data The incoming object data (create payload).
	 *
	 * @return array The data, with the lifecycle field seeded when applicable.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function seedLifecycleFieldOnCreate(Schema $schema, array $data): array {
		return (new LifecycleSeed(mapper: $this->unifiedObjectMapper, logger: $this->logger))->apply(schema: $schema, data: $data);
	}//end seedLifecycleFieldOnCreate()

	/**
	 * Apply property default values to a data array (for use in bulk save paths).
	 *
	 * This is the public counterpart to setDefaultValues() that can work without
	 * an ObjectEntity. It applies defaults based on defaultBehavior settings and
	 * renders templates using MetadataHydrationHandler's processTwigLikeTemplate().
	 *
	 * @param Schema $schema The schema containing property definitions.
	 * @param array $data The object data to apply defaults to.
	 *
	 * @return array The data with defaults applied.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function applyPropertyDefaults(Schema $schema, array $data): array {
		return $this->propertyDefaults()->applying(schema: $schema, data: $data);
	}//end applyPropertyDefaults()

	/**
	 * Cascade objects from the data to separate objects.
	 *
	 * This method processes object properties that have schema references ($ref) and determines
	 * whether they should be cascaded as separate objects or kept as nested data.
	 *
	 * Objects are cascaded (saved separately) only if they have both:
	 * - $ref: Schema reference
	 * - inversedBy: Relation configuration
	 *
	 * Objects with only $ref (like nested objects with objectConfiguration.handling: "nested-object")
	 * are kept as-is in the data and not cascaded.
	 *
	 * TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
	 *
	 * @param CascadeRun $run    The cascade pass: the parent object entity, the object data to
	 *                           process and the parent's stored data when its schema opted into
	 *                           orphan cleanup, else empty.
	 * @param Schema     $schema The schema of the parent object
	 *
	 * @return array The processed data with cascaded objects removed
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cascadeObjects(CascadeRun $run, Schema $schema): array {
		try {
			$schemaObject = $schema->getSchemaObject($this->urlGenerator);
			$properties = json_decode(json_encode($schemaObject), associative: true)['properties'] ?? [];
		} catch (Exception $e) {
			return $run->data;
		}

		// Single object properties first, then array properties, as each pass rewrites the data.
		$objectProperties = array_filter($properties, fn (array $property) => CascadeRules::isObjectProperty(property: $property));
		$arrayObjProps = array_filter($properties, fn (array $property) => CascadeRules::isArrayProperty(property: $property));
		foreach ($objectProperties as $property => $definition) {
			$run->property = $property;
			$run->definition = $definition;
			$this->cascadeObjectProperty(run: $run);
		}

		foreach ($arrayObjProps as $property => $definition) {
			$run->property = $property;
			$run->definition = $definition;
			$this->cascadeArrayProperty(run: $run);
		}

		return $run->data;
	}//end cascadeObjects()

	/**
	 * Cascade the single object of the run's property into its own object.
	 *
	 * A failing cascade leaves the property as it was, except when the caller
	 * is not authorized, which is rethrown.
	 *
	 * @param CascadeRun $run The cascade pass, on the property to cascade.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the caller may not create the nested object.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cascadeObjectProperty(CascadeRun $run): void {
		$propValue = $run->data[$run->property] ?? null;
		if ($propValue === null || empty($propValue) === true
			|| (is_array($propValue) === false && is_object($propValue) === false)
		) {
			return;
		}

		$objectData = $propValue;
		if (is_object($propValue) === true) {
			$objectData = (array)$propValue;
		}

		// Skip if the object is effectively empty (only contains empty values).
		if ($this->emptyValues()->isEmptyObject(object: $objectData) === true) {
			return;
		}

		try {
			$createdUuid = $this->cascadeSingleObject(
				target: new NestedObjectTarget(parent: $run->parent, definition: $run->definition),
				object: $objectData
			);
			CascadeRules::applySingleResult(run: $run, createdUuid: $createdUuid);
		} catch (NotAuthorizedException $e) {
			throw $e;
		} catch (Exception $e) {
			// Continue with other properties even if one fails.
			$this->logger->warning(
				message: '[SaveObject] Cascade of an object property failed, continuing with the other properties',
				context: [
					'property' => $run->property,
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end cascadeObjectProperty()

	/**
	 * Cascade the objects of the run's array property, and queue what the parent no longer references.
	 *
	 * @param CascadeRun $run The cascade pass, on the property to cascade.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the caller may not create a nested object.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cascadeArrayProperty(CascadeRun $run): void {
		if (is_array($run->data[$run->property] ?? null) === false) {
			return;
		}

		CascadeRules::classifyArray(run: $run);

		// Handle empty array: clean up all related objects and continue.
		if (empty($run->data[$run->property]) === true) {
			$this->queueOrphans(run: $run, uuids: $run->oldUuids);
			$run->data[$run->property] = [];
			return;
		}

		try {
			$createdUuids = $this->cascadeMultipleObjects(
				target: new NestedObjectTarget(parent: $run->parent, definition: $run->definition),
				propData: $run->data[$run->property]
			);

			if ($run->isRelated === true) {
				$this->applyRelatedCascadeResult(run: $run, createdUuids: $createdUuids);
				return;
			}

			CascadeRules::applyArrayResult(run: $run, createdUuids: $createdUuids);
		} catch (NotAuthorizedException $e) {
			throw $e;
		} catch (Exception $e) {
			// Continue with other properties even if one fails.
			$this->logger->warning(
				message: '[SaveObject] Cascade of an array property failed, continuing with the other properties',
				context: [
					'property' => $run->property,
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end cascadeArrayProperty()

	/**
	 * Queue references of the run's property for orphan cleanup after the parent is saved.
	 *
	 * @param CascadeRun $run   The cascade pass, on the array property.
	 * @param string[]   $uuids The references the parent no longer holds.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function queueOrphans(CascadeRun $run, array $uuids): void {
		if (empty($uuids) === true) {
			return;
		}

		$this->pendingOrphans[(string)$run->parent->getUuid()][] = [
			$uuids,
			$this->orphanScope(objectEntity: $run->parent, definition: $run->definition),
		];
	}//end queueOrphans()

	/**
	 * Store related-object references in the parent: the identifiers passed through plus the ones just created.
	 *
	 * The cascade of a list returns only newly created UUIDs, so existing
	 * identifiers (standard, dashless, prefixed or numeric) are kept from the
	 * incoming data. Stored references that are no longer held are queued as
	 * orphans.
	 *
	 * @param CascadeRun $run          The cascade pass, on the array property.
	 * @param string[]   $createdUuids The UUIDs of the newly created objects.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyRelatedCascadeResult(CascadeRun $run, array $createdUuids): void {
		$existingUuids = array_filter(
			$run->data[$run->property] ?? [],
			fn ($item) => is_string($item) === true && CascadeItem::isObjectIdentifier(value: $item) === true
		);

		$run->data[$run->property] = array_values(array_unique(array_merge($existingUuids, $createdUuids)));

		if (empty($run->oldUuids) === false) {
			$this->queueOrphans(
				run: $run,
				uuids: self::removedReferences(old: $run->oldUuids, kept: $run->data[$run->property])
			);
		}
	}//end applyRelatedCascadeResult()

	/**
	 * Cascade multiple objects from an array of objects in the data.
	 *
	 * @param NestedObjectTarget $target   The parent object and the definition of the property to add the objects to.
	 * @param array              $propData The data in the property.
	 *
	 * @return string[] Array of UUIDs of created objects
	 *
	 * @throws Exception
	 *
	 * @psalm-return list<string>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)         Uuid::isValid is standard Symfony UID pattern
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex array object cascading logic
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple validation and processing paths
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cascadeMultipleObjects(NestedObjectTarget $target, array $propData): array {
		if (array_is_list($propData) === false) {
			return [];
		}

		$validObjects = array_filter($propData, fn ($object): bool => CascadeItem::isCandidate(object: $object));
		if (empty($validObjects) === true) {
			return [];
		}

		$definition = CascadeRules::itemsDefinition(property: $target->definition);
		if ($definition === null) {
			return [];
		}

		// Existing ids need no cascade; only nested objects are created.
		$objectsToCreate = array_filter($validObjects, fn ($object): bool => is_string($object) === false);
		if (empty($objectsToCreate) === true) {
			return [];
		}

		// Create each sub-object using optimized cascadeSingleObject().
		// Performance optimizations applied:
		// - Request-scoped schema/register caching (avoids repeated DB lookups)
		// - Silent mode (skips audit trails for sub-objects)
		// - Skipped inverse relation updates (handled via inversedBy property).
		$createdUuids = [];
		foreach ($objectsToCreate as $object) {
			try {
				$uuid = $this->cascadeSingleObject(
					target: new NestedObjectTarget(parent: $target->parent, definition: $definition),
					object: $object
				);
				if ($uuid !== null) {
					$createdUuids[] = $uuid;
				}
			} catch (NotAuthorizedException $e) {
				throw $e;
			} catch (Exception $e) {
				// Continue with other objects even if one fails.
				$this->logger->warning(
					message: '[SaveObject] Cascade of a nested object failed, continuing with the others',
					context: [
						'exception' => $e->getMessage(),
					]
				);
			}
		}

		return $createdUuids;
	}//end cascadeMultipleObjects()

	/**
	 * Where the sub-objects of a cascading property live, and how one names its parent.
	 *
	 * @param ObjectEntity $objectEntity The parent object.
	 * @param array        $definition   The property definition.
	 *
	 * @return array{register: Register|null, schema: Schema|null, inverse: string|null, parent: string|null} Null when unresolved.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function orphanScope(ObjectEntity $objectEntity, array $definition): array {
		$subSchemaRef = $definition['items']['$ref'] ?? $definition['$ref'] ?? null;
		$subSchemaId = null;
		if ($subSchemaRef !== null) {
			$subSchemaId = $this->resolveSchemaReference(reference: $subSchemaRef);
		}

		$subSchema = null;
		if ($subSchemaId !== null) {
			$subSchema = $this->getCachedSchema(schemaId: $subSchemaId);
		}

		$subRegister = null;
		if ($objectEntity->getRegister() !== null) {
			$subRegister = $this->getCachedRegister(registerId: $objectEntity->getRegister());
		}

		return [
			'register' => $subRegister,
			'schema' => $subSchema,
			'inverse' => $definition['items']['inversedBy'] ?? $definition['inversedBy'] ?? null,
			'parent' => $objectEntity->getUuid(),
		];
	}//end orphanScope()

	/**
	 * Cascade a single object form an object in the source data
	 *
	 * @param NestedObjectTarget $target The parent object and the definition of the property the cascaded object is found in
	 * @param array              $object The object to cascade
	 *
	 * @return string|null The UUID of the created object, or null if no object was created
	 *
	 * @throws Exception
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex single object cascading with relation handling
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple configuration and validation paths
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cascadeSingleObject(NestedObjectTarget $target, array $object): ?string {
		$objectEntity = $target->parent;
		$definition   = $target->definition;
		// Validate that we have the necessary configuration.
		if (isset($definition['$ref']) === false) {
			return null;
		}

		// Skip if object is empty or doesn't contain actual data.
		if (empty($object) === true || CascadeItem::hasOnlyEmptyId(object: $object) === true) {
			return null;
		}

		$objectId = $objectEntity->getUuid();
		if (empty($objectId) === true) {
			return null;
		}

		// Only set inversedBy if it's configured (for relation-based cascading).
		if (($definition['inversedBy'] ?? null) !== null) {
			$object[$definition['inversedBy']] = CascadeItem::withParentReference(
				current: $object[$definition['inversedBy']] ?? null,
				parentId: $objectId
			);
		}

		$register = CascadeItem::register(definition: $definition, parentRegister: $objectEntity->getRegister());

		// A nested inline object is always created. The save would take its `id` or
		// `@self.id` as the row to write onto, looked up past the read filters.
		unset($object['id']);
		if (is_array($object['@self'] ?? null) === true) {
			unset($object['@self']['id'], $object['@self']['uuid']);
		}

		if (($definition['inversedBy'] ?? null) === null) {
			unset($object['@self']);
		}

		// Resolve schema reference to actual schema ID.
		$schemaId = $this->resolveSchemaReference(reference: $definition['$ref']);
		if ($schemaId === null) {
			throw new Exception("Invalid schema reference: {$definition['$ref']}");
		}

		$cascadeRegister = null;
		if (is_int($register) === true || is_string($register) === true) {
			$cascadeRegister = (string)$register;
		}

		$this->assertMayCascadeCreate(schema: $this->getCachedSchema(schemaId: $schemaId), register: $cascadeRegister);

		// Use silent mode for cascaded sub-objects to improve performance.
		// This skips audit trail creation for each sub-object, reducing database writes.
		// The parent object's audit trail still captures the overall operation.
		$savedObject = $this->saveObject(
			register: $register,
			schema: $schemaId,
			data: $object,
			uuid: null,
			_rbac: true,
			_multitenancy: true,
			persist: true,
			silent: true
		);
		$savedUuid = $savedObject->getUuid();

		// Track the created sub-object for inclusion in @self.objects.
		// This allows the parent response to include the full sub-object data.
		if ($savedUuid !== null) {
			$this->createdSubObjects[$savedUuid] = $savedObject->jsonSerialize();
		}

		return $savedUuid;
	}//end cascadeSingleObject()

	/**
	 * Deletes orphaned related objects that are no longer referenced by the parent.
	 *
	 * When a schema sets `orphanCleanup` and a parent stops referencing some
	 * sub-objects, the sub-objects the parent owns are soft-deleted. A property without
	 * an inverse relation holds plain pointers, so nothing is deleted for it, and a
	 * row that may not be deleted only loses the reference.
	 *
	 * @param string[] $orphanedUuids References the stored parent held and no longer holds.
	 * @param array $scope The scope orphanScope() answers.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function deleteOrphanedRelatedObjects(array $orphanedUuids, array $scope): void {
		$register = $scope['register'];
		$schema = $scope['schema'];
		if ($register === null || $schema === null || $scope['inverse'] === null) {
			return;
		}

		foreach ($orphanedUuids as $uuid) {
			try {
				$orphanedObject = $this->objectEntityMapper->find(
					identifier: $uuid,
					register: $register,
					schema: $schema,
					includeDeleted: false,
					_rbac: false,
					_multitenancy: false
				);

				if ($this->orphanMayBeDeleted(orphan: $orphanedObject, scope: $scope + ['uuid' => $uuid]) === false) {
					continue;
				}

				// Soft delete: set deletion metadata and update (consistent with DeleteObject).
				// The guard admits only a signed-in caller.
				$deletionData = [
					'deletedBy' => $this->userSession->getUser()?->getUID(),
					'deletedAt' => (new DateTime())->format(DateTime::ATOM),
					'objectId' => $orphanedObject->getUuid(),
					'reason' => 'orphaned-related-object',
				];
				$orphanedObject->setDeleted($deletionData);

				$this->objectEntityMapper->update(
					entity: $orphanedObject,
					register: $register,
					schema: $schema
				);

				$this->logger->info(
					message: '[SaveObject] Soft-deleted orphaned related object',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'uuid' => $uuid,
					]
				);
			} catch (DoesNotExistException $e) {
				// Object already deleted or doesn't exist, nothing to clean up.
			} catch (Exception $e) {
				// Log but continue — don't let cleanup failures block the parent update.
				$this->logger->warning(
					message: '[SaveObject] Failed to delete orphaned related object',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'uuid' => $uuid,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach
	}//end deleteOrphanedRelatedObjects()

	/**
	 * Soft-delete what a parent's save left orphaned, once the parent is saved.
	 *
	 * @param string $parent The parent's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function settleOrphans(string $parent): void {
		foreach (($this->pendingOrphans[$parent] ?? []) as [$orphanedUuids, $scope]) {
			$this->deleteOrphanedRelatedObjects(orphanedUuids: $orphanedUuids, scope: $scope);
		}

		unset($this->pendingOrphans[$parent]);
	}//end settleOrphans()

	/**
	 * The stored references a save no longer keeps, compared in one normal form.
	 *
	 * @param string[] $old  The stored references.
	 * @param array    $kept The references the save keeps.
	 *
	 * @return string[] The removed references.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private static function removedReferences(array $old, array $kept): array {
		$keptKeys = array_map(self::referenceKey(...), array_filter($kept, 'is_string'));

		return array_values(
			array_filter($old, static fn (string $uuid): bool => in_array(self::referenceKey(reference: $uuid), $keptKeys, true) === false)
		);
	}//end removedReferences()

	/**
	 * One normal form of a uuid reference: lower case, no dashes, no `id-` style prefix.
	 *
	 * @param string $reference The reference.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private static function referenceKey(string $reference): string {
		$pattern = '/^[a-z]+-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/';

		return str_replace('-', '', preg_replace($pattern, '$1', strtolower($reference)));
	}//end referenceKey()

	/**
	 * Whether a row the parent stopped referencing is its own and may be deleted.
	 *
	 * The row must be the referenced uuid itself (the lookup also matches slugs and
	 * uris) and must name the parent through the inverse property, otherwise the
	 * reference was a plain pointer to a row with a life of its own.
	 *
	 * @param ObjectEntity $orphan The row that was found.
	 * @param array        $scope  The orphan scope, plus the referenced `uuid`.
	 *
	 * @return bool True when the row may be soft-deleted.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function orphanMayBeDeleted(ObjectEntity $orphan, array $scope): bool {
		$namesParent = (array)($orphan->getObject()[$scope['inverse']] ?? []);
		if ($orphan->getUuid() !== $scope['uuid'] || in_array($scope['parent'], $namesParent, true) === false) {
			return false;
		}

		$this->orphanGuard ??= new OrphanDeletionGuard(
			objectMapper: $this->objectEntityMapper,
			permissionHandler: $this->permissionHandler,
			userSession: $this->userSession,
			container: $this->container
		);

		return $this->orphanGuard->allows(row: $orphan, register: $scope['register'], schema: $scope['schema']);
	}//end orphanMayBeDeleted()

	/**
	 * Fills in missing schema properties with null for an object being updated.
	 *
	 * The magic mapper performs partial updates (only columns present in the data
	 * are SET in SQL). When a property is removed from the object data, the
	 * corresponding column is NOT touched. This method ensures all schema-defined
	 * properties are present in the data, with missing ones set to null, so the
	 * magic mapper will explicitly NULL them in the database.
	 *
	 * @param array $data The object data (may have missing properties).
	 * @param int|string $schemaId The schema ID to look up properties.
	 *
	 * @return array The data with all schema properties present (missing ones set to null).
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function fillMissingSchemaPropertiesWithNull(array $data, int|string $schemaId): array {
		try {
			$schema = $this->getCachedSchema(schemaId: $schemaId);
			$schemaObject = json_decode(json_encode($schema->getSchemaObject($this->urlGenerator)), associative: true);
			$properties = $schemaObject['properties'] ?? [];
		} catch (Exception $e) {
			return $data;
		}

		foreach (array_keys($properties) as $propertyName) {
			if (array_key_exists($propertyName, $data) === false) {
				$data[$propertyName] = null;
			}
		}

		return $data;
	}//end fillMissingSchemaPropertiesWithNull()

	/**
	 * Saves an object.
	 *
	 * @param Register|int|string|null $register The register containing the object.
	 * @param Schema|int|string $schema The schema to validate against.
	 * @param array $data The object data to save.
	 * @param string|null $uuid The UUID of the object to update (if updating).
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering (default: true).
	 * @param bool $persist Whether to persist the object to database (default: true).
	 * @param bool $silent Whether to skip audit trail creation and events (default: false).
	 * @param bool $_validation Whether to validate the object (default: true).
	 * @param array|null $uploadedFiles Uploaded files array (optional).
	 * @param IUser|null $currentUser Explicit acting user for `@self.folder` access checks; falls back to the session user when null.
	 * @param bool $failIfExists Insert-only: throw ObjectExistsException rather than update when taken (default: false).
	 * @param bool $_unowned Stamp the system identity even when a session exists.
	 * @param bool $_dedupOverride Save through a blocking duplicate match, when the caller is entitled to.
	 * @param string|null $_owner An explicit owner for a new object, such as a draft's scope value (decision 195).
	 *
	 * @return ObjectEntity The saved object entity.
	 *
	 * @throws Exception If there is an error during save.
	 * @throws ObjectExistsException When $failIfExists is true and the identifier is already taken.
	 * @throws DuplicateBlockedException When the schema declares `onCreate: "block"`, the candidate
	 *         strongly matches a stored object, and the caller may not override.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible save options
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Boolean flags needed for flexible save behavior
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function saveObject(
		Register|int|string|null $register,
		Schema|int|string $schema,
		array $data,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $persist = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
		bool $failIfExists = false,
		bool $_unowned = false,
		bool $_dedupOverride = false,
		?string $_owner = null,
	): ObjectEntity {
		// Extract UUID and @self metadata from data, then put the uploaded files in.
		[$uuid, $selfData, $data] = self::splitSelfData(data: $data, uuid: $uuid);
		$data = $this->injectUploadedFiles(data: $data, uploadedFiles: $uploadedFiles);

		$call = new ObjectSaveCall();
		$call->flags = new ObjectSaveFlags();
		$call->data = $data;
		$call->selfData = $selfData;
		$call->uuid = $uuid;
		$call->flags->rbac = $_rbac;
		$call->flags->multitenancy = $_multitenancy;
		$call->flags->persist = $persist;
		$call->flags->silent = $silent;
		$call->flags->currentUser = $currentUser;
		$call->flags->failIfExists = $failIfExists;
		$call->flags->unowned = $_unowned;
		$call->flags->dedupOverride = $_dedupOverride;
		$call->flags->owner = $_owner;
		$this->takeDedupOverrideFromBody(call: $call);
		$this->readClientCauseAttempt(call: $call);

		// Resolve schema and register to entity objects.
		[$call->schema, $call->schemaId, $call->register, $call->registerId] = $this->resolveSchemaAndRegister(
			schema: $schema,
			register: $register
		);

		return $this->saveResolvedObject(call: $call);
	}//end saveObject()

	/**
	 * Take the duplicate-override flag off the body before anything reads it as data.
	 *
	 * It is an instruction about this write, not a property, and leaving it in
	 * would fail schema validation on every schema that never declared it. The
	 * policy it belongs to is enforced on the create branch.
	 *
	 * There are two ways in, and both are needed. The PARAMETER is how the HTTP
	 * path gets here: `ObjectsController` strips every `_`-prefixed key from the
	 * body before it reaches saveObject(), so a flag sent only in the body would
	 * be gone by now. The BODY KEY is how a service-layer caller gets here: an
	 * import, a migration or a background job hands saveObject() an array and
	 * has no request to read a parameter from.
	 *
	 * @param ObjectSaveCall $call The save call; its data loses the key.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function takeDedupOverrideFromBody(ObjectSaveCall $call): void {
		if (array_key_exists(DedupCreatePolicyInterface::OVERRIDE_KEY, $call->data) === false) {
			return;
		}

		if (filter_var($call->data[DedupCreatePolicyInterface::OVERRIDE_KEY], FILTER_VALIDATE_BOOLEAN) === true) {
			$call->flags->dedupOverride = true;
		}

		unset($call->data[DedupCreatePolicyInterface::OVERRIDE_KEY]);
	}//end takeDedupOverrideFromBody()

	/**
	 * Read the client-cause mark from the object's `@self`.
	 *
	 * @param ObjectSaveCall $call The save call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	private function readClientCauseAttempt(ObjectSaveCall $call): void {
		$call->flags->clientCauseAttempt = (($call->selfData[WriteCause::CLIENT_ATTEMPT_KEY] ?? false) === true);
	}//end readClientCauseAttempt()

	/**
	 * Note a client-cause attempt against the object this save wrote.
	 *
	 * Runs before that object's audit row is built, and only for the save the
	 * mark came in on: an object cascaded from it is a save of its own.
	 *
	 * @param ObjectSaveCall $call   The save call.
	 * @param ObjectEntity   $object The object it wrote.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	private function noteClientCauseAttempt(ObjectSaveCall $call, ObjectEntity $object): void {
		if ($call->flags->clientCauseAttempt === true) {
			WriteCause::noteClientAttempt(objectUuid: (string)$object->getUuid());
		}
	}//end noteClientCauseAttempt()

	/**
	 * Save an object once its register and schema are resolved.
	 *
	 * @param ObjectSaveCall $call The save call.
	 *
	 * @return ObjectEntity The saved object entity.
	 *
	 * @throws Exception If there is an error during save.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function saveResolvedObject(ObjectSaveCall $call): ObjectEntity {
		// Shared master data guard (REQ-SLE-001, design D-2): a register or
		// schema this organisation only CONSUMES is read-only to it. It is a
		// property of the RESOURCE being written, decided before anything is
		// prepared, validated or persisted.
		if ($call->flags->persist === true && OrganisationContext::multitenancy(requested: $call->flags->multitenancy) === true) {
			$this->refuseSharedMasterDataWrite(
				target: new ObjectWriteTarget(registerId: $call->registerId, schemaId: $call->schemaId, uuid: $call->uuid)
			);
		}

		// Read-only projection guard: a schema served from an external source
		// (x-openregister-object-source) is read-only unless its provider opts
		// in to write-through (dbal-virtual-registers-crud), which re-verifies
		// its backing source's writable flag live and fails closed.
		$objectSource = $call->schema->getObjectSource();
		if ($call->flags->persist === true && $objectSource !== null) {
			return $this->delegateObjectSourceWrite(call: $call, objectSource: $objectSource);
		}

		// Normalize translatable properties (wrap simple values under default language).
		$call->data = $this->translationHandler->normalizeTranslationsForSave(
			objectData: $call->data,
			scope: new TranslationScope(schema: $call->schema, register: $call->register)
		);

		// Skip when _rbac is false (internal/system calls bypass all authorization).
		if ($call->flags->rbac === true && $call->schema->hasPropertyAuthorization() === true) {
			$this->refuseUnauthorizedProperties(call: $call);
		}

		$existingObject = $this->existingObjectForSave(call: $call);
		if ($existingObject !== null) {
			return $this->updateExistingForSave(call: $call, existingObject: $existingObject);
		}

		return $this->createNewForSave(call: $call);
	}//end saveResolvedObject()

	/**
	 * Refuse a save that modifies properties the caller may not write.
	 *
	 * @param ObjectSaveCall $call The save call.
	 *
	 * @return void
	 *
	 * @throws Exception When the incoming data touches an unauthorized property.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function refuseUnauthorizedProperties(ObjectSaveCall $call): void {
		$isCreate = ($call->uuid === null);
		$existingObjectData = [];

		// For updates, get existing object data for matching.
		if ($isCreate === false) {
			$envelope = $this->storedMatchEnvelope(call: $call);
			$isCreate = ($envelope === null);
			$existingObjectData = ($envelope ?? []);
		}

		$unauthorizedProps = $this->propertyRbacHandler->getUnauthorizedProperties(
			write: new PropertyWriteCheck(schema: $call->schema, request: new PropertyAccessRequest(object: $existingObjectData, isCreate: $isCreate), incoming: $call->data)
		);

		if (empty($unauthorizedProps) === false) {
			throw new Exception(
				'You are not authorized to modify the following properties: ' . implode(', ', $unauthorizedProps)
			);
		}
	}//end refuseUnauthorizedProperties()

	/**
	 * The match envelope of the stored object a save names, or null when there is none.
	 *
	 * @param ObjectSaveCall $call The save call.
	 *
	 * @return array|null The stored object's match envelope.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function storedMatchEnvelope(ObjectSaveCall $call): ?array {
		try {
			// The mapper's find() throws DoesNotExistException rather than returning null.
			return $this->unifiedObjectMapper->find(
				identifier: $call->uuid,
				register: $call->register,
				schema: $call->schema,
				_rbac: false,
				_multitenancy: false
			)->getMatchEnvelope();
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end storedMatchEnvelope()

	/**
	 * The stored object a save updates, or null when the save creates.
	 *
	 * Whether a write creates is decided here, by the stored row alone: nothing a
	 * caller sends says so.
	 *
	 * @param ObjectSaveCall $call The save call.
	 *
	 * @return ObjectEntity|null The object to update.
	 *
	 * @throws ObjectExistsException When failIfExists is set and the identifier is already taken.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function existingObjectForSave(ObjectSaveCall $call): ?ObjectEntity {
		if ($call->uuid === null) {
			return null;
		}

		$this->logSaveLookup(
			call: $call,
			message: '[SaveObject] UUID provided, checking for existing object'
		);

		$existingObject = $this->findAndValidateExistingObject(call: $call);

		if ($existingObject !== null) {
			$this->refuseExistingWhenInsertOnly(call: $call, existingObject: $existingObject);
		}

		return $existingObject;
	}//end existingObjectForSave()

	/**
	 * Log a step of the existing-object lookup of a save.
	 *
	 * @param ObjectSaveCall $call    The save call.
	 * @param string         $message The log message.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function logSaveLookup(ObjectSaveCall $call, string $message): void {
		$this->logger->debug(
			message: $message,
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $call->uuid,
				'register' => $call->register?->getId(),
				'schema' => $call->schema?->getId(),
			]
		);
	}//end logSaveLookup()

	/**
	 * Refuse the write when the caller claims an identifier that already exists.
	 *
	 * INSERT-ONLY GUARD (openregister#2210). The default is an upsert: an
	 * existing identifier is updated, silently and successfully. A caller that
	 * is CLAIMING something (a lock, a slot, a lease, a queue position) opts in
	 * with failIfExists and gets a 409 instead. Opt-in, because there is no way
	 * to discover from the code which callers depend on the silent upsert.
	 *
	 * This guard is a fast path, not what makes the claim safe: under
	 * concurrency N callers can all pass it having found nothing. The
	 * arbitration that closes the race lives in
	 * MagicMapper::saveObjectToRegisterSchemaTable(), which refuses both the
	 * update branch and a losing INSERT when this flag is set
	 * (openregister#2215). Do not remove that guard on the assumption that this
	 * one covers it.
	 *
	 * @param ObjectSaveCall $call           The save call.
	 * @param ObjectEntity   $existingObject The stored object the identifier names.
	 *
	 * @return void
	 *
	 * @throws ObjectExistsException When failIfExists is set.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function refuseExistingWhenInsertOnly(ObjectSaveCall $call, ObjectEntity $existingObject): void {
		if ($call->flags->failIfExists === true) {
			$this->logger->debug(
				message: '[SaveObject] Existing object found and failIfExists is set, refusing the write',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $call->uuid,
					'objectId' => $existingObject->getId(),
				]
			);

			throw new ObjectExistsException(
				message: sprintf('An object with identifier "%s" already exists.', (string)$call->uuid),
				uuid: (string)$call->uuid
			);
		}

		$this->logger->debug(
			message: '[SaveObject] Existing object found, proceeding with UPDATE',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $call->uuid,
				'objectId' => $existingObject->getId(),
			]
		);
	}//end refuseExistingWhenInsertOnly()

	/**
	 * Push the in-flight save onto the call stack and enter the cascade scope.
	 *
	 * Cascade descendants detect cycles back to ancestors through the call
	 * stack in `validateReferences()`. The caller pops the frame and leaves the
	 * scope in a finally block.
	 *
	 * @param ObjectSaveCall $call The save call.
	 *
	 * @return string|null The frame key to pop, or null when no frame was pushed.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function enterSaveFrame(ObjectSaveCall $call): ?string {
		$frameKey = $this->saveCalls()->push(
			frame: new SaveCallFrame(
				schemaSlug: ((string)($call->schema->getSlug() ?? $call->schema->getId())),
				uuid: (string)$call->uuid,
				register: self::frameRegister(register: $call->register)
			)
		);
		$this->enterCascadeScope(_rbac: $call->flags->rbac);

		return $frameKey;
	}//end enterSaveFrame()

	/**
	 * Update the stored object a save names, inside its save frame.
	 *
	 * @param ObjectSaveCall $call           The save call.
	 * @param ObjectEntity   $existingObject The stored object.
	 *
	 * @return ObjectEntity The updated object entity.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function updateExistingForSave(ObjectSaveCall $call, ObjectEntity $existingObject): ObjectEntity {
		$this->noteClientCauseAttempt(call: $call, object: $existingObject);
		$frameKey = $this->enterSaveFrame(call: $call);
		try {
			$call->selfData = $this->selfDataForUpdate(selfData: $call->selfData, _rbac: $call->flags->rbac);
			return $this->handleObjectUpdate(call: $call, existingObject: $existingObject);
		} finally {
			$this->leaveCascadeScope();
			$this->saveCalls()->pop(key: $frameKey);
		}
	}//end updateExistingForSave()

	/**
	 * Create a new object, under the schema's create-time duplicate policy.
	 *
	 * CREATE-TIME DUPLICATE POLICY (dedup-check-before-create). It runs on the
	 * SAVE path and not only in the check endpoint, so an import, a script or
	 * an integration is stopped all the same. It is NOT gated on $_rbac: the
	 * controller sets that off for every administrator, and an administrator is
	 * no more entitled to a second copy of a record than anyone else. Nothing
	 * happens unless the schema declares `onCreate: "block"`.
	 *
	 * @param ObjectSaveCall $call The save call.
	 *
	 * @return ObjectEntity The created object entity.
	 *
	 * @throws DuplicateBlockedException When a blocking duplicate match stands.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function createNewForSave(ObjectSaveCall $call): ObjectEntity {
		$dedupOverriddenMatches = [];
		$dedupPolicy = $this->resolveDedupCreatePolicy();
		if ($dedupPolicy !== null) {
			$dedupOverriddenMatches = $dedupPolicy->guardCreate(
				candidate: new CreateCandidate(
					register: $call->registerId,
					schema: $call->schemaId,
					data: $call->data,
					overrideRequested: $call->flags->dedupOverride
				)
			);
		}

		$this->recordUniqueHints(call: $call);

		$frameKey = $this->enterSaveFrame(call: $call);
		try {
			$created = $this->handleObjectCreation(call: $call);

			// An override is only on record once the object it overrode for
			// exists, so this runs after the write and never before it.
			if ($dedupPolicy !== null && count($dedupOverriddenMatches) > 0 && $call->flags->persist === true) {
				$dedupPolicy->recordOverride(object: $created, matches: $dedupOverriddenMatches);
			}

			return $created;
		} finally {
			$this->leaveCascadeScope();
			$this->saveCalls()->pop(key: $frameKey);
		}
	}//end createNewForSave()

	/**
	 * Record the soft-uniqueness warnings of a create.
	 *
	 * SOFT UNIQUENESS, the create half: it never refuses, it records a warning
	 * the controller puts on the response beside the created object.
	 *
	 * @param ObjectSaveCall $call The save call.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function recordUniqueHints(ObjectSaveCall $call): void {
		$uniqueHints = $this->resolveUniqueHintChecker();
		if ($uniqueHints === null) {
			return;
		}

		$uniqueHints->check(
			configuration: ($call->schema->getConfiguration() ?? []),
			candidate: new UniqueHintCandidate(
				scope: new UniqueHintScope(register: $call->registerId, schema: $call->schemaId),
				data: $call->data,
				selfUuid: null
			)
		);
	}//end recordUniqueHints()

	/**
	 * The register identifier a save call frame records.
	 *
	 * @param Register|null $register The register being written to.
	 *
	 * @return string|null The register id, or null when there is none.
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	private static function frameRegister(?Register $register): ?string {
		if ($register?->getId() === null) {
			return null;
		}

		return (string)$register->getId();
	}//end frameRegister()

	/**
	 * Record the permission mode of a save whose cascades are about to run.
	 *
	 * Paired with leaveCascadeScope() in a finally. A nested object created
	 * inside the scope is checked for create rights unless an enclosing save
	 * runs with RBAC off.
	 *
	 * @param bool $_rbac Whether the enclosing save applies RBAC.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC flag follows the save API
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function enterCascadeScope(bool $_rbac): void {
		$this->cascadeScopes[] = $_rbac;
	}//end enterCascadeScope()

	/**
	 * Close the scope opened by the matching enterCascadeScope().
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function leaveCascadeScope(): void {
		array_pop($this->cascadeScopes);
	}//end leaveCascadeScope()

	/**
	 * Refuse a nested object the caller may not create.
	 *
	 * A cascade creates every nested inline object as a new row of the
	 * property's target schema, so the caller needs create rights there,
	 * just as for a direct create. Skipped only when an enclosing save runs
	 * with RBAC off. With no enclosing save the check runs.
	 *
	 * @param Schema      $schema   The schema the nested object is created in.
	 * @param string|null $register The register it is created in, when known.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the caller may not create objects in the schema.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function assertMayCascadeCreate(Schema $schema, ?string $register = null): void {
		$this->permissionHandler->checkPermission(
			schema: $schema,
			action: 'create',
			options: new PermissionCheckOptions(register: $register),
			_rbac: in_array(needle: false, haystack: $this->cascadeScopes, strict: true) === false
		);
	}//end assertMayCascadeCreate()

	/**
	 * Resolve the soft-uniqueness checker from the app container, or null when
	 * there is none.
	 *
	 * Lazily resolved for the same cycle reason {@see resolveDedupCreatePolicy()}
	 * is: the checker reads through `ObjectService`, which owns this very
	 * handler, so constructor injection would close the loop and the container
	 * would refuse to build either end of it.
	 *
	 * @return UniqueHintChecker|null The checker, or null when unavailable.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	private function resolveUniqueHintChecker(): ?UniqueHintChecker {
		return $this->containerService(id: UniqueHintChecker::class, label: 'UniqueHintChecker');
	}//end resolveUniqueHintChecker()

	/**
	 * Resolve the create-time duplicate policy from the app container, or null
	 * when there is none.
	 *
	 * Lazily resolved for the same reason {@see resolveRetentionService()} is:
	 * the policy reuses `DuplicateDetectionService`, which reads candidates
	 * through `ObjectService`, which owns this very handler. Constructor
	 * injection would close that loop and Nextcloud's container would refuse
	 * to build either end of it. Going through the INJECTED app container
	 * rather than the global server is what keeps the lookup bounded: the
	 * global container knows nothing of this app's registrations and would
	 * autowire the cycle instead of failing.
	 *
	 * @return DedupCreatePolicyInterface|null The policy, or null when unavailable.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	private function resolveDedupCreatePolicy(): ?DedupCreatePolicyInterface {
		return $this->containerService(id: DedupCreatePolicyInterface::class, label: 'DedupCreatePolicy');
	}//end resolveDedupCreatePolicy()

	/**
	 * Takes `@self` and the identifier out of the data.
	 *
	 * @param array       $data The object data.
	 * @param string|null $uuid The UUID the caller gave, if any.
	 *
	 * @return array{0: string|null, 1: array, 2: array} [uuid, selfData, data without `@self` and `id`]
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function splitSelfData(array $data, ?string $uuid): array {
		// Extract @self metadata.
		$selfData = [];
		if (($data['@self'] ?? null) !== null && is_array($data['@self']) === true) {
			$selfData = $data['@self'];
		}

		// Use @self.id as UUID if no UUID is provided.
		if ($uuid === null && (($selfData['id'] ?? null) !== null || (($data['id'] ?? null) !== null) === true)) {
			$uuid = $selfData['id'] ?? $data['id'];
		}

		// Normalize empty string to null.
		if ($uuid === '') {
			$uuid = null;
		}

		// Remove the @self property from the data.
		unset($data['@self']);
		unset($data['id']);

		return [$uuid, $selfData, $data];
	}//end splitSelfData()

	/**
	 * Injects uploaded files into the object data.
	 *
	 * @param array      $data          The object data.
	 * @param array|null $uploadedFiles The uploaded files, if any.
	 *
	 * @return array The data with the uploaded files in it.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function injectUploadedFiles(array $data, ?array $uploadedFiles): array {
		if ($uploadedFiles === null || empty($uploadedFiles) === true) {
			return $data;
		}

		return $this->filePropertyHandler->processUploadedFiles(
			uploadedFiles: $uploadedFiles,
			data: $data
		);
	}//end injectUploadedFiles()

	/**
	 * Resolve schema and register to entity objects with their IDs.
	 *
	 * @param Schema|int|string $schema Schema parameter
	 * @param Register|int|string|null $register Register parameter
	 *
	 * @return array{0: Schema, 1: int, 2: Register, 3: int} [schema, schemaId, register, registerId]
	 *
	 * @throws Exception If schema or register cannot be resolved
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function resolveSchemaAndRegister(
		Schema|int|string $schema,
		Register|int|string|null $register,
	): array {
		[$schema, $schemaId] = $this->lookups()->schemaWithId(schema: $schema);
		[$register, $registerId] = $this->lookups()->registerWithId(register: $register);

		return [$schema, $schemaId, $register, $registerId];
	}//end resolveSchemaAndRegister()

	/**
	 * Find and validate existing object by UUID.
	 *
	 * RBAC and multitenancy are always off for this internal lookup, so that
	 * validating the stored object never fails on the caller's permissions;
	 * the caller's access to the write is decided elsewhere.
	 *
	 * @param ObjectSaveCall $call The save call: the object UUID, and the optional register and schema for magic mapper routing.
	 *
	 * @return ObjectEntity|null Existing object or null if not found.
	 *
	 * @throws Exception If object is locked by another user.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function findAndValidateExistingObject(ObjectSaveCall $call): ?ObjectEntity {
		$uuid = (string)$call->uuid;
		try {
			// Always disable RBAC and multitenancy for internal object lookup
			// to avoid permission errors when validating existing objects.
			$existingObject = $this->objectEntityMapper->find(
				identifier: $uuid,
				register: $call->register,
				schema: $call->schema,
				includeDeleted: false,
				_rbac: false,
				_multitenancy: false
			);

			// An explicit uuid names the row of that uuid only. The lookup also
			// matches ids, slugs and uris; a row found that way is another row,
			// so the write is a create under the given uuid.
			if (strcasecmp((string) $existingObject->getUuid(), $uuid) !== 0) {
				return null;
			}

			// Check if object is locked - prevent updates on locked objects.
			//
			// This guard NEVER FIRED before the run-scoped-locking change. It
			// read the holder from `$lockData['userId']`, and
			// `ObjectEntity::lock()` has always written it under `user`, so
			// `$lockOwner` was invariably null and the `!== null` test
			// short-circuited every time. Every lock taken since the endpoint
			// shipped was decorative on this path. It is fixed by delegating
			// to the one production predicate instead of re-spelling the
			// comparison here, which is what went wrong in the first place.
			$currentUser = $this->userSession->getUser();
			$currentUserId = null;
			if ($currentUser !== null) {
				$currentUserId = $currentUser->getUID();
			}

			// WHICH RUN IS WRITING, not just which user. A run-scoped lock
			// refuses every caller but the holding run, so a guard that omits
			// the run identity refuses THE RUN THAT TOOK THE LOCK: a flow
			// locks a case at one step and is turned away by its own lock at
			// the next. Ambient rather than a parameter because the write is
			// routinely several calls deep in code that has never heard of
			// flows; absent, it reads as a person, which is the fail-closed
			// answer a run lock already gives.
			$callerRun = $this->runContext?->currentRunUuid();

			// A TYPED refusal, not a plain `Exception`. The message is unchanged,
			// but the type is what lets a caller answer 423 instead of falling
			// into a generic 500 — which is what PATCH and POST-patch did for a
			// payload that was otherwise valid. The holder travels on the
			// exception rather than being re-derived at each catch site.
			if ($existingObject->isLockedBySomeoneElse(userId: $currentUserId, runUuid: $callerRun) === true) {
				throw LockedException::forObject($existingObject);
			}

			// The archive and the freeze refuse the write here, at the one
			// choke point every data write to an existing object already
			// passes through. Put anywhere further out — in a controller, say —
			// and a flow, an import or a cascade would each need its own copy
			// of the check, which is how a guard ends up holding one door of
			// four.
			//
			// Neither refusal applies to the reversal or to the retention
			// machinery: `unarchive()`, `unfreeze()`, a destruction date and a
			// legal hold are all written through the mapper's dedicated
			// metadata paths and never reach `saveObject()`. That is deliberate.
			// An archive that quietly disabled `retention-management` would be
			// the opposite of what an archive is for.
			if ($existingObject->isArchived() === true) {
				throw ObjectStateWriteException::archived($existingObject);
			}

			if ($existingObject->isFrozen() === true) {
				throw ObjectStateWriteException::frozen($existingObject);
			}

			return $existingObject;
		} catch (DoesNotExistException $e) {
			// Object not found, will create new one.
			return null;
		}//end try
	}//end findAndValidateExistingObject()

	/**
	 * The `@self` an update works from: a version the caller carries counts only for a trusted caller.
	 *
	 * An app's seed objects carry their shipped version in `@self.version`, and
	 * an update takes it when it is higher than the stored one, so the next
	 * import reads the seed as current (ObjectVersionHandler::advanceVersion()).
	 * A caller judged by RBAC could send any higher version through the object
	 * API, and a seed that reads as newer than every later shipped version
	 * never receives the app's fix. So `@self.version` is dropped from the
	 * update of a caller whose RBAC applies, and that update bumps the patch
	 * like any other. The importer, an administrator and a system caller save
	 * with RBAC off and keep it.
	 *
	 * @param array<string, mixed> $selfData The `@self` the caller sent.
	 * @param bool                 $_rbac    Whether the save is judged for a signed-in caller.
	 *
	 * @return array<string, mixed> The `@self` the update uses.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors saveObject()'s own RBAC flag.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function selfDataForUpdate(array $selfData, bool $_rbac): array {
		if ($_rbac === true) {
			unset($selfData['version']);
		}

		return $selfData;
	}//end selfDataForUpdate()

	/**
	 * Handle update of existing object.
	 *
	 * @param ObjectSaveCall $call           The save call: register, schema, data, `@self` data and flags.
	 * @param ObjectEntity   $existingObject Existing object to update
	 *
	 * @return ObjectEntity Updated object
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function handleObjectUpdate(ObjectSaveCall $call, ObjectEntity $existingObject): ObjectEntity {
		self::refuseArchivedUpdate(existingObject: $existingObject);
		$this->recordUpdateUniqueHints(call: $call, existingObject: $existingObject);

		// Capture the old state BEFORE prepareObjectForUpdate modifies the entity:
		// event dispatching needs the old status, and the data array is copied to
		// prevent reference issues.
		$oldObjectData = $existingObject->getObject();
		$oldObject = clone $existingObject;
		$oldObject->setObject($oldObjectData);

		$preparedObject = $this->prepareUpdatedObject(call: $call, existingObject: $existingObject);

		// Decide the slug once, here: a stored one is frozen. updateObject() does
		// not decide it again; `@self` is gone from the data there (#4037).
		$sent = new ObjectPayload(data: $call->data, selfData: $call->selfData);
		$this->decideSlug(
			request: new SlugRequest(entity: $preparedObject, schema: $call->schema, clientSlug: $sent->clientSlug()),
			stored: $oldObject->getSlug()
		);

		// A record that changes its own selectielijst category (DECISIONS row 48)
		// is refused when that category has no row, re-derived while it is still
		// active, and refused once it has been nominated.
		$retentionService = $this->resolveRetentionService();
		if ($retentionService !== null) {
			$preparedObject = $retentionService->applyClassification(
				update: new RecordUpdate(object: $preparedObject, schema: $call->schema, previousData: ($oldObjectData ?? []))
			);
		}

		// Not persisting: nothing reached storage, so for a batching caller the row changed nothing.
		if ($call->flags->persist === false) {
			$this->lastSaveOutcome = 'unchanged';
			return $preparedObject;
		}

		$updatedObject = $this->updateObject(
			register: $call->register,
			schema: $call->schema,
			data: $call->data,
			existingObject: $preparedObject,
			silent: $call->flags->silent,
			oldObject: $oldObject
		);

		$this->settleOrphans(parent: (string)$preparedObject->getUuid());

		// Recorded AFTER the write, so a cascade descendant saved inside
		// updateObject() cannot leave its outcome behind as this frame's answer.
		// The strict array comparison errs toward `updated` (a reordered property
		// reads as a change), never toward `unchanged`, the bucket a caller acts
		// on by skipping work.
		$this->lastSaveOutcome = self::updateOutcome(updated: $updatedObject, old: $oldObject);

		return $updatedObject;
	}//end handleObjectUpdate()

	/**
	 * Refuse to modify a destroyed or transferred object.
	 *
	 * Both vocabularies are known: GAP A4 moved the stored spelling to English
	 * and stored data is not migrated, so already-destroyed records in an
	 * existing install still carry the Dutch value.
	 *
	 * @param ObjectEntity $existingObject The stored object.
	 *
	 * @return void
	 *
	 * @throws Exception With code 409 when the archival status forbids changes.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function refuseArchivedUpdate(ObjectEntity $existingObject): void {
		$archStatus = ($existingObject->getRetention() ?? [])['archiefstatus'] ?? null;
		$immutableMap = [
			'vernietigd' => 'OBJECT_DESTROYED',
			'destroyed' => 'OBJECT_DESTROYED',
			'overgebracht' => 'OBJECT_TRANSFERRED',
			'transferred' => 'OBJECT_TRANSFERRED',
		];

		if ($archStatus !== null && isset($immutableMap[$archStatus]) === true) {
			throw new Exception(
				'Cannot modify object: archival status is ' . $archStatus . ' (error: ' . $immutableMap[$archStatus] . ')',
				409
			);
		}
	}//end refuseArchivedUpdate()

	/**
	 * Record the soft-uniqueness warnings of an update, excluding the object itself from its matches.
	 *
	 * SOFT UNIQUENESS, the update half: a create-only check would let every
	 * duplicate in through an edit. Re-saving a record that already holds the
	 * value stays silent.
	 *
	 * @param ObjectSaveCall $call           The save call.
	 * @param ObjectEntity   $existingObject The stored object.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function recordUpdateUniqueHints(ObjectSaveCall $call, ObjectEntity $existingObject): void {
		$uniqueHints = $this->resolveUniqueHintChecker();
		if ($uniqueHints === null) {
			return;
		}

		$uniqueHints->check(
			configuration: ($call->schema->getConfiguration() ?? []),
			candidate: new UniqueHintCandidate(
				scope: new UniqueHintScope(register: $call->register->getId(), schema: $call->schema->getId()),
				data: $call->data,
				selfUuid: ((string)$existingObject->getUuid())
			)
		);
	}//end recordUpdateUniqueHints()

	/**
	 * Prepare the stored object with the update, after dropping orphans a previous pass queued for it.
	 *
	 * @param ObjectSaveCall $call           The save call.
	 * @param ObjectEntity   $existingObject The stored object.
	 *
	 * @return ObjectEntity The prepared object.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function prepareUpdatedObject(ObjectSaveCall $call, ObjectEntity $existingObject): ObjectEntity {
		unset($this->pendingOrphans[(string)$existingObject->getUuid()]);

		return $this->prepareObjectForUpdate(
			existingObject: $existingObject,
			schema: $call->schema,
			payload: new ObjectPayload(data: $call->data, selfData: $call->selfData),
			currentUser: $call->flags->currentUser
		);
	}//end prepareUpdatedObject()

	/**
	 * The verdict of a persisted update.
	 *
	 * @param ObjectEntity $updated The object after the write.
	 * @param ObjectEntity $old     The object before the write.
	 *
	 * @return string `unchanged` or `updated`.
	 */
	private static function updateOutcome(ObjectEntity $updated, ObjectEntity $old): string {
		if ($updated->getObject() === $old->getObject()) {
			return 'unchanged';
		}

		return 'updated';
	}//end updateOutcome()

	/**
	 * Handle creation of new object.
	 *
	 * @param ObjectSaveCall $call The save call: register, schema, data, `@self` data and flags.
	 *
	 * @return ObjectEntity Created object
	 *
	 * @throws Exception If file processing fails
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function handleObjectCreation(ObjectSaveCall $call): ObjectEntity {
		$preparedObject = $this->prepareNewObject(call: $call);

		// If not persisting, return the prepared object.
		if ($call->flags->persist === false) {
			return $preparedObject;
		}

		// Save the object to database FIRST (so it gets an ID).
		\OCA\OpenRegister\Service\WritePhaseProbe::mark('sv:pre-insert');
		$savedEntity = $this->unifiedObjectMapper->insert(
			entity: $preparedObject,
			register: $call->register,
			schema: $call->schema,
			failIfExists: $call->flags->failIfExists
		);
		\OCA\OpenRegister\Service\WritePhaseProbe::mark('sv:INSERT');

		// Capture the MAPPER's verdict for this row NOW: file-property processing
		// and inverse-relation maintenance below write OTHER objects, after which
		// getLastWriteAction() answers about one of those rows. The mapper is the
		// authority, not this method's name: a concurrent writer can land the row
		// between the service lookup and the insert, and the mapper then took its
		// UPDATE branch on what this path calls a create (#2212/#2217).
		$mapperWriteAction = $this->unifiedObjectMapper->getLastWriteAction();

		$this->cacheSavedName(savedEntity: $savedEntity);
		$savedEntity = $this->storeCreatedFileProperties(call: $call, savedEntity: $savedEntity);
		\OCA\OpenRegister\Service\WritePhaseProbe::mark('sv:FILEPROPS');

		$this->auditCreation(call: $call, savedEntity: $savedEntity);

		// Bidirectional relations. Skipped in silent mode (cascaded sub-objects):
		// the parent handles the relationship, and cascadeSingleObject already set
		// the inversedBy property before saving.
		if ($call->flags->silent === false) {
			$this->inverseRelations()->update(saved: $savedEntity, write: new InverseRelationWrite(register: $call->register, schema: $call->schema));
			\OCA\OpenRegister\Service\WritePhaseProbe::mark('sv:INVERSE-RELATIONS');
		}

		// Published last, from the verdict captured right after THIS row's insert,
		// so the cascade and inverse-relation saves above cannot be mistaken for it.
		$this->lastSaveOutcome = 'created';
		if ($mapperWriteAction === 'update') {
			$this->lastSaveOutcome = 'updated';
		}

		return $savedEntity;
	}//end handleObjectCreation()

	/**
	 * A new object entity for a create, prepared and with its archival metadata, not yet persisted.
	 *
	 * @param ObjectSaveCall $call The save call.
	 *
	 * @return ObjectEntity The prepared object.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function prepareNewObject(ObjectSaveCall $call): ObjectEntity {
		$objectEntity = new ObjectEntity();
		$objectEntity->setRegister((string)$call->registerId);
		$objectEntity->setSchema((string)$call->schemaId);
		$objectEntity->setCreated(new DateTime());
		$objectEntity->setUpdated(new DateTime());
		$this->userActivity?->stamp(object: $objectEntity);

		if ($call->uuid !== null) {
			$objectEntity->setUuid($call->uuid);
		}

		// Prepare the object for creation (WITHOUT file processing yet).
		$preparedObject = $this->prepareObjectForCreation(
			objectEntity: $objectEntity,
			schema: $call->schema,
			payload: new ObjectPayload(data: $call->data, selfData: $call->selfData),
			flags: $call->flags
		);

		return $this->applyArchivalOnCreate(schema: $call->schema, preparedObject: $preparedObject);
	}//end prepareNewObject()

	/**
	 * Apply the schema's archival metadata to a new object, when retention is available.
	 *
	 * A per-object selectielijst category that names no row is the caller's
	 * error and is refused; any other failure to apply metadata is logged.
	 *
	 * @param Schema       $schema         The object's schema.
	 * @param ObjectEntity $preparedObject The prepared object.
	 *
	 * @return ObjectEntity The object with its archival metadata.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyArchivalOnCreate(Schema $schema, ObjectEntity $preparedObject): ObjectEntity {
		$retentionService = $this->resolveRetentionService();
		if ($retentionService === null) {
			return $preparedObject;
		}

		$retentionService->guardClassificationOverride(
			schema: $schema,
			data: ($preparedObject->getObject() ?? [])
		);

		try {
			return $retentionService->applyArchivalMetadata($preparedObject, $schema);
		} catch (\Throwable $e) {
			$this->logger->debug('[SaveObject] Skipping archival metadata: ' . $e->getMessage());
			return $preparedObject;
		}
	}//end applyArchivalOnCreate()

	/**
	 * Put a saved object's name in the name cache, scoped to its organisation (SEC-CTRL-2).
	 *
	 * @param ObjectEntity $savedEntity The saved object.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cacheSavedName(ObjectEntity $savedEntity): void {
		$savedName = $savedEntity->getName();
		$savedUuid = $savedEntity->getUuid();
		if ($savedUuid !== null && $savedName !== null && trim($savedName) !== '') {
			$this->cacheHandler->setObjectName(
				identifier: $savedUuid,
				name: $savedName,
				organisation: $savedEntity->getOrganisation()
			);
		}
	}//end cacheSavedName()

	/**
	 * Write the audit trail of a create, unless silent or audit trails are off.
	 *
	 * The action comes from the mapper, not from this path's name: when the
	 * mapper took its UPDATE branch the entry is an update, never a second
	 * create (#2212/#2217). A genuine create keeps `old: null`.
	 *
	 * @param ObjectSaveCall $call        The save call.
	 * @param ObjectEntity   $savedEntity The saved object.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function auditCreation(ObjectSaveCall $call, ObjectEntity $savedEntity): void {
		if ($call->flags->silent === true || $this->isAuditTrailsEnabled() === false) {
			return;
		}

		$action = null;
		if ($this->unifiedObjectMapper->getLastWriteAction() === 'update') {
			$action = 'update';
		}

		$this->noteClientCauseAttempt(call: $call, object: $savedEntity);
		$log = $this->auditTrailMapper->createAuditTrail(old: null, new: $savedEntity, action: $action);

		$savedEntity->setLastLog($log->jsonSerialize());
		\OCA\OpenRegister\Service\WritePhaseProbe::mark('sv:AUDIT');
	}//end auditCreation()

	/**
	 * Prepares an object for creation by applying all necessary transformations.
	 *
	 * @param ObjectEntity $objectEntity The object entity to prepare.
	 * @param Schema $schema The schema of the object.
	 * @param ObjectPayload $payload The object data and the @self metadata.
	 * @param ObjectSaveFlags $flags The save flags: the explicit acting user for `@self.folder` access
	 *                               checks (forwarded to setSelfMetadata), whether to stamp the system
	 *                               identity even when a session exists, and an explicit owner, such
	 *                               as a draft's scope value (decision 195).
	 *
	 * @return ObjectEntity The prepared object entity.
	 *
	 * @throws Exception If there is an error during preparation.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex preparation with multiple transformations
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple optional configuration paths
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive preparation requires extended logic
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function prepareObjectForCreation(
		ObjectEntity $objectEntity,
		Schema $schema,
		ObjectPayload $payload,
		ObjectSaveFlags $flags,
	): ObjectEntity {
		$data     = $payload->data;
		$selfData = $payload->selfData;

		// Set @self metadata properties.
		$this->setSelfMetadata(objectEntity: $objectEntity, submission: new SelfSubmission(selfData: $selfData, actingUser: $flags->currentUser));

		// Start the object's version sequence. Nothing wrote this before, so on
		// the magic tables (which carry no column default) every object read
		// back a NULL version, and on the legacy table every object read back
		// the same `0.0.1` forever. See ObjectVersionHandler.
		$this->versionHandler->stampInitialVersion(entity: $objectEntity);

		// Set UUID if provided, otherwise generate a new one.
		if ($objectEntity->getUuid() === null) {
			$objectEntity->setUuid(Uuid::v4()->toRfc4122());
		}

		$objectEntity->setUri(
			$this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute(
					self::URL_PATH_IDENTIFIER,
					[
						'register' => $objectEntity->getRegister(),
						'schema' => $objectEntity->getSchema(),
						'id' => $objectEntity->getUuid(),
					]
				)
			)
		);

		// Prepare the data.
		$preparedData = $this->prepareCascadeData(run: CascadeRun::forObject(parent: $objectEntity, data: $data), schema: $schema);

		// Validate reference existence for properties with validateReference: true.
		$this->validateReferences(schema: $schema, run: ReferencePropertyRun::forSave(data: $preparedData, register: $objectEntity->getRegister()));

		// Set the prepared data.
		$objectEntity->setObject($preparedData);

		// Hydrate name and description from schema configuration.
		try {
			$this->hydrateObjectMetadata(entity: $objectEntity, schema: $schema);
		} catch (Exception $e) {
			// CRITICAL FIX: Hydration failures indicate schema/data mismatch - don't suppress!
			$mismatchHint = 'This indicates a mismatch between object data and schema configuration.';
			throw new Exception('Object metadata hydration failed: ' . $e->getMessage() . '. ' . $mismatchHint, 0, $e);
		}

		// Decide the slug after hydration, with the uuid known (#4037).
		$this->decideSlug(
			request: new SlugRequest(entity: $objectEntity, schema: $schema, clientSlug: $payload->clientSlug()),
			stored: null
		);

		// Extract Nc* property references and populate linked entity metadata columns.
		$this->linkedEntityHandler->extractAndPopulate($objectEntity, $schema, $preparedData);

		// Populate TMLO archival metadata defaults if register has TMLO enabled.
		$this->populateTmloDefaults(objectEntity: $objectEntity, schema: $schema);

		// Set owner from the active user session, or fall back to the system
		// identifier when no session is active. See applyOwnerAttribution()
		// for the system-context contract (openregister#1617).
		$this->applyOwnerAttribution(objectEntity: $objectEntity, flags: $flags);

		// Set organisation from active organisation if not already set.
		// setSelfMetadata() (called above) only accepted a client-supplied @self.organisation
		// value when the caller was admin or a verified member of that organisation (SB1 fix).
		// If setOrganisation() was NOT called there (non-member caller, or no @self.organisation
		// provided at all), stamp the caller's active organisation here as the authoritative value.
		if ($objectEntity->getOrganisation() === null || $objectEntity->getOrganisation() === '') {
			$organisationUuid = $this->organisationService->getOrganisationForNewEntity();
			$objectEntity->setOrganisation($organisationUuid);
		}

		// Update object relations.
		try {
			$objectEntity->setRelations($this->scanForRelations(data: $preparedData, prefix: '', schema: $schema));
		} catch (Exception $e) {
			// CRITICAL FIX: Relation processing failures indicate serious data integrity issues!
			$hint = 'This indicates invalid relation data or schema configuration problems.';
			throw new Exception('Object relations processing failed: ' . $e->getMessage() . '. ' . $hint, 0, $e);
		}

		return $objectEntity;
	}//end prepareObjectForCreation()

	/**
	 * Attribute owner on a freshly created object entity.
	 *
	 * When an `IUserSession` user is active, owner is set to the user's UID
	 * (legacy behaviour, unchanged for logged-in writes).
	 *
	 * When no user session is active — cron jobs, background workers, internal
	 * service calls like openconnector's `CallService::call()` — owner is set
	 * to the configured system identifier instead of being left empty.
	 * Rows persisted with empty `_owner` are invisible to the REST list path's
	 * RBAC filter even for admins, which is the bug fixed by openregister#1617.
	 *
	 * A pre-existing non-empty `_owner` value (e.g. set explicitly by the
	 * caller via @self metadata) is honoured and not overwritten by the
	 * system-context fallback.
	 *
	 * `$_unowned` is the ONE way to opt out, and it is a service-level argument
	 * that no REST caller can reach — the controllers never forward it, so a
	 * request cannot ask to be anonymised.
	 *
	 * WHY IT EXISTS. An application can accept a genuinely anonymous submission
	 * — a citizen reporting a pothole on a public portal, with no account and
	 * no ownership — and until now the row was still stamped with whatever
	 * Nextcloud user happened to hold a session in that browser. Measured on a
	 * portaliq portal: the same anonymous form stored `_owner = __system__` when
	 * posted by `curl` and `_owner = admin` when posted from a browser holding
	 * an admin cookie, while the page it was submitted from told the visitor the
	 * submission was "niet aan een account gekoppeld". A caller could not fix it
	 * from its side, because this method overrides a caller-set owner whenever
	 * there is a session.
	 *
	 * EXPLICITLY OWNED. `$_owner` is the other service-level argument: a caller
	 * that asserted who owns a new object, such as a portal subject's draft
	 * owned by `portal:<subjectRef>` (decision 195, see DraftOwner), names
	 * it here. No controller forwards it either.
	 *
	 * @param ObjectEntity    $objectEntity Entity being prepared for creation.
	 * @param ObjectSaveFlags $flags        Its `unowned` stamps the system identity even when a session exists;
	 *                                      its `owner` is an explicit owner that wins over the session and the
	 *                                      system identity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	private function applyOwnerAttribution(ObjectEntity $objectEntity, ObjectSaveFlags $flags = new ObjectSaveFlags()): void {
		$owner = ($this->attributedOwner(_unowned: $flags->unowned, _owner: $flags->owner) ?? $this->ownerWithoutSession(objectEntity: $objectEntity));
		if ($owner !== null) {
			$objectEntity->setOwner($owner);
		}
	}//end applyOwnerAttribution()

	/**
	 * The owner a new object gets from the write itself: the explicit owner, the system identity, or the session user.
	 *
	 * @param bool        $_unowned Stamp the system identity even when a session exists.
	 * @param string|null $_owner   An explicit owner; wins over the session and the system identity.
	 *
	 * @return string|null The owner, or null when there is no session and nothing was asked for.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function attributedOwner(bool $_unowned, ?string $_owner): ?string {
		if ($_owner !== null && $_owner !== '') {
			return $_owner;
		}

		// DELIBERATELY UNOWNED. The system identifier rather than an empty
		// string: rows persisted with an empty `_owner` are invisible to the
		// REST list path's RBAC filter even for admins (openregister#1617), so
		// "no owner" has to mean the system, not nobody.
		if ($_unowned === true) {
			return $this->organisationService->getSystemUserId();
		}

		return $this->userSession->getUser()?->getUID();
	}//end attributedOwner()

	/**
	 * The owner a new object gets when there is no session: the system identity, unless it already has one.
	 *
	 * @param ObjectEntity $objectEntity Entity being prepared for creation.
	 *
	 * @return string|null The system identity, or null to keep the owner it has.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function ownerWithoutSession(ObjectEntity $objectEntity): ?string {
		// No user session — only fall back to the system identifier when
		// owner is genuinely missing (empty or null). An explicit non-empty
		// owner (e.g. set by the caller) is preserved.
		$currentOwner = $objectEntity->getOwner();
		if ($currentOwner === null || $currentOwner === '') {
			return $this->organisationService->getSystemUserId();
		}

		return null;
	}//end ownerWithoutSession()

	/**
	 * Prepares an object for update by applying all necessary transformations.
	 *
	 * @param ObjectEntity $existingObject The existing object entity to prepare.
	 * @param Schema $schema The schema of the object.
	 * @param ObjectPayload $payload The updated object data and the @self metadata.
	 * @param IUser|null $currentUser Explicit acting user for `@self.folder` access checks (forwarded to setSelfMetadata).
	 *
	 * @return ObjectEntity The prepared object entity.
	 *
	 * @throws Exception If there is an error during preparation.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function prepareObjectForUpdate(
		ObjectEntity $existingObject,
		Schema $schema,
		ObjectPayload $payload,
		?IUser $currentUser = null,
	): ObjectEntity {
		$data     = $payload->data;
		$selfData = $payload->selfData;

		// The stored record as property rules see it, taken before this request's
		// `@self` is applied to the entity.
		$storedRecord = $existingObject->getMatchEnvelope();

		// Set @self metadata properties.
		$this->setSelfMetadata(objectEntity: $existingObject, submission: new SelfSubmission(selfData: $selfData, actingUser: $currentUser));

		// A save is a patch. Bumped here rather than after the write so the
		// value the audit trail copies onto its own `version` column is the
		// version this save produced, not the one it replaced. A higher
		// version the save carries (a re-imported seed object) is taken
		// instead, so the next import sees the seed as current.
		$carriedVersion = null;
		if (is_scalar($selfData['version'] ?? null) === true) {
			$carriedVersion = (string)$selfData['version'];
		}

		$this->versionHandler->advanceVersion(entity: $existingObject, carried: $carriedVersion);

		// Set folder ID if provided.
		// Snapshot the object's pre-update data BEFORE prepareCascadeData runs.
		// prepareCascadeData calls preCacheParentName which writes the incoming
		// data back into $existingObject via setObject() so cascade descendants
		// can resolve the parent's name template; reading getObject() afterwards
		// would yield the new data, not the original. Capturing here keeps the
		// "skip validation when reference unchanged" check honest.
		$oldData = $existingObject->getObject();

		// Detect which declared write-only locations this payload OMITS, so the stored
		// values can be carried forward below instead of being nulled (openregister#463).
		// This runs on the RAW $data on purpose: prepareCascadeData's setDefaultValues()
		// applies a property's `default` when the key is absent, which would materialize
		// an omitted key and hide the omission from this check.
		$omittedWriteOnly = $this->propertyRbacHandler->collectOmittedWriteOnlyPaths(
			schema: $schema,
			incoming: $data
		);

		// A property the writer may not read was stripped from what they were
		// shown, so omitting it is not a request to clear it (openregister#4170);
		// one they may not update cannot be cleared by omission either, since that
		// is the write the property rule refuses. Both are carried forward. The
		// rules are asked of the stored record; the values come from $oldData.
		$omittedWriteOnly = array_values(
			array_unique(
				array_merge(
					$omittedWriteOnly,
					$this->propertyRbacHandler->collectOmittedProtectedProperties(update: new ProtectedCarryForward(schema: $schema, incoming: $data, stored: $storedRecord))
				)
			)
		);

		// Prepare the data.
		$preparedData = $this->prepareCascadeData(run: CascadeRun::forObject(parent: $existingObject, data: $data), schema: $schema);

		// Validate reference existence for properties with validateReference: true.
		// On updates, skip validation for unchanged values to avoid re-validating existing references.
		$referenceRun = ReferencePropertyRun::forSave(data: $preparedData, register: $existingObject->getRegister());
		$referenceRun->oldData = $oldData;
		$this->validateReferences(schema: $schema, run: $referenceRun);

		// Carry omitted write-only values forward from the stored object (openregister#463).
		// The read boundary strips writeOnly from every response, so a client doing the
		// natural GET/edit/PUT round-trip re-sends a body without the secret it was never
		// shown; the PUT-semantic null-fill immediately below would then destroy it. This
		// is the save-side half of the writeOnly contract, symmetric with the read-side
		// strip in PropertyRbacHandler::stripWriteOnlyProperties().
		//
		// Ordering is load-bearing in both directions. It must run AFTER prepareCascadeData
		// because that is where FieldEncryptionHandler::encryptProperties() runs: $oldData
		// holds the stored value, which for an encrypted property is ciphertext, and
		// restoring it any earlier would double-encrypt it. It must run BEFORE the null-fill
		// because that fill materializes every absent property as null, after which an
		// omitted secret is indistinguishable from one the client explicitly cleared.
		//
		// $oldData is the raw ObjectEntity::getObject() snapshot taken above — never a
		// rendered read, which by construction would already have the secret stripped.
		//
		// Short-circuited on the common case exactly as the read path is (RenderObject
		// gates its strip on schemaHasWriteOnlyRule()): the overwhelming majority of
		// schemas declare no write-only location, and for those this whole rule must be
		// provably inert rather than merely harmless.
		if (empty($omittedWriteOnly) === false) {
			$preparedData = $this->propertyRbacHandler->restoreWriteOnlyValues(
				prepared: $preparedData,
				omitted: new OmittedWriteOnly(stored: $oldData, paths: $omittedWriteOnly)
			);
		}

		// PUT semantics: fill missing schema properties with null to ensure complete replacement.
		// For magic-mapped objects, the MagicMapper generates SET clauses only for properties
		// present in the data. Without this, removed properties would retain their old values
		// because the mapper never issues a SET column=NULL for missing keys.
		$preparedData = $this->fillMissingSchemaPropertiesWithNull(data: $preparedData, schemaId: $schema->getId());

		// Set the prepared data.
		$existingObject->setObject($preparedData);

		// Hydrate name and description from schema configuration.
		$this->hydrateObjectMetadata(entity: $existingObject, schema: $schema);

		// Extract Nc* property references and populate linked entity metadata columns.
		$this->linkedEntityHandler->extractAndPopulate($existingObject, $schema, $preparedData);

		// Validate TMLO metadata if present (status transitions and field values).
		$this->validateTmloOnUpdate(existingObject: $existingObject, selfData: $selfData);

		// NOTE: Relations are already updated in prepareObjectForCreation() - no need to update again
		// Duplicate call would overwrite relations after the write-back removes properties
		// Update object relations (result currently unused but operation has side effects).
		try {
			$existingObject->setRelations($this->scanForRelations(data: $preparedData, prefix: '', schema: $schema));
		} catch (Exception $e) {
			// CRITICAL FIX: Relation processing failures indicate serious data integrity issues!
			$hint = 'This indicates invalid relation data or schema configuration problems.';
			throw new Exception('Object relations processing failed: ' . $e->getMessage() . '. ' . $hint, 0, $e);
		}

		return $existingObject;
	}//end prepareObjectForUpdate()

	/**
	 * Decide and set an entity's slug: frozen when it has one, otherwise unique in its register+schema (#4037).
	 *
	 * @param SlugRequest $request The entity (hydrated and with its uuid), its schema and the slug the client sent.
	 * @param string|null $stored  The slug stored before this save (null on create).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-an-object-slug-once-set-must-not-be-changed-by-a-later-save
	 */
	private function decideSlug(SlugRequest $request, ?string $stored): void {
		$this->slugHandler->applyOnSave(
			request: $request,
			originalSlug: $stored
		);
	}//end decideSlug()

	/**
	 * The slug handler this save pipeline uses, so the bulk path shares one instance.
	 *
	 * @return ObjectSlugHandler The handler.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function getSlugHandler(): ObjectSlugHandler {
		return $this->slugHandler;
	}//end getSlugHandler()

	/**
	 * Sets @self metadata properties on an object entity.
	 *
	 * @param ObjectEntity   $objectEntity The object entity to set metadata on.
	 * @param SelfSubmission $submission   The @self metadata and the explicit acting user for the `@self.folder`
	 *                                     access check (the session user when null).
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-openregisters-own-account-holds-every-managed-folder-req-ofoa-001
	 */
	private function setSelfMetadata(ObjectEntity $objectEntity, SelfSubmission $submission): void {
		$selfData = $submission->selfData;
		$this->applyClientSelfData(objectEntity: $objectEntity, selfData: $selfData);
		$this->bindSelfFolder(objectEntity: $objectEntity, bind: FolderBind::fromSelfData(selfData: $selfData, actingUser: $submission->actingUser));
		self::applySelfGeo(objectEntity: $objectEntity, selfData: $selfData);
		// SECURITY (wave-11 WF1): TMLO fields (@self.tmlo) must NOT be accepted verbatim
		// from client input on CREATE.  The destruction pipeline keys off archiefstatus;
		// a client-submitted {"archiefstatus":"vernietigd"} would mark an object as
		// destruction-eligible bypassing the validated state machine.  populateTmloDefaults()
		// (called after this method) will apply the correct system defaults including
		// resetting archiefstatus to 'actief' for new objects, overriding any client value.
		// On UPDATE, validateTmloOnUpdate() enforces the allowed transition matrix.
		// Therefore: strip @self.tmlo entirely here; let populateTmloDefaults() own it.
		// (No setTmlo() call — the field is intentionally omitted).
	}//end setSelfMetadata()

	/**
	 * Applies the client-writable @self fields that need no folder: owner refusal, status and organisation.
	 *
	 * @param ObjectEntity $objectEntity The object entity to set metadata on.
	 * @param array        $selfData     The @self metadata.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When @self names an owner the caller may not set.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyClientSelfData(ObjectEntity $objectEntity, array $selfData): void {
		// The slug is not set here any more: ObjectSlugHandler::applyOnSave()
		// decides it after hydration, so the freeze and uniqueness rules see
		// the client slug and the stored one together (#4037).

		// SECURITY (wave-7 CRITICAL C2): owner must NOT be set from client-supplied
		// @self input. The sole authoritative setter is applyOwnerAttribution() which
		// stamps the session user's UID (or the configured system identifier for
		// background jobs) AFTER this method returns. Accepting an owner value here
		// would allow any caller to forge object ownership:
		// - For authenticated REST requests applyOwnerAttribution() would override it,
		// but the defence-in-depth is still worthwhile.
		// - For background / system contexts (no IUserSession user) applyOwnerAttribution
		// only fills in owner when it is empty, so a client-supplied value would
		// persist — that is the actual attack vector closed by this change.
		//
		// SILENTLY DROPPING IT IS NOT ENOUGH. A write that asks for an owner it
		// may not have was accepted with a 2xx and a different owner stored, so
		// the caller was told its request succeeded when the one thing it asked
		// for did not happen. It is now REFUSED, and the refusal names the
		// endpoint that does change an owner. See refuseOwnerClaim().
		$this->refuseOwnerClaim(objectEntity: $objectEntity, selfData: $selfData);

		// Decision 180: `@self.status`. A client may create an object as a
		// draft and keep it one; it may not turn a saved object into a draft,
		// and only the form submit (after full validation) may leave draft.
		$objectEntity->setStatus((new DraftStatusPolicy())->resolveStatus(entity: $objectEntity, selfData: $selfData));

		// SECURITY (wave-11 SB1 / wave-12 Fix 3): organisation must only be accepted from
		// @self when the caller is an admin or has verified membership in that organisation.
		// Blindly applying a client-supplied organisation UUID allows any authenticated
		// user to plant data inside another tenant's view (cross-tenant data injection).
		//
		// Wave-12 closed SB1 with an admin-ONLY gate; this lineage later widened the
		// allow-list to admins OR verified members of the requested organisation. That
		// widening does not reopen SB1: hasAccessToOrganisation() verifies the caller
		// actually belongs to the target organisation, so a caller can still never plant
		// data in a tenant they are not a member of — which is the vector SB1 describes.
		// The admin arm delegates to wave-12's callerIsAdmin() helper, which is the same
		// session-user + IGroupManager check this lineage had inlined, additionally
		// hardened against IGroupManager throwing.
		if (array_key_exists('organisation', $selfData) === true
			&& empty($selfData['organisation']) === false
		) {
			// Only allow admin, or callers who actually belong to the requested organisation.
			if ($this->callerIsAdmin() === true
				|| $this->organisationService->hasAccessToOrganisation($selfData['organisation']) === true
			) {
				$objectEntity->setOrganisation($selfData['organisation']);
			}

			// Non-member callers silently fall through; prepareObjectForCreation() will
			// stamp the caller's active organisation via getOrganisationForNewEntity().
		}
	}//end applyClientSelfData()

	/**
	 * Binds the folder @self asks for, after the access check when it names a folder id.
	 *
	 * @param ObjectEntity    $objectEntity The object entity to set the folder on.
	 * @param FolderBind|null $bind         The requested bind, or null when @self names no folder.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-openregisters-own-account-holds-every-managed-folder-req-ofoa-001
	 */
	private function bindSelfFolder(ObjectEntity $objectEntity, ?FolderBind $bind): void {
		// Propagate @self.folder so the access-control check governs the bind
		// (numeric → access-checked, empty/legacy → auto-create). Without
		// propagation a user-supplied @self.folder is silently dropped —
		// defeating the whole `self-folder-access-control` capability.
		if ($bind === null) {
			return;
		}

		$folderValue = $bind->folder;

		// For folder values that are a bare positive integer node id (the
		// format produced by explicit `@self.folder` writes), require that
		// the acting user can read the target folder — otherwise reject the
		// bind with `FolderAccessDeniedException`. We gate on `ctype_digit`
		// rather than `is_numeric` so floats / scientific-notation / signed
		// strings (e.g. "42.5", "4e2", "+42") don't slip through and then
		// get `(int)`-truncated to a *different* node than was validated.
		// Legacy non-numeric values fall through to the auto-create path.
		// A payload that echoes the folder the object already has is not a
		// bind: the stored value was written by OpenRegister and is
		// re-validated as a managed folder in ensureObjectFolder(). Asking
		// the caller-supplied question here refused every editor, because a
		// managed folder sits in the openregister account's home and in
		// nobody's own mount.
		$storedFolder = (string)($objectEntity->getFolder() ?? '');
		$isUnchanged = ($storedFolder !== '' && $storedFolder === $folderValue);

		if (ctype_digit($folderValue) === true && $isUnchanged === false) {
			// Pass `$currentUser` through so the access check uses the
			// SAME user identity that the downstream
			// `createObjectFolderById` check uses on the lazy-init path.
			// When `$currentUser` is null (HTTP path), both checks fall
			// back to `IUserSession::getUser()` and agree by default.
			// When `$currentUser` is non-null (DI / event listener path),
			// both checks use it explicitly — no chance of one site
			// passing while another denies because of session vs explicit
			// user disagreement.
			$this->folderManagementHandler->assertFolderIsAccessible(
				check: new FolderAccessCheck(folderId: $folderValue, currentUser: $bind->actingUser, objectEntity: $objectEntity)
			);

			// Normalise to the canonical integer string so the persisted
			// value matches the node that was validated (no "42.5" vs "42"
			// drift in audit / analytics / equality checks).
			$folderValue = (string)(int)$folderValue;
		}//end if

		$objectEntity->setFolder($folderValue);
	}//end bindSelfFolder()

	/**
	 * Applies @self.geo, the object's geographical metadata.
	 *
	 * @param ObjectEntity $objectEntity The object entity to set the geo on.
	 * @param array        $selfData     The @self metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function applySelfGeo(ObjectEntity $objectEntity, array $selfData): void {
		// Propagate @self.geo — the object's geographical metadata (a GeoJSON-ish
		// array rendered/edited by map widgets, e.g. CnObjectGeoWidget). Unlike
		// organisation/tmlo this is plain descriptive data driving no security or
		// lifecycle decisions, so it is client-writable. An explicit null clears it.
		if (array_key_exists('geo', $selfData) === true) {
			$geoValue = $selfData['geo'];
			if ($geoValue === null || is_array($geoValue) === true) {
				$objectEntity->setGeo($geoValue);
			}
		}
	}//end applySelfGeo()

	/**
	 * Refuse a write that asks for an owner the acting user may not take.
	 *
	 * OWNERSHIP IS DERIVED FROM THE AUTHENTICATED ACTOR, NEVER CLAIMED BY A
	 * CALLER. A caller that can set the owner can grant itself edit rights on
	 * somebody else's record, because the owner is admitted unconditionally by
	 * every enforcement path. So an ordinary save cannot move the owner, and a
	 * save that tries is refused rather than quietly accepted: the previous
	 * behaviour answered 2xx while storing a different owner, which reads as
	 * success and is not.
	 *
	 * WHAT IS ALLOWED THROUGH, and why each one has to be:
	 *
	 *  - A value equal to the ACTING USER. Nothing moves, so there is nothing to
	 *    refuse.
	 *  - A value equal to the STORED owner. Every read emits `@self.owner`, so the
	 *    ordinary GET-edit-PUT round trip sends it straight back; refusing that
	 *    would break every consuming app for asking for no change at all.
	 *  - A NON-STRING value. A read can render the owner as an expanded user
	 *    object, and sending that back is the same echo as above.
	 *  - ANY value when there is no session user. A background job, an import or a
	 *    migration has no acting user to derive an owner from, and the owner it
	 *    carries is the one being restored. This path is unauthenticated by
	 *    construction and is not reachable from a request.
	 *
	 * @param ObjectEntity $objectEntity The entity being written; its stored owner on an update, empty on a create.
	 * @param array $selfData The caller's `@self` block.
	 *
	 * @throws NotAuthorizedException When the write asks for a different owner.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	private function refuseOwnerClaim(ObjectEntity $objectEntity, array $selfData): void {
		if (array_key_exists('owner', $selfData) === false) {
			return;
		}

		$requested = $selfData['owner'];
		if (is_string($requested) === false || $requested === '') {
			return;
		}

		$actor = $this->userSession->getUser()?->getUID();
		if ($actor === null) {
			return;
		}

		if ($requested === $actor) {
			return;
		}

		$stored = $objectEntity->getOwner();
		if ($stored !== null && $stored !== '' && $requested === $stored) {
			return;
		}

		throw new NotAuthorizedException(
			message: 'An owner cannot be set through a save. Use the ownership endpoint to hand a record over.'
		);
	}//end refuseOwnerClaim()

	/**
	 * Resolve whether the current session user is in the admin group.
	 *
	 * Used as the gate for accepting `@self.organisation` overrides on write
	 * (Wave-12 Fix 3). Returns false when:
	 *  - no session user (anonymous / background);
	 *  - no IGroupManager was injected (legacy DI in tests);
	 *  - IGroupManager raises an exception.
	 *
	 * @return bool True when the current caller is an admin.
	 */
	private function callerIsAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager === null) {
			return false;
		}

		try {
			return $this->groupManager->isAdmin($user->getUID());
		} catch (\Throwable $e) {
			return false;
		}
	}//end callerIsAdmin()

	/**
	 * Populate TMLO defaults on a new object if the register has TMLO enabled.
	 *
	 * Client-supplied @self.tmlo is intentionally NOT accepted here (wave-11 WF1 fix).
	 * All TMLO fields on CREATE are system-managed defaults applied by TmloService.
	 * On UPDATE, validateTmloOnUpdate() enforces the allowed transition matrix instead.
	 *
	 * @param ObjectEntity $objectEntity The object entity being created
	 * @param Schema $schema The schema for TMLO defaults
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function populateTmloDefaults(ObjectEntity $objectEntity, Schema $schema): void {
		$registerId = $objectEntity->getRegister();
		if ($registerId === null) {
			return;
		}

		try {
			$register = $this->getCachedRegister(registerId: (int)$registerId);
		} catch (Exception $e) {
			return;
		}

		if ($this->tmloService->isTmloEnabled($register) === false) {
			return;
		}

		// SECURITY (wave-11 WF1): Do NOT seed TMLO from client-supplied @self.tmlo on CREATE.
		// The destruction pipeline keys off archiefstatus; a client-submitted
		// {"archiefstatus":"vernietigd"} would bypass the validated state machine and
		// mark the object destruction-eligible immediately.  On CREATE, all TMLO fields
		// are system-managed defaults set by tmloService->populateDefaults() below.
		// On UPDATE, the separate validateTmloOnUpdate() path enforces the transition matrix.
		// Validate field values before populating (entity may have leftover TMLO from a prior
		// update path; ensure they are valid before stamping defaults).
		$currentTmlo = $objectEntity->getTmlo();
		if (is_array($currentTmlo) === true && empty($currentTmlo) === false) {
			$errors = $this->tmloService->validateFieldValues($currentTmlo);
			if (empty($errors) === false) {
				throw new Exception('TMLO validation failed: ' . implode('; ', $errors));
			}
		}

		$this->tmloService->populateDefaults($objectEntity, new RegisterSchema(register: $register, schema: $schema));
	}//end populateTmloDefaults()

	/**
	 * Validate TMLO metadata on an object update (status transitions and field values).
	 *
	 * @param ObjectEntity $existingObject The existing object being updated
	 * @param array $selfData The @self metadata from the request
	 *
	 * @return void
	 *
	 * @throws Exception If TMLO validation fails
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function validateTmloOnUpdate(ObjectEntity $existingObject, array $selfData): void {
		// Only validate if TMLO data was provided in the update.
		if (array_key_exists('tmlo', $selfData) === false || is_array($selfData['tmlo']) === false) {
			return;
		}

		$newTmlo = $selfData['tmlo'];

		// Validate field values.
		$fieldErrors = $this->tmloService->validateFieldValues($newTmlo);
		if (empty($fieldErrors) === false) {
			throw new Exception('TMLO validation failed: ' . implode('; ', $fieldErrors));
		}

		// Validate status transition if archiefstatus is changing.
		$oldTmlo = $existingObject->getTmlo();
		$oldStatus = ($oldTmlo['archiefstatus'] ?? TmloService::ARCHIEFSTATUS_ACTIEF);
		$newStatus = ($newTmlo['archiefstatus'] ?? null);

		if ($newStatus !== null && $newStatus !== $oldStatus) {
			// Merge old TMLO with new for complete validation context.
			$mergedTmlo = array_merge(($oldTmlo ?? []), $newTmlo);
			$transitionErrors = $this->tmloService->validateStatusTransition($mergedTmlo, $oldStatus);
			if (empty($transitionErrors) === false) {
				throw new Exception('TMLO status transition failed: ' . implode('; ', $transitionErrors));
			}
		}

		// Update the TMLO field on the entity.
		$existingObject->setTmlo(array_merge(($oldTmlo ?? []), $newTmlo));
	}//end validateTmloOnUpdate()

	/**
	 * Validate reference existence for all properties with validateReference: true.
	 *
	 * Operator-controlled admin bypass: an admin with the bypass flag on (default
	 * true) skips this, so admins can repair broken cross-references during
	 * migrations or bulk imports. `occ config:app:set openregister
	 * reference_validation_admin_bypass --value=false` enforces it for every user.
	 *
	 * @param Schema               $schema The schema containing property definitions.
	 * @param ReferencePropertyRun $run    The data, register and previous data of the write.
	 *
	 * @return void
	 *
	 * @throws ValidationException If a referenced object does not exist.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function validateReferences(Schema $schema, ReferencePropertyRun $run): void {
		if ($this->referenceAdminBypass()->applies() === false) {
			$this->referenceValidator()->validate(schema: $schema, run: $run);
		}
	}//end validateReferences()

	/**
	 * Clear the request-scoped reference-existence cache.
	 *
	 * Provided for long-running CLI processes (e.g. background jobs that
	 * iterate over hundreds of objects) that should not let cached
	 * verdicts leak across logical request boundaries.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function clearReferenceValidationCache(): void {
		$this->referenceExistence()->forget();
	}//end clearReferenceValidationCache()

	/**
	 * Read and clear the verdict the last completed save published.
	 *
	 * A single-slot handover, read exactly once by the row that caused it. The
	 * clear-on-read is what stops a row that reaches neither terminal path from
	 * being classified with the PREVIOUS row's answer — a stale verdict is
	 * indistinguishable from a real one, where a null falls through to a
	 * fallback derived from the row in hand.
	 *
	 * A METHOD rather than a bare property read, deliberately. Reading the
	 * property inline defeats static analysis: PHPStan sees the slot set to null
	 * just before `saveObject()` is called, does not track that the callee
	 * writes it, and concludes the `unchanged` branch is unreachable — which is
	 * how a live branch gets reported as dead code and then deleted. The
	 * declared `?string` return states the contract that is actually true.
	 *
	 * @return string|null `created`, `updated`, `unchanged`, or null when unset.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function consumeLastSaveOutcome(): ?string {
		$outcome = $this->lastSaveOutcome;

		$this->lastSaveOutcome = null;

		return $outcome;
	}//end consumeLastSaveOutcome()

	/**
	 * Streaming bulk-upsert primitive — closes 2c on the
	 * `reference-existence-validation` change.
	 *
	 * Iterates `$rows` one-at-a-time through the standard
	 * `saveObject()` write path (NOT through MagicMapper's
	 * ultraFastBulkSave) so the request-scoped reference-validation
	 * cache is engaged for every row. The cache reduces per-row
	 * reference checks to O(1) lookup against the in-memory map for
	 * the second-and-subsequent occurrences of any given target UUID,
	 * eliminating the N×M database round-trips that the unstreamed
	 * bulk path incurs.
	 *
	 * The returned `BatchOperationStatus` aggregates per-row
	 * outcomes (created/updated/unchanged/failed) plus reference-
	 * cache hit/miss counters, suitable for surfacing as a job
	 * dashboard entry, a streaming response body, or telemetry.
	 *
	 * Failure isolation: per-row exceptions are caught and recorded
	 * on the status; the batch continues. Callers that need
	 * fail-fast semantics can iterate the `failed` array on the
	 * returned status and abort early.
	 *
	 * Out of scope (this is the prerequisite, not the feature):
	 * async dispatch of the streaming loop. Async post-save
	 * re-validation is sequenced after this primitive lands and is
	 * tracked separately.
	 *
	 * @param StreamingSave $save The register and schema context of the batch, its rows (each the shape
	 *                            `saveObject` accepts) and an optional pre-existing status for callers
	 *                            that accumulate across multiple calls.
	 *
	 * @return BatchOperationStatus
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function saveObjectsStreaming(StreamingSave $save): BatchOperationStatus {
		$status = ($save->status ?? new BatchOperationStatus());
		$status->start();

		$run = new StreamRun();
		$run->register = $save->register;
		$run->schema = $save->schema;
		$run->status = $status;

		// Position in the submitted batch. A failed create has no UUID yet, so
		// this is the only thing that lets a caller identify the row it lost
		// (issue #2778). Counted rather than read from the key because $rows is
		// an iterable and may be a generator.
		foreach ($save->rows as $row) {
			$run->rowIndex++;
			$run->row = $row;
			$cacheBefore = $this->referenceExistence()->verdictCount();
			$this->saveStreamRow(run: $run);
			$this->recordReferenceCacheUse(status: $status, cacheBefore: $cacheBefore);
		}

		$status->complete();
		return $status;
	}//end saveObjectsStreaming()

	/**
	 * Save one streamed row and record its outcome, or its failure without stopping the batch.
	 *
	 * @param StreamRun $run The batch, on one row.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function saveStreamRow(StreamRun $run): void {
		try {
			$rowData = [];
			if (is_array($run->row) === true) {
				$rowData = $run->row;
			}

			// Clear the slot first: a row that reaches neither terminal path must
			// not be classified with the PREVIOUS row's verdict.
			$this->lastSaveOutcome = null;
			$entity = $this->saveObject(register: $run->register, schema: $run->schema, data: $rowData);
			$this->recordStreamOutcome(run: $run, uuid: (string)$entity->getUuid());
		} catch (Exception $e) {
			$rowUuid = self::streamRowUuid(row: $run->row);
			$run->status->recordFailed(
				failure: new RowFailure(uuid: $rowUuid, message: $e->getMessage(), exceptionClass: $e::class, index: $run->rowIndex)
			);

			$this->logger->warning(
				message: '[SaveObject] saveObjectsStreaming row failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $rowUuid,
					'index' => $run->rowIndex,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end saveStreamRow()

	/**
	 * Record a saved row as created, updated or unchanged, from the save path's own verdict.
	 *
	 * The verdict is not guessed from the input: a row supplying a uuid for an
	 * object that does not exist yet is a create, and a write that changed
	 * nothing is unchanged. Only when the verdict is unset (a `persist: false`
	 * prepare reaching neither terminal path) does the old heuristic apply,
	 * so a row is never dropped from the totals.
	 *
	 * @param StreamRun $run  The batch, on one row.
	 * @param string    $uuid The saved object's UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function recordStreamOutcome(StreamRun $run, string $uuid): void {
		$outcome = ($this->consumeLastSaveOutcome() ?? self::guessedStreamOutcome(row: $run->row));
		if ($outcome === 'created') {
			$run->status->recordCreated(uuid: $uuid);
		}

		if ($outcome === 'updated') {
			$run->status->recordUpdated(uuid: $uuid);
		}

		if ($outcome === 'unchanged') {
			$run->status->recordUnchanged(uuid: $uuid);
		}
	}//end recordStreamOutcome()

	/**
	 * The outcome guessed from a row's shape: updated when it carries an identifier, else created.
	 *
	 * @param mixed $row The input row.
	 *
	 * @return string The guessed outcome.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function guessedStreamOutcome(mixed $row): string {
		$hadUuid = (
			is_array($row) === true
			&& (isset($row['@self']['id']) === true
			|| isset($row['id']) === true
			|| isset($row['uuid']) === true)
		);
		if ($hadUuid === true) {
			return 'updated';
		}

		return 'created';
	}//end guessedStreamOutcome()

	/**
	 * The identifier a failed row carries, or null when it carries no string identifier.
	 *
	 * @param mixed $row The input row.
	 *
	 * @return string|null The identifier.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function streamRowUuid(mixed $row): ?string {
		if (is_array($row) === false) {
			return null;
		}

		$rowUuid = ($row['@self']['id'] ?? $row['id'] ?? $row['uuid'] ?? null);
		if (is_string($rowUuid) === false) {
			return null;
		}

		return $rowUuid;
	}//end streamRowUuid()

	/**
	 * Count a row's reference checks as cache misses (the cache grew) or one hit (it did not).
	 *
	 * Comparing the cache size before and after the row is a cheap proxy.
	 *
	 * @param BatchOperationStatus $status      The batch status.
	 * @param int                  $cacheBefore The reference cache size before the row.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private function recordReferenceCacheUse(BatchOperationStatus $status, int $cacheBefore): void {
		$delta = ($this->referenceExistence()->verdictCount() - $cacheBefore);
		if ($delta <= 0) {
			$status->recordReferenceCacheHit();
			return;
		}

		for ($i = 0; $i < $delta; $i++) {
			$status->recordReferenceCacheMiss();
		}
	}//end recordReferenceCacheUse()

	/**
	 * Whether the current user skips reference validation.
	 *
	 * @return ReferenceAdminBypass The bypass decision.
	 */
	private function referenceAdminBypass(): ReferenceAdminBypass {
		return new ReferenceAdminBypass(
			adminGate: new AdminGate(userSession: $this->userSession, groupManager: $this->groupManager),
			appConfig: $this->appConfig
		);
	}//end referenceAdminBypass()

	/**
	 * Prepares the run's object data by applying all necessary transformations.
	 *
	 * @param CascadeRun $run    The pass: the object entity as parent, and the object data.
	 * @param Schema     $schema The schema of the object.
	 *
	 * @return array The prepared object data.
	 *
	 * @throws Exception If there is an error during preparation.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function prepareCascadeData(CascadeRun $run, Schema $schema): array {
		$objectEntity = $run->parent;
		$data = $run->data;
		// Sanitize empty strings after validation but before cascading operations.
		// This prevents empty values from causing issues in downstream processing.
		try {
			$data = $this->emptyValues()->sanitize(data: $data, schema: $schema);
		} catch (Exception $e) {
			// CRITICAL FIX: Sanitization failures indicate serious data problems - don't suppress!
			$part1 = 'Object data sanitization failed: ' . $e->getMessage();
			$part2 = '. This indicates invalid or corrupted object data that cannot be processed safely.';
			$errorMessage = $part1 . $part2;
			throw new Exception($errorMessage, 0, $e);
		}

		// Removed references are only cleaned up when the schema opts in. Read before
		// the pre-cache below overwrites the entity's data with the incoming data.
		$run->data = $data;
		if (($schema->getConfiguration()['orphanCleanup'] ?? false) === true) {
			$run->stored = ($objectEntity->getObject() ?? []);
		}

		// Pre-populate the name cache with the parent object's name before cascading.
		// This ensures that when sub-objects (e.g., koppelingen) resolve relation fields
		// (e.g., {{ moduleA }}) via the name template, they can find the parent's name
		// in the cache even though the parent hasn't been persisted to the database yet.
		// Without this, cascaded sub-objects fall back to showing the raw UUID as the name.
		$this->preCacheParentName(run: $run, schema: $schema);

		// Apply cascading operations.
		$data = $this->cascadeObjects(run: $run, schema: $schema);
		$writeBack = new CascadeRun();
		$writeBack->parent = $objectEntity;
		$writeBack->data = $data;
		$data = $this->inverseWriteBack()->write(run: $writeBack, schema: $schema);

		$data = $this->propertyDefaults()->forSave(
			schema: $schema,
			context: DefaultValueContext::ofSave(objectEntity: $objectEntity, data: $data)
		);

		// Evaluate computed fields with evaluateOn: 'save'.
		// This computes values from Twig expressions and stores them in the object data.
		if ($this->computedFieldHandler->hasComputedProperties($schema) === true) {
			$data = $this->computedFieldHandler->evaluateComputedFields(
				data: $data,
				pass: new ComputedPass(schema: $schema, evaluateOn: 'save')
			);
		}

		// Encrypt properties flagged `x-openregister-encrypted: true` (field-level-
		// object-encryption). This is the LAST step before the entity is persisted —
		// every transform above (cascading, defaults, computed fields) needs the
		// plaintext value to operate correctly, and nothing after this point needs
		// it: relation scanning treats an opaque ciphertext string like any other
		// non-reference scalar, and the mapper insert/update writes exactly what it
		// is given. Schema validation already ran on plaintext upstream in
		// ObjectService::saveObject(), before this handler is ever invoked.
		if ($this->fieldEncryptionHandler !== null) {
			$data = $this->fieldEncryptionHandler->encryptProperties(data: $data, schema: $schema);
		}

		return $data;
	}//end prepareCascadeData()

	/**
	 * Pre-populate the name cache with the parent object's name before cascading.
	 *
	 * When a parent object (e.g., an applicatie) is being created with nested sub-objects
	 * (e.g., koppelingen), the sub-objects may reference the parent via inversedBy fields.
	 * The sub-object's name template (e.g., "{{ moduleA }} → {{ moduleB }}") needs to
	 * resolve the parent's UUID to a human-readable name. Since the parent hasn't been
	 * persisted yet, the name cache won't contain its name, causing the template to
	 * fall back to the raw UUID.
	 *
	 * This method computes the parent's name from its data and schema configuration,
	 * then stores it in the cache so sub-objects can resolve it during cascading.
	 *
	 * @param CascadeRun $run    The pass: the parent object entity (with UUID already set) and its data.
	 * @param Schema     $schema The parent object's schema.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function preCacheParentName(CascadeRun $run, Schema $schema): void {
		$objectEntity = $run->parent;
		$data = $run->data;
		$uuid = $objectEntity->getUuid();
		if ($uuid === null) {
			return;
		}

		// Temporarily set the object data so hydrateObjectMetadata can extract the name.
		$objectEntity->setObject($data);

		try {
			$this->metaHydrationHandler->hydrateObjectMetadata(entity: $objectEntity, schema: $schema);
		} catch (\Exception $e) {
			// Non-critical: if hydration fails, cascaded sub-objects will fall back to UUID.
			$this->logger->debug(
				message: '[SaveObject] Pre-cache name hydration failed, sub-objects may show UUID names',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'error' => $e->getMessage(),
				]
			);

			return;
		}

		// SEC-CTRL-2 step 2: pre-cached parent names carry their owning organisation
		// so the shared name cache can never disclose them across tenants.
		$parentOrganisation = $objectEntity->getOrganisation();

		$name = $objectEntity->getName();
		if ($name !== null && trim($name) !== '') {
			$this->cacheHandler->setObjectName(
				identifier: $uuid,
				name: $name,
				organisation: $parentOrganisation
			);
		}

		// Also try the 'naam' field as a fallback (common in Dutch schemas).
		// This covers cases where objectNameField is not configured but naam exists in data.
		if (($name === null || trim($name) === '') && isset($data['naam']) === true) {
			$name = trim((string)$data['naam']);
			if ($name !== '') {
				$this->cacheHandler->setObjectName(
					identifier: $uuid,
					name: $name,
					organisation: $parentOrganisation
				);
			}
		}
	}//end preCacheParentName()

	/**
	 * Updates an existing object.
	 *
	 * @param Register|int|string $register The register containing the object.
	 * @param Schema|int|string $schema The schema to validate against.
	 * @param array $data The updated object data.
	 * @param ObjectEntity $existingObject The existing object to update.
	 * @param bool $silent Whether to skip audit trail creation and events (default: false).
	 * @param ObjectEntity|null $oldObject The original object before changes (optional).
	 *
	 * @return ObjectEntity The updated object entity.
	 *
	 * @throws Exception If there is an error during update.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Silent flag needed for audit trail control
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function updateObject(
		Register|int|string $register,
		Schema|int|string $schema,
		array $data,
		ObjectEntity $existingObject,
		bool $silent = false,
		?ObjectEntity $oldObject = null,
	): ObjectEntity {
		$run = new ObjectUpdateRun();
		$run->silent = $silent;

		// Prefer the caller's oldObject (handleObjectUpdate): a clone taken here may
		// already hold data prepareObjectForUpdate changed in place.
		$run->oldObject = ($oldObject ?? clone $existingObject);

		if (($data['@self'] ?? null) !== null && is_array($data['@self']) === true) {
			$run->selfData = $data['@self'];
		}

		unset($data['@self'], $data['id']);
		$run->data = $data;

		// Resolve register and schema to entity objects if needed.
		if (is_int($register) === true || is_string($register) === true) {
			$register = $this->registerMapper->find(id: (int)$register, _multitenancy: false);
		}

		if (is_int($schema) === true || is_string($schema) === true) {
			$schema = $this->schemaMapper->find(id: (int)$schema, _multitenancy: false);
		}

		$run->register = $register;
		$run->schema = $schema;

		$updatedEntity = $this->persistObjectUpdate(run: $run, existingObject: $existingObject);
		$this->outdatedTranslations()->flag(run: $run, updated: $updatedEntity);
		$this->recordObjectUpdate(run: $run, updatedEntity: $updatedEntity);
		$updatedEntity = $this->objectFiles()->storeForUpdate(run: $run, updated: $updatedEntity);

		// Bidirectional relations; skipped in silent mode (cascaded sub-objects) for performance.
		if ($silent === false) {
			$this->inverseRelations()->update(saved: $updatedEntity, write: new InverseRelationWrite(register: $register, schema: $schema));
		}

		return $updatedEntity;
	}//end updateObject()

	/**
	 * Prepare the stored object with the update and write it through the unified mapper.
	 *
	 * The old object goes along so events detect status changes accurately.
	 *
	 * @param ObjectUpdateRun $run            The update call.
	 * @param ObjectEntity    $existingObject The stored object.
	 *
	 * @return ObjectEntity The updated entity.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function persistObjectUpdate(ObjectUpdateRun $run, ObjectEntity $existingObject): ObjectEntity {
		$registerId = $run->register->getId();
		$schemaId = $run->schema->getId();
		$preparedObject = $this->prepareObjectForUpdate(
			existingObject: $existingObject,
			schema: $run->schema,
			payload: new ObjectPayload(data: $run->data, selfData: $run->selfData)
		);
		$preparedObject = $this->recalculateArchiveDate(run: $run, preparedObject: $preparedObject);

		$preparedObject->setRegister((string)$registerId);
		$preparedObject->setSchema((string)$schemaId);
		$preparedObject->setUpdated(new DateTime());
		$this->userActivity?->stamp(object: $preparedObject);

		$this->logger->debug(
			message: '[SaveObject] Updating object using MagicMapper',
			context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $preparedObject->getUuid()]
		);

		return $this->unifiedObjectMapper->update(
			entity: $preparedObject,
			register: $run->register,
			schema: $run->schema,
			oldEntity: $run->oldObject
		);
	}//end persistObjectUpdate()

	/**
	 * Recalculate the archive action date when a source property changed, when retention is available.
	 *
	 * @param ObjectUpdateRun $run            The update call.
	 * @param ObjectEntity    $preparedObject The object prepared for the update.
	 *
	 * @return ObjectEntity The object with its archive action date recalculated.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function recalculateArchiveDate(ObjectUpdateRun $run, ObjectEntity $preparedObject): ObjectEntity {
		$retentionService = $this->resolveRetentionService();
		if ($retentionService === null) {
			return $preparedObject;
		}

		try {
			return $retentionService->recalculateActionDate(
				new RecordUpdate($preparedObject, $run->schema, $run->oldObject->getObject())
			);
		} catch (\Throwable $e) {
			$this->logger->debug('[SaveObject] Skipping archive action date recalculation: ' . $e->getMessage());
			return $preparedObject;
		}
	}//end recalculateArchiveDate()

	/**
	 * Log the update and write its audit trail, unless silent or audit trails are off.
	 *
	 * The log is debug: a routine update is the noise floor, and a failed
	 * update logs on its own path.
	 *
	 * @param ObjectUpdateRun $run           The update call.
	 * @param ObjectEntity    $updatedEntity The updated entity.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function recordObjectUpdate(ObjectUpdateRun $run, ObjectEntity $updatedEntity): void {
		$this->logger->debug(
			message: '[SaveObject] Object updated successfully',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'app' => 'openregister',
				'uuid' => $updatedEntity->getUuid(),
			]
		);

		if ($run->silent === false && $this->isAuditTrailsEnabled() === true) {
			$log = $this->auditTrailMapper->createAuditTrail(old: $run->oldObject, new: $updatedEntity);
			$updatedEntity->setLastLog($log->jsonSerialize());
		}
	}//end recordObjectUpdate()

	/**
	 * Check if audit trails are enabled in the settings
	 *
	 * @return bool True if audit trails are enabled, false otherwise
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function isAuditTrailsEnabled(): bool {
		try {
			$retentionSettings = $this->settingsService->getRetentionSettingsOnly();
			return $retentionSettings['auditTrailsEnabled'] ?? true;
		} catch (Exception $e) {
			// If we can't get settings, default to enabled for safety.
			$this->logger->warning(
				message: '[SaveObject] Failed to check audit trails setting, defaulting to enabled',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return true;
		}
	}//end isAuditTrailsEnabled()

	/**
	 * Delegate a persisting save on an object-source schema to its writable provider.
	 *
	 * The v1 read-only rejection is preserved for every case that is not an
	 * explicit, currently-valid opt-in: annotation `readOnly` missing or not
	 * `false`, provider missing or not writable, register unresolvable. The
	 * provider itself re-verifies the backing source's writable flag at write
	 * time (fail closed), so a stale annotation can never authorize a write.
	 *
	 * @param ObjectSaveCall $call The save call: the resolved register, the sourced schema, the
	 *                            object data, the object id (null for creates) and the silent flag.
	 * @param array<string, mixed> $objectSource The `x-openregister-object-source` annotation.
	 *
	 * @return ObjectEntity The written virtual object as returned by the provider.
	 *
	 * @throws \RuntimeException When the schema is not writable (v1 rejection).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function delegateObjectSourceWrite(ObjectSaveCall $call, array $objectSource): ObjectEntity {
		$register = $call->register;
		$schema   = $call->schema;
		$data     = $call->data;
		$uuid     = $call->uuid;
		$silent   = $call->flags->silent;
		$provider = null;
		if ($this->objectSourceRegistry !== null) {
			$provider = $this->objectSourceRegistry->get((string)$objectSource['provider']);
		}

		$writableOptIn = (($objectSource['readOnly'] ?? true) === false);
		$writable = ($provider instanceof \OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider);

		if ($writableOptIn === false || $writable === false || $register instanceof Register === false) {
			throw new RuntimeException(
				sprintf(
					'Schema "%s" is a read-only projection of object-source provider "%s"; writes are not allowed.',
					(string)$schema->getSlug(),
					$objectSource['provider']
				)
			);
		}

		$config = ($objectSource['config'] ?? []);

		if ($uuid === null || $uuid === '') {
			$entity = $provider->insert(
				source: new SourcedTable(table: new RegisterSchema(register: $register, schema: $schema), config: $config),
				data: $data
			);
			$this->recordObjectSourceAudit(change: new ObjectSourceChange(action: 'create', old: null, new: $entity), silent: $silent);
			return $entity;
		}

		$old = $provider->find(source: new SourcedTable(table: new RegisterSchema(register: $register, schema: $schema), config: $config), id: $uuid);
		$entity = $provider->update(
			source: new SourcedTable(table: new RegisterSchema(register: $register, schema: $schema), config: $config),
			id: $uuid,
			data: $data
		);
		$this->recordObjectSourceAudit(change: new ObjectSourceChange(action: 'update', old: $old, new: $entity), silent: $silent);

		return $entity;
	}//end delegateObjectSourceWrite()

	/**
	 * Record an audit-trail row for an external write (best effort, design D6).
	 *
	 * An audit failure must never mask a successful external write; it degrades
	 * to a structured secret-free warning.
	 *
	 * @param ObjectSourceChange $change What the external write changed, and as which action.
	 * @param bool               $silent Whether audit creation is suppressed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function recordObjectSourceAudit(ObjectSourceChange $change, bool $silent): void {
		if ($silent === true) {
			return;
		}

		try {
			$this->auditTrailMapper->createAuditTrail(old: $change->old, new: $change->new, action: $change->action);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[SaveObject] audit trail for external ' . $change->action . ' on uuid ' . ((string)$change->new->getUuid()) . ' could not be recorded: ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end recordObjectSourceAudit()
}//end class
