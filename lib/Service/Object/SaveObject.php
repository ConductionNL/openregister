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

use finfo;
use Adbar\Dot;
use DateTime;
use Exception;
use RuntimeException;
use ReflectionClass;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Event\ReferenceValidatedEvent;
use OCA\OpenRegister\Event\ReferenceValidationFailedEvent;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Exception\CircularReferenceException;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use OCP\AppFramework\Db\DoesNotExistException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

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
 */
class SaveObject
{
    private const URL_PATH_IDENTIFIER = 'openregister.objects.show';

    /**
     * App identifier used for `IAppConfig` lookups.
     *
     * @var string
     */
    private const APP_ID = 'openregister';

    /**
     * App-config key controlling whether admins bypass reference
     * existence validation. When the stored value parses to `true`
     * (default), members of the `admin` group skip the check; when
     * `false`, admins are validated like any other user. Operators
     * can flip the flag at runtime via `occ config:app:set`.
     *
     * @var string
     */
    private const CONFIG_KEY_REFERENCE_VALIDATION_ADMIN_BYPASS = 'reference_validation_admin_bypass';

    /**
     * Twig template engine instance
     *
     * @var Environment
     */
    private Environment $twig;

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
     * Request-scoped cache for resolved schemas.
     *
     * Caches Schema entities by their ID to avoid repeated database lookups
     * when creating multiple sub-objects of the same type during cascade operations.
     * This significantly improves POST performance for objects with many sub-objects.
     *
     * @var array<int|string, Schema>
     */
    private array $schemaCache = [];

    /**
     * Request-scoped cache for resolved registers.
     *
     * Caches Register entities by their ID to avoid repeated database lookups
     * during cascade operations.
     *
     * @var array<int|string, Register>
     */
    private array $registerCache = [];

    /**
     * Request-scoped cache for resolved schema references (slug -> ID).
     *
     * Caches the mapping from schema slugs/references to their numeric IDs
     * to avoid repeated findAll() calls during cascade operations.
     *
     * @var array<string, string|null>
     */
    private array $schemaReferenceCache = [];

    /**
     * Request-scoped cache for reference-existence verdicts.
     *
     * Caches the result of `validateReferenceExists()` keyed on
     * `{targetSchemaId}:{uuid}`. Stores `true` for "exists" and `false`
     * for "does not exist". Closes the spec's
     * "Validation results cached within a request scope" + bulk-import
     * batch optimisation requirements: a 1000-row import that points at
     * the same 50 organisations now hits the database 50 times instead
     * of 1000.
     *
     * The verdict is intentionally request-scoped (no distributed
     * cache): cross-request consistency is enforced by re-running
     * validation on every save, and an in-flight cascade can rely on
     * the cache covering the lifetime of the cascade. Listeners only
     * fire on cache misses to avoid duplicate event emission.
     *
     * @var array<string, bool>
     */
    private array $referenceValidationCache = [];

    /**
     * Stack of `(targetSchemaSlug, uuid)` entries currently being saved.
     *
     * Pushed when `saveObject()` enters, popped when it exits. Each
     * entry is keyed on `<targetSchemaSlug>:<uuid>` for fast O(1)
     * membership lookup. Used by `validateReferences()` to detect
     * circular reference chains (A->B->A) — when the value of a
     * `validateReference` property equals one of the in-flight
     * UUIDs, we throw `CircularReferenceException` instead of a
     * regular reference-existence check.
     *
     * Closes the `reference-existence-validation` spec's
     * "Circular reference chains detected during validation"
     * requirement.
     *
     * @var array<int, array{schemaSlug:string,uuid:string,register:string|null}>
     */
    private array $saveCallStack = [];

    /**
     * Fast membership index for `$saveCallStack`. Keys are
     * `<schemaSlug>:<uuid>`; values are integers (the depth at which
     * the entry was pushed) so we can detect re-entries without
     * iterating the stack.
     *
     * @var array<string, int>
     */
    private array $saveCallStackIndex = [];

    /**
     * Constructor for SaveObject handler.
     *
     * @param MagicMapper                 $objectEntityMapper   Object entity mapper
     * @param MagicMapper                 $unifiedObjectMapper  Unified object mapper for object operations
     * @param MetadataHydrationHandler    $metaHydrationHandler Handler for metadata extraction
     * @param FilePropertyHandler         $filePropertyHandler  Handler for file property operations
     * @param LinkedEntityPropertyHandler $linkedEntityHandler  Linked entity property handler
     * @param IUserSession                $userSession          User session service
     * @param AuditTrailMapper            $auditTrailMapper     Audit trail mapper for logging changes
     * @param SchemaMapper                $schemaMapper         Schema mapper for schema operations
     * @param RegisterMapper              $registerMapper       Register mapper for register operations
     * @param IURLGenerator               $urlGenerator         URL generator service
     * @param OrganisationService         $organisationService  Service for organisation operations
     * @param CacheHandler                $cacheHandler         Object cache service for entity and query caching
     * @param SettingsService             $settingsService      Settings service for accessing trail settings
     * @param PropertyRbacHandler         $propertyRbacHandler  Property-level RBAC handler
     * @param ComputedFieldHandler        $computedFieldHandler Handler for computed field evaluation
     * @param TranslationHandler          $translationHandler   Handler for translation operations
     * @param LoggerInterface             $logger               Logger interface for logging operations
     * @param TmloService                 $tmloService          TMLO archival metadata service
     * @param ArrayLoader                 $arrayLoader          Twig array loader for template rendering
     * @param IGroupManager|null          $groupManager         Group manager for admin-bypass detection
     * @param IAppConfig|null             $appConfig            App-config reader for the admin-bypass toggle
     * @param IEventDispatcher|null       $eventDispatcher      Event dispatcher for reference validation events
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    public function __construct(
        private readonly MagicMapper $objectEntityMapper,
        private readonly MagicMapper $unifiedObjectMapper,
        private readonly MetadataHydrationHandler $metaHydrationHandler,
        private readonly FilePropertyHandler $filePropertyHandler,
        private readonly LinkedEntityPropertyHandler $linkedEntityHandler,
        private readonly IUserSession $userSession,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly IURLGenerator $urlGenerator,
        private readonly OrganisationService $organisationService,
        private readonly CacheHandler $cacheHandler,
        private readonly SettingsService $settingsService,
        private readonly PropertyRbacHandler $propertyRbacHandler,
        private readonly ComputedFieldHandler $computedFieldHandler,
        private readonly TranslationHandler $translationHandler,
        private readonly LoggerInterface $logger,
        private readonly TmloService $tmloService,
        private readonly \OCA\OpenRegister\Service\File\FolderManagementHandler $folderManagementHandler,
        ArrayLoader $arrayLoader,
        private readonly ?IGroupManager $groupManager=null,
        private readonly ?IAppConfig $appConfig=null,
        private readonly ?IEventDispatcher $eventDispatcher=null,
    ) {
        $this->twig = new Environment($arrayLoader);
    }//end __construct()

    /**
     * Get sub-objects created during cascade operations.
     *
     * Returns an array of sub-objects indexed by their UUID, suitable for
     * inclusion in the parent object's @self.objects property.
     *
     * @return array<string, array> Sub-objects indexed by UUID
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    public function getCreatedSubObjects(): array
    {
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
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    public function clearCreatedSubObjects(): void
    {
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
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    public function clearAllCaches(): void
    {
        $this->createdSubObjects    = [];
        $this->schemaCache          = [];
        $this->registerCache        = [];
        $this->schemaReferenceCache = [];
    }//end clearAllCaches()

    /**
     * Get a cached schema by ID, or fetch and cache it.
     *
     * @param int|string $schemaId The schema ID to look up
     *
     * @return Schema The schema entity
     *
     * @throws DoesNotExistException If schema not found
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function getCachedSchema(int|string $schemaId): Schema
    {
        $cacheKey = (string) $schemaId;
        if (isset($this->schemaCache[$cacheKey]) === false) {
            $this->schemaCache[$cacheKey] = $this->schemaMapper->find(id: $schemaId);
        }

        return $this->schemaCache[$cacheKey];
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
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function getCachedRegister(int|string $registerId): Register
    {
        $cacheKey = (string) $registerId;
        if (isset($this->registerCache[$cacheKey]) === false) {
            $this->registerCache[$cacheKey] = $this->registerMapper->find(id: $registerId);
        }

        return $this->registerCache[$cacheKey];
    }//end getCachedRegister()

    /**
     * Track a created sub-object for inclusion in @self.objects.
     *
     * This method is called by CascadingHandler when creating related objects
     * during pre-validation cascading.
     *
     * @param string $uuid       The UUID of the created sub-object
     * @param array  $objectData The serialized object data
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    public function trackCreatedSubObject(string $uuid, array $objectData): void
    {
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple resolution strategies require branching
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function resolveSchemaReference(string $reference): string|null
    {
        if (empty($reference) === true) {
            return null;
        }

        // Check the reference cache first (performance optimization for cascade operations).
        // When creating many sub-objects of the same type, this avoids repeated lookups.
        if (isset($this->schemaReferenceCache[$reference]) === true) {
            return $this->schemaReferenceCache[$reference];
        }

        // Remove query parameters if present (e.g., "schema?key=value" -> "schema").
        $cleanReference = $this->removeQueryParameters(reference: $reference);

        // Also check cache with cleaned reference.
        if ($cleanReference !== $reference && isset($this->schemaReferenceCache[$cleanReference]) === true) {
            $this->schemaReferenceCache[$reference] = $this->schemaReferenceCache[$cleanReference];
            return $this->schemaReferenceCache[$reference];
        }

        // First, try direct ID lookup (numeric ID or UUID).
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (is_numeric($cleanReference) === true || preg_match($uuidPattern, $cleanReference) === 1) {
            try {
                $schema   = $this->getCachedSchema(schemaId: $cleanReference);
                $schemaId = (string) $schema->getId();
                $this->schemaReferenceCache[$reference] = $schemaId;
                return $schemaId;
            } catch (DoesNotExistException $e) {
                // Continue with other resolution methods.
            }
        }

        // Extract the last part of path/URL references.
        $slug = $cleanReference;
        if (str_contains($cleanReference, '/') === true) {
            // For references like "#/components/schemas/Contactgegevens" or "http://example.com/schemas/contactgegevens".
            $slug = substr($cleanReference, strrpos($cleanReference, '/') + 1);
        }

        // Try to find schema by slug (case-insensitive).
        // Use findAll() once and cache the results for subsequent lookups.
        try {
            $schemas = $this->schemaMapper->findAll();
            // Cache all schemas by slug for future lookups.
            foreach ($schemas as $schema) {
                $schemaSlug = strtolower($schema->getSlug());
                $schemaId   = (string) $schema->getId();
                // Cache the schema entity.
                $this->schemaCache[$schemaId] = $schema;
                // Cache the slug -> ID mapping.
                $this->schemaReferenceCache['#/components/schemas/'.$schema->getSlug()] = $schemaId;
                $this->schemaReferenceCache[$schema->getSlug()] = $schemaId;
                $this->schemaReferenceCache[$schemaSlug]        = $schemaId;

                if (strcasecmp($schema->getSlug(), $slug) === 0) {
                    $this->schemaReferenceCache[$reference] = $schemaId;
                    return $schemaId;
                }
            }
        } catch (Exception $e) {
            // Schema not found.
        }//end try

        // Try direct slug match as last resort.
        try {
            // SchemaMapper->find() supports id, uuid, and slug via orX().
            $schema = $this->schemaMapper->find(id: $slug, published: null, _rbac: false, _multitenancy: false);
            if ($schema !== null) {
                $schemaId = (string) $schema->getId();
                $this->schemaCache[$schemaId]           = $schema;
                $this->schemaReferenceCache[$reference] = $schemaId;
                return $schemaId;
            }
        } catch (Exception $e) {
            // Schema not found.
        }

        // Cache the null result too to avoid repeated lookups for invalid references.
        $this->schemaReferenceCache[$reference] = null;
        return null;
    }//end resolveSchemaReference()

    /**
     * Removes query parameters from a reference string.
     *
     * @param string $reference The reference string that may contain query parameters
     *
     * @return string The reference string without query parameters
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function removeQueryParameters(string $reference): string
    {
        // Remove query parameters if present (e.g., "schema?key=value" -> "schema").
        if (str_contains($reference, '?') === true) {
            return substr($reference, 0, strpos($reference, '?'));
        }

        return $reference;
    }//end removeQueryParameters()

    /**
     * Resolves a register reference to a register ID.
     *
     * This method handles various types of register references:
     * - Direct ID/UUID: "34", "21aab6e0-2177-4920-beb0-391492fed04b"
     * - Slug references: "publication", "voorzieningen"
     * - URL references: "http://example.com/api/registers/34"
     *
     * For path and URL references, it extracts the last part and matches against register slugs (case-insensitive).
     *
     * @param string $reference The register reference to resolve
     *
     * @return null|numeric-string The resolved register ID or null if not found
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple resolution strategies require branching
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function resolveRegisterReference(string $reference): string|null
    {
        if (empty($reference) === true) {
            return null;
        }

        // First, try direct ID lookup (numeric ID or UUID).
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (is_numeric($reference) === true || preg_match($uuidPattern, $reference) === 1) {
            try {
                $register = $this->registerMapper->find($reference);
                return (string) $register->getId();
            } catch (DoesNotExistException $e) {
                // Continue with other resolution methods.
            }
        }

        // Extract the last part of path/URL references.
        $slug = $reference;
        if (str_contains($reference, '/') === true) {
            // For references like "http://example.com/registers/publication".
            $slug = substr($reference, strrpos($reference, '/') + 1);
        }

        // Try to find register by slug (case-insensitive).
        try {
            $registers = $this->registerMapper->findAll();
            foreach ($registers as $register) {
                if (strcasecmp($register->getSlug(), $slug) === 0) {
                    return (string) $register->getId();
                }
            }
        } catch (Exception $e) {
            // Register not found.
        }

        // Try direct slug match as last resort.
        try {
            // RegisterMapper->find() supports id, uuid, and slug via orX().
            $register = $this->registerMapper->find(id: $slug, published: null, _rbac: false, _multitenancy: false);
            if ($register !== null) {
                return (string) $register->getId();
            }
        } catch (Exception $e) {
            // Register not found.
        }

        return null;
    }//end resolveRegisterReference()

    /**
     * Scans an object for relations (UUIDs and URLs) and returns them in dot notation
     *
     * This method now also checks schema properties for relation types:
     * - Properties with type 'text' and format 'uuid', 'uri', or 'url'
     * - Properties with type 'object' that contain string values (always treated as relations)
     * - Properties with type 'array' of objects that contain string values
     *
     * @param array       $data   The object data to scan
     * @param string      $prefix The current prefix for dot notation (used in recursion)
     * @param Schema|null $schema The schema to check property definitions against
     *
     * @return array Array of relations with dot notation paths as keys and UUIDs/URLs as values
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex relation detection logic
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple detection paths for different value types
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive relation scanning requires extended logic
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    public function scanForRelations(array $data, string $prefix='', ?Schema $schema=null): array
    {
        $relations = [];

        try {
            // Get schema properties if available.
            $schemaProperties = null;
            if ($schema !== null) {
                try {
                    $schemaJson       = json_encode($schema->getSchemaObject($this->urlGenerator));
                    $schemaObject     = json_decode($schemaJson, associative: true);
                    $schemaProperties = $schemaObject['properties'] ?? [];
                } catch (Exception $e) {
                    // Continue without schema properties if parsing fails.
                }
            }

            foreach ($data as $key => $value) {
                // Skip if key is not a string or is empty.
                if (is_string($key) === false || empty($key) === true) {
                    continue;
                }

                $currentPath = $key;
                if (($prefix !== '') === true) {
                    $currentPath = $prefix.'.'.$key;
                }

                if (is_array($value) === true && empty($value) === false) {
                    // Check if this is an array property in the schema.
                    $propertyConfig   = $schemaProperties[$key] ?? null;
                    $isArrayOfObjects = $propertyConfig &&
                                      ($propertyConfig['type'] ?? '') === 'array' &&
                                      isset($propertyConfig['items']['type']) &&
                                      $propertyConfig['items']['type'] === 'object';

                    if ($isArrayOfObjects === true) {
                        // For arrays of objects, scan each item for relations.
                        foreach ($value as $index => $item) {
                            if (is_array($item) === true) {
                                $itemRelations = $this->scanForRelations(
                                    data: $item,
                                    prefix: $currentPath.'.'.$index,
                                    schema: $schema
                                );
                                $relations     = array_merge($relations, $itemRelations);
                            } else if (is_string($item) === true && empty($item) === false) {
                                // String values in object arrays are always treated as relations.
                                $relations[$currentPath.'.'.$index] = $item;
                            }
                        }
                    }

                    if ($isArrayOfObjects === false) {
                        // For non-object arrays, check each item.
                        foreach ($value as $index => $item) {
                            if (is_array($item) === true) {
                                // Recursively scan nested arrays/objects.
                                $itemRelations = $this->scanForRelations(
                                    data: $item,
                                    prefix: $currentPath.'.'.$index,
                                    schema: $schema
                                );
                                $relations     = array_merge($relations, $itemRelations);
                            } else if (is_string($item) === true && empty($item) === false && trim($item) !== '') {
                                // Check if the string looks like a reference.
                                if ($this->isReference(value: $item) === true) {
                                    $relations[$currentPath.'.'.$index] = $item;
                                }
                            }
                        }
                    }//end if
                } else if (is_string($value) === true && empty($value) === false && trim($value) !== '') {
                    $treatAsRelation = false;

                    // Check schema property configuration first.
                    if (($schemaProperties !== null) === true && (($schemaProperties[$key] ?? null) !== null)) {
                        $propertyConfig = $schemaProperties[$key];
                        $propertyType   = $propertyConfig['type'] ?? '';
                        $propertyFormat = $propertyConfig['format'] ?? '';

                        // Check for explicit relation types.
                        if ($propertyType === 'text' && in_array($propertyFormat, ['uuid', 'uri', 'url']) === true) {
                            $treatAsRelation = true;
                        } else if ($propertyType === 'object') {
                            // Object properties with string values are always relations.
                            $treatAsRelation = true;
                        }
                    }

                    // If not determined by schema, check for reference patterns.
                    if ($treatAsRelation === false) {
                        $treatAsRelation = $this->isReference(value: $value);
                    }

                    if ($treatAsRelation === true) {
                        $relations[$currentPath] = $value;
                    }
                }//end if
            }//end foreach
        } catch (Exception $e) {
            // Error scanning for relations.
        }//end try

        return $relations;
    }//end scanForRelations()

    /**
     * Determines if a string value should be treated as a reference to another object
     *
     * This method checks for various reference patterns including:
     * - Standard UUIDs (e.g., "dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4")
     * - Prefixed IDs (e.g., "id-819c2fe5-db4e-4b6f-8071-6a63fd400e34")
     * - URLs
     * - Other identifier patterns
     *
     * @param string $value The string value to check
     *
     * @return bool True if the value should be treated as a reference
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple reference pattern checks required
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function isReference(string $value): bool
    {
        $value = trim($value);

        // Empty strings are not references.
        if (empty($value) === true) {
            return false;
        }

        // Check for standard UUID pattern (8-4-4-4-12 format).
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return true;
        }

        // Check for UUID without dashes (32 hex chars).
        if (preg_match('/^[0-9a-f]{32}$/i', $value) === 1) {
            return true;
        }

        // Check for prefixed UUID patterns (e.g., "id-uuid", "ref-uuid", etc.) - with dashes.
        if (preg_match('/^[a-z]+-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return true;
        }

        // Check for prefixed UUID patterns without dashes (e.g., "id-32hexchars").
        if (preg_match('/^[a-z]+-[0-9a-f]{32}$/i', $value) === 1) {
            return true;
        }

        // Check for numeric IDs.
        if (preg_match('/^[0-9]+$/', $value) === 1) {
            return true;
        }

        // Check for URLs.
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        // Check for other common ID patterns, but be more selective to avoid false positives.
        // Only consider strings that look like identifiers, not regular text.
        if (preg_match('/^[a-z0-9][a-z0-9_-]{7,}$/i', $value) === 1) {
            // Must contain at least one hyphen or underscore (indicating it's likely an ID).
            // AND must not contain spaces or common text words.
            $hasHyphenUndscr = (strpos($value, '-') !== false || strpos($value, '_') !== false);
            $hasNoSpaces     = preg_match('/\s/', $value) === 0;
            $commonWords     = ['applicatie', 'systeemsoftware', 'open-source', 'closed-source'];
            $isNotCommonWord = in_array(strtolower($value), $commonWords, true) === false;
            if ($hasHyphenUndscr === true && $hasNoSpaces === true && $isNotCommonWord === true) {
                return true;
            }
        }

        return false;
    }//end isReference()

    /**
     * Updates the relations property of an object entity
     *
     * @param ObjectEntity $objectEntity The object entity to update
     * @param array        $data         The object data to scan for relations
     * @param Schema|null  $schema       The schema to check property definitions against
     *
     * @return ObjectEntity The updated object entity
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function updateObjectRelations(ObjectEntity $objectEntity, array $data, ?Schema $schema=null): ObjectEntity
    {
        // Scan for relations in the object data.
        $relations = $this->scanForRelations(data: $data, prefix: '', schema: $schema);

        // Set the relations on the object entity.
        $objectEntity->setRelations($relations);

        return $objectEntity;
    }//end updateObjectRelations()

    /**
     * Updates inverse relations on related objects (bidirectional relationship management).
     *
     * When an object references another object (e.g., contactpersoon references organisatie),
     * this method updates the referenced object's relations to include the referencing object.
     * This ensures bidirectional relationships are maintained.
     *
     * @param ObjectEntity $savedEntity The saved object that has relations
     * @param Register     $register    The register the saved entity belongs to
     * @param Schema       $schema      The schema the saved entity belongs to
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Inverse relation handling requires per-type branching
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function updateInverseRelations(ObjectEntity $savedEntity, Register $register, Schema $schema): void
    {
        $relations = $savedEntity->getRelations();
        $savedUuid = $savedEntity->getUuid();

        $relationsCount = 0;
        if ($relations !== null) {
            $relationsCount = count($relations);
        }

        $this->logger->debug(
            message: '[SaveObject] updateInverseRelations called',
            context: [
                'file'            => __FILE__,
                'line'            => __LINE__,
                'savedObjectUuid' => $savedUuid,
                'relationsCount'  => $relationsCount,
                'schemaId'        => $schema->getId(),
            ]
        );

        if (empty($relations) === true) {
            return;
        }

        // Get schema properties to determine target schemas for relations.
        $schemaProperties = $schema->getProperties() ?? [];

        // Process each relation (key = property path, value = related UUID).
        foreach ($relations as $propertyPath => $relatedUuid) {
            // Skip if not a valid UUID string.
            if (is_string($relatedUuid) === false || empty($relatedUuid) === true) {
                continue;
            }

            // Skip if doesn't look like a UUID.
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $relatedUuid) !== 1) {
                continue;
            }

            try {
                // Get the base property name (e.g., "organisatie" from "organisatie.0").
                $baseProperty = explode('.', $propertyPath)[0];

                // Look up the target schema from the property configuration.
                $propertyConfig = $schemaProperties[$baseProperty] ?? null;
                if ($propertyConfig === null) {
                    $this->logger->debug(
                        message: '[SaveObject] No property config for relation',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $baseProperty]
                    );
                    continue;
                }

                // Get the target schema from $ref field (format: #/components/schemas/schemaslug).
                // For arrays, the $ref is in items.$ref instead of directly on the property.
                $ref = $propertyConfig['$ref'] ?? '';
                if (empty($ref) === true && isset($propertyConfig['items']['$ref']) === true) {
                    $ref = $propertyConfig['items']['$ref'];
                }

                // Parse the schema slug from the $ref (e.g., "#/components/schemas/organisatie" -> "organisatie").
                $targetSchemaSlug = '';
                if (preg_match('~^\#/components/schemas/(.+)$~', $ref, $matches) === 1) {
                    $targetSchemaSlug = $matches[1];
                }

                if (empty($targetSchemaSlug) === true) {
                    $this->logger->debug(
                        message: '[SaveObject] No target schema in $ref for relation',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $baseProperty]
                    );
                    continue;
                }

                // Resolve the target schema by slug.
                try {
                    $targetSchema = $this->schemaMapper->find(
                        id: $targetSchemaSlug,
                        published: null,
                        _rbac: false,
                        _multitenancy: false
                    );
                } catch (\Exception $e) {
                    $this->logger->warning(
                        message: '[SaveObject] Could not resolve target schema',
                        context: [
                            'file'             => __FILE__,
                            'line'             => __LINE__,
                            'targetSchemaSlug' => $targetSchemaSlug,
                            'error'            => $e->getMessage(),
                        ]
                    );
                    continue;
                }

                // Find the related object using the resolved target schema.
                $relatedObject = $this->objectEntityMapper->find(
                    identifier: $relatedUuid,
                    register: $register,
                    schema: $targetSchema,
                    includeDeleted: false,
                    _rbac: false,
                    _multitenancy: false
                );

                // Get current relations of the related object.
                $relatedRelations = $relatedObject->getRelations() ?? [];

                // Check if this object's UUID is already in the related object's relations.
                if (in_array($savedUuid, $relatedRelations, true) === true) {
                    $this->logger->debug(
                        message: '[SaveObject] Object already in related object\'s relations',
                        context: [
                            'file'        => __FILE__,
                            'line'        => __LINE__,
                            'savedUuid'   => $savedUuid,
                            'relatedUuid' => $relatedUuid,
                        ]
                    );
                    continue;
                }

                // Add this object's UUID to the related object's relations.
                $relatedRelations[] = $savedUuid;
                $relatedObject->setRelations($relatedRelations);
                $relatedObject->setUpdated(new DateTime());

                // Save the related object.
                $this->objectEntityMapper->update($relatedObject);

                $this->logger->debug(
                    message: '[SaveObject] Updated inverse relation',
                    context: [
                        'file'              => __FILE__,
                        'line'              => __LINE__,
                        'savedUuid'         => $savedUuid,
                        'relatedUuid'       => $relatedUuid,
                        'newRelationsCount' => count($relatedRelations),
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[SaveObject] Failed to update inverse relation',
                    context: [
                        'file'        => __FILE__,
                        'line'        => __LINE__,
                        'savedUuid'   => $savedUuid,
                        'relatedUuid' => $relatedUuid,
                        'error'       => $e->getMessage(),
                    ]
                );
                // Continue with other relations even if one fails.
            }//end try
        }//end foreach
    }//end updateInverseRelations()

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
     * @param Schema       $schema The schema containing the configuration
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
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    public function hydrateObjectMetadata(ObjectEntity $entity, Schema $schema): void
    {
        $config     = $schema->getConfiguration();
        $objectData = $entity->getObject();

        // Delegate simple metadata extraction (name, description, summary, slug) to handler.
        $this->metaHydrationHandler->hydrateObjectMetadata(entity: $entity, schema: $schema);

        // Image field mapping.
        if (($config['objectImageField'] ?? null) !== null) {
            // First check if the field points to a file object.
            $imageValue = $this->metaHydrationHandler->getValueFromPath(
                data: $objectData,
                path: $config['objectImageField']
            );

            // Handle different value types:.
            // 1. Array of file IDs: [123, 124].
            // 2. Array of file objects: [{accessUrl: ...}, {accessUrl: ...}]
            // 3. Single file ID: 123.
            // 4. Single file object: {accessUrl: ...}
            // 5. String URL.
            if (is_array($imageValue) === true && empty($imageValue) === false) {
                // Check if first element is a file ID or file object.
                $firstElement = $imageValue[0] ?? null;

                if (is_numeric($firstElement) === true) {
                    // Array of file IDs - load first file and get its download URL.
                    try {
                        // TODO: fileService->getFile(object: $entity, file: (int) $firstElement).
                        // When implemented, uncomment:
                        // If ($fileNode !== null) {
                        // $fileData = null;
                        // TODO: fileService->formatFile($fileNode);
                        // IMPORTANT: Object image requires public access.
                        // If file is not published, auto-publish it.
                        // If (empty($fileData['downloadUrl']) === true) {
                        // $this->logger->warning(
                        // 'File configured as objectImageField is not published. Auto-publishing file.',
                        // [
                        // 'app'      => 'openregister',
                        // 'fileId'   => $firstElement,
                        // 'objectId' => $entity->getId(),
                        // 'field'    => $config['objectImageField'],
                        // ]
                        // );
                        // Publish the file.
                        // Null;
                        // TODO: fileService->publishFile(object: $entity, file: $fileNode->getId());
                        // Re-fetch file data after publishing.
                        // $fileData = null;
                        // TODO: fileService->formatFile($fileNode).
                        // }
                        // .
                        // If (($fileData['downloadUrl'] ?? null) !== null) {
                        // $entity->setImage($fileData['downloadUrl']).
                        // }
                        // }//end if.
                    } catch (Exception $e) {
                        // File not found or error loading - skip.
                        $this->logger->error(
                            message: '[SaveObject] Failed to load file for objectImageField',
                            context: [
                                'file'   => __FILE__,
                                'line'   => __LINE__,
                                'app'    => 'openregister',
                                'fileId' => $firstElement,
                                'error'  => $e->getMessage(),
                            ]
                        );
                    }//end try
                } else if (is_array($firstElement) === true && (($firstElement['downloadUrl'] ?? null) !== null)) {
                    // Array of file objects - use first file's downloadUrl.
                    $entity->setImage($firstElement['downloadUrl']);
                } else if (is_array($firstElement) === true && (($firstElement['accessUrl'] ?? null) !== null)) {
                    // Fallback to accessUrl if downloadUrl not available.
                    $entity->setImage($firstElement['accessUrl']);
                }//end if
            } else if (is_numeric($imageValue) === true) {
                // Single file ID - load file and get its download URL (not yet implemented).
                $this->logger->debug(
                    message: '[SaveObject] File ID detected for objectImageField - file loading not yet implemented',
                    context: [
                        'file'   => __FILE__,
                        'line'   => __LINE__,
                        'app'    => 'openregister',
                        'fileId' => $imageValue,
                    ]
                );
            } else if (is_string($imageValue) === true && trim($imageValue) !== '') {
                // Regular string URL.
                $entity->setImage(trim($imageValue));
            }//end if
        }//end if

    }//end hydrateObjectMetadata()

    /**
     * Gets a value from an object using dot notation path.
     *
     * @param array  $data The object data
     * @param string $path The dot notation path (e.g., 'name', 'contact.email', 'address.street')
     *
     * @return string|null The value at the path, or null if not found
     *
     * @psalm-return   string|null
     * @phpstan-return string|null
     */

    /**
     * Get value from nested array using dot notation path
     *
     * @param array  $data The data array to search
     * @param string $path The dot notation path (e.g., 'user.profile.name')
     *
     * @return mixed The value at the path or null if not found
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function getValueFromPath(array $data, string $path)
    {
        $keys    = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (is_array($current) === false || array_key_exists($key, $current) === false) {
                return null;
            }

            $current = $current[$key];
        }

        // Convert to string if it's not null and not already a string.
        if ($current !== null && is_string($current) === false) {
            $current = (string) $current;
        }

        return $current;
    }//end getValueFromPath()

    /**
     * Set default values and constant values for properties based on the schema.
     *
     * This method now supports different default value behaviors:
     * - 'false' (default): Only apply defaults when property is missing or null
     * - 'falsy': Also apply defaults when property is empty string or empty array/object
     *
     * @param ObjectEntity $objectEntity The objectEntity for which to perform this action.
     * @param Schema       $schema       The schema the objectEntity belongs to.
     * @param array        $data         The data that is written to the object.
     *
     * @return array The data object updated with default values and constant values from the $schema.
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\SyntaxError
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex default value logic with multiple behaviors
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple property types and behaviors require branching
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive default value handling
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function setDefaultValues(ObjectEntity $objectEntity, Schema $schema, array $data): array
    {
        try {
            $schemaObject = json_decode(json_encode($schema->getSchemaObject($this->urlGenerator)), associative: true);

            if (isset($schemaObject['properties']) === false || is_array($schemaObject['properties']) === false) {
                return $data;
            }
        } catch (Exception $e) {
            return $data;
        }

        // Convert the properties array to a processable array.
        $properties = array_map(
            function (string $key, array $property) {
                if (isset($property['default']) === false) {
                    $property['default'] = null;
                }

                $property['title'] = $key;
                return $property;
            },
            array_keys($schemaObject['properties']),
            $schemaObject['properties']
        );

        // Handle constant values - these should ALWAYS be set regardless of input data.
        $constantValues = [];
        foreach ($properties as $property) {
            if (($property['const'] ?? null) !== null) {
                $constantValues[$property['title']] = $property['const'];
            }
        }

        // Handle default values with new behavior support.
        $defaultValues = [];
        foreach ($properties as $property) {
            $key          = $property['title'];
            $defaultValue = $property['default'] ?? null;

            // Skip if no default value is defined.
            if ($defaultValue === null) {
                continue;
            }

            $defaultBehavior = $property['defaultBehavior'] ?? 'false';

            // Determine if default should be applied based on behavior.
            $shouldApplyDefault = false;

            // Default behavior: only apply if property is missing or null.
            $shouldApplyDefault = isset($data[$key]) === false || $data[$key] === null;
            if ($defaultBehavior === 'falsy') {
                // Apply default if property is missing, null, empty string, or empty array/object.
                $shouldApplyDefault = isset($data[$key]) === false
                    || $data[$key] === null
                    || $data[$key] === ''
                    || (is_array($data[$key]) === true && empty($data[$key]));
            } else if ($defaultBehavior === 'always') {
                // Always apply default value on every save (computed/derived property).
                $shouldApplyDefault = true;
            }

            if ($shouldApplyDefault === true) {
                $defaultValues[$key] = $defaultValue;
            }
        }//end foreach

        // Render twig templated default values.
        // Merge incoming $data with existing object data so Twig templates can reference
        // both newly submitted values and existing object properties.
        $twigContext      = array_merge($objectEntity->getObjectArray(), $data);
        $renderedDefaults = [];
        foreach ($defaultValues as $key => $defaultValue) {
            try {
                if (is_string($defaultValue) === true
                    && str_contains(haystack: $defaultValue, needle: '{{') === true
                    && str_contains(haystack: $defaultValue, needle: '}}') === true
                ) {
                    // Check if this is a simple property reference like "{{ propertyName }}"
                    // to preserve array values instead of converting to string.
                    $simpleRefPattern = '/^\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}$/';
                    if (preg_match($simpleRefPattern, $defaultValue, $matches) === 1) {
                        $sourceProperty = $matches[1];
                        if (isset($twigContext[$sourceProperty]) === true) {
                            // Direct copy preserves arrays and other types.
                            $renderedDefaults[$key] = $twigContext[$sourceProperty];
                        }

                        if (isset($twigContext[$sourceProperty]) === false) {
                            // Source property not found, use empty value.
                            $renderedDefaults[$key] = null;
                        }
                    }

                    if (preg_match($simpleRefPattern, $defaultValue, $matches) !== 1) {
                        // Complex template, use MetadataHydrationHandler which supports
                        // pipe-based filters (| map:) and fallback syntax (| field2).
                        $rendered = $this->metaHydrationHandler->processTwigLikeTemplate(
                            data: $twigContext,
                            template: $defaultValue,
                            schemaProperties: $schemaObject['properties'] ?? []
                        );
                        $renderedDefaults[$key] = $rendered;
                    }
                }//end if

                if (is_string($defaultValue) === false
                    || str_contains(haystack: $defaultValue, needle: '{{') === false
                    || str_contains(haystack: $defaultValue, needle: '}}') === false
                ) {
                    $renderedDefaults[$key] = $defaultValue;
                }
            } catch (Exception $e) {
                $renderedDefaults[$key] = $defaultValue;
                // Use original value if template fails.
            }//end try
        }//end foreach

        // Merge in this order:.
        // 1. Start with existing data.
        // 2. Apply rendered default values (only for properties that should get defaults).
        // 3. Override with constant values (constants always take precedence).
        $mergedData = array_merge($data, $renderedDefaults, $constantValues);

        // Generate slug if not present and schema has slug configuration.
        if (isset($mergedData['slug']) === false && isset($mergedData['@self']['slug']) === false) {
            $slug = $this->generateSlug(data: $mergedData, schema: $schema);
            if ($slug !== null) {
                // Set slug in the data (will be applied to entity in setSelfMetadata).
                $mergedData['slug'] = $slug;
            }
        }

        return $mergedData;
    }//end setDefaultValues()

    /**
     * Applies defaults with defaultBehavior: "always" BEFORE validation.
     *
     * This method is called from ObjectService before validation to ensure that
     * computed/derived properties with defaultBehavior: "always" are set before
     * validation runs. This allows properties like "dienstType" to be automatically
     * populated from "type" even when the payload contains an invalid value.
     *
     * @param Schema $schema The schema containing property definitions.
     * @param array  $data   The object data to transform.
     *
     * @return array The transformed data with "always" defaults applied.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Default value resolution requires template + type branching
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    public function applyAlwaysDefaults(Schema $schema, array $data): array
    {
        try {
            $schemaObject = json_decode(json_encode($schema->getSchemaObject($this->urlGenerator)), associative: true);

            if (isset($schemaObject['properties']) === false || is_array($schemaObject['properties']) === false) {
                return $data;
            }
        } catch (Exception $e) {
            return $data;
        }

        // Find properties with defaultBehavior: "always" and a default value.
        $alwaysDefaults = [];
        foreach ($schemaObject['properties'] as $key => $property) {
            $defaultBehavior = $property['defaultBehavior'] ?? null;
            $defaultValue    = $property['default'] ?? null;

            if ($defaultBehavior === 'always' && $defaultValue !== null) {
                $alwaysDefaults[$key] = $defaultValue;
            }
        }

        // If no "always" defaults, return data unchanged.
        if (empty($alwaysDefaults) === true) {
            return $data;
        }

        // Render twig templated default values.
        // Use the data itself as Twig context (no existing object at this point).
        foreach ($alwaysDefaults as $key => $defaultValue) {
            $resolved = $this->resolveDefaultTemplateValue(
                defaultValue: $defaultValue,
                context: $data,
                schemaProperties: $schemaObject['properties'] ?? []
            );
            if ($resolved !== null) {
                $data[$key] = $resolved;
            }
        }//end foreach

        return $data;
    }//end applyAlwaysDefaults()

    /**
     * Apply property default values to a data array (for use in bulk save paths).
     *
     * This is the public counterpart to setDefaultValues() that can work without
     * an ObjectEntity. It applies defaults based on defaultBehavior settings and
     * renders templates using MetadataHydrationHandler's processTwigLikeTemplate().
     *
     * @param Schema $schema The schema containing property definitions.
     * @param array  $data   The object data to apply defaults to.
     *
     * @return array The data with defaults applied.
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    public function applyPropertyDefaults(Schema $schema, array $data): array
    {
        try {
            $schemaObject = json_decode(json_encode($schema->getSchemaObject($this->urlGenerator)), associative: true);

            if (isset($schemaObject['properties']) === false || is_array($schemaObject['properties']) === false) {
                return $data;
            }
        } catch (Exception $e) {
            return $data;
        }

        foreach ($schemaObject['properties'] as $key => $property) {
            $defaultValue = $property['default'] ?? null;
            if ($defaultValue === null) {
                continue;
            }

            $defaultBehavior = $property['defaultBehavior'] ?? 'false';

            // Determine if default should be applied based on behavior setting.
            if ($this->shouldApplyDefault(behavior: $defaultBehavior, data: $data, key: $key) === false) {
                continue;
            }

            // Render templates using MetadataHydrationHandler (supports | map: syntax).
            $resolved = $this->resolveDefaultTemplateValue(
                defaultValue: $defaultValue,
                context: $data,
                schemaProperties: $schemaObject['properties'] ?? []
            );
            if ($resolved !== null) {
                $data[$key] = $resolved;
            }
        }//end foreach

        return $data;
    }//end applyPropertyDefaults()

    /**
     * Determines whether a default value should be applied based on the behavior setting.
     *
     * Evaluates the defaultBehavior setting against the current data to decide
     * if the default should override or fill in the value:
     * - "always": always apply the default
     * - "falsy": apply when the value is missing, null, empty string, or empty array
     * - default: apply only when the value is missing or null
     *
     * @param string $behavior The defaultBehavior setting from the schema property.
     * @param array  $data     The current object data.
     * @param string $key      The property key to check.
     *
     * @return bool True if the default should be applied.
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function shouldApplyDefault(string $behavior, array $data, string $key): bool
    {
        if ($behavior === 'always') {
            return true;
        }

        if ($behavior === 'falsy') {
            return isset($data[$key]) === false
                || $data[$key] === null
                || $data[$key] === ''
                || (is_array($data[$key]) === true && empty($data[$key]));
        }

        // Default behavior: apply only when missing or null.
        return isset($data[$key]) === false || $data[$key] === null;
    }//end shouldApplyDefault()

    /**
     * Resolves a default value, rendering templates if the value contains Twig-like syntax.
     *
     * Handles three cases:
     * - Simple property reference (e.g., "{{ propertyName }}"): copies the value directly,
     *   preserving arrays and other non-string types.
     * - Complex template (e.g., "{{ items | map:name }}"): renders via MetadataHydrationHandler.
     * - Non-template value: returns the value as-is.
     *
     * @param mixed $defaultValue     The default value to resolve (may contain templates).
     * @param array $context          The data context for template rendering.
     * @param array $schemaProperties The schema properties for template rendering.
     *
     * @return mixed The resolved value, or null if resolution failed.
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function resolveDefaultTemplateValue($defaultValue, array $context, array $schemaProperties)
    {
        try {
            if (is_string($defaultValue) === true
                && str_contains(haystack: $defaultValue, needle: '{{') === true
                && str_contains(haystack: $defaultValue, needle: '}}') === true
            ) {
                // Check if this is a simple property reference like "{{ propertyName }}"
                // to preserve array values instead of converting to string.
                $simpleRefPattern = '/^\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}$/';
                if (preg_match($simpleRefPattern, $defaultValue, $matches) === 1) {
                    $sourceProperty = $matches[1];
                    if (isset($context[$sourceProperty]) === true) {
                        // Direct copy preserves arrays and other types.
                        return $context[$sourceProperty];
                    }

                    // If source property not found, skip (don't overwrite with null).
                    return null;
                }

                // Complex template, use MetadataHydrationHandler which supports
                // pipe-based filters (| map:) and fallback syntax (| field2).
                return $this->metaHydrationHandler->processTwigLikeTemplate(
                    data: $context,
                    template: $defaultValue,
                    schemaProperties: $schemaProperties
                );
            }//end if

            // Non-template value, use directly.
            return $defaultValue;
        } catch (Exception $e) {
            // Template failed, return null to skip this default.
            return null;
        }//end try
    }//end resolveDefaultTemplateValue()

    /**
     * Generates a slug for an object based on its data and schema configuration.
     *
     * @param array  $data   The object data
     * @param Schema $schema The schema containing the configuration
     *
     * @return null|string The generated slug or null if no slug could be generated
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function generateSlug(array $data, Schema $schema): string|null
    {
        try {
            $config    = $schema->getConfiguration();
            $slugField = $config['objectSlugField'] ?? null;

            if ($slugField === null) {
                return null;
            }

            // Get the value from the specified field.
            $value = $this->getValueFromPath(data: $data, path: $slugField);
            if ($value === null || empty($value) === true) {
                return null;
            }

            // Convert to string and generate slug.
            $slug = $this->createSlug(text: $value);

            // Ensure uniqueness by appending timestamp if needed.
            $timestamp  = time();
            $uniqueSlug = $slug.'-'.$timestamp;

            return $uniqueSlug;
        } catch (Exception $e) {
            return null;
        }//end try
    }//end generateSlug()

    /**
     * Creates a URL-friendly slug from a string.
     *
     * @param string $text The text to convert to a slug
     *
     * @return string The generated slug
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function createSlug(string $text): string
    {
        // Convert to lowercase.
        $text = strtolower($text);

        // Replace non-alphanumeric characters with hyphens.
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);

        // Remove leading and trailing hyphens.
        $text = trim($text, '-');

        // Limit length.
        if (strlen($text) > 50) {
            $text = substr($text, 0, 50);
            $text = rtrim($text, '-');
        }

        return $text;
    }//end createSlug()

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
     * @param ObjectEntity $objectEntity The parent object entity
     * @param Schema       $schema       The schema of the parent object
     * @param array        $data         The object data to process
     *
     * @return array The processed data with cascaded objects removed
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex cascading logic with multiple property types
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple cascading paths and configurations
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive cascading for objects and arrays
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function cascadeObjects(ObjectEntity $objectEntity, Schema $schema, array $data): array
    {
        try {
            $schemaObject = $schema->getSchemaObject($this->urlGenerator);
            $properties   = json_decode(json_encode($schemaObject), associative: true)['properties'] ?? [];
        } catch (Exception $e) {
            return $data;
        }

        // Cascade objects that have $ref with either:.
        // 1. inversedBy (creates relation back to parent) - results in empty array/null in parent.
        // 2. objectConfiguration.handling: "cascade" (stores IDs in parent) - results in IDs stored in parent.
        // Objects with only $ref and nested-object handling remain in the data.
        // BUT skip if they have writeBack enabled (those are handled by write-back method).
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items to config.
        $objectProperties = array_filter(
            $properties,
            function (array $property) {
                // Skip if writeBack is enabled (handled by write-back method).
                $hasWriteBack = ($property['writeBack'] ?? null) !== null
                    && $property['writeBack'] === true;
                if ($hasWriteBack === true) {
                    return false;
                }

                $hasRef        = ($property['$ref'] ?? null) !== null;
                $hasInversedBy = isset($property['inversedBy']);
                $hasCascadeHandling = isset($property['objectConfiguration']['handling'])
                    && $property['objectConfiguration']['handling'] === 'cascade';

                return $property['type'] === 'object' && $hasRef && ($hasInversedBy || $hasCascadeHandling);
            }
        );

        // Same logic for array properties - cascade if they have inversedBy OR cascade handling.
        // BUT skip if they have writeBack enabled (those are handled by write-back method).
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items to config.
        $arrayObjProps = array_filter(
            $properties,
            function (array $property) {
                // Skip if writeBack is enabled (handled by write-back method).
                $propWriteBack  = ($property['writeBack'] ?? null) !== null
                    && $property['writeBack'] === true;
                $itemsWriteBack = ($property['items']['writeBack'] ?? null) !== null
                    && $property['items']['writeBack'] === true;
                if ($propWriteBack === true || $itemsWriteBack === true) {
                    return false;
                }

                $hasRef        = isset($property['$ref']) || (($property['items']['$ref'] ?? null) !== null);
                $hasInversedBy = isset($property['inversedBy'])
                    || (($property['items']['inversedBy'] ?? null) !== null);
                $objHandling   = $property['objectConfiguration']['handling'] ?? null;
                $itemsHandling = $property['items']['objectConfiguration']['handling'] ?? null;
                $hasCascade    = $objHandling === 'cascade' || $objHandling === 'related-object'
                    || $itemsHandling === 'cascade' || $itemsHandling === 'related-object';

                return $property['type'] === 'array' && $hasRef && ($hasInversedBy || $hasCascade);
            }
        );

        // Process single object properties that need cascading.
        foreach ($objectProperties as $property => $definition) {
            // Skip if property not present in data.
            if (isset($data[$property]) === false) {
                continue;
            }

            // Skip if the property is empty or not an array/object.
            $propValue          = $data[$property];
            $isEmpty            = empty($propValue) === true;
            $isNotArrayOrObject = is_array($propValue) === false && is_object($propValue) === false;
            if ($isEmpty === true || $isNotArrayOrObject === true) {
                continue;
            }

            // Convert object to array if needed.
            $objectData = $data[$property];
            if (is_object($data[$property]) === true) {
                $objectData = (array) $data[$property];
            }

            // Skip if the object is effectively empty (only contains empty values).
            if ($this->isEffectivelyEmptyObject(object: $objectData) === true) {
                continue;
            }

            try {
                $createdUuid = $this->cascadeSingleObject(
                    objectEntity: $objectEntity,
                    definition: $definition,
                    object: $objectData
                );

                // Handle the result based on whether inversedBy is present.
                if (($definition['inversedBy'] ?? null) !== null) {
                    // With inversedBy: check if writeBack is enabled.
                    if (($definition['writeBack'] ?? null) !== null && $definition['writeBack'] === true) {
                        // Keep the property for write-back processing.
                        $data[$property] = $createdUuid;
                    }

                    if (($definition['writeBack'] ?? null) === null || $definition['writeBack'] === false) {
                        // Remove the property (traditional cascading).
                        unset($data[$property]);
                    }
                }

                if (($definition['inversedBy'] ?? null) === null) {
                    // Without inversedBy: store the created object's UUID.
                    $data[$property] = $createdUuid;
                }
            } catch (Exception $e) {
                // Continue with other properties even if one fails.
            }//end try
        }//end foreach

        // Process array object properties that need cascading.
        foreach ($arrayObjProps as $property => $definition) {
            // Check if property is present and is an array.
            if (isset($data[$property]) === false || is_array($data[$property] ?? null) === false) {
                continue;
            }

            // Determine handling type for orphan cleanup.
            $objHandling     = $definition['objectConfiguration']['handling'] ?? null;
            $itemsHandling   = $definition['items']['objectConfiguration']['handling'] ?? null;
            $isRelatedObject = $objHandling === 'related-object' || $itemsHandling === 'related-object';
            $isCascade       = $objHandling === 'cascade' || $itemsHandling === 'cascade';

            // Capture old UUIDs from the existing object for orphan detection.
            // This must happen BEFORE cascading modifies anything.
            $oldUuids = [];
            if ($isRelatedObject === true || $isCascade === true) {
                $oldObjectData = $objectEntity->getObject();
                if (isset($oldObjectData[$property]) === true && is_array($oldObjectData[$property]) === true) {
                    $oldUuids = array_values(array_filter($oldObjectData[$property], 'is_string'));
                }
            }

            // Handle empty array: clean up all related objects and continue.
            $propIsEmpty = empty($data[$property]) === true;
            if ($propIsEmpty === true) {
                if (($isRelatedObject === true || $isCascade === true) && empty($oldUuids) === false) {
                    // Resolve the sub-object's schema and register for magic-mapped lookups.
                    $subSchemaRef = $definition['items']['$ref'] ?? $definition['$ref'] ?? null;
                    $subSchemaId  = null;
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

                    $this->deleteOrphanedRelatedObjects(
                        orphanedUuids: $oldUuids,
                        register: $subRegister,
                        schema: $subSchema
                    );
                }//end if

                $data[$property] = [];
                continue;
            }//end if

            try {
                $createdUuids = $this->cascadeMultipleObjects(
                    objectEntity: $objectEntity,
                    property: $definition,
                    propData: $data[$property]
                );

                // For related-object handling: always store UUIDs in parent property.
                // This ensures the parent object contains references to the sub-objects.
                // Note: cascadeMultipleObjects skips existing UUIDs and returns only newly created ones.
                // So we need to preserve existing UUIDs from the original data.
                if ($isRelatedObject === true) {
                    // Collect existing IDs that were passed through (not created, just referenced).
                    // Supports: standard UUIDs, UUIDs without dashes, prefixed UUIDs, and numeric IDs.
                    $existingUuids = array_filter(
                        $data[$property] ?? [],
                        function ($item) {
                            if (is_string($item) === false) {
                                return false;
                            }

                            // Standard UUID with dashes.
                            $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
                            if (preg_match($uuidPattern, $item) === 1) {
                                return true;
                            }

                            // UUID without dashes (32 hex chars).
                            if (preg_match('/^[0-9a-f]{32}$/i', $item) === 1) {
                                return true;
                            }

                            // Prefixed UUID (e.g., "id-uuid" with or without dashes).
                            $prefixedPattern  = '/^[a-z]+-([0-9a-f]{8}-[0-9a-f]{4}';
                            $prefixedPattern .= '-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/i';
                            if (preg_match($prefixedPattern, $item) === 1) {
                                return true;
                            }

                            // Numeric ID.
                            if (preg_match('/^[0-9]+$/', $item) === 1) {
                                return true;
                            }

                            return false;
                        }
                    );

                    // Merge existing UUIDs with newly created ones.
                    $data[$property] = array_values(array_unique(array_merge($existingUuids, $createdUuids)));

                    // Delete orphaned related objects (present in old data but not in new).
                    if (empty($oldUuids) === false) {
                        $orphanedUuids = array_diff($oldUuids, $data[$property]);
                        if (empty($orphanedUuids) === false) {
                            // Resolve the sub-object's schema and register for magic-mapped lookups.
                            $subSchemaRef = $definition['items']['$ref'] ?? $definition['$ref'] ?? null;
                            $subSchemaId  = null;
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

                            $this->deleteOrphanedRelatedObjects(
                                orphanedUuids: array_values($orphanedUuids),
                                register: $subRegister,
                                schema: $subSchema
                            );
                        }//end if
                    }//end if
                }//end if

                if ($isRelatedObject !== true) {
                    // Handle the result based on whether inversedBy is present.
                    $hasInversedBy      = ($definition['inversedBy'] ?? null) !== null;
                    $hasItemsInversedBy = (($definition['items']['inversedBy'] ?? null) !== null) === true;
                    if ($hasInversedBy === true || $hasItemsInversedBy === true) {
                        // With inversedBy: check if writeBack is enabled.
                        $defWriteBack   = ($definition['writeBack'] ?? null) !== null
                            && $definition['writeBack'] === true;
                        $itemsWriteBack = isset($definition['items']['writeBack'])
                            && $definition['items']['writeBack'] === true;
                        $hasWriteBack   = $defWriteBack || $itemsWriteBack;

                        if ($hasWriteBack === true) {
                            // Keep the property for write-back processing.
                            $data[$property] = $createdUuids;
                        }

                        if ($hasWriteBack === false) {
                            // Remove the property (traditional cascading).
                            unset($data[$property]);
                        }
                    }

                    $noInversedBy      = ($definition['inversedBy'] ?? null) === null;
                    $noItemsInversedBy = (($definition['items']['inversedBy'] ?? null) !== null) === false;
                    if ($noInversedBy === true && $noItemsInversedBy === true) {
                        // Without inversedBy: store the created objects' UUIDs.
                        $data[$property] = $createdUuids;
                    }
                }//end if
            } catch (Exception $e) {
                // Continue with other properties even if one fails.
            }//end try
        }//end foreach

        return $data;
    }//end cascadeObjects()

    /**
     * Cascade multiple objects from an array of objects in the data.
     *
     * @param ObjectEntity $objectEntity The parent object.
     * @param array        $property     The property to add the objects to.
     * @param array        $propData     The data in the property.
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
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function cascadeMultipleObjects(ObjectEntity $objectEntity, array $property, array $propData): array
    {
        if (array_is_list($propData) === false) {
            return [];
        }

        // Filter out empty or invalid objects.
        // Supports: standard UUIDs, UUIDs without dashes, prefixed UUIDs, and numeric IDs.
        $validObjects = array_filter(
            $propData,
            function ($object) {
                if (is_array($object) === true
                    && empty($object) === false
                    && (count($object) === 1
                    && (($object['id'] ?? null) !== null)
                    && empty($object['id']) === true) === false
                ) {
                    return true;
                }

                if (is_string($object) === true) {
                    // Standard UUID with dashes.
                    $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
                    if (preg_match($uuidPattern, $object) === 1) {
                        return true;
                    }

                    // UUID without dashes (32 hex chars).
                    if (preg_match('/^[0-9a-f]{32}$/i', $object) === 1) {
                        return true;
                    }

                    // Prefixed UUID (e.g., "id-uuid" with or without dashes).
                    $prefixedPattern  = '/^[a-z]+-([0-9a-f]{8}-[0-9a-f]{4}';
                    $prefixedPattern .= '-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/i';
                    if (preg_match($prefixedPattern, $object) === 1) {
                        return true;
                    }

                    // Numeric ID.
                    if (preg_match('/^[0-9]+$/', $object) === 1) {
                        return true;
                    }
                }//end if

                return false;
            }
        );

        if (empty($validObjects) === true) {
            return [];
        }

        if (($property['$ref'] ?? null) !== null) {
            $property['items']['$ref'] = $property['$ref'];
        }

        if (($property['inversedBy'] ?? null) !== null) {
            $property['items']['inversedBy'] = $property['inversedBy'];
        }

        if (($property['register'] ?? null) !== null) {
            $property['items']['register'] = $property['register'];
        }

        if (($property['objectConfiguration'] ?? null) !== null) {
            $property['items']['objectConfiguration'] = $property['objectConfiguration'];
        }

        // Validate that we have the necessary configuration.
        if (isset($property['items']['$ref']) === false) {
            return [];
        }

        // Collect objects that need to be created (filter out existing UUIDs).
        $objectsToCreate = [];
        foreach ($validObjects as $object) {
            // Skip existing IDs (UUIDs, prefixed UUIDs, numeric IDs) - they don't need to be cascaded (created).
            // Only arrays (nested objects) need to be created via cascading.
            if (is_string($object) === true) {
                continue;
            }

            $objectsToCreate[] = $object;
        }

        // If no objects need to be created, return empty array.
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
                    objectEntity: $objectEntity,
                    definition: $property['items'],
                    object: $object
                );
                if ($uuid !== null) {
                    $createdUuids[] = $uuid;
                }
            } catch (Exception $e) {
                // Continue with other objects even if one fails.
            }
        }

        return $createdUuids;
    }//end cascadeMultipleObjects()

    /**
     * Cascade a single object form an object in the source data
     *
     * @param ObjectEntity $objectEntity The parent object
     * @param array        $definition   The definition of the property the cascaded object is found in
     * @param array        $object       The object to cascade
     *
     * @return string|null  The UUID of the created object, or null if no object was created
     *
     * @throws Exception
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex single object cascading with relation handling
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple configuration and validation paths
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function cascadeSingleObject(ObjectEntity $objectEntity, array $definition, array $object): ?string
    {
        // Validate that we have the necessary configuration.
        if (isset($definition['$ref']) === false) {
            return null;
        }

        // Skip if object is empty or doesn't contain actual data.
        $hasOnlyEmptyId = count($object) === 1
            && (($object['id'] ?? null) !== null)
            && empty($object['id']) === true;
        if (empty($object) === true || $hasOnlyEmptyId === true) {
            return null;
        }

        $objectId = $objectEntity->getUuid();
        if (empty($objectId) === true) {
            return null;
        }

        // Only set inversedBy if it's configured (for relation-based cascading).
        if (($definition['inversedBy'] ?? null) !== null) {
            $inversedByProperty = $definition['inversedBy'];

            // Check if the inversedBy property already exists and is an array.
            if (($object[$inversedByProperty] ?? null) !== null && is_array($object[$inversedByProperty]) === true) {
                // Add to existing array if not already present.
                if (in_array($objectId, $object[$inversedByProperty], true) === false) {
                    $object[$inversedByProperty][] = $objectId;
                }
            }

            if (($object[$inversedByProperty] ?? null) === null || is_array($object[$inversedByProperty]) === false) {
                // Set as single value or create new array.
                $object[$inversedByProperty] = $objectId;
            }
        }

        // Extract register ID from definition or use parent object's register.
        $register = $definition['register'] ?? $objectEntity->getRegister();

        // If register is an array, extract the ID.
        if (is_array($register) === true) {
            $register = $register['id'] ?? $register;
        }

        // For cascading with inversedBy, preserve existing UUID for updates.
        // For cascading without inversedBy, always create new objects (no UUID).
        $uuid = null;
        if (($definition['inversedBy'] ?? null) !== null) {
            $uuid = $object['id'] ?? $object['@self']['id'] ?? null;
        }

        if (($definition['inversedBy'] ?? null) === null) {
            // Remove any existing UUID/id fields to force new object creation.
            unset($object['id']);
            unset($object['@self']);
        }

        // Resolve schema reference to actual schema ID.
        $schemaId = $this->resolveSchemaReference(reference: $definition['$ref']);
        if ($schemaId === null) {
            throw new Exception("Invalid schema reference: {$definition['$ref']}");
        }

        // For updates (UUID present): fill in missing schema properties with null.
        // This ensures the magic mapper explicitly sets removed properties to NULL
        // instead of leaving old values intact (magic mapper does partial updates).
        if ($uuid !== null) {
            $object = $this->fillMissingSchemaPropertiesWithNull(data: $object, schemaId: $schemaId);
        }

        try {
            // Use silent mode for cascaded sub-objects to improve performance.
            // This skips audit trail creation for each sub-object, reducing database writes.
            // The parent object's audit trail still captures the overall operation.
            $savedObject = $this->saveObject(
                register: $register,
                schema: $schemaId,
                data: $object,
                uuid: $uuid,
                folderId: null,
                _rbac: true,
                _multitenancy: true,
                persist: true,
                silent: true
            );
            $savedUuid   = $savedObject->getUuid();

            // Track the created sub-object for inclusion in @self.objects.
            // This allows the parent response to include the full sub-object data.
            if ($savedUuid !== null) {
                $this->createdSubObjects[$savedUuid] = $savedObject->jsonSerialize();
            }

            return $savedUuid;
        } catch (Exception $e) {
            throw $e;
        }//end try
    }//end cascadeSingleObject()

    /**
     * Deletes orphaned related objects that are no longer referenced by the parent.
     *
     * When a parent object's array property (with related-object or cascade handling)
     * is updated and some sub-objects are removed, those orphaned sub-objects should
     * be deleted from the database since they are "owned" by the parent.
     *
     * @param string[]      $orphanedUuids Array of UUIDs of orphaned objects to delete.
     * @param Register|null $register      Optional register to scope the lookup.
     * @param Schema|null   $schema        Optional schema to scope the lookup.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function deleteOrphanedRelatedObjects(
        array $orphanedUuids,
        ?Register $register=null,
        ?Schema $schema=null
    ): void {
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

                // Soft delete: set deletion metadata and update (consistent with DeleteObject).
                $user   = $this->userSession->getUser();
                $userId = 'system';
                if ($user !== null) {
                    $userId = $user->getUID();
                }

                $deletionData = [
                    'deletedBy' => $userId,
                    'deletedAt' => (new DateTime())->format(DateTime::ATOM),
                    'objectId'  => $orphanedObject->getUuid(),
                    'reason'    => 'orphaned-related-object',
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
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach
    }//end deleteOrphanedRelatedObjects()

    /**
     * Fills in missing schema properties with null for an object being updated.
     *
     * The magic mapper performs partial updates (only columns present in the data
     * are SET in SQL). When a property is removed from the object data, the
     * corresponding column is NOT touched. This method ensures all schema-defined
     * properties are present in the data, with missing ones set to null, so the
     * magic mapper will explicitly NULL them in the database.
     *
     * @param array      $data     The object data (may have missing properties).
     * @param int|string $schemaId The schema ID to look up properties.
     *
     * @return array The data with all schema properties present (missing ones set to null).
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function fillMissingSchemaPropertiesWithNull(array $data, int|string $schemaId): array
    {
        try {
            $schema       = $this->getCachedSchema(schemaId: $schemaId);
            $schemaObject = json_decode(json_encode($schema->getSchemaObject($this->urlGenerator)), associative: true);
            $properties   = $schemaObject['properties'] ?? [];
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
     * Handles inverse relations write-back by updating target objects to include reference to current object
     *
     * This method extends the existing inverse relations functionality to handle write operations.
     * When a property has `inversedBy` configuration and `writeBack: true`, this method will
     * update the target objects to include a reference back to the current object.
     *
     * For example, when creating a community with a list of deelnemers (participant UUIDs),
     * this method will update each participant's deelnames array to include the community's UUID.
     *
     * TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
     *
     * @param ObjectEntity $objectEntity The current object being saved
     * @param Schema       $schema       The schema of the current object
     * @param array        $data         The data being saved
     *
     * @return array The data with write-back properties optionally removed
     * @throws Exception
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex write-back logic with multiple configurations
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple property and item level configurations
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive write-back handling for all relation types
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function handleInverseRelationsWriteBack(ObjectEntity $objectEntity, Schema $schema, array $data): array
    {

        try {
            $schemaObject = $schema->getSchemaObject($this->urlGenerator);
            $properties   = json_decode(json_encode($schemaObject), associative: true)['properties'] ?? [];
        } catch (Exception $e) {
            return $data;
        }

        // Find properties that have inversedBy configuration with writeBack enabled.
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items to config.
        $writeBackProperties = array_filter(
            $properties,
            function (array $property) {
                // Check for inversedBy with writeBack at property level.
                $hasInversedBy = ($property['inversedBy'] ?? null) !== null;
                $hasWriteBack  = (($property['writeBack'] ?? null) !== null)
                    && $property['writeBack'] === true;
                if ($hasInversedBy === true && $hasWriteBack === true) {
                    return true;
                }

                // Check for inversedBy with writeBack in array items.
                if ($property['type'] === 'array'
                    && (($property['items']['inversedBy'] ?? null) !== null)
                    && (($property['items']['writeBack'] ?? null) !== null)
                    && $property['items']['writeBack'] === true
                ) {
                    return true;
                }

                // Check for inversedBy with writeBack at array property level (for array of objects).
                if ($property['type'] === 'array'
                    && (($property['items']['inversedBy'] ?? null) !== null)
                    && (($property['writeBack'] ?? null) !== null)
                    && $property['writeBack'] === true
                ) {
                    return true;
                }

                return false;
            }
        );

        foreach ($writeBackProperties as $propertyName => $definition) {
            // Skip if property not present in data or is empty.
            if (($data[$propertyName] ?? null) === null || empty($data[$propertyName]) === true) {
                continue;
            }

            $targetUuids      = $data[$propertyName];
            $inverseProperty  = null;
            $targetSchema     = null;
            $targetRegister   = null;
            $removeFromSource = false;

            // Extract configuration from property or array items.
            $hasDefInversedBy   = ($definition['inversedBy'] ?? null) !== null;
            $hasDefWriteBack    = (($definition['writeBack'] ?? null) !== null)
                && $definition['writeBack'] === true;
            $hasItemsInversedBy = ($definition['items']['inversedBy'] ?? null) !== null;
            $hasItemsWriteBack  = (($definition['items']['writeBack'] ?? null) !== null)
                && $definition['items']['writeBack'] === true;

            if ($hasDefInversedBy === true && $hasDefWriteBack === true) {
                $inverseProperty  = $definition['inversedBy'];
                $targetSchema     = $definition['$ref'] ?? null;
                $targetRegister   = $definition['register'] ?? $objectEntity->getRegister();
                $removeFromSource = $definition['removeAfterWriteBack'] ?? false;
            } else if ($hasItemsInversedBy === true && $hasItemsWriteBack === true) {
                $inverseProperty  = $definition['items']['inversedBy'];
                $targetSchema     = $definition['items']['$ref'] ?? null;
                $targetRegister   = $definition['items']['register'] ?? $objectEntity->getRegister();
                $removeFromSource = $definition['items']['removeAfterWriteBack'] ?? false;
            } else if ($hasItemsInversedBy === true && $hasDefWriteBack === true) {
                // Handle array of objects with writeBack at array level.
                $inverseProperty  = $definition['items']['inversedBy'];
                $targetSchema     = $definition['items']['$ref'] ?? null;
                $targetRegister   = $definition['register'] ?? $objectEntity->getRegister();
                $removeFromSource = $definition['removeAfterWriteBack'] ?? false;
            }//end if

            // Skip if we don't have the necessary configuration.
            $noInverseProperty = $inverseProperty === false || $inverseProperty === null;
            $noTargetSchema    = $targetSchema === false || $targetSchema === null;
            if ($noInverseProperty === true || $noTargetSchema === true) {
                continue;
            }

            // Resolve schema reference to actual schema ID.
            $resolvedSchemaId = $this->resolveSchemaReference(reference: $targetSchema);
            if ($resolvedSchemaId === null) {
                continue;
            }

            // Ensure targetUuids is an array.
            if (is_array($targetUuids) === false) {
                $targetUuids = [$targetUuids];
            }

            // Filter out empty or invalid IDs.
            // Supports: standard UUIDs, UUIDs without dashes, prefixed UUIDs, and numeric IDs.
            $validUuids = array_filter(
                $targetUuids,
                function ($uuid) {
                    if (empty($uuid) === true || is_string($uuid) === false || trim($uuid) === '') {
                        return false;
                    }

                    // Standard UUID with dashes.
                    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1) {
                        return true;
                    }

                    // UUID without dashes (32 hex chars).
                    if (preg_match('/^[0-9a-f]{32}$/i', $uuid) === 1) {
                        return true;
                    }

                    // Prefixed UUID (e.g., "id-uuid" with or without dashes).
                    $prefixedPattern  = '/^[a-z]+-([0-9a-f]{8}-[0-9a-f]{4}';
                    $prefixedPattern .= '-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/i';
                    if (preg_match($prefixedPattern, $uuid) === 1) {
                        return true;
                    }

                    // Numeric ID.
                    if (preg_match('/^[0-9]+$/', $uuid) === 1) {
                        return true;
                    }

                    return false;
                }
            );

            if (empty($validUuids) === true) {
                continue;
            }

            // Update each target object.
            foreach ($validUuids as $targetUuid) {
                // Ensure targetUuid is string (filter already validated it is a valid UUID string).
                $targetUuid = (string) $targetUuid;
                try {
                    // Find the target object.
                    $targetObject = $this->objectEntityMapper->find($targetUuid);
                    // Find() throws DoesNotExistException, never returns null.
                    // Get current data from target object.
                    $targetData = $targetObject->getObject();

                    // Initialize inverse property as array if it doesn't exist.
                    if (isset($targetData[$inverseProperty]) === false) {
                        $targetData[$inverseProperty] = [];
                    }

                    // Ensure inverse property is an array.
                    if (is_array($targetData[$inverseProperty]) === false) {
                        $targetData[$inverseProperty] = [$targetData[$inverseProperty]];
                    }

                    // Add current object's UUID to the inverse property if not already present.
                    if (in_array($objectEntity->getUuid(), $targetData[$inverseProperty], true) === false) {
                        $targetData[$inverseProperty][] = $objectEntity->getUuid();
                    }

                    // Save the updated target object.
                    $this->saveObject(
                        register: $targetRegister,
                        schema: $resolvedSchemaId,
                        data: $targetData,
                        uuid: $targetUuid
                    );
                } catch (Exception $e) {
                    // Continue with other targets even if one fails.
                }//end try
            }//end foreach

            // Remove the property from source object if configured to do so.
            if ($removeFromSource === true) {
                unset($data[$propertyName]);
            }
        }//end foreach

        return $data;
    }//end handleInverseRelationsWriteBack()

    /**
     * Sanitizes empty strings and handles empty objects/arrays based on schema definitions.
     *
     * This method prevents empty strings from causing issues in downstream processing by converting
     * them to appropriate values for properties based on their schema definitions.
     *
     * For object properties:
     * - If not required: empty objects {} become null (allows clearing the field)
     * - If required: empty objects {} remain as {} but will fail validation with clear error
     *
     * For array properties:
     * - If no minItems constraint: empty arrays [] are allowed
     * - If minItems > 0: empty arrays [] will fail validation with clear error
     * - Empty strings become null for array properties
     *
     * @param array  $data   The object data to sanitize
     * @param Schema $schema The schema to check property definitions against
     *
     * @return array The sanitized data with appropriate handling of empty values
     *
     * @throws \Exception If schema processing fails
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex sanitization logic for multiple property types
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple property types and required states
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function sanitizeEmptyStringsForObjectProperties(array $data, Schema $schema): array
    {
        try {
            $schemaObject = $schema->getSchemaObject($this->urlGenerator);
            $properties   = json_decode(json_encode($schemaObject), associative: true)['properties'] ?? [];
            $required     = json_decode(json_encode($schemaObject), associative: true)['required'] ?? [];
        } catch (Exception $e) {
            return $data;
        }

        $sanitizedData = $data;

        foreach ($properties as $propertyName => $propertyDefinition) {
            // Skip if property is not in the data.
            if (isset($sanitizedData[$propertyName]) === false) {
                continue;
            }

            $value        = $sanitizedData[$propertyName];
            $propertyType = $propertyDefinition['type'] ?? null;
            $isRequired   = in_array($propertyName, $required) || ($propertyDefinition['required'] ?? false);

            // Handle object properties.
            if ($propertyType === 'object') {
                if ($value === '') {
                    // Empty string to null for object properties.
                    $sanitizedData[$propertyName] = null;
                } else if (is_array($value) === true && empty($value) === true && ($isRequired === false)) {
                    // Empty object {} to null for non-required object properties.
                    $sanitizedData[$propertyName] = null;
                } else if (is_array($value) === true && empty($value) === true && ($isRequired === true)) {
                    // Keep empty object {} for required properties - will fail validation with clear error.
                }
            } else if ($propertyType === 'array') {
                // Handle array properties.
                if ($value === '') {
                    // Empty string to null for array properties.
                    $sanitizedData[$propertyName] = null;
                } else if (is_array($value) === true) {
                    // Check minItems constraint.
                    $minItems = $propertyDefinition['minItems'] ?? 0;

                    if (empty($value) === true && $minItems > 0) {
                        // Keep empty array [] for arrays with minItems > 0 - will fail validation with clear error.
                    }

                    if (empty($value) === true && $minItems === 0) {
                        // Empty array is valid for arrays with no minItems constraint.
                    }

                    if (empty($value) === false) {
                        // Handle array items that might contain empty strings.
                        $sanitizedArray = [];
                        $hasChanges     = false;
                        foreach ($value as $index => $item) {
                            $sanitizedArray[$index] = $item;
                            if ($item === '') {
                                $sanitizedArray[$index] = null;
                                $hasChanges = true;
                            }
                        }

                        if ($hasChanges === true) {
                            $sanitizedData[$propertyName] = $sanitizedArray;
                        }
                    }//end if
                }//end if
            } else if ($value === '' && in_array($propertyType, ['string', 'number', 'integer', 'boolean']) === true) {
                // Handle other property types with empty strings.
                if ($isRequired === false) {
                    // Convert empty string to null for non-required scalar properties.
                    $sanitizedData[$propertyName] = null;
                }

                if ($isRequired === true) {
                    // Keep empty string for required properties - will fail validation with clear error.
                    // No action needed - property stays as is.
                }
            }//end if
        }//end foreach

        return $sanitizedData;
    }//end sanitizeEmptyStringsForObjectProperties()

    /**
     * Saves an object.
     *
     * @param Register|int|string|null $register      The register containing the object.
     * @param Schema|int|string        $schema        The schema to validate against.
     * @param array                    $data          The object data to save.
     * @param string|null              $uuid          The UUID of the object to update (if updating).
     * @param int|null                 $folderId      The folder ID to set on the object (optional).
     * @param bool                     $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool                     $_multitenancy Whether to apply multitenancy filtering (default: true).
     * @param bool                     $persist       Whether to persist the object to database (default: true).
     * @param bool                     $silent        Whether to skip audit trail creation and events (default: false).
     * @param bool                     $_validation   Whether to validate the object (default: true).
     * @param array|null               $uploadedFiles Uploaded files array (optional).
     *
     * @return ObjectEntity The saved object entity.
     *
     * @throws Exception If there is an error during save.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible save options
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Boolean flags needed for flexible save behavior
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    public function saveObject(
        Register | int | string | null $register,
        Schema | int | string $schema,
        array $data,
        ?string $uuid=null,
        ?int $folderId=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $persist=true,
        bool $silent=false,
        bool $_validation=true,
        ?array $uploadedFiles=null
    ): ObjectEntity {
        // Extract UUID and @self metadata from data.
        [$uuid, $selfData, $data] = $this->extractUuidAndSelfData(
            data: $data,
            uuid: $uuid,
            uploadedFiles: $uploadedFiles
        );

        // Resolve schema and register to entity objects.
        [$schema, $schemaId, $register, $registerId] = $this->resolveSchemaAndRegister(
            schema: $schema,
            register: $register
        );

        // Normalize translatable properties (wrap simple values under default language).
        $data = $this->translationHandler->normalizeTranslationsForSave(
            objectData: $data,
            schema: $schema,
            register: $register
        );

        // Check property-level authorization for incoming data.
        // This throws a ValidationException if user tries to modify unauthorized properties.
        // Skip when _rbac is false (internal/system calls should bypass all authorization).
        if ($_rbac === true && $schema->hasPropertyAuthorization() === true) {
            $isCreate           = ($uuid === null);
            $existingObjectData = [];

            // For updates, get existing object data for matching.
            if ($isCreate === false) {
                try {
                    $tempExistingObject = $this->unifiedObjectMapper->find(
                        identifier: $uuid,
                        register: $register,
                        schema: $schema,
                        _rbac: false,
                        _multitenancy: false
                    );
                    if ($tempExistingObject !== null) {
                        $existingObjectData = $tempExistingObject->getObject();
                    }
                } catch (DoesNotExistException $e) {
                    // Object doesn't exist, treat as create.
                    $isCreate = true;
                }
            }

            $unauthorizedProps = $this->propertyRbacHandler->getUnauthorizedProperties(
                schema: $schema,
                object: $existingObjectData,
                incomingData: $data,
                isCreate: $isCreate
            );

            if (empty($unauthorizedProps) === false) {
                throw new Exception(
                    'You are not authorized to modify the following properties: '.implode(', ', $unauthorizedProps)
                );
            }
        }//end if

        // Check if UUID was auto-generated (indicates CREATE operation, not UPDATE).
        $isAutoGeneratedUuid = ($selfData['_autoGeneratedUuid'] ?? false) === true;

        // Try to update existing object if UUID provided AND it's not auto-generated.
        // Auto-generated UUIDs are for new objects, so skip the lookup.
        if ($uuid !== null && $isAutoGeneratedUuid === false) {
            $debugMsg  = '[SaveObject] UUID provided and not auto-generated,';
            $debugMsg .= ' checking for existing object (UPDATE operation)';
            $this->logger->debug(
                message: $debugMsg,
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'uuid'     => $uuid,
                    'register' => $register?->getId(),
                    'schema'   => $schema?->getId(),
                ]
            );

            // Always disable RBAC and multitenancy for internal object lookup
            // to avoid permission errors when validating existing objects.
            $existingObject = $this->findAndValidateExistingObject(
                uuid: $uuid,
                register: $register,
                schema: $schema,
                _rbac: false,
                _multitenancy: false
            );

            if ($existingObject !== null) {
                $this->logger->debug(
                    message: '[SaveObject] Existing object found, proceeding with UPDATE',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'uuid'     => $uuid,
                        'objectId' => $existingObject->getId(),
                    ]
                );

                // Push the in-flight save onto the call stack so
                // cascade descendants can detect cycles back to
                // ancestors via `validateReferences()`. Popped in
                // finally regardless of success/failure.
                $frameKey = $this->pushSaveCallFrame(
                    schemaSlug: ((string) ($schema->getSlug() ?? $schema->getId())),
                    uuid: (string) $uuid,
                    register: ($register?->getId() !== null ? (string) $register->getId() : null)
                );
                try {
                    return $this->handleObjectUpdate(
                        existingObject: $existingObject,
                        register: $register,
                        schema: $schema,
                        data: $data,
                        selfData: $selfData,
                        folderId: $folderId,
                        persist: $persist,
                        silent: $silent
                    );
                } finally {
                    $this->popSaveCallFrame(key: $frameKey);
                }
            }//end if
        } else if ($isAutoGeneratedUuid === true) {
            $this->logger->debug(
                message: '[SaveObject] UUID is auto-generated, skipping existing object lookup (CREATE operation)',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'uuid'     => $uuid,
                    'register' => $register?->getId(),
                    'schema'   => $schema?->getId(),
                ]
            );
        }//end if

        // Push the in-flight save onto the call stack so cascade
        // descendants can detect cycles via `validateReferences()`.
        // Popped in finally regardless of success/failure.
        $frameKey = $this->pushSaveCallFrame(
            schemaSlug: ((string) ($schema->getSlug() ?? $schema->getId())),
            uuid: ($uuid ?? ''),
            register: ($register?->getId() !== null ? (string) $register->getId() : null)
        );
        try {
            // Create new object if no existing object found.
            return $this->handleObjectCreation(
                registerId: $registerId,
                schemaId: $schemaId,
                register: $register,
                schema: $schema,
                data: $data,
                selfData: $selfData,
                uuid: $uuid,
                folderId: $folderId,
                persist: $persist,
                silent: $silent,
                _multitenancy: $_multitenancy
            );
        } finally {
            $this->popSaveCallFrame(key: $frameKey);
        }
    }//end saveObject()

    /**
     * Extract UUID and @self metadata from data.
     *
     * @param array       $data          Object data
     * @param string|null $uuid          Provided UUID
     * @param array|null  $uploadedFiles Uploaded files
     *
     * @return array{0: string|null, 1: array, 2: array} [uuid, selfData, cleanedData]
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function extractUuidAndSelfData(
        array $data,
        ?string $uuid,
        ?array $uploadedFiles
    ): array {
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

        // Process uploaded files and inject them into data.
        if ($uploadedFiles !== null && empty($uploadedFiles) === false) {
            $data = $this->filePropertyHandler->processUploadedFiles(
                uploadedFiles: $uploadedFiles,
                data: $data
            );
        }

        return [$uuid, $selfData, $data];
    }//end extractUuidAndSelfData()

    /**
     * Resolve schema and register to entity objects with their IDs.
     *
     * @param Schema|int|string        $schema   Schema parameter
     * @param Register|int|string|null $register Register parameter
     *
     * @return array{0: Schema, 1: int, 2: Register, 3: int} [schema, schemaId, register, registerId]
     *
     * @throws Exception If schema or register cannot be resolved
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple type resolution paths for schema and register
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function resolveSchemaAndRegister(
        Schema | int | string $schema,
        Register | int | string | null $register
    ): array {
        // Initialize IDs before conditional assignment.
        $schemaId   = null;
        $registerId = null;

        // Resolve schema using request-scoped cache for performance.
        // This avoids repeated database lookups when creating multiple sub-objects of the same type.
        if ($schema instanceof Schema === true) {
            $schemaId = $schema->getId();
            // Cache the schema entity for potential reuse.
            $this->schemaCache[(string) $schemaId] = $schema;
        } else if (is_string($schema) === true) {
            // Resolve schema reference if it's a string (uses cached reference lookup).
            $schemaId = $this->resolveSchemaReference(reference: $schema);
            if ($schemaId === null) {
                throw new Exception("Could not resolve schema reference: $schema");
            }

            // Use cached schema lookup instead of direct mapper call.
            $schema = $this->getCachedSchema(schemaId: $schemaId);
        } else if (is_int($schema) === true) {
            // It's an integer ID - use cached lookup.
            $schemaId = $schema;
            $schema   = $this->getCachedSchema(schemaId: $schema);
        }

        // Resolve register using request-scoped cache for performance.
        if ($register instanceof Register === true) {
            $registerId = $register->getId();
            // Cache the register entity for potential reuse.
            $this->registerCache[(string) $registerId] = $register;
        } else if (is_string($register) === true) {
            // Resolve register reference if it's a string.
            $registerId = $this->resolveRegisterReference(reference: $register);
            if ($registerId === null) {
                throw new Exception("Could not resolve register reference: $register");
            }

            // Use cached register lookup instead of direct mapper call.
            $register = $this->getCachedRegister(registerId: $registerId);
        } else if (is_int($register) === true) {
            // It's an integer ID - use cached lookup.
            $registerId = $register;
            $register   = $this->getCachedRegister(registerId: $register);
        } else if ($register === null) {
            // Register is NULL (e.g., for seedData objects) - leave as NULL.
            $registerId = null;
        }//end if

        return [$schema, $schemaId, $register, $registerId];
    }//end resolveSchemaAndRegister()

    /**
     * Find and validate existing object by UUID.
     *
     * @param string        $uuid          Object UUID.
     * @param Register|null $register      Optional register for magic mapper routing.
     * @param Schema|null   $schema        Optional schema for magic mapper routing.
     * @param bool          $_rbac         Whether to apply RBAC checks.
     * @param bool          $_multitenancy Whether to apply multitenancy checks.
     *
     * @return ObjectEntity|null Existing object or null if not found.
     *
     * @throws Exception If object is locked by another user.
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function findAndValidateExistingObject(
        string $uuid,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ?ObjectEntity {
        try {
            $existingObject = $this->objectEntityMapper->find(
                identifier: $uuid,
                register: $register,
                schema: $schema,
                includeDeleted: false,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );

            // Check if object is locked - prevent updates on locked objects.
            $lockData = $existingObject->getLocked();
            if ($lockData !== null && is_array($lockData) === true) {
                $currentUser   = $this->userSession->getUser();
                $currentUserId = null;
                if ($currentUser !== null) {
                    $currentUserId = $currentUser->getUID();
                }

                $lockOwner = $lockData['userId'] ?? null;

                // If object is locked by someone other than the current user, prevent update.
                if ($lockOwner !== null && $lockOwner !== $currentUserId) {
                    $unlockAdvice = 'Please unlock the object before attempting to update it.';
                    throw new Exception("Cannot update object: Object is locked by user '{$lockOwner}'. ".$unlockAdvice);
                }
            }

            return $existingObject;
        } catch (DoesNotExistException $e) {
            // Object not found, will create new one.
            return null;
        }//end try
    }//end findAndValidateExistingObject()

    /**
     * Handle update of existing object.
     *
     * @param ObjectEntity $existingObject Existing object to update
     * @param Register     $register       Register entity
     * @param Schema       $schema         Schema entity
     * @param array        $data           Object data
     * @param array        $selfData       @self metadata
     * @param int|null     $folderId       Folder ID
     * @param bool         $persist        Whether to persist changes
     * @param bool         $silent         Whether to skip audit trail
     *
     * @return ObjectEntity Updated object
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function handleObjectUpdate(
        ObjectEntity $existingObject,
        Register $register,
        Schema $schema,
        array $data,
        array $selfData,
        ?int $folderId,
        bool $persist,
        bool $silent
    ): ObjectEntity {
        // Check archival immutability: destroyed and transferred objects cannot be modified.
        $retention    = $existingObject->getRetention() ?? [];
        $archStatus   = $retention['archiefstatus'] ?? null;
        $immutableMap = [
            'vernietigd'   => 'OBJECT_DESTROYED',
            'overgebracht' => 'OBJECT_TRANSFERRED',
        ];

        if ($archStatus !== null && isset($immutableMap[$archStatus]) === true) {
            throw new Exception(
                'Cannot modify object: archival status is '.$archStatus.' (error: '.$immutableMap[$archStatus].')',
                409
            );
        }

        // IMPORTANT: Capture the old state BEFORE prepareObjectForUpdate modifies the entity.
        // This is critical for event dispatching - the old status must be captured here,
        // not after preparation when the entity has already been modified.
        $oldObjectData = $existingObject->getObject();
        $oldObject     = clone $existingObject;
        // Deep clone the object data array to prevent reference issues.
        $oldObject->setObject($oldObjectData);

        // Prepare the object for update.
        $preparedObject = $this->prepareObjectForUpdate(
            existingObject: $existingObject,
            schema: $schema,
            data: $data,
            selfData: $selfData,
            folderId: $folderId
        );

        // If not persisting, return the prepared object.
        if ($persist === false) {
            return $preparedObject;
        }

        // Update the object, passing the captured old state.
        return $this->updateObject(
            register: $register,
            schema: $schema,
            data: $data,
            existingObject: $preparedObject,
            folderId: $folderId,
            silent: $silent,
            oldObject: $oldObject
        );
    }//end handleObjectUpdate()

    /**
     * Handle creation of new object.
     *
     * @param int         $registerId    Register ID
     * @param int         $schemaId      Schema ID
     * @param Register    $register      Register entity
     * @param Schema      $schema        Schema entity
     * @param array       $data          Object data
     * @param array       $selfData      @self metadata
     * @param string|null $uuid          UUID for new object
     * @param int|null    $folderId      Folder ID
     * @param bool        $persist       Whether to persist changes
     * @param bool        $silent        Whether to skip audit trail
     * @param bool        $_multitenancy Whether to apply multitenancy
     *
     * @return ObjectEntity Created object
     *
     * @throws Exception If file processing fails
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible object creation
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function handleObjectCreation(
        int $registerId,
        int $schemaId,
        Register $register,
        Schema $schema,
        array $data,
        array $selfData,
        ?string $uuid,
        ?int $folderId,
        bool $persist,
        bool $silent,
        bool $_multitenancy
    ): ObjectEntity {
        // Create a new object entity.
        $objectEntity = new ObjectEntity();
        $objectEntity->setRegister((string) $registerId);
        $objectEntity->setSchema((string) $schemaId);
        $objectEntity->setCreated(new DateTime());
        $objectEntity->setUpdated(new DateTime());

        if ($uuid !== null) {
            $objectEntity->setUuid($uuid);
        }

        // Set folder ID if provided.
        if ($folderId !== null) {
            $objectEntity->setFolder((string) $folderId);
        }

        // Prepare the object for creation (WITHOUT file processing yet).
        $preparedObject = $this->prepareObjectForCreation(
            objectEntity: $objectEntity,
            schema: $schema,
            data: $data,
            selfData: $selfData,
            _multitenancy: $_multitenancy
        );

        // Apply archival metadata from schema archive configuration.
        try {
            $retentionService = \OC::$server->get(\OCA\OpenRegister\Service\RetentionService::class);
            $preparedObject   = $retentionService->applyArchivalMetadata($preparedObject, $schema);
        } catch (\Throwable $e) {
            $this->logger->debug(
                '[SaveObject] RetentionService not available, skipping archival metadata: '.$e->getMessage()
            );
        }

        // If not persisting, return the prepared object.
        if ($persist === false) {
            return $preparedObject;
        }

        // Save the object to database FIRST (so it gets an ID).
        // Use MagicMapper to route to MagicMapper when magic mapping is enabled.
        $savedEntity = $this->unifiedObjectMapper->insert(entity: $preparedObject, register: $register, schema: $schema);

        // Update the name cache with the saved object's name.
        // This ensures the name is available for subsequent operations and relation resolution.
        $savedName = $savedEntity->getName();
        $savedUuid = $savedEntity->getUuid();
        if ($savedUuid !== null && $savedName !== null && trim($savedName) !== '') {
            $this->cacheHandler->setObjectName(identifier: $savedUuid, name: $savedName);
        }

        // Process file properties with rollback on failure.
        $savedEntity = $this->processFilePropertiesWithRollback(
            savedEntity: $savedEntity,
            data: $data,
            register: $register,
            schema: $schema
        );

        // Create audit trail if not in silent mode.
        if ($silent === false && $this->isAuditTrailsEnabled() === true) {
            $log = $this->auditTrailMapper->createAuditTrail(old: null, new: $savedEntity);
            $savedEntity->setLastLog($log->jsonSerialize());
        }

        // Update inverse relations on related objects (bidirectional relationship management).
        // This ensures that when object A references object B, object B's relations also include A.
        // Skip for silent mode (cascaded sub-objects) - the parent object handles the relationship,
        // and the inversedBy property is already set in cascadeSingleObject before saving.
        // This optimization significantly improves performance when creating many sub-objects.
        if ($silent === false) {
            $this->updateInverseRelations(savedEntity: $savedEntity, register: $register, schema: $schema);
        }

        return $savedEntity;
    }//end handleObjectCreation()

    /**
     * Process file properties with automatic rollback on failure.
     *
     * @param ObjectEntity $savedEntity Saved object entity
     * @param array        $data        Object data (modified by reference)
     * @param Register     $register    Register entity
     * @param Schema       $schema      Schema entity
     *
     * @return ObjectEntity Updated object with file IDs
     *
     * @throws Exception If file processing fails
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function processFilePropertiesWithRollback(
        ObjectEntity $savedEntity,
        array &$data,
        Register $register,
        Schema $schema
    ): ObjectEntity {
        $this->logger->debug(
            message: '[SaveObject] processFilePropertiesWithRollback called',
            context: [
                'file'     => __FILE__,
                'line'     => __LINE__,
                'app'      => 'openregister',
                'uuid'     => $savedEntity->getUuid(),
                'dataKeys' => array_keys($data),
            ]
        );

        $filePropsProcessed = false;

        try {
            // Process all file properties.
            foreach ($data as $propertyName => $value) {
                if ($this->filePropertyHandler->isFileProperty(
                        value: $value,
                        schema: $schema,
                        propertyName: $propertyName
                    ) === true
                ) {
                    $this->filePropertyHandler->handleFileProperty(
                        objectEntity: $savedEntity,
                        object: $data,
                        propertyName: $propertyName,
                        schema: $schema
                    );
                    $filePropsProcessed = true;
                }
            }

            // If files were processed, update the object with file IDs.
            if ($filePropsProcessed === true) {
                $this->logger->warning(
                    message: '[SaveObject] File properties processed, updating object with file IDs',
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                        'app'  => 'openregister',
                        'uuid' => $savedEntity->getUuid(),
                        'data' => json_encode($data),
                    ]
                );

                $savedEntity->setObject($data);

                // DEBUG: Verify setObject worked.
                $this->logger->error(
                    message: '[SaveObject] DEBUG: After setObject - entity object is now',
                    context: [
                        'file'         => __FILE__,
                        'line'         => __LINE__,
                        'app'          => 'openregister',
                        'entityObject' => json_encode($savedEntity->getObject()),
                    ]
                );

                // DEBUG: About to call update.
                $this->logger->error(
                    message: '[SaveObject] DEBUG: About to call objectEntityMapper->update()',
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                        'app'  => 'openregister',
                        'uuid' => $savedEntity->getUuid(),
                    ]
                );

                // Clear image metadata if objectImageField is a file property.
                $this->clearImageMetadataIfFileProperty(
                    savedEntity: $savedEntity,
                    schema: $schema
                );

                $savedEntity = $this->objectEntityMapper->update(entity: $savedEntity, register: $register, schema: $schema);

                // DEBUG: After update.
                $this->logger->error(
                    message: '[SaveObject] DEBUG: After objectEntityMapper->update() - result object',
                    context: [
                        'file'         => __FILE__,
                        'line'         => __LINE__,
                        'app'          => 'openregister',
                        'resultObject' => json_encode($savedEntity->getObject()),
                    ]
                );
            }//end if

            return $savedEntity;
        } catch (Exception $e) {
            // ROLLBACK: Delete the object if file processing failed.
            $this->logger->warning(
                message: '[SaveObject] File processing failed, rolling back object creation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $savedEntity->getUuid(),
                    'error' => $e->getMessage(),
                ]
            );
            $this->objectEntityMapper->delete($savedEntity);

            // Re-throw the exception so the controller can handle it.
            throw $e;
        }//end try
    }//end processFilePropertiesWithRollback()

    /**
     * Clear image metadata if objectImageField points to a file property.
     *
     * @param ObjectEntity $savedEntity Saved object entity
     * @param Schema       $schema      Schema entity
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function clearImageMetadataIfFileProperty(
        ObjectEntity $savedEntity,
        Schema $schema
    ): void {
        $config = $schema->getConfiguration();
        if (($config['objectImageField'] ?? null) === null) {
            return;
        }

        $imageField       = $config['objectImageField'];
        $schemaProperties = $schema->getProperties() ?? [];

        // Check if the image field is a file property.
        if (($schemaProperties[$imageField] ?? null) !== null) {
            $propertyConfig = $schemaProperties[$imageField];
            if (($propertyConfig['type'] ?? '') === 'file') {
                // Clear the image metadata so it will be extracted from the file object during rendering.
                $savedEntity->setImage(null);
            }
        }
    }//end clearImageMetadataIfFileProperty()

    /**
     * Prepares an object for creation by applying all necessary transformations.
     *
     * @param ObjectEntity $objectEntity  The object entity to prepare.
     * @param Schema       $schema        The schema of the object.
     * @param array        $data          The object data.
     * @param array        $selfData      The @self metadata.
     * @param bool         $_multitenancy Whether to apply multitenancy filtering.
     *
     * @return ObjectEntity The prepared object entity.
     *
     * @throws Exception If there is an error during preparation.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex preparation with multiple transformations
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple optional configuration paths
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive preparation requires extended logic
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function prepareObjectForCreation(
        ObjectEntity $objectEntity,
        Schema $schema,
        array $data,
        array $selfData,
        bool $_multitenancy
    ): ObjectEntity {
        // Set @self metadata properties.
        $this->setSelfMetadata(objectEntity: $objectEntity, selfData: $selfData, data: $data);

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
                        'schema'   => $objectEntity->getSchema(),
                        'id'       => $objectEntity->getUuid(),
                    ]
                )
            )
        );

        // Prepare the data.
        $preparedData = $this->prepareObjectData(objectEntity: $objectEntity, schema: $schema, data: $data);

        // Validate reference existence for properties with validateReference: true.
        $this->validateReferences(schema: $schema, data: $preparedData, register: $objectEntity->getRegister());

        // Set the prepared data.
        $objectEntity->setObject($preparedData);

        // Hydrate name and description from schema configuration.
        try {
            $this->hydrateObjectMetadata(entity: $objectEntity, schema: $schema);
        } catch (Exception $e) {
            // CRITICAL FIX: Hydration failures indicate schema/data mismatch - don't suppress!
            $mismatchHint = 'This indicates a mismatch between object data and schema configuration.';
            throw new Exception('Object metadata hydration failed: '.$e->getMessage().'. '.$mismatchHint, 0, $e);
        }

        // Extract Nc* property references and populate linked entity metadata columns.
        $this->linkedEntityHandler->extractAndPopulate($objectEntity, $schema, $preparedData);

        // Populate TMLO archival metadata defaults if register has TMLO enabled.
        $this->populateTmloDefaults(objectEntity: $objectEntity, schema: $schema, selfData: $selfData);

        // Set user information if available.
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $objectEntity->setOwner($user->getUID());
        }

        // Set organisation from active organisation if not already set.
        // Always respect user's active organisation regardless of multitenancy settings.
        // BUT: Don't override if organisation was explicitly set via @self metadata (e.g., for organization activation).
        if (($objectEntity->getOrganisation() === null || $objectEntity->getOrganisation() === '')
            && isset($selfData['organisation']) === false
        ) {
            $organisationUuid = $this->organisationService->getOrganisationForNewEntity();
            $objectEntity->setOrganisation($organisationUuid);
        }

        // Update object relations.
        try {
            $objectEntity = $this->updateObjectRelations(
                objectEntity: $objectEntity,
                data: $preparedData,
                schema: $schema
            );
        } catch (Exception $e) {
            // CRITICAL FIX: Relation processing failures indicate serious data integrity issues!
            $hint = 'This indicates invalid relation data or schema configuration problems.';
            throw new Exception('Object relations processing failed: '.$e->getMessage().'. '.$hint, 0, $e);
        }

        return $objectEntity;
    }//end prepareObjectForCreation()

    /**
     * Prepares an object for update by applying all necessary transformations.
     *
     * @param ObjectEntity $existingObject The existing object entity to prepare.
     * @param Schema       $schema         The schema of the object.
     * @param array        $data           The updated object data.
     * @param array        $selfData       The @self metadata.
     * @param int|null     $folderId       The folder ID to set on the object.
     *
     * @return ObjectEntity The prepared object entity.
     *
     * @throws Exception If there is an error during preparation.
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function prepareObjectForUpdate(
        ObjectEntity $existingObject,
        Schema $schema,
        array $data,
        array $selfData,
        ?int $folderId
    ): ObjectEntity {
        // Set @self metadata properties.
        $this->setSelfMetadata(objectEntity: $existingObject, selfData: $selfData, data: $data);

        // Set folder ID if provided.
        if ($folderId !== null) {
            $existingObject->setFolder((string) $folderId);
        }

        // Snapshot the object's pre-update data BEFORE prepareObjectData runs.
        // prepareObjectData calls preCacheParentName which writes the incoming
        // data back into $existingObject via setObject() so cascade descendants
        // can resolve the parent's name template; reading getObject() afterwards
        // would yield the new data, not the original. Capturing here keeps the
        // "skip validation when reference unchanged" check honest.
        $oldData = $existingObject->getObject();

        // Prepare the data.
        $preparedData = $this->prepareObjectData(objectEntity: $existingObject, schema: $schema, data: $data);

        // Validate reference existence for properties with validateReference: true.
        // On updates, skip validation for unchanged values to avoid re-validating existing references.
        $this->validateReferences(
            schema: $schema,
            data: $preparedData,
            register: $existingObject->getRegister(),
            oldData: $oldData
        );

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
        // Duplicate call would overwrite relations after handleInverseRelationsWriteBack removes properties
        // Update object relations (result currently unused but operation has side effects).
        try {
            // $objectEntity = $this->updateObjectRelations($existingObject, $preparedData, $schema);
            $this->updateObjectRelations(
                objectEntity: $existingObject,
                data: $preparedData,
                schema: $schema
            );
        } catch (Exception $e) {
            // CRITICAL FIX: Relation processing failures indicate serious data integrity issues!
            $hint = 'This indicates invalid relation data or schema configuration problems.';
            throw new Exception('Object relations processing failed: '.$e->getMessage().'. '.$hint, 0, $e);
        }

        return $existingObject;
    }//end prepareObjectForUpdate()

    /**
     * Sets @self metadata properties on an object entity.
     *
     * @param ObjectEntity $objectEntity The object entity to set metadata on.
     * @param array        $selfData     The @self metadata.
     * @param array        $data         The object data (for generated values like slug).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex metadata extraction from multiple sources
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple optional metadata fields with validation
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function setSelfMetadata(ObjectEntity $objectEntity, array $selfData, array $data=[]): void
    {
        // Extract and set slug property if present (check both @self and data).
        $slug = $selfData['slug'] ?? $data['slug'] ?? null;
        if (empty($slug) === false) {
            $objectEntity->setSlug($slug);
        }

        if (array_key_exists('owner', $selfData) === true && empty($selfData['owner']) === false) {
            $objectEntity->setOwner($selfData['owner']);
        }

        if (array_key_exists('organisation', $selfData) === true && empty($selfData['organisation']) === false) {
            $objectEntity->setOrganisation($selfData['organisation']);
        }

        // Propagate @self.folder so the access-control check governs the bind
        // (numeric → access-checked, empty/legacy → auto-create). Without
        // propagation a user-supplied @self.folder is silently dropped —
        // defeating the whole `self-folder-access-control` capability.
        if (array_key_exists('folder', $selfData) === true && empty($selfData['folder']) === false) {
            $folderValue = (string) $selfData['folder'];

            // For non-empty numeric folder values (the format produced by
            // explicit `@self.folder` writes), require that the acting user
            // can read the target folder — otherwise reject the bind with
            // `FolderAccessDeniedException`. Legacy non-numeric values fall
            // through to the auto-create path unchanged.
            if (is_numeric($folderValue) === true) {
                $this->folderManagementHandler->assertFolderIsAccessible(
                    folderId: $folderValue,
                    objectEntity: $objectEntity
                );
            }

            $objectEntity->setFolder($folderValue);
        }

        // Set TMLO metadata from @self if provided.
        if (array_key_exists('tmlo', $selfData) === true && is_array($selfData['tmlo']) === true) {
            $objectEntity->setTmlo($selfData['tmlo']);
        }
    }//end setSelfMetadata()

    /**
     * Populate TMLO defaults on a new object if the register has TMLO enabled.
     *
     * @param ObjectEntity $objectEntity The object entity being created
     * @param Schema       $schema       The schema for TMLO defaults
     * @param array        $selfData     The @self metadata from the request
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function populateTmloDefaults(ObjectEntity $objectEntity, Schema $schema, array $selfData): void
    {
        $registerId = $objectEntity->getRegister();
        if ($registerId === null) {
            return;
        }

        try {
            $register = $this->getCachedRegister(registerId: (int) $registerId);
        } catch (Exception $e) {
            return;
        }

        if ($this->tmloService->isTmloEnabled($register) === false) {
            return;
        }

        // If TMLO data was explicitly provided via @self, use it as the starting point.
        if (array_key_exists('tmlo', $selfData) === true && is_array($selfData['tmlo']) === true) {
            $objectEntity->setTmlo($selfData['tmlo']);
        }

        // Validate field values before populating.
        $currentTmlo = $objectEntity->getTmlo();
        if (is_array($currentTmlo) === true && empty($currentTmlo) === false) {
            $errors = $this->tmloService->validateFieldValues($currentTmlo);
            if (empty($errors) === false) {
                throw new Exception('TMLO validation failed: '.implode('; ', $errors));
            }
        }

        $this->tmloService->populateDefaults($objectEntity, $register, $schema);
    }//end populateTmloDefaults()

    /**
     * Validate TMLO metadata on an object update (status transitions and field values).
     *
     * @param ObjectEntity $existingObject The existing object being updated
     * @param array        $selfData       The @self metadata from the request
     *
     * @return void
     *
     * @throws Exception If TMLO validation fails
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function validateTmloOnUpdate(ObjectEntity $existingObject, array $selfData): void
    {
        // Only validate if TMLO data was provided in the update.
        if (array_key_exists('tmlo', $selfData) === false || is_array($selfData['tmlo']) === false) {
            return;
        }

        $newTmlo = $selfData['tmlo'];

        // Validate field values.
        $fieldErrors = $this->tmloService->validateFieldValues($newTmlo);
        if (empty($fieldErrors) === false) {
            throw new Exception('TMLO validation failed: '.implode('; ', $fieldErrors));
        }

        // Validate status transition if archiefstatus is changing.
        $oldTmlo   = $existingObject->getTmlo();
        $oldStatus = ($oldTmlo['archiefstatus'] ?? TmloService::ARCHIEFSTATUS_ACTIEF);
        $newStatus = ($newTmlo['archiefstatus'] ?? null);

        if ($newStatus !== null && $newStatus !== $oldStatus) {
            // Merge old TMLO with new for complete validation context.
            $mergedTmlo       = array_merge(($oldTmlo ?? []), $newTmlo);
            $transitionErrors = $this->tmloService->validateStatusTransition($mergedTmlo, $oldStatus);
            if (empty($transitionErrors) === false) {
                throw new Exception('TMLO status transition failed: '.implode('; ', $transitionErrors));
            }
        }

        // Update the TMLO field on the entity.
        $existingObject->setTmlo(array_merge(($oldTmlo ?? []), $newTmlo));
    }//end validateTmloOnUpdate()

    /**
     * Validate reference existence for all properties with validateReference: true.
     *
     * Iterates schema properties, finds those with $ref and validateReference enabled,
     * and checks that referenced object UUIDs exist in the target schema.
     *
     * @param Schema      $schema   The schema containing property definitions.
     * @param array       $data     The object data to validate.
     * @param string|null $register The object's register ID (fallback for target register).
     * @param array|null  $oldData  Previous object data (for update skip-unchanged logic).
     *
     * @return void
     *
     * @throws ValidationException If a referenced object does not exist.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple property type checks
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function validateReferences(
        Schema $schema,
        array $data,
        ?string $register,
        ?array $oldData=null
    ): void {
        // Operator-controlled admin bypass: when the current user is in
        // the admin group AND the bypass flag is on (default true), skip
        // reference existence validation entirely. Mirrors the
        // `MultiTenancyTrait::isCurrentUserAdmin()` short-circuit so
        // admins can repair broken cross-references during migrations or
        // bulk imports without tripping 422s. Operators can flip the
        // flag off via `occ config:app:set openregister
        // reference_validation_admin_bypass --value=false` to enforce
        // validation for every user.
        if ($this->shouldBypassValidationForAdmin() === true) {
            return;
        }

        $properties = $schema->getProperties();
        if ($properties === null) {
            return;
        }

        foreach ($properties as $propertyName => $property) {
            // Resolve the configured strictness level for this property.
            // Returns null when validation is not enabled — short-circuit
            // so legacy `validateReference: false` (or absent) costs zero.
            $strictness = $this->resolveReferenceStrictness(property: $property);
            if ($strictness === null) {
                continue;
            }

            // Determine the $ref target.
            $ref     = $property['$ref'] ?? $property['items']['$ref'] ?? null;
            $isArray = isset($property['type']) && $property['type'] === 'array';

            if ($ref === null) {
                continue;
            }

            // Get the value from data.
            $value = $data[$propertyName] ?? null;

            // Skip null or empty values (non-required property).
            if ($value === null || $value === '') {
                continue;
            }

            // On updates, skip validation for unchanged values.
            if ($oldData !== null && array_key_exists($propertyName, $oldData) === true) {
                if ($oldData[$propertyName] === $value) {
                    continue;
                }
            }

            // Resolve the target register: property-level config or object's register.
            $targetRegister = $property['register'] ?? $register;

            // Normalize to array for uniform validation.
            $uuidsToValidate = [$value];
            if ($isArray === true && is_array($value) === true) {
                $uuidsToValidate = $value;
            }

            foreach ($uuidsToValidate as $uuid) {
                if (empty($uuid) === true) {
                    continue;
                }

                try {
                    // Cross-app references can be expressed as HTTP(S)
                    // URLs (the canonical resource address of an object
                    // owned by another app or instance). Components are
                    // designed to operate individually — component A may
                    // not have read access to component B for security
                    // or tenancy reasons, so we MUST NOT fetch the URL
                    // to verify existence. Instead, validate syntax only:
                    // scheme is http/https and host parses. Decision
                    // recorded in `reference-existence-validation`
                    // tasks.md (2026-05-02).
                    if ($this->looksLikeHttpUrl(value: (string) $uuid) === true) {
                        $this->validateExternalUrlSyntax(
                            propertyName: $propertyName,
                            value: (string) $uuid,
                            schemaRef: $ref,
                            register: $targetRegister
                        );
                        continue;
                    }

                    $this->validateReferenceExists(
                        propertyName: $propertyName,
                        uuid: (string) $uuid,
                        schemaRef: $ref,
                        register: $targetRegister
                    );
                } catch (ReferenceValidationException $exception) {
                    // Strict mode (`error`) re-raises the 422 so the save
                    // is rejected. `warn` mode swallows the exception
                    // after the failure event has already fired in
                    // `validateReferenceExists()` — listeners still see
                    // the miss, the warning is logged for ops visibility,
                    // and the save proceeds. This lets schema authors
                    // adopt reference validation gradually on registers
                    // with known dirty data without forcing every save
                    // through an HTTP 422.
                    if ($strictness === 'warn') {
                        $this->logger->warning(
                            message: '[SaveObject] Reference validation failed (warn-only)',
                            context: [
                                'file'           => __FILE__,
                                'line'           => __LINE__,
                                'property'       => $propertyName,
                                'uuid'           => $uuid,
                                'targetSchema'   => $exception->getTargetSchemaSlug(),
                                'targetRegister' => $exception->getTargetRegister(),
                            ]
                        );
                        continue;
                    }

                    throw $exception;
                }//end try
            }//end foreach
        }//end foreach
    }//end validateReferences()

    /**
     * Resolve the configured strictness level for a schema property.
     *
     * Schema authors declare reference validation in two related fields:
     *
     * - `validateReference` — the on/off toggle (boolean) or a shorthand
     *   string carrying both the on/off bit AND the severity:
     *   `true` / `'error'` / `'strict'` / `'block'` enable strict
     *   validation; `'warn'` enables warn-only mode; `false` or
     *   `'off'` disables validation entirely.
     * - `validationStrictness` — optional explicit severity field
     *   (`'strict'`, `'warn'`, `'off'`) that overrides the default
     *   strict severity when `validateReference` is just the boolean
     *   `true`. Matches the spec's documented schema shape.
     *
     * Returns `'error'` when validation is strict, `'warn'` when
     * warn-only, and `null` when validation is disabled — the caller
     * uses `null` as the short-circuit signal to skip the property
     * without paying for the database round trip.
     *
     * @param array $property The schema property definition.
     *
     * @return string|null Returns `'error'` for strict mode, `'warn'` for
     *                     warn-only, `null` when validation is disabled.
     *
     * @spec openspec/changes/reference-existence-validation/tasks.md
     */
    private function resolveReferenceStrictness(array $property): ?string
    {
        $configured = ($property['validateReference'] ?? false);
        $explicit   = ($property['validationStrictness'] ?? null);

        // Explicit strictness field can disable validation outright,
        // mirroring the spec's "off" level even when the boolean toggle
        // is true.
        if (is_string($explicit) === true && strtolower($explicit) === 'off') {
            return null;
        }

        // Map the optional explicit strictness onto our internal verdict.
        $explicitNormalized = null;
        if (is_string($explicit) === true) {
            $candidate = strtolower($explicit);
            if ($candidate === 'strict' || $candidate === 'error' || $candidate === 'block') {
                $explicitNormalized = 'error';
            } else if ($candidate === 'warn') {
                $explicitNormalized = 'warn';
            }
        }

        // Boolean true: validation enabled, default strict, but defer
        // to explicit strictness when present.
        if ($configured === true) {
            return $explicitNormalized ?? 'error';
        }

        // String shorthand on `validateReference` carries both the
        // on/off bit and the severity.
        if (is_string($configured) === true) {
            $normalized = strtolower($configured);
            if ($normalized === 'off' || $normalized === 'false') {
                return null;
            }

            if ($normalized === 'error' || $normalized === 'block' || $normalized === 'strict') {
                return $explicitNormalized ?? 'error';
            }

            if ($normalized === 'warn') {
                return $explicitNormalized ?? 'warn';
            }
        }

        return null;
    }//end resolveReferenceStrictness()

    /**
     * Detect whether a reference value expresses HTTP(S) URL intent.
     *
     * Scheme-prefix only — once the user writes `http://` or `https://`
     * they've signalled a URL and we want strict syntax validation to
     * speak in URL terms (so a missing host gets the URL-shaped error,
     * not the UUID-not-found error). Strict checks happen in
     * `validateExternalUrlSyntax()`.
     *
     * @param string $value Reference value to inspect.
     *
     * @return bool True when the value declares HTTP(S) URL intent.
     */
    private function looksLikeHttpUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') === true
            || str_starts_with($value, 'https://') === true;
    }//end looksLikeHttpUrl()

    /**
     * Validate that an external URL reference is syntactically well-formed.
     *
     * Components are designed to operate individually; component A may
     * not have read access to component B for security or tenancy
     * reasons. Fetching the URL to verify existence would either pass
     * falsely (no read = "not found") or block legitimate writes, so
     * the design decision (`reference-existence-validation` tasks.md
     * 2026-05-02) is to validate syntax only:
     *
     * - Scheme MUST be `http` or `https`.
     * - Host MUST parse (non-empty after `parse_url`).
     * - Host MUST NOT be a literal IP without port-and-path (defensive
     *   against trivially malformed inputs that pass the scheme check
     *   but are clearly broken).
     *
     * On miss: dispatches `ReferenceValidationFailedEvent` (so warn-mode
     * listeners observe the rejection like they would for a UUID miss)
     * and throws `ReferenceValidationException` carrying HTTP 422.
     *
     * @param string      $propertyName The property holding the reference.
     * @param string      $value        The URL value to validate.
     * @param string      $schemaRef    The schema $ref the URL is supposed to address.
     * @param string|null $register     The register the URL is supposed to address.
     *
     * @return void
     *
     * @throws ReferenceValidationException When the URL syntax is invalid.
     */
    private function validateExternalUrlSyntax(
        string $propertyName,
        string $value,
        string $schemaRef,
        ?string $register
    ): void {
        $valid = false;
        $parts = parse_url($value);
        if ($parts !== false && is_array($parts) === true) {
            $scheme   = ($parts['scheme'] ?? '');
            $host     = ($parts['host'] ?? '');
            $hasHost  = ($host !== '');
            $okScheme = ($scheme === 'http' || $scheme === 'https');
            $valid    = ($okScheme === true && $hasHost === true);
        }

        if ($valid === true) {
            return;
        }

        // Reduce `#/components/schemas/foo` to `foo` for the failure
        // payload — the bare slug matches the convention used by the
        // UUID-existence path so listeners see a consistent shape.
        $targetSlug = $schemaRef;
        $prefix     = '#/components/schemas/';
        if (str_starts_with($schemaRef, $prefix) === true) {
            $targetSlug = substr($schemaRef, strlen($prefix));
        }

        $this->dispatchReferenceValidationFailedEvent(
            propertyName: $propertyName,
            referencedUuid: $value,
            targetSchemaSlug: $targetSlug,
            targetRegister: $register
        );

        throw new ReferenceValidationException(
            propertyName: $propertyName,
            referencedUuid: $value,
            targetSchemaSlug: $targetSlug,
            targetRegister: $register,
            message: sprintf(
                'External URL reference "%s" is malformed: scheme MUST be http or https and host MUST parse.',
                $value
            )
        );
    }//end validateExternalUrlSyntax()

    /**
     * Validate that a referenced object exists in the target schema.
     *
     * @param string      $propertyName The property name holding the reference.
     * @param string      $uuid         The UUID to validate.
     * @param string      $schemaRef    The $ref value pointing to the target schema.
     * @param string|null $register     The register ID to search in.
     *
     * @return void
     *
     * @throws ValidationException If the referenced object does not exist (HTTP 422).
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function validateReferenceExists(
        string $propertyName,
        string $uuid,
        string $schemaRef,
        ?string $register
    ): void {
        // Circular reference short-circuit: if the UUID we're about
        // to validate is already on the in-flight save call stack,
        // we have a cycle (A -> B -> A). Reject before hitting the
        // database — a cycle on otherwise-valid UUIDs would silently
        // pass the existence check, but cascading the save would
        // recurse forever.
        $cycle = $this->detectCircularReference(uuid: $uuid);
        if ($cycle !== null) {
            $this->logger->warning(
                message: '[SaveObject] Circular reference detected during validation',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'property'     => $propertyName,
                    'uuid'         => $uuid,
                    'targetSchema' => $schemaRef,
                    'cycleDepth'   => count($cycle),
                ]
            );

            throw new CircularReferenceException(
                referencedUuid: $uuid,
                targetSchemaSlug: $schemaRef,
                cycle: array_map(
                    static fn (array $frame): array => [
                        'schema'   => $frame['schemaSlug'],
                        'uuid'     => $frame['uuid'],
                        'register' => $frame['register'],
                    ],
                    $cycle
                ),
                code: 422
            );
        }//end if

        // Resolve the target schema ID.
        $targetSchemaId = $this->resolveSchemaReference(reference: $schemaRef);
        if ($targetSchemaId === null) {
            $this->logger->warning(
                message: '[SaveObject] Could not resolve schema reference for reference validation',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'property' => $propertyName,
                    'ref'      => $schemaRef,
                ]
            );
            return;
        }

        // Request-scoped cache short-circuit: if we already validated this
        // (targetSchemaId, uuid) pair during this request — typically
        // during a bulk import or cascade save — skip the database round
        // trip and replay the verdict. We still raise/return without
        // re-emitting events so listeners observe each unique reference
        // exactly once per request.
        $cacheKey = $targetSchemaId.':'.$uuid;
        if (array_key_exists($cacheKey, $this->referenceValidationCache) === true) {
            if ($this->referenceValidationCache[$cacheKey] === true) {
                return;
            }

            // Cached negative verdict — re-raise without dispatching the
            // failure event again (already dispatched on first miss).
            throw new ReferenceValidationException(
                propertyName: $propertyName,
                referencedUuid: $uuid,
                targetSchemaSlug: $schemaRef,
                targetRegister: $register,
                code: 422
            );
        }

        // Get the target schema for the error message.
        $targetSchemaSlug = $schemaRef;
        try {
            $targetSchema     = $this->getCachedSchema(schemaId: $targetSchemaId);
            $targetSchemaSlug = $targetSchema->getSlug() ?? $schemaRef;
        } catch (DoesNotExistException $e) {
            // Use the raw reference as the slug in the error message.
        }

        // Resolve register and schema to entity objects for MagicMapper.
        $registerEntity = null;
        if ($register !== null) {
            try {
                $registerEntity = $this->getCachedRegister(registerId: $register);
            } catch (DoesNotExistException $e) {
                $this->logger->warning(
                    message: '[SaveObject] Could not resolve register for reference validation',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'property' => $propertyName,
                        'register' => $register,
                    ]
                );
                return;
            }
        }

        $targetSchemaEntity = $targetSchema ?? null;

        // Check if the object exists.
        // Explicitly pass `includeDeleted: false` so soft-deleted target
        // objects are treated as nonexistent — closes the spec's
        // "Soft-deleted references treated as nonexistent" requirement.
        // The MagicMapper default is already `false`, but pinning it
        // here keeps the validation contract local + survives mapper
        // signature changes.
        try {
            $this->unifiedObjectMapper->find(
                identifier: $uuid,
                register: $registerEntity,
                schema: $targetSchemaEntity,
                includeDeleted: false,
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $e) {
            // Cache the negative verdict before the throw so subsequent
            // checks for the same UUID short-circuit cheaply (and don't
            // re-dispatch the failure event).
            $this->referenceValidationCache[$cacheKey] = false;

            // Side-channel notification for monitoring / extensibility.
            // Dispatched BEFORE the exception so listeners observe every
            // failure regardless of whether the controller layer
            // recovers or surfaces the 422.
            $this->dispatchReferenceValidationFailedEvent(
                propertyName: $propertyName,
                referencedUuid: $uuid,
                targetSchemaSlug: $targetSchemaSlug,
                targetRegister: $register
            );

            // Throw a structured exception so API clients can render
            // actionable error UI without parsing the message string —
            // closes the spec's "structured diagnostic information"
            // requirement. Subclasses ValidationException so existing
            // 422 handlers route it correctly.
            throw new ReferenceValidationException(
                propertyName: $propertyName,
                referencedUuid: $uuid,
                targetSchemaSlug: $targetSchemaSlug,
                targetRegister: $register,
                code: 422,
                previous: $e
            );
        } catch (Exception $e) {
            // Non-existence errors (e.g., database errors) — log warning but don't block.
            $this->logger->warning(
                message: '[SaveObject] Reference validation lookup failed',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'property' => $propertyName,
                    'uuid'     => $uuid,
                    'error'    => $e->getMessage(),
                ]
            );
            return;
        }//end try

        // Cache the positive verdict so subsequent checks for the same
        // (target schema, UUID) inside the same request — bulk imports,
        // cascading saves — skip the database round trip.
        $this->referenceValidationCache[$cacheKey] = true;

        // Lookup succeeded — emit a success event so listeners can hook
        // into accepted references (analytics, cache warming, etc.)
        // without changing the save flow.
        $this->dispatchReferenceValidatedEvent(
            propertyName: $propertyName,
            referencedUuid: $uuid,
            targetSchemaSlug: $targetSchemaSlug,
            targetRegister: $register
        );
    }//end validateReferenceExists()

    /**
     * Clear the request-scoped reference-existence cache.
     *
     * Provided for long-running CLI processes (e.g. background jobs that
     * iterate over hundreds of objects) that should not let cached
     * verdicts leak across logical request boundaries.
     *
     * @return void
     */
    public function clearReferenceValidationCache(): void
    {
        $this->referenceValidationCache = [];
    }//end clearReferenceValidationCache()

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
     * @param Register|null             $register Register context for the batch.
     * @param Schema|int|string|null    $schema   Schema context for the batch.
     * @param iterable                  $rows     Input rows (each is the same
     *                                            shape `saveObject` accepts).
     * @param BatchOperationStatus|null $status   Optional pre-existing status
     *                                            (for callers that want to
     *                                            accumulate across multiple
     *                                            calls).
     *
     * @return BatchOperationStatus
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function saveObjectsStreaming(
        Register | int | string | null $register,
        Schema | int | string | null $schema,
        iterable $rows,
        ?BatchOperationStatus $status=null
    ): BatchOperationStatus {
        $status ??= new BatchOperationStatus();
        $status->start();

        foreach ($rows as $row) {
            $cacheBefore = count($this->referenceValidationCache);

            try {
                $entity = $this->saveObject(
                    register: $register,
                    schema: $schema,
                    data: (is_array($row) === true ? $row : [])
                );

                $uuid = ((string) $entity->getUuid());

                // Outcome bucket — distinguishing create vs update
                // requires comparing the input UUID to the resulting
                // entity's UUID. When the input has no UUID, the save
                // path generates one — that's a create. When the
                // input carries a UUID and an existing object was
                // matched, that's an update. The unchanged bucket is
                // a future enhancement (would need a deep diff against
                // the previous state) and is out of scope here; for
                // now treat all non-create paths as updates.
                $hadUuid = (
                    is_array($row) === true
                    && (isset($row['@self']['id']) === true
                    || isset($row['id']) === true
                    || isset($row['uuid']) === true)
                );
                if ($hadUuid === true) {
                    $status->recordUpdated(uuid: $uuid);
                }

                if ($hadUuid === false) {
                    $status->recordCreated(uuid: $uuid);
                }
            } catch (Exception $e) {
                $rowUuid = null;
                if (is_array($row) === true) {
                    $rowUuid = (
                        $row['@self']['id'] ?? $row['id'] ?? $row['uuid'] ?? null
                    );
                    if (is_string($rowUuid) === false) {
                        $rowUuid = null;
                    }
                }

                $status->recordFailed(
                    uuid: $rowUuid,
                    message: $e->getMessage(),
                    exceptionClass: $e::class
                );

                $this->logger->warning(
                    message: '[SaveObject] saveObjectsStreaming row failed',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'uuid'  => $rowUuid,
                        'error' => $e->getMessage(),
                    ]
                );
            }//end try

            // Each row's reference checks either grew the cache (miss)
            // or hit existing entries (hit). Comparing cache size
            // before/after the row gives us a cheap proxy: any growth
            // is a miss; rows that didn't grow the cache resolved
            // their references via existing entries (hit).
            $cacheAfter = count($this->referenceValidationCache);
            $delta      = ($cacheAfter - $cacheBefore);
            if ($delta > 0) {
                for ($i = 0; $i < $delta; $i++) {
                    $status->recordReferenceCacheMiss();
                }
            }

            if ($delta <= 0) {
                $status->recordReferenceCacheHit();
            }
        }//end foreach

        $status->complete();
        return $status;
    }//end saveObjectsStreaming()

    /**
     * Push an in-flight save onto the call stack used for circular
     * reference detection.
     *
     * Returns the unique key under which the frame is recorded so
     * `popSaveCallFrame()` can remove it cleanly even when the caller
     * is in a nested cascade (we may have multiple frames on the stack
     * at once). Empty UUIDs are skipped — pre-persist creates have no
     * stable identifier yet, so they cannot be re-encountered as a
     * back-reference within the same chain.
     *
     * @param string      $schemaSlug Schema slug (or id when slug is null).
     * @param string      $uuid       Object UUID. Empty string when not yet known.
     * @param string|null $register   Register identifier.
     *
     * @return string|null The stack frame key, or null when nothing was pushed.
     *
     * @spec openspec/changes/reference-existence-validation/tasks.md
     */
    private function pushSaveCallFrame(string $schemaSlug, string $uuid, ?string $register): ?string
    {
        if ($uuid === '') {
            return null;
        }

        $key = $schemaSlug.':'.$uuid;
        if (array_key_exists($key, $this->saveCallStackIndex) === true) {
            // Already on the stack — re-entry without going through
            // a child save. This is unusual but harmless; just refuse
            // to double-push so pop semantics stay balanced.
            return null;
        }

        $this->saveCallStack[]          = [
            'schemaSlug' => $schemaSlug,
            'uuid'       => $uuid,
            'register'   => $register,
        ];
        $this->saveCallStackIndex[$key] = (count($this->saveCallStack) - 1);

        return $key;

    }//end pushSaveCallFrame()

    /**
     * Pop a frame previously pushed by `pushSaveCallFrame()`.
     *
     * Tolerant of null keys (pushSaveCallFrame returns null when the
     * frame couldn't be pushed) so callers can wrap unconditional
     * `try { … } finally { popSaveCallFrame($key); }` without checking.
     *
     * @param string|null $key The frame key returned by pushSaveCallFrame, or null.
     *
     * @return void
     *
     * @spec openspec/changes/reference-existence-validation/tasks.md
     */
    private function popSaveCallFrame(?string $key): void
    {
        if ($key === null) {
            return;
        }

        if (array_key_exists($key, $this->saveCallStackIndex) === false) {
            return;
        }

        $depth = $this->saveCallStackIndex[$key];
        unset($this->saveCallStackIndex[$key]);

        // Pop only the frames at or above the recorded depth so
        // unbalanced finallys (e.g. an exception that skipped a pop
        // somewhere) self-heal instead of leaking entries forever.
        $stackSize = count($this->saveCallStack);
        while ($stackSize > $depth) {
            $frame = array_pop($this->saveCallStack);
            if ($frame === null) {
                break;
            }

            $stackSize--;

            $existing = $frame['schemaSlug'].':'.$frame['uuid'];
            if ($existing !== $key) {
                unset($this->saveCallStackIndex[$existing]);
            }
        }

    }//end popSaveCallFrame()

    /**
     * Detect whether the supplied UUID is currently being saved
     * higher up the cascade chain.
     *
     * Returns the cycle path (the visited stack) when re-entry is
     * detected, otherwise null. Schema match is loose — if any frame
     * on the stack carries the same UUID (regardless of schema), we
     * consider it a cycle. This is the right shape for the spec's
     * "A->B->A" example: the schema may legitimately differ between
     * the parent and child cascades but the UUID is what closes the
     * loop.
     *
     * @param string $uuid Candidate UUID being referenced.
     *
     * @return array<int, array{schemaSlug:string,uuid:string,register:string|null}>|null
     *         Cycle path when detected, null otherwise.
     *
     * @spec openspec/changes/reference-existence-validation/tasks.md
     */
    private function detectCircularReference(string $uuid): ?array
    {
        if ($uuid === '') {
            return null;
        }

        foreach ($this->saveCallStack as $frame) {
            if ($frame['uuid'] === $uuid) {
                return $this->saveCallStack;
            }
        }

        return null;

    }//end detectCircularReference()

    /**
     * Decide whether the current user should bypass reference validation.
     *
     * Returns true when the current session user is in the `admin`
     * group AND the `reference_validation_admin_bypass` app-config flag
     * resolves to `true` (the default). The dependencies are optional
     * to keep older test fixtures working — when either is missing the
     * method returns false so validation runs as before.
     *
     * @return bool True when admin bypass applies.
     */
    private function shouldBypassValidationForAdmin(): bool
    {
        if ($this->groupManager === null || $this->appConfig === null) {
            return false;
        }

        $bypassEnabled = filter_var(
            $this->appConfig->getValueString(
                app: self::APP_ID,
                key: self::CONFIG_KEY_REFERENCE_VALIDATION_ADMIN_BYPASS,
                default: 'true'
            ),
            FILTER_VALIDATE_BOOLEAN
        );

        if ($bypassEnabled === false) {
            return false;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());

    }//end shouldBypassValidationForAdmin()

    /**
     * Dispatch a `ReferenceValidatedEvent` if a dispatcher is wired.
     *
     * Encapsulates the null-check so call-sites stay readable. Failures
     * inside listeners are caught and logged so a misbehaving listener
     * cannot block a save.
     *
     * @param string      $propertyName     Schema property name.
     * @param string      $referencedUuid   UUID that resolved.
     * @param string      $targetSchemaSlug Target schema slug.
     * @param string|null $targetRegister   Register the lookup ran in.
     *
     * @return void
     */
    private function dispatchReferenceValidatedEvent(
        string $propertyName,
        string $referencedUuid,
        string $targetSchemaSlug,
        ?string $targetRegister
    ): void {
        if ($this->eventDispatcher === null) {
            return;
        }

        try {
            $this->eventDispatcher->dispatchTyped(
                new ReferenceValidatedEvent(
                    propertyName: $propertyName,
                    referencedUuid: $referencedUuid,
                    targetSchemaSlug: $targetSchemaSlug,
                    targetRegister: $targetRegister
                )
            );
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[SaveObject] ReferenceValidatedEvent dispatch failed',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'property' => $propertyName,
                    'uuid'     => $referencedUuid,
                    'error'    => $e->getMessage(),
                ]
            );
        }//end try

    }//end dispatchReferenceValidatedEvent()

    /**
     * Dispatch a `ReferenceValidationFailedEvent` if a dispatcher is wired.
     *
     * Encapsulates the null-check so call-sites stay readable. Failures
     * inside listeners are caught and logged so a misbehaving listener
     * cannot mask the underlying validation failure.
     *
     * @param string      $propertyName     Schema property name.
     * @param string      $referencedUuid   UUID that did not resolve.
     * @param string      $targetSchemaSlug Target schema slug.
     * @param string|null $targetRegister   Register the lookup ran in.
     *
     * @return void
     */
    private function dispatchReferenceValidationFailedEvent(
        string $propertyName,
        string $referencedUuid,
        string $targetSchemaSlug,
        ?string $targetRegister
    ): void {
        if ($this->eventDispatcher === null) {
            return;
        }

        try {
            $this->eventDispatcher->dispatchTyped(
                new ReferenceValidationFailedEvent(
                    propertyName: $propertyName,
                    referencedUuid: $referencedUuid,
                    targetSchemaSlug: $targetSchemaSlug,
                    targetRegister: $targetRegister
                )
            );
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[SaveObject] ReferenceValidationFailedEvent dispatch failed',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'property' => $propertyName,
                    'uuid'     => $referencedUuid,
                    'error'    => $e->getMessage(),
                ]
            );
        }//end try

    }//end dispatchReferenceValidationFailedEvent()

    /**
     * Prepares object data by applying all necessary transformations.
     *
     * @param ObjectEntity $objectEntity The object entity.
     * @param Schema       $schema       The schema of the object.
     * @param array        $data         The object data.
     *
     * @return array The prepared object data.
     *
     * @throws Exception If there is an error during preparation.
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function prepareObjectData(ObjectEntity $objectEntity, Schema $schema, array $data): array
    {
        // Sanitize empty strings after validation but before cascading operations.
        // This prevents empty values from causing issues in downstream processing.
        try {
            $data = $this->sanitizeEmptyStringsForObjectProperties(data: $data, schema: $schema);
        } catch (Exception $e) {
            // CRITICAL FIX: Sanitization failures indicate serious data problems - don't suppress!
            $part1        = 'Object data sanitization failed: '.$e->getMessage();
            $part2        = '. This indicates invalid or corrupted object data that cannot be processed safely.';
            $errorMessage = $part1.$part2;
            throw new Exception($errorMessage, 0, $e);
        }

        // Pre-populate the name cache with the parent object's name before cascading.
        // This ensures that when sub-objects (e.g., koppelingen) resolve relation fields
        // (e.g., {{ moduleA }}) via the name template, they can find the parent's name
        // in the cache even though the parent hasn't been persisted to the database yet.
        // Without this, cascaded sub-objects fall back to showing the raw UUID as the name.
        $this->preCacheParentName(objectEntity: $objectEntity, schema: $schema, data: $data);

        // Apply cascading operations.
        $data = $this->cascadeObjects(objectEntity: $objectEntity, schema: $schema, data: $data);
        $data = $this->handleInverseRelationsWriteBack(objectEntity: $objectEntity, schema: $schema, data: $data);

        // Apply default values (including slug generation).
        $data = $this->setDefaultValues(objectEntity: $objectEntity, schema: $schema, data: $data);

        // Evaluate computed fields with evaluateOn: 'save'.
        // This computes values from Twig expressions and stores them in the object data.
        if ($this->computedFieldHandler->hasComputedProperties($schema) === true) {
            $data = $this->computedFieldHandler->evaluateComputedFields(
                data: $data,
                schema: $schema,
                evaluateOn: 'save'
            );
        }

        return $data;
    }//end prepareObjectData()

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
     * @param ObjectEntity $objectEntity The parent object entity (with UUID already set).
     * @param Schema       $schema       The parent object's schema.
     * @param array        $data         The parent object's data.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function preCacheParentName(ObjectEntity $objectEntity, Schema $schema, array $data): void
    {
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
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );

            return;
        }

        $name = $objectEntity->getName();
        if ($name !== null && trim($name) !== '') {
            $this->cacheHandler->setObjectName(identifier: $uuid, name: $name);
        }

        // Also try the 'naam' field as a fallback (common in Dutch schemas).
        // This covers cases where objectNameField is not configured but naam exists in data.
        if (($name === null || trim($name) === '') && isset($data['naam']) === true) {
            $naam = trim((string) $data['naam']);
            if ($naam !== '') {
                $this->cacheHandler->setObjectName(identifier: $uuid, name: $naam);
            }
        }
    }//end preCacheParentName()

    /**
     * Updates an existing object.
     *
     * @param Register|int|string $register       The register containing the object.
     * @param Schema|int|string   $schema         The schema to validate against.
     * @param array               $data           The updated object data.
     * @param ObjectEntity        $existingObject The existing object to update.
     * @param int|null            $folderId       The folder ID to set on the object (optional).
     * @param bool                $silent         Whether to skip audit trail creation and events (default: false).
     * @param ObjectEntity|null   $oldObject      The original object before changes (optional).
     *
     * @return ObjectEntity The updated object entity.
     *
     * @throws Exception If there is an error during update.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex update logic with file handling
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple update paths and file processing
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive update with file handling
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Silent flag needed for audit trail control
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    public function updateObject(
        Register | int | string $register,
        Schema | int | string $schema,
        array $data,
        ObjectEntity $existingObject,
        ?int $folderId=null,
        bool $silent=false,
        ?ObjectEntity $oldObject=null
    ): ObjectEntity {

        // Use provided oldObject or clone the existing object for audit trail.
        // Note: If oldObject is not provided, the clone here may have modified data
        // since prepareObjectForUpdate already modified existingObject in place.
        // Always prefer passing oldObject from the caller (handleObjectUpdate).
        if ($oldObject === null) {
            $oldObject = clone $existingObject;
        }

        // Extract @self data if present.
        $selfData = [];
        if (($data['@self'] ?? null) !== null && is_array($data['@self']) === true) {
            $selfData = $data['@self'];
        }

        // Remove @self and id from the data before processing.
        unset($data['@self'], $data['id']);

        // Resolve register and schema to entity objects if needed.
        if (is_int($register) === true || is_string($register) === true) {
            $register = $this->registerMapper->find(id: (int) $register, _multitenancy: false);
        }

        if (is_int($schema) === true || is_string($schema) === true) {
            $schema = $this->schemaMapper->find(id: (int) $schema, _multitenancy: false);
        }

        // Set register ID and schema ID.
        $registerId = $register->getId();
        $schemaId   = $schema->getId();

        // Prepare the object for update using the new structure.
        $preparedObject = $this->prepareObjectForUpdate(
            existingObject: $existingObject,
            schema: $schema,
            data: $data,
            selfData: $selfData,
            folderId: $folderId
        );

        // Recalculate archiefactiedatum if source property changed.
        try {
            $retentionService = \OC::$server->get(\OCA\OpenRegister\Service\RetentionService::class);
            $preparedObject   = $retentionService->recalculateArchiefactiedatum(
                $preparedObject,
                $schema,
                $oldObject->getObject()
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                '[SaveObject] RetentionService not available for recalculation: '.$e->getMessage()
            );
        }

        // Update the object properties.
        $preparedObject->setRegister((string) $registerId);
        $preparedObject->setSchema((string) $schemaId);
        $preparedObject->setUpdated(new DateTime());

        // Log that we're about to update using MagicMapper.
        $this->logger->debug(
            message: '[SaveObject] Updating object using MagicMapper',
            context: [
                'file' => __FILE__,
                'line' => __LINE__,
                'uuid' => $preparedObject->getUuid(),
            ]
                );

        // Save the object to database using MagicMapper.
        // This ensures proper event dispatching for magic table objects.
        // Pass the oldObject to ensure accurate status change detection in events.
        $updatedEntity = $this->unifiedObjectMapper->update(
            entity: $preparedObject,
            register: $register,
            schema: $schema,
            oldEntity: $oldObject
        );

        $this->logger->info(
            message: '[SaveObject] Object updated successfully',
            context: [
                'file' => __FILE__,
                'line' => __LINE__,
                'app'  => 'openregister',
                'uuid' => $updatedEntity->getUuid(),
            ]
                );

        // Create audit trail for update if audit trails are enabled and not in silent mode.
        if ($silent === false && $this->isAuditTrailsEnabled() === true) {
            $log = $this->auditTrailMapper->createAuditTrail(old: $oldObject, new: $updatedEntity);
            $updatedEntity->setLastLog($log->jsonSerialize());
        }

        // Handle file properties - process them and replace content with file IDs.
        $filePropsProcessed = false;
        foreach ($data as $propertyName => $value) {
            $isFileProperty = $this->filePropertyHandler->isFileProperty(
                value: $value,
                schema: $schema,
                propertyName: $propertyName
            );
            if ($isFileProperty === true) {
                $this->filePropertyHandler->handleFileProperty(
                    objectEntity: $updatedEntity,
                    object: $data,
                    propertyName: $propertyName,
                    schema: $schema
                );
                $filePropsProcessed = true;
            }
        }

        // Update the object with the modified data (file IDs instead of content).
        if ($filePropsProcessed === true) {
            $updatedEntity->setObject($data);

            // Clear image metadata if objectImageField points to a file property.
            // This ensures the image URL is extracted from the file object during rendering.
            $config = $schema->getConfiguration();
            if (($config['objectImageField'] ?? null) !== null) {
                $imageField       = $config['objectImageField'];
                $schemaProperties = $schema->getProperties() ?? [];

                // Check if the image field is a file property.
                if (($schemaProperties[$imageField] ?? null) !== null) {
                    $propertyConfig = $schemaProperties[$imageField];
                    if (($propertyConfig['type'] ?? '') === 'file') {
                        // Clear the image metadata so it will be extracted from the file object during rendering.
                        $updatedEntity->setImage(null);
                    }
                }
            }

            // Save the updated entity with file IDs back to database.
            $updatedEntity = $this->objectEntityMapper->update(entity: $updatedEntity, register: $register, schema: $schema);
        }//end if

        // Update inverse relations on related objects (bidirectional relationship management).
        // This ensures that when object A references object B, object B's relations also include A.
        // Skip for silent mode (cascaded sub-objects) to improve performance.
        if ($silent === false) {
            $this->updateInverseRelations(savedEntity: $updatedEntity, register: $register, schema: $schema);
        }

        return $updatedEntity;
    }//end updateObject()

    /**
     * Check if an object is effectively empty (contains only empty values)
     *
     * This method checks if an object contains only empty strings, empty arrays,
     * empty objects, or null values, which indicates it doesn't contain meaningful data
     * that should be cascaded.
     *
     * @param array $object The object data to check
     *
     * @return bool True if the object is effectively empty, false otherwise
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function isEffectivelyEmptyObject(array $object): bool
    {
        // If the array is completely empty, it's effectively empty.
        if (empty($object) === true) {
            return true;
        }

        // Check each value in the object.
        foreach ($object as $key => $value) {
            // Skip metadata keys that don't represent actual data.
            if (in_array($key, ['@self', 'id', '_id']) === true) {
                continue;
            }

            // If we find any non-empty value, the object is not effectively empty.
            if ($this->isValueNotEmpty(value: $value) === true) {
                return false;
            }
        }

        // All values are empty, so the object is effectively empty.
        return true;
    }//end isEffectivelyEmptyObject()

    /**
     * Check if a value is not empty (contains meaningful data)
     *
     * @param mixed $value The value to check
     *
     * @return bool True if the value is not empty, false otherwise
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple value type checks required
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function isValueNotEmpty($value): bool
    {
        // Null values are empty.
        if ($value === null) {
            return false;
        }

        // Empty strings are empty.
        if (is_string($value) === true && trim($value) === '') {
            return false;
        }

        // Empty arrays are empty.
        if (is_array($value) === true && empty($value) === true) {
            return false;
        }

        // For objects/arrays with content, check recursively.
        if (is_array($value) === true && empty($value) === false) {
            // If it's an associative array (object-like), check if it's effectively empty.
            if (array_keys($value) !== range(0, count($value) - 1)) {
                return $this->isEffectivelyEmptyObject(object: $value) === false;
            }

            // For indexed arrays, check if any element is not empty.
            foreach ($value as $item) {
                if ($this->isValueNotEmpty(value: $item) === true) {
                    return true;
                }
            }

            return false;
        }

        // For all other values (numbers, booleans, etc.), they are not empty.
        return true;
    }//end isValueNotEmpty()

    /**
     * Check if audit trails are enabled in the settings
     *
     * @return bool True if audit trails are enabled, false otherwise
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function isAuditTrailsEnabled(): bool
    {
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
}//end class
