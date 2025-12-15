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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\SchemaCacheService;
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
 */
class SaveObject
{

    private const URL_PATH_IDENTIFIER = 'openregister.objects.show';

    private Environment $twig;


    /**
     * Constructor for SaveObject handler.
     *
     * @param ObjectEntityMapper       $objectEntityMapper       Object entity data mapper.
     * @param MetadataHydrationHandler $metadataHydrationHandler Handler for metadata extraction.
     * @param FilePropertyHandler      $filePropertyHandler      Handler for file property operations.
     * @param FileService              $fileService              File service for managing files.
     * @param IUserSession             $userSession              User session service.
     * @param AuditTrailMapper         $auditTrailMapper         Audit trail mapper for logging changes.
     * @param SchemaMapper             $schemaMapper             Schema mapper for schema operations.
     * @param RegisterMapper           $registerMapper           Register mapper for register operations.
     * @param IURLGenerator            $urlGenerator             URL generator service.
     * @param OrganisationService      $organisationService      Service for organisation operations.
     * @param CacheHandler             $cacheHandler             Object cache service for entity and query caching.
     * @param SettingsService          $settingsService          Settings service for accessing trail settings.
     * @param LoggerInterface          $logger                   Logger interface for logging operations.
     * @param ArrayLoader              $arrayLoader              Twig array loader for template rendering.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly MetadataHydrationHandler $metadataHydrationHandler,
        private readonly FilePropertyHandler $filePropertyHandler,
        private readonly IUserSession $userSession,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly IURLGenerator $urlGenerator,
        private readonly CacheHandler $cacheHandler,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        ArrayLoader $arrayLoader,
    ) {
        $this->twig = new Environment($arrayLoader);

    }//end __construct()


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
     */
    private function resolveSchemaReference(string $reference): string|null
    {
        if (empty($reference) === true) {
            return null;
        }

        // Remove query parameters if present (e.g., "schema?key=value" -> "schema").
        $cleanReference = $this->removeQueryParameters($reference);

        // First, try direct ID lookup (numeric ID or UUID).
        if (is_numeric($cleanReference) === true || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $cleanReference) === true) {
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
            $schema = $this->schemaMapper->findBySlug($slug);
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
     */
    private function resolveRegisterReference(string $reference): string|null
    {
        if (empty($reference) === true) {
            return null;
        }

        // First, try direct ID lookup (numeric ID or UUID).
        if (is_numeric($reference) === true || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $reference) === true) {
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
            // RegisterMapper doesn't have findBySlug, use searchObjects or find instead.
            // Try to find register by slug using searchObjects.
            $registers = $this->registerMapper->findAll();
            foreach ($registers as $register) {
                if ($register->getSlug() === $slug) {
                    return (string) $register->getId();
                }
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
     */
    private function scanForRelations(array $data, string $prefix='', ?Schema $schema=null): array
    {
        $relations = [];

        try {
            // Get schema properties if available.
            $schemaProperties = null;
            if ($schema !== null) {
                try {
                    $schemaObject     = json_decode(json_encode($schema->getSchemaObject($this->urlGenerator)), associative: true);
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

                if (($prefix !== '') === true) {
                    $currentPath = $prefix.'.'.$key;
                } else {
                    $currentPath = $key;
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
                    } else {
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
                    $shouldTreatAsRelation = false;

                    // Check schema property configuration first.
                    if (($schemaProperties !== null) === true && (($schemaProperties[$key] ?? null) !== null)) {
                        $propertyConfig = $schemaProperties[$key];
                        $propertyType   = $propertyConfig['type'] ?? '';
                        $propertyFormat = $propertyConfig['format'] ?? '';

                        // Check for explicit relation types.
                        if ($propertyType === 'text' && in_array($propertyFormat, ['uuid', 'uri', 'url']) === true) {
                            $shouldTreatAsRelation = true;
                        } else if ($propertyType === 'object') {
                            // Object properties with string values are always relations.
                            $shouldTreatAsRelation = true;
                        }
                    }

                    // If not determined by schema, check for reference patterns.
                    if ($shouldTreatAsRelation === false) {
                        $shouldTreatAsRelation = $this->isReference($value);
                    }

                    if ($shouldTreatAsRelation === true) {
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
            if ((strpos($value, '-') !== false || strpos($value, '_') !== false)
                && preg_match('/\s/', $value) === false
                && in_array(strtolower($value), ['applicatie', 'systeemsoftware', 'open-source', 'closed-source'], true) === false
            ) {
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
     * @see website/docs/developers/import-flow.md for complete import flow documentation
     * @see website/docs/core/schema.md for schema configuration details
     *
     * @param ObjectEntity $entity The entity to hydrate
     * @param Schema       $schema The schema containing the configuration
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    public function hydrateObjectMetadata(ObjectEntity $entity, Schema $schema): void
    {
        $config     = $schema->getConfiguration();
        $objectData = $entity->getObject();

        // Delegate simple metadata extraction (name, description, summary, slug) to handler.
        $this->metadataHydrationHandler->hydrateObjectMetadata(entity: $entity, schema: $schema);

        // Image field mapping.
        if (($config['objectImageField'] ?? null) !== null) {
            // First check if the field points to a file object.
            $imageValue = $this->metadataHydrationHandler->getValueFromPath(data: $objectData, path: $config['objectImageField']);

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
                        $fileNode = null
                        // TODO: fileService->getFile(object: $entity, file: (int) $firstElement);
                        if ($fileNode !== null) {
                            $fileData = null
                            // TODO: fileService->formatFile($fileNode);
                            // IMPORTANT: Object image requires public access.
                            // If file is not published, auto-publish it.
                            if (empty($fileData['downloadUrl']) === true) {
                                $this->logger->warning(
                                    'File configured as objectImageField is not published. Auto-publishing file.',
                                    [
                                        'app'      => 'openregister',
                                        'fileId'   => $firstElement,
                                        'objectId' => $entity->getId(),
                                        'field'    => $config['objectImageField'],
                                    ]
                                );
                                // Publish the file.
                                null
                                // TODO: fileService->publishFile(object: $entity, file: $fileNode->getId());
                                // Re-fetch file data after publishing.
                                $fileData = null
                                // TODO: fileService->formatFile($fileNode);
                            }

                            if (($fileData['downloadUrl'] ?? null) !== null) {
                                $entity->setImage($fileData['downloadUrl']);
                            }
                        }//end if
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
                // Single file ID - load file and get its download URL.
                try {
                    $fileNode = null
                    // TODO: fileService->getFile(object: $entity, file: (int) $imageValue);
                    if ($fileNode !== null) {
                        $fileData = null
                        // TODO: fileService->formatFile($fileNode);
                        // IMPORTANT: Object image requires public access.
                        // If file is not published, auto-publish it.
                        if (empty($fileData['downloadUrl']) === true) {
                            $this->logger->warning(
                                'File configured as objectImageField is not published. Auto-publishing file.',
                                [
                                    'app'      => 'openregister',
                                    'fileId'   => $imageValue,
                                    'objectId' => $entity->getId(),
                                    'field'    => $config['objectImageField'],
                                ]
                            );
                            // Publish the file.
                            null
                            // TODO: fileService->publishFile(object: $entity, file: $fileNode->getId());
                            // Re-fetch file data after publishing.
                            $fileData = null
                            // TODO: fileService->formatFile($fileNode);
                        }

                        if (($fileData['downloadUrl'] ?? null) !== null) {
                            $entity->setImage($fileData['downloadUrl']);
                        }
                    }//end if
                } catch (Exception $e) {
                    // File not found or error loading - skip.
                    $this->logger->error(
                            'Failed to load file for objectImageField',
                            [
                                'app'    => 'openregister',
                                'fileId' => $imageValue,
                                'error'  => $e->getMessage(),
                            ]
                            );
                }//end try
            } else if (is_array($imageValue) === true) {
                // Check for downloadUrl first (preferred).
                // Use array_key_exists to safely check and access array keys.
                // Add type assertion to help Psalm understand this is a non-empty array.
                /*
                 * @var array<string, mixed> $imageValue
                 */
                if (array_key_exists('downloadUrl', $imageValue) === true) {
                    $downloadUrlValue = $imageValue['downloadUrl'];
                    if (is_string($downloadUrlValue) === true) {
                        $downloadUrl = trim($downloadUrlValue);
                        if ($downloadUrl !== '') {
                            // Single file object - use its downloadUrl.
                            $entity->setImage($downloadUrl);
                        }
                    }
                } else if (array_key_exists('accessUrl', $imageValue) === true) {
                    $accessUrlValue = $imageValue['accessUrl'];
                    if (is_string($accessUrlValue) === true) {
                        $accessUrl = trim($accessUrlValue);
                        if ($accessUrl !== '') {
                            $entity->setImage($accessUrl);
                        }
                    }
                }
            } else if (is_string($imageValue) === true && trim($imageValue) !== '') {
                // Regular string URL.
                $entity->setImage(trim($imageValue));
            }//end if
        }//end if

        // Published field mapping.
        if (($config['objectPublishedField'] ?? null) !== null) {
            $published = $this->metadataHydrationHandler->extractMetadataValue(data: $objectData, fieldPath: $config['objectPublishedField']);
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
        }

        // Depublished field mapping.
        if (($config['objectDepublishedField'] ?? null) !== null) {
            $depublished = $this->metadataHydrationHandler->extractMetadataValue(data: $objectData, fieldPath: $config['objectDepublishedField']);
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
                }
            }
        }

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
     * @return mixed
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
     * Extracts metadata value from object data with support for twig-like concatenation.
     *
     * This method supports two formats:
     * 1. Simple dot notation paths: "naam", "contact.email"
     * 2. Twig-like templates: "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}"
     *
     * For twig-like templates, it extracts field names from {{ }} syntax and concatenates
     * their values with spaces, handling empty/null values gracefully.
     *
     * @param array  $data      The object data
     * @param string $fieldPath The field path or twig-like template
     *
     * @return string|null The extracted/concatenated value, or null if not found
     *
     * @psalm-return   string|null
     * @phpstan-return string|null
     */
    private function extractMetadataValue(array $data, string $fieldPath): ?string
    {
        // Check if this is a twig-like template with {{ }} syntax.
        if (str_contains($fieldPath, '{{') === true && str_contains($fieldPath, '}}') === true) {
            return $this->processTwigLikeTemplate(data: $data, template: $fieldPath);
        }

        // Simple field path - use existing method.
        return $this->getValueFromPath(data: $data, path: $fieldPath);

    }//end extractMetadataValue()


    /**
     * Processes twig-like templates by extracting field values and concatenating them.
     *
     * This method parses templates like "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}"
     * and replaces each {{ fieldName }} with the corresponding value from the data.
     * Empty or null values are handled gracefully and excess whitespace is cleaned up.
     *
     * @param array  $data     The object data
     * @param string $template The twig-like template string
     *
     * @return null|string
     *
     * @psalm-return   string|null
     * @phpstan-return string|null
     */
    private function processTwigLikeTemplate(array $data, string $template): string|null
    {
        // Extract all {{ fieldName }} patterns.
        preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $template, $matches);

        if (empty($matches[0]) === true) {
            return null;
        }

        $result    = $template;
        $hasValues = false;

        // Replace each {{ fieldName }} with its value.
        foreach ($matches[0] as $index => $fullMatch) {
            $fieldName = trim($matches[1][$index]);
            $value     = $this->getValueFromPath(data: $data, path: $fieldName);

            if ($value !== null && trim($value) !== '') {
                $result    = str_replace($fullMatch, trim($value), $result);
                $hasValues = true;
            } else {
                // Replace with empty string for missing/empty values.
                $result = str_replace($fullMatch, '', $result);
            }
        }

        if ($hasValues === false) {
            return null;
        }

        // Clean up excess whitespace and normalize spaces.
        $result = preg_replace('/\s+/', ' ', $result);
        $result = trim($result);

        if ($result !== '') {
            return $result;
        } else {
            return null;
        }

    }//end processTwigLikeTemplate()


    /**
     * Creates a URL-friendly slug from a metadata value.
     *
     * This method is different from the generateSlug method used in setDefaultValues
     * as it works with already extracted metadata values rather than generating defaults.
     * It creates a slug without adding timestamps to avoid conflicts with schema-based slugs.
     *
     * @param string $value The value to convert to a slug
     *
     * @return string|null The generated slug or null if value is empty
     *
     * @psalm-return   string|null
     * @phpstan-return string|null
     */
    private function createSlugFromValue(string $value): ?string
    {
        if (empty($value) === true || trim($value) === '') {
            return null;
        }

        // Use the existing createSlug method for consistency.
        return $this->createSlug(trim($value));

    }//end createSlugFromValue()


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
            if ($defaultBehavior === 'falsy') {
                // Apply default if property is missing, null, empty string, or empty array/object.
                $shouldApplyDefault = isset($data[$key]) === false
                    || $data[$key] === null
                    || $data[$key] === ''
                    || (is_array($data[$key]) === true && empty($data[$key]));
            } else {
                // Default behavior: only apply if property is missing or null.
                $shouldApplyDefault = isset($data[$key]) === false || $data[$key] === null;
            }

            if ($shouldApplyDefault === true) {
                $defaultValues[$key] = $defaultValue;
            }
        }//end foreach

        // Render twig templated default values.
        $renderedDefaultValues = [];
        foreach ($defaultValues as $key => $defaultValue) {
            try {
                if (is_string($defaultValue) === true && str_contains(haystack: $defaultValue, needle: '{{') === true && str_contains(haystack: $defaultValue, needle: '}}') === true) {
                    $renderedDefaultValues[$key] = $this->twig->createTemplate($defaultValue)->render($objectEntity->getObjectArray());
                } else {
                    $renderedDefaultValues[$key] = $defaultValue;
                }
            } catch (Exception $e) {
                $renderedDefaultValues[$key] = $defaultValue;
                // Use original value if template fails.
            }
        }

        // Merge in this order:.
        // 1. Start with existing data.
        // 2. Apply rendered default values (only for properties that should get defaults).
        // 3. Override with constant values (constants always take precedence).
        $mergedData = array_merge($data, $renderedDefaultValues, $constantValues);

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
                        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property.
        $objectProperties = array_filter(
          $properties,
          function (array $property) {
            // Skip if writeBack is enabled (handled by write-back method).
            if (($property['writeBack'] ?? null) !== null && $property['writeBack'] === true) {
                return false;
            }

            return $property['type'] === 'object'
                && (($property['$ref'] ?? null) !== null)
                && (isset($property['inversedBy']) ||
                    (isset($property['objectConfiguration']['handling']) && $property['objectConfiguration']['handling'] === 'cascade'));
          }
          );

        // Same logic for array properties - cascade if they have inversedBy OR cascade handling.
        // BUT skip if they have writeBack enabled (those are handled by write-back method).
                        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property.
        $arrayObjectProperties = array_filter(
          $properties,
          function (array $property) {
            // Skip if writeBack is enabled (handled by write-back method).
            if ((($property['writeBack'] ?? null) !== null && $property['writeBack'] === true)
                || (($property['items']['writeBack'] ?? null) !== null && $property['items']['writeBack'] === true)
            ) {
                return false;
            }

            return $property['type'] === 'array'
                && (isset($property['$ref']) || (($property['items']['$ref'] ?? null) !== null))
                && (isset($property['inversedBy']) || (($property['items']['inversedBy'] ?? null) !== null) ||
                    (isset($property['objectConfiguration']['handling']) && ($property['objectConfiguration']['handling'] === 'cascade'|| $property['objectConfiguration']['handling'] === 'related-object')) ||
                    (isset($property['items']['objectConfiguration']['handling']) && ($property['items']['objectConfiguration']['handling'] === 'cascade' || $property['objectConfiguration']['handling'] === 'related-object')));
          }
          );

        // Process single object properties that need cascading.
        foreach ($objectProperties as $property => $definition) {
            // Skip if property not present in data.
            if (isset($data[$property]) === false) {
                continue;
            }

            // Skip if the property is empty or not an array/object.
            if (empty($data[$property]) === true || (is_array($data[$property]) === false && is_object($data[$property]) === false)) {
                continue;
            }

            // Convert object to array if needed.
            if (is_object($data[$property]) === true) {
                $objectData = (array) $data[$property];
            } else {
                $objectData = $data[$property];
            }

            // Skip if the object is effectively empty (only contains empty values).
            if ($this->isEffectivelyEmptyObject($objectData) === true) {
                continue;
            }

            try {
                $createdUuid = $this->cascadeSingleObject(objectEntity: $objectEntity, definition: $definition, object: $objectData);

                // Handle the result based on whether inversedBy is present.
                if (($definition['inversedBy'] ?? null) !== null) {
                    // With inversedBy: check if writeBack is enabled.
                    if (($definition['writeBack'] ?? null) !== null && $definition['writeBack'] === true) {
                        // Keep the property for write-back processing.
                        $data[$property] = $createdUuid;
                    } else {
                        // Remove the property (traditional cascading).
                        unset($data[$property]);
                    }
                } else {
                    // Without inversedBy: store the created object's UUID.
                    $data[$property] = $createdUuid;
                }
            } catch (Exception $e) {
                // Continue with other properties even if one fails.
            }
        }//end foreach

        // Process array object properties that need cascading.
        foreach ($arrayObjectProperties as $property => $definition) {
            // Skip if property not present, empty, or not an array.
            if (isset($data[$property]) === false || empty($data[$property]) === true || is_array($data[$property]) === false) {
                continue;
            }

            try {
                $createdUuids = $this->cascadeMultipleObjects(objectEntity: $objectEntity, property: $definition, propData: $data[$property]);

                // Handle the result based on whether inversedBy is present.
                if (($definition['inversedBy'] ?? null) !== null || (($definition['items']['inversedBy'] ?? null) !== null) === true) {
                    // With inversedBy: check if writeBack is enabled.
                    $hasWriteBack = (($definition['writeBack'] ?? null) !== null && $definition['writeBack'] === true) ||
                                   (isset($definition['items']['writeBack']) && $definition['items']['writeBack'] === true);

                    if ($hasWriteBack === true) {
                        // Keep the property for write-back processing.
                        $data[$property] = $createdUuids;
                    } else {
                        // Remove the property (traditional cascading).
                        unset($data[$property]);
                    }
                } else {
                    // Without inversedBy: store the created objects' UUIDs.
                    $data[$property] = $createdUuids;
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
                return (is_array($object) === true && empty($object) === false && !(count($object) === 1 && (($object['id'] ?? null) !== null) && empty($object['id']) === true)) || (is_string($object) === true && Uuid::isValid($object) === true);
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
                $uuid = $this->cascadeSingleObject(objectEntity: $objectEntity, definition: $property['items'], object: $object);
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
     * @param  ObjectEntity $objectEntity The parent object.
     * @param  array        $definition   The definition of the property the cascaded object is found in.
     * @param  array        $object       The object to cascade.
     * @return string|null  The UUID of the created object, or null if no object was created
     * @throws Exception
     */
    private function cascadeSingleObject(ObjectEntity $objectEntity, array $definition, array $object): ?string
    {
        // Validate that we have the necessary configuration.
        if (isset($definition['$ref']) === false) {
            return null;
        }

        // Skip if object is empty or doesn't contain actual data.
        if (empty($object) === true || (count($object) === 1 && (($object['id'] ?? null) !== null) && empty($object['id']) === true)) {
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
            } else {
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
        } else {
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
            return $savedObject->getUuid();
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
                        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property.
        $writeBackProperties = array_filter(
          $properties,
          function (array $property) {
            // Check for inversedBy with writeBack at property level.
            if (($property['inversedBy'] ?? null) !== null && (($property['writeBack'] ?? null) !== null) && $property['writeBack'] === true) {
                return true;
            }

            // Check for inversedBy with writeBack in array items.
            if ($property['type'] === 'array' && (($property['items']['inversedBy'] ?? null) !== null) && (($property['items']['writeBack'] ?? null) !== null) && $property['items']['writeBack'] === true) {
                return true;
            }

            // Check for inversedBy with writeBack at array property level (for array of objects).
            if ($property['type'] === 'array' && (($property['items']['inversedBy'] ?? null) !== null) && (($property['writeBack'] ?? null) !== null) && $property['writeBack'] === true) {
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
            if (($definition['inversedBy'] ?? null) !== null && (($definition['writeBack'] ?? null) !== null) && $definition['writeBack'] === true) {
                $inverseProperty  = $definition['inversedBy'];
                $targetSchema     = $definition['$ref'] ?? null;
                $targetRegister   = $definition['register'] ?? $objectEntity->getRegister();
                $removeFromSource = $definition['removeAfterWriteBack'] ?? false;
            } else if (($definition['items']['inversedBy'] ?? null) !== null && (($definition['items']['writeBack'] ?? null) !== null) && $definition['items']['writeBack'] === true) {
                $inverseProperty  = $definition['items']['inversedBy'];
                $targetSchema     = $definition['items']['$ref'] ?? null;
                $targetRegister   = $definition['items']['register'] ?? $objectEntity->getRegister();
                $removeFromSource = $definition['items']['removeAfterWriteBack'] ?? false;
            } else if (($definition['items']['inversedBy'] ?? null) !== null && (($definition['writeBack'] ?? null) !== null) && $definition['writeBack'] === true) {
                // Handle array of objects with writeBack at array level.
                $inverseProperty  = $definition['items']['inversedBy'];
                $targetSchema     = $definition['items']['$ref'] ?? null;
                $targetRegister   = $definition['register'] ?? $objectEntity->getRegister();
                $removeFromSource = $definition['removeAfterWriteBack'] ?? false;
            }

            // Skip if we don't have the necessary configuration.
            if (($inverseProperty === false || $inverseProperty === null) === true || ($targetSchema === false || $targetSchema === null) === true) {
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
            $validUuids = array_filter(
            $targetUuids,
           function ($uuid) {
                return empty($uuid) === false && is_string($uuid) && trim($uuid) !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
           }
            );

            if (empty($validUuids) === true) {
                continue;
            }

            // Update each target object.
            foreach ($validUuids as $targetUuid) {
                try {
                    // Find the target object.
                    $targetObject = $this->objectEntityMapper->find($targetUuid);
                    /*
                     * @psalm-suppress TypeDoesNotContainNull - find() throws DoesNotExistException, never returns null
                     */
                    if ($targetObject === null) {
                        continue;
                    }

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
            }
            // Handle array properties.
            else if ($propertyType === 'array') {
                if ($value === '') {
                    // Empty string to null for array properties.
                    $sanitizedData[$propertyName] = null;
                } else if (is_array($value) === true) {
                    // Check minItems constraint.
                    $minItems = $propertyDefinition['minItems'] ?? 0;

                    if (empty($value) === true && $minItems > 0) {
                        // Keep empty array [] for arrays with minItems > 0 - will fail validation with clear error.
                    } else if (empty($value) === true && $minItems === 0) {
                        // Empty array is valid for arrays with no minItems constraint.
                    } else {
                        // Handle array items that might contain empty strings.
                        $sanitizedArray = [];
                        $hasChanges     = false;
                        foreach ($value as $index => $item) {
                            if ($item === '') {
                                $sanitizedArray[$index] = null;
                                $hasChanges = true;
                            } else {
                                $sanitizedArray[$index] = $item;
                            }
                        }

                        if ($hasChanges === true) {
                            $sanitizedData[$propertyName] = $sanitizedArray;
                        }
                    }//end if
                }//end if
            }
            // Handle other property types with empty strings.
            else if ($value === '' && in_array($propertyType, ['string', 'number', 'integer', 'boolean']) === true) {
                if ($isRequired === false) {
                    // Convert empty string to null for non-required scalar properties.
                    $sanitizedData[$propertyName] = null;
                } else {
                    // Keep empty string for required properties - will fail validation with clear error.
                }
            }
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
     *
     * @return ObjectEntity The saved object entity.
     *
     * @throws Exception If there is an error during save.
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

        $selfData = [];
        if (($data['@self'] ?? null) !== null && is_array($data['@self']) === true) {
            $selfData = $data['@self'];
        }

        // Use @self.id as UUID if no UUID is provided.
        if ($uuid === null && (($selfData['id'] ?? null) !== null || (($data['id'] ?? null) !== null) === true)) {
            $uuid = $selfData['id'] ?? $data['id'];
        }

        if ($uuid === '') {
            $uuid = null;
        }

        // Remove the @self property from the data.
        unset($data['@self']);
        unset($data['id']);

        // Process uploaded files and inject them into data.
        if ($uploadedFiles !== null && empty($uploadedFiles) === false) {
            $data = $this->filePropertyHandler->processUploadedFiles(uploadedFiles: $uploadedFiles, data: $data);
        }

        // Debug logging can be added here if needed.
        // Set schema ID based on input type.
        if ($schema instanceof Schema === true) {
            $schemaId = $schema->getId();
        } else {
            // Resolve schema reference if it's a string.
            if (is_string($schema) === true) {
                $schemaId = $this->resolveSchemaReference($schema);
                if ($schemaId === null) {
                    throw new Exception("Could not resolve schema reference: $schema");
                }

                $schema = $this->schemaMapper->find(id: $schemaId);
            } else {
                $schemaId = $schema;
                $schema   = $this->schemaMapper->find(id: $schema);
            }
        }

        if ($register instanceof Register === true) {
            $registerId = $register->getId();
        } else {
            // Resolve register reference if it's a string.
            if (is_string($register) === true) {
                $registerId = $this->resolveRegisterReference($register);
                if ($registerId === null) {
                    throw new Exception("Could not resolve register reference: $register");
                }

                $register = $this->registerMapper->find(id: $registerId);
            } else {
                $registerId = $register;
                $register   = $this->registerMapper->find(id: $register);
            }
        }

        // NOTE: Do NOT sanitize here - let validation happen first in ObjectService.
        // Sanitization will happen after validation but before cascading operations.
        // If UUID is provided, try to find and update existing object.
        if ($uuid !== null) {
            try {
                $existingObject = $this->objectEntityMapper->find(identifier: $uuid);

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
                return $this->updateObject(register: $register, schema: $schema, data: $data, existingObject: $preparedObject, folderId: $folderId, silent: $silent);
            } catch (DoesNotExistException $e) {
                // Object not found, proceed with creating new object.
            } catch (Exception $e) {
                // Other errors during object lookup.
                throw $e;
            }//end try
        }//end if

        // Create a new object entity.
        $objectEntity = new ObjectEntity();
        $objectEntity->setRegister($registerId);
        $objectEntity->setSchema($schemaId);
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
                    _multi: $multi
        );

        // If not persisting, return the prepared object.
        if ($persist === false) {
            return $preparedObject;
        }

        // Save the object to database FIRST (so it gets an ID).
        $savedEntity = $this->objectEntityMapper->insert($preparedObject);

        // NOW handle file properties - process them and replace content with file IDs.
        // This must happen AFTER insert so the object has a database ID for FileService.
        // IMPORTANT: If file processing fails, we must rollback the object insertion.
        $filePropertiesProcessed = false;
        try {
            foreach ($data as $propertyName => $value) {
                if ($this->filePropertyHandler->isFileProperty(value: $value, schema: $schema, propertyName: $propertyName) === true) {
                    $this->filePropertyHandler->handleFileProperty(objectEntity: $savedEntity, object: $data, propertyName: $propertyName, schema: $schema);
                    $filePropertiesProcessed = true;
                }
            }

            // If files were processed, update the object with file IDs.
            if ($filePropertiesProcessed === true) {
                $savedEntity->setObject($data);

                // Re-hydrate image metadata if objectImageField points to a file property.
                // At this point, file properties are file IDs, but we need to check if we should.
                // clear the image metadata so it can be properly extracted during rendering.
                $config = $schema->getConfiguration();
                if (($config['objectImageField'] ?? null) !== null) {
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
                }

                $savedEntity = $this->objectEntityMapper->update($savedEntity);
            }//end if
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

        // Create audit trail for creation if audit trails are enabled and not in silent mode.
        if (($silent === false) === true && $this->isAuditTrailsEnabled() === true) {
            $log = $this->auditTrailMapper->createAuditTrail(old: null, new: $savedEntity);
            $savedEntity->setLastLog($log->jsonSerialize());
        }

        // Update the object with the modified data (file IDs instead of content).
        // $savedEntity->setObject($data);
        // **CACHE INVALIDATION**: Clear collection and facet caches so new/updated objects appear immediately.
        // Determine operation type.
        if ($uuid === true) {
            $operation = 'update';
        } else {
            $operation = 'create';
        }

        // Determine register ID.
        if ($savedEntity->getRegister() !== null) {
            $registerId = (int) $savedEntity->getRegister();
        } else {
            $registerId = null;
        }

        // Determine schema ID.
        if ($savedEntity->getSchema() !== null) {
            $schemaId = (int) $savedEntity->getSchema();
        } else {
            $schemaId = null;
        }

        $this->objectCacheService->invalidateForObjectChange(
            object: $savedEntity,
            operation: $operation,
            registerId: $registerId,
            schemaId: $schemaId
        );

        return $savedEntity;

    }//end saveObject()


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
     */
    private function prepareObjectForCreation(
        ObjectEntity $objectEntity,
        Schema $schema,
        array $data,
        array $selfData,
        bool $_multi
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
            throw new Exception(
                'Object metadata hydration failed: '.$e->getMessage().'. This indicates a mismatch between object data and schema configuration.',
                0,
                $e
            );
        }

        // Auto-publish logic: Set published date to now if autoPublish is enabled in schema configuration.
        // and no published date has been set yet (either from field mapping or explicit data).
        $config = $schema->getConfiguration();
        if (($config['autoPublish'] ?? null) !== null && $config['autoPublish'] === true) {
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
            } else {
                $this->logger->debug(
                        'Object already has published date, skipping auto-publish',
                        [
                            'uuid'          => $objectEntity->getUuid(),
                            'publishedDate' => $objectEntity->getPublished()->format('Y-m-d H:i:s'),
                        ]
                        );
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
            $organisationUuid = null
            // TODO: organisationService->getOrganisationForNewEntity();
            $objectEntity->setOrganisation($organisationUuid);
        }

        // Update object relations.
        try {
            $objectEntity = $this->updateObjectRelations(objectEntity: $objectEntity, data: $preparedData, schema: $schema);
        } catch (Exception $e) {
            // CRITICAL FIX: Relation processing failures indicate serious data integrity issues!
            throw new Exception(
                'Object relations processing failed: '.$e->getMessage().'. This indicates invalid relation data or schema configuration problems.',
                0,
                $e
            );
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
        // Update object relations.
        try {
            $objectEntity = $this->updateObjectRelations($existingObject, $preparedData, $schema);
        } catch (Exception $e) {
            // CRITICAL FIX: Relation processing failures indicate serious data integrity issues!
            throw new Exception(
                'Object relations processing failed: '.$e->getMessage().'. This indicates invalid relation data or schema configuration problems.',
                0,
                $e
            );
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
            } else {
                $this->logger->debug('Published value is empty, setting to null');
                $objectEntity->setPublished(null);
            }//end if
        } else {
            $this->logger->debug('No published field found in selfData, setting to existing value');
            $objectEntity->setPublished($objectEntity->getPublished());
        }//end if

        // Extract and set depublished property if present.
        if (array_key_exists('depublished', $selfData) === true && empty($selfData['depublished']) === false) {
            try {
                // Convert string to DateTime if it's a valid date string.
                if (is_string($selfData['depublished']) === true) {
                    $objectEntity->setDepublished(new DateTime($selfData['depublished']));
                }
            } catch (Exception $exception) {
                // Silently ignore invalid date formats.
            }
        } else {
            $objectEntity->setDepublished(null);
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
            throw new Exception(
                'Object data sanitization failed: '.$e->getMessage().'. This indicates invalid or corrupted object data that cannot be processed safely.',
                0,
                $e
            );
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

        // Set register ID based on input type.
        if ($register instanceof Register === true) {
            $registerId = $register->getId();
        } else {
            $registerId = $register;
        }

        // Set schema ID based on input type.
        if ($schema instanceof Schema === true) {
            $schemaId = $schema->getId();
        } else {
            $schemaId = $schema;
        }

        // Prepare the object for update using the new structure.
        $preparedObject = $this->prepareObjectForUpdate(
            existingObject: $existingObject,
            schema: $schema,
            data: $data,
            selfData: $selfData,
            folderId: $folderId
        );

        // Update the object properties.
        $preparedObject->setRegister($registerId);
        $preparedObject->setSchema($schemaId);
        $preparedObject->setUpdated(new DateTime());

        // Save the object to database.
        $updatedEntity = $this->objectEntityMapper->update($preparedObject);

        // Create audit trail for update if audit trails are enabled and not in silent mode.
        if ($silent === false && $this->isAuditTrailsEnabled() === true) {
            $log = $this->auditTrailMapper->createAuditTrail(old: $oldObject, new: $updatedEntity);
            $updatedEntity->setLastLog($log->jsonSerialize());
        }

        // Handle file properties - process them and replace content with file IDs.
        $filePropertiesProcessed = false;
        foreach ($data as $propertyName => $value) {
            if ($this->filePropertyHandler->isFileProperty(value: $value, schema: $schema, propertyName: $propertyName) === true) {
                $this->filePropertyHandler->handleFileProperty(objectEntity: $updatedEntity, object: $data, propertyName: $propertyName, schema: $schema);
                $filePropertiesProcessed = true;
            }
        }

        // Update the object with the modified data (file IDs instead of content).
        if ($filePropertiesProcessed === true) {
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
            $updatedEntity = $this->objectEntityMapper->update($updatedEntity);
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
            $this->logger->warning('Failed to check audit trails setting, defaulting to enabled', ['error' => $e->getMessage()]);
            return true;
        }

    }//end isAuditTrailsEnabled()


}//end class
