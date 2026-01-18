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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Exception\ValidationException;
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex cascading and relation logic
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Requires many service and mapper dependencies
 */

class SaveObject
{
    private const URL_PATH_IDENTIFIER = 'openregister.objects.show';

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
     * Constructor for SaveObject handler.
     *
     * @param ObjectEntityMapper       $objectEntityMapper   Object entity mapper
     * @param MetadataHydrationHandler $metaHydrationHandler Handler for metadata extraction
     * @param FilePropertyHandler      $filePropertyHandler  Handler for file property operations
     * @param IUserSession             $userSession          User session service
     * @param AuditTrailMapper         $auditTrailMapper     Audit trail mapper for logging changes
     * @param SchemaMapper             $schemaMapper         Schema mapper for schema operations
     * @param RegisterMapper           $registerMapper       Register mapper for register operations
     * @param IURLGenerator            $urlGenerator         URL generator service
     * @param OrganisationService      $organisationService  Service for organisation operations
     * @param CacheHandler             $cacheHandler         Object cache service for entity and query caching
     * @param SettingsService          $settingsService      Settings service for accessing trail settings
     * @param LoggerInterface          $logger               Logger interface for logging operations
     * @param ArrayLoader              $arrayLoader          Twig array loader for template rendering
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly UnifiedObjectMapper $unifiedObjectMapper,
        private readonly MetadataHydrationHandler $metaHydrationHandler,
        private readonly FilePropertyHandler $filePropertyHandler,
        private readonly IUserSession $userSession,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly IURLGenerator $urlGenerator,
        private readonly OrganisationService $organisationService,
        private readonly CacheHandler $cacheHandler,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        ArrayLoader $arrayLoader,
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
     */
    public function clearCreatedSubObjects(): void
    {
        $this->createdSubObjects = [];
    }//end clearCreatedSubObjects()

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
     */
    private function resolveSchemaReference(string $reference): string|null
    {
        if (empty($reference) === true) {
            return null;
        }

        // Remove query parameters if present (e.g., "schema?key=value" -> "schema").
        $cleanReference = $this->removeQueryParameters($reference);

        // First, try direct ID lookup (numeric ID or UUID).
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        if (is_numeric($cleanReference) === true || preg_match($uuidPattern, $cleanReference) === true) {
            try {
                $schema = $this->schemaMapper->find(id: $cleanReference);
                return (string) $schema->getId();
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
        try {
            $schemas = $this->schemaMapper->findAll();
            foreach ($schemas as $schema) {
                if (strcasecmp($schema->getSlug(), $slug) === 0) {
                    return (string) $schema->getId();
                }
            }
        } catch (Exception $e) {
            // Schema not found.
        }

        // Try direct slug match as last resort.
        try {
            // SchemaMapper->find() supports id, uuid, and slug via orX().
            $schema = $this->schemaMapper->find(id: $slug, published: null, _rbac: false, _multitenancy: false);
            if ($schema !== null) {
                return (string) $schema->getId();
            }
        } catch (Exception $e) {
            // Schema not found.
        }

        return null;
    }//end resolveSchemaReference()

    /**
     * Removes query parameters from a reference string.
     *
     * @param string $reference The reference string that may contain query parameters
     *
     * @return string The reference string without query parameters
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
     */
    private function resolveRegisterReference(string $reference): string|null
    {
        if (empty($reference) === true) {
            return null;
        }

        // First, try direct ID lookup (numeric ID or UUID).
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        if (is_numeric($reference) === true || preg_match($uuidPattern, $reference) === true) {
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

                if (is_array($value) === true && empty($value) === true) {
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
                                if ($this->isReference($item) === true) {
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
                        $treatAsRelation = $this->isReference($value);
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
     */
    private function isReference(string $value): bool
    {
        $value = trim($value);

        // Empty strings are not references.
        if (empty($value) === true) {
            return false;
        }

        // Check for standard UUID pattern (8-4-4-4-12 format).
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === true) {
            return true;
        }

        // Check for prefixed UUID patterns (e.g., "id-uuid", "ref-uuid", etc.).
        if (preg_match('/^[a-z]+-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === true) {
            return true;
        }

        // Check for URLs.
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        // Check for other common ID patterns, but be more selective to avoid false positives.
        // Only consider strings that look like identifiers, not regular text.
        if (preg_match('/^[a-z0-9][a-z0-9_-]{7,}$/i', $value) === true) {
            // Must contain at least one hyphen or underscore (indicating it's likely an ID).
            // AND must not contain spaces or common text words.
            $hasHyphenUndscr = (strpos($value, '-') !== false || strpos($value, '_') !== false);
            $hasNoSpaces     = preg_match('/\s/', $value) === false;
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
     * Hydrates object metadata fields based on schema configuration.
     *
     * This method uses the schema configuration to set metadata fields on the object entity
     * based on the object data. It supports:
     * - Simple field mapping using dot notation paths (e.g., 'contact.email', 'title')
     * - Twig-like concatenation for combining multiple fields (e.g., '{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}')
     * - All metadata fields: name, description, summary, image, slug, published, depublished
     *
     * Schema configuration example:
     * ```json
     * {
     *   "objectNameField": "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}",
     *   "objectDescriptionField": "beschrijving",
     *   "objectSummaryField": "beschrijvingKort",
     *   "objectImageField": "afbeelding",
     *   "objectSlugField": "naam",
     *   "objectPublishedField": "publicatieDatum",
     *   "objectDepublishedField": "einddatum"
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
                            'Failed to load file for objectImageField',
                            [
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
                    'File ID detected for objectImageField - file loading not yet implemented',
                    [
                        'app'    => 'openregister',
                        'fileId' => $imageValue,
                    ]
                );
            } else if (is_string($imageValue) === true && trim($imageValue) !== '') {
                // Regular string URL.
                $entity->setImage(trim($imageValue));
            }//end if
        }//end if

        // Published field mapping.
        if (($config['objectPublishedField'] ?? null) !== null) {
            $publishedPath = $config['objectPublishedField'];
            $published     = $this->metaHydrationHandler->extractMetadataValue(
                data: $objectData,
                fieldPath: $publishedPath
            );
            if ($published !== null && trim($published) !== '') {
                try {
                    $publishedDate = new DateTime(trim($published));
                    $entity->setPublished($publishedDate);
                } catch (Exception $e) {
                    // Log warning but don't fail the entire operation.
                    $this->logger->warning(
                        'Invalid published date format',
                        [
                            'value' => $published,
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }
        }//end if

        // Depublished field mapping.
        if (($config['objectDepublishedField'] ?? null) !== null) {
            $depublishedPath = $config['objectDepublishedField'];
            $depublished     = $this->metaHydrationHandler->extractMetadataValue(
                data: $objectData,
                fieldPath: $depublishedPath
            );
            if ($depublished !== null && trim($depublished) !== '') {
                try {
                    $depublishedDate = new DateTime(trim($depublished));
                    $entity->setDepublished($depublishedDate);
                } catch (Exception $e) {
                    // Log warning but don't fail the entire operation.
                    $this->logger->warning(
                        'Invalid depublished date format',
                        [
                            'value' => $depublished,
                            'error' => $e->getMessage(),
                        ]
                    );
                }//end try
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
            }

            if ($shouldApplyDefault === true) {
                $defaultValues[$key] = $defaultValue;
            }
        }//end foreach

        // Render twig templated default values.
        $renderedDefaults = [];
        foreach ($defaultValues as $key => $defaultValue) {
            try {
                if (is_string($defaultValue) === true
                    && str_contains(haystack: $defaultValue, needle: '{{') === true
                    && str_contains(haystack: $defaultValue, needle: '}}') === true
                ) {
                    $template    = $this->twig->createTemplate($defaultValue);
                    $objectArray = $objectEntity->getObjectArray();
                    $renderedDefaults[$key] = $template->render($objectArray);
                }

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
     * Generates a slug for an object based on its data and schema configuration.
     *
     * @param array  $data   The object data
     * @param Schema $schema The schema containing the configuration
     *
     * @return null|string The generated slug or null if no slug could be generated
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
            $slug = $this->createSlug($value);

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
            if ($this->isEffectivelyEmptyObject($objectData) === true) {
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
            // Skip if property not present, empty, or not an array.
            $propIsSet   = isset($data[$property]);
            $propIsEmpty = empty($data[$property]) === true;
            $propIsArray = is_array($data[$property]);
            if ($propIsSet === false || $propIsEmpty === true || $propIsArray === false) {
                continue;
            }

            try {
                $createdUuids = $this->cascadeMultipleObjects(
                    objectEntity: $objectEntity,
                    property: $definition,
                    propData: $data[$property]
                );

                // Check if this is a related-object handling (stores UUIDs in parent).
                $objHandling   = $definition['objectConfiguration']['handling'] ?? null;
                $itemsHandling = $definition['items']['objectConfiguration']['handling'] ?? null;
                $isRelatedObject = $objHandling === 'related-object' || $itemsHandling === 'related-object';

                // For related-object handling: always store UUIDs in parent property.
                // This ensures the parent object contains references to the sub-objects.
                // Note: cascadeMultipleObjects skips existing UUIDs and returns only newly created ones.
                // So we need to preserve existing UUIDs from the original data.
                if ($isRelatedObject === true) {
                    // Collect existing UUIDs that were passed through (not created, just referenced).
                    $existingUuids = array_filter(
                        $data[$property] ?? [],
                        fn($item) => is_string($item) && \Symfony\Component\Uid\Uuid::isValid($item)
                    );
                    // Merge existing UUIDs with newly created ones.
                    $data[$property] = array_values(array_unique(array_merge($existingUuids, $createdUuids)));
                } else {
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
                }
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
     */
    private function cascadeMultipleObjects(ObjectEntity $objectEntity, array $property, array $propData): array
    {
        if (array_is_list($propData) === false) {
            return [];
        }

        // Filter out empty or invalid objects.
        $validObjects = array_filter(
            $propData,
            function ($object) {
                return (is_array($object) === true && empty($object) === false
                        && !(count($object) === 1 && (($object['id'] ?? null) !== null) && empty($object['id']) === true))
                    || (is_string($object) === true && Uuid::isValid($object) === true);
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

        $createdUuids = [];
        foreach ($validObjects as $object) {
            if (is_string($object) === true && Uuid::isValid($object) === true) {
                continue;
            }

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
        $schemaId = $this->resolveSchemaReference($definition['$ref']);
        if ($schemaId === null) {
            throw new Exception("Invalid schema reference: {$definition['$ref']}");
        }

        try {
            $savedObject = $this->saveObject(register: $register, schema: $schemaId, data: $object, uuid: $uuid);
            $savedUuid = $savedObject->getUuid();

            // Track the created sub-object for inclusion in @self.objects.
            // This allows the parent response to include the full sub-object data.
            if ($savedUuid !== null) {
                $this->createdSubObjects[$savedUuid] = $savedObject->jsonSerialize();
            }

            return $savedUuid;
        } catch (Exception $e) {
            throw $e;
        }
    }//end cascadeSingleObject()

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
            $resolvedSchemaId = $this->resolveSchemaReference($targetSchema);
            if ($resolvedSchemaId === null) {
                continue;
            }

            // Ensure targetUuids is an array.
            if (is_array($targetUuids) === false) {
                $targetUuids = [$targetUuids];
            }

            // Filter out empty or invalid UUIDs.
            $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
            $validUuids  = array_filter(
                $targetUuids,
                function ($uuid) use ($uuidPattern) {
                    $isNotEmpty = empty($uuid) === false && is_string($uuid) && trim($uuid) !== '';
                    return $isNotEmpty && preg_match($uuidPattern, $uuid);
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

        // Try to update existing object if UUID provided.
        if ($uuid !== null) {
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
            }
        }

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
    }//end saveObject()

    /**
     * Extract UUID and @self metadata from data.
     *
     * @param array       $data          Object data
     * @param string|null $uuid          Provided UUID
     * @param array|null  $uploadedFiles Uploaded files
     *
     * @return array{0: string|null, 1: array, 2: array} [uuid, selfData, cleanedData]
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
     */
    private function resolveSchemaAndRegister(
        Schema | int | string $schema,
        Register | int | string | null $register
    ): array {
        // Initialize IDs before conditional assignment.
        $schemaId   = null;
        $registerId = null;

        // Resolve schema.
        if ($schema instanceof Schema === true) {
            $schemaId = $schema->getId();
        } else if (is_string($schema) === true) {
            // Resolve schema reference if it's a string.
            $schemaId = $this->resolveSchemaReference($schema);
            if ($schemaId === null) {
                throw new Exception("Could not resolve schema reference: $schema");
            }

            $schema = $this->schemaMapper->find(id: $schemaId);
        } else if (is_int($schema) === true) {
            // It's an integer ID.
            $schemaId = $schema;
            $schema   = $this->schemaMapper->find(id: $schema);
        }

        // Resolve register.
        if ($register instanceof Register === true) {
            $registerId = $register->getId();
        } else if (is_string($register) === true) {
            // Resolve register reference if it's a string.
            $registerId = $this->resolveRegisterReference($register);
            if ($registerId === null) {
                throw new Exception("Could not resolve register reference: $register");
            }

            $register = $this->registerMapper->find(id: $registerId);
        } else if (is_int($register) === true) {
            // It's an integer ID - fetch the register.
            $registerId = $register;
            $register   = $this->registerMapper->find(id: $register);
        } else if ($register === null) {
            // Register is NULL (e.g., for seedData objects) - leave as NULL.
            $registerId = null;
        }

        return [$schema, $schemaId, $register, $registerId];
    }//end resolveSchemaAndRegister()

    /**
     * Find and validate existing object for update.
     *
     * @param string $uuid Object UUID
     *
     * @return ObjectEntity|null Existing object or null if not found
     *
     * @throws Exception If object is locked by another user
     */

    /**
     * Find and validate existing object by UUID.
     *
     * @param string        $uuid     Object UUID.
     * @param Register|null $register Optional register for magic mapper routing.
     * @param Schema|null   $schema   Optional schema for magic mapper routing.
     *
     * @return ObjectEntity|null Existing object or null if not found.
     *
     * @throws Exception If object is locked by another user.
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

        // Update the object.
        return $this->updateObject(
            register: $register,
            schema: $schema,
            data: $data,
            existingObject: $preparedObject,
            folderId: $folderId,
            silent: $silent
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

        // If not persisting, return the prepared object.
        if ($persist === false) {
            return $preparedObject;
        }

        // Save the object to database FIRST (so it gets an ID).
        $savedEntity = $this->objectEntityMapper->insert(entity: $preparedObject, register: $register, schema: $schema);

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
     */
    private function processFilePropertiesWithRollback(
        ObjectEntity $savedEntity,
        array &$data,
        Register $register,
        Schema $schema
    ): ObjectEntity {
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
                $savedEntity->setObject($data);

                // Clear image metadata if objectImageField is a file property.
                $this->clearImageMetadataIfFileProperty(
                    savedEntity: $savedEntity,
                    schema: $schema
                );

                $savedEntity = $this->objectEntityMapper->update(entity: $savedEntity, register: $register, schema: $schema);
            }

            return $savedEntity;
        } catch (Exception $e) {
            // ROLLBACK: Delete the object if file processing failed.
            $this->logger->warning(
                'File processing failed, rolling back object creation',
                [
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

        // Auto-publish logic: Set published date to now if autoPublish is enabled in schema configuration.
        // And no published date has been set yet (either from field mapping or explicit data).
        $config = $schema->getConfiguration();
        if (($config['autoPublish'] ?? null) !== null && $config['autoPublish'] === true) {
            if ($objectEntity->getPublished() !== null) {
                $this->logger->debug(
                    'Object already has published date, skipping auto-publish',
                    [
                        'uuid'          => $objectEntity->getUuid(),
                        'publishedDate' => $objectEntity->getPublished()->format('Y-m-d H:i:s'),
                    ]
                );
            }

            if ($objectEntity->getPublished() === null) {
                $this->logger->debug(
                    'Auto-publishing object on creation',
                    [
                        'uuid'        => $objectEntity->getUuid(),
                        'schema'      => $schema->getTitle(),
                        'autoPublish' => true,
                    ]
                );
                $objectEntity->setPublished(new DateTime());
            }
        }//end if

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

        // Prepare the data.
        $preparedData = $this->prepareObjectData(objectEntity: $existingObject, schema: $schema, data: $data);

        // Set the prepared data.
        $existingObject->setObject($preparedData);

        // Hydrate name and description from schema configuration.
        $this->hydrateObjectMetadata(entity: $existingObject, schema: $schema);

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
     */
    private function setSelfMetadata(ObjectEntity $objectEntity, array $selfData, array $data=[]): void
    {
        // Extract and set slug property if present (check both @self and data).
        $slug = $selfData['slug'] ?? $data['slug'] ?? null;
        if (empty($slug) === false) {
            $objectEntity->setSlug($slug);
        }

        // Extract and set published property if present.
        $this->logger->debug(
            'Processing published field in SaveObject',
            [
                'selfDataKeys' => array_keys($selfData),
            ]
        );

        if (array_key_exists('published', $selfData) === true) {
            $publishedValue = $selfData['published'];
            $isEmpty        = empty($publishedValue);

            $this->logger->debug(
                'Published field found in object data',
                [
                    'publishedValue' => $publishedValue,
                    'isEmpty'        => $isEmpty,
                ]
            );

            if (empty($publishedValue) === false) {
                try {
                    // Convert string to DateTime if it's a valid date string.
                    if (is_string($publishedValue) === true) {
                        $this->logger->debug(
                            'Setting published date on object entity',
                            [
                                'publishedValue' => $publishedValue,
                            ]
                        );
                        $objectEntity->setPublished(new DateTime($publishedValue));
                    }
                } catch (Exception $exception) {
                    $this->logger->warning(
                        'Failed to convert published date',
                        [
                            'publishedValue' => $publishedValue,
                            'error'          => $exception->getMessage(),
                        ]
                    );
                    // Silently ignore invalid date formats.
                }//end try
            }//end if

            if (empty($publishedValue) === true) {
                $this->logger->debug('Published value is empty, setting to null');
                $objectEntity->setPublished(null);
            }//end if
        }//end if

        if (array_key_exists('published', $selfData) === false) {
            $this->logger->debug('No published field found in selfData, setting to existing value');
            $objectEntity->setPublished($objectEntity->getPublished());
        }//end if

        // Extract and set depublished property if present.
        if (array_key_exists('depublished', $selfData) === false || empty($selfData['depublished']) === true) {
            $objectEntity->setDepublished(null);
        }

        if (array_key_exists('depublished', $selfData) === true && empty($selfData['depublished']) === false) {
            try {
                // Convert string to DateTime if it's a valid date string.
                if (is_string($selfData['depublished']) === true) {
                    $objectEntity->setDepublished(new DateTime($selfData['depublished']));
                }
            } catch (Exception $exception) {
                // Silently ignore invalid date formats.
            }
        }

        if (array_key_exists('owner', $selfData) === true && empty($selfData['owner']) === false) {
            $objectEntity->setOwner($selfData['owner']);
        }

        if (array_key_exists('organisation', $selfData) === true && empty($selfData['organisation']) === false) {
            $objectEntity->setOrganisation($selfData['organisation']);
        }
    }//end setSelfMetadata()

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

        // Apply cascading operations.
        $data = $this->cascadeObjects(objectEntity: $objectEntity, schema: $schema, data: $data);
        $data = $this->handleInverseRelationsWriteBack(objectEntity: $objectEntity, schema: $schema, data: $data);

        // Apply default values (including slug generation).
        $data = $this->setDefaultValues(objectEntity: $objectEntity, schema: $schema, data: $data);

        return $data;
    }//end prepareObjectData()

    /**
     * Updates an existing object.
     *
     * @param Register|int|string $register       The register containing the object.
     * @param Schema|int|string   $schema         The schema to validate against.
     * @param array               $data           The updated object data.
     * @param ObjectEntity        $existingObject The existing object to update.
     * @param int|null            $folderId       The folder ID to set on the object (optional).
     * @param bool                $silent         Whether to skip audit trail creation and events (default: false).
     *
     * @return ObjectEntity The updated object entity.
     *
     * @throws Exception If there is an error during update.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex update logic with file handling
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple update paths and file processing
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive update with file handling
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Silent flag needed for audit trail control
     */
    public function updateObject(
        Register | int | string $register,
        Schema | int | string $schema,
        array $data,
        ObjectEntity $existingObject,
        ?int $folderId=null,
        bool $silent=false
    ): ObjectEntity {

        // Store the old state for audit trail.
        $oldObject = clone $existingObject;

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

        // Update the object properties.
        $preparedObject->setRegister((string) $registerId);
        $preparedObject->setSchema((string) $schemaId);
        $preparedObject->setUpdated(new DateTime());

        // Log that we're about to update using UnifiedObjectMapper
        $this->logger->critical('[SaveObject] About to update object using UnifiedObjectMapper', [
            'app' => 'openregister',
            'uuid' => $preparedObject->getUuid(),
            'oldStatus' => $oldObject->getObject()['status'] ?? 'unknown',
            'newStatus' => $preparedObject->getObject()['status'] ?? 'unknown',
            'mapperClass' => get_class($this->unifiedObjectMapper)
        ]);

        // Save the object to database using UnifiedObjectMapper.
        // This ensures proper event dispatching for both magic-mapped and blob storage objects.
        $updatedEntity = $this->unifiedObjectMapper->update(entity: $preparedObject, register: $register, schema: $schema);

        $this->logger->critical('[SaveObject] Object updated successfully', [
            'app' => 'openregister',
            'uuid' => $updatedEntity->getUuid()
        ]);

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
            if ($this->isValueNotEmpty($value) === true) {
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
                return $this->isEffectivelyEmptyObject($value) === false;
            }

            // For indexed arrays, check if any element is not empty.
            foreach ($value as $item) {
                if ($this->isValueNotEmpty($item) === true) {
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
     */
    private function isAuditTrailsEnabled(): bool
    {
        try {
            $retentionSettings = $this->settingsService->getRetentionSettingsOnly();
            return $retentionSettings['auditTrailsEnabled'] ?? true;
        } catch (Exception $e) {
            // If we can't get settings, default to enabled for safety.
            $this->logger->warning(
                'Failed to check audit trails setting, defaulting to enabled',
                ['error' => $e->getMessage()]
            );
            return true;
        }
    }//end isAuditTrailsEnabled()
}//end class
