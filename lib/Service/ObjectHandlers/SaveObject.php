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

namespace OCA\OpenRegister\Service\ObjectHandlers;

use Adbar\Dot;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\ObjectCacheService;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\SettingsService;
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
 * @package   OCA\OpenRegister\Service\ObjectHandlers
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
     * @param ObjectEntityMapper      $objectEntityMapper      Object entity data mapper.
     * @param FileService             $fileService             File service for managing files.
     * @param IUserSession            $userSession             User session service.
     * @param AuditTrailMapper        $auditTrailMapper        Audit trail mapper for logging changes.
     * @param SchemaMapper            $schemaMapper            Schema mapper for schema operations.
     * @param RegisterMapper          $registerMapper          Register mapper for register operations.
     * @param IURLGenerator           $urlGenerator            URL generator service.
     * @param OrganisationService     $organisationService     Service for organisation operations.
     * @param ObjectCacheService      $objectCacheService      Object cache service for entity and query caching.
     * @param SchemaCacheService      $schemaCacheService      Schema cache service for schema entity caching.
     * @param SchemaFacetCacheService $schemaFacetCacheService Schema facet cache service for facet caching.
     * @param SettingsService         $settingsService         Settings service for accessing trail settings.
     * @param LoggerInterface         $logger                  Logger interface for logging operations.
     * @param ArrayLoader             $arrayLoader             Twig array loader for template rendering.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService,
        private readonly IUserSession $userSession,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly IURLGenerator $urlGenerator,
        private readonly OrganisationService $organisationService,
        private readonly ObjectCacheService $objectCacheService,
        private readonly SchemaCacheService $schemaCacheService,
        private readonly SchemaFacetCacheService $schemaFacetCacheService,
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
     * @return string|null The resolved schema ID or null if not found
     */
    private function resolveSchemaReference(string $reference): ?string
    {
        if (empty($reference)) {
            return null;
        }

        // Remove query parameters if present (e.g., "schema?key=value" -> "schema")
        $cleanReference = $this->removeQueryParameters($reference);

        // First, try direct ID lookup (numeric ID or UUID)
        if (is_numeric($cleanReference) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $cleanReference)) {
            try {
                $schema = $this->schemaMapper->find($cleanReference);
                return $schema->getId();
            } catch (DoesNotExistException $e) {
                // Continue with other resolution methods
            }
        }

        // Extract the last part of path/URL references
        $slug = $cleanReference;
        if (str_contains($cleanReference, '/')) {
            // For references like "#/components/schemas/Contactgegevens" or "http://example.com/schemas/contactgegevens"
            $slug = substr($cleanReference, strrpos($cleanReference, '/') + 1);
        }

        // Try to find schema by slug (case-insensitive)
        try {
            $schemas = $this->schemaMapper->findAll();
            foreach ($schemas as $schema) {
                if (strcasecmp($schema->getSlug(), $slug) === 0) {
                    return $schema->getId();
                }
            }
        } catch (Exception $e) {
            // Schema not found
        }

        // Try direct slug match as last resort.
        try {
            $schema = $this->schemaMapper->findBySlug($slug);
            if ($schema !== null) {
                return $schema->getId();
            }
        } catch (Exception $e) {
            // Schema not found
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
        // Remove query parameters if present (e.g., "schema?key=value" -> "schema")
        if (str_contains($reference, '?')) {
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
     * @return string|null The resolved register ID or null if not found
     */
    private function resolveRegisterReference(string $reference): ?string
    {
        if (empty($reference)) {
            return null;
        }

        // First, try direct ID lookup (numeric ID or UUID)
        if (is_numeric($reference) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $reference)) {
            try {
                $register = $this->registerMapper->find($reference);
                return $register->getId();
            } catch (DoesNotExistException $e) {
                // Continue with other resolution methods
            }
        }

        // Extract the last part of path/URL references
        $slug = $reference;
        if (str_contains($reference, '/')) {
            // For references like "http://example.com/registers/publication"
            $slug = substr($reference, strrpos($reference, '/') + 1);
        }

        // Try to find register by slug (case-insensitive)
        try {
            $registers = $this->registerMapper->findAll();
            foreach ($registers as $register) {
                if (strcasecmp($register->getSlug(), $slug) === 0) {
                    return $register->getId();
                }
            }
        } catch (Exception $e) {
            // Register not found
        }

        // Try direct slug match as last resort.
        try {
            $register = $this->registerMapper->findBySlug($slug);
            if ($register !== null) {
                return $register->getId();
            }
        } catch (Exception $e) {
            // Register not found
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
            // Get schema properties if available
            $schemaProperties = null;
            if ($schema !== null) {
                try {
                    $schemaObject     = json_decode(json_encode($schema->getSchemaObject($this->urlGenerator)), associative: true);
                    $schemaProperties = $schemaObject['properties'] ?? [];
                } catch (Exception $e) {
                    // Continue without schema properties if parsing fails
                }
            }

            foreach ($data as $key => $value) {
                // Skip if key is not a string or is empty
                if (!is_string($key) || empty($key)) {
                    continue;
                }

                $currentPath = $prefix ? $prefix.'.'.$key : $key;

                if (is_array($value) && !empty($value)) {
                    // Check if this is an array property in the schema
                    $propertyConfig   = $schemaProperties[$key] ?? null;
                    $isArrayOfObjects = $propertyConfig &&
                                      ($propertyConfig['type'] ?? '') === 'array' &&
                                      isset($propertyConfig['items']['type']) &&
                                      $propertyConfig['items']['type'] === 'object';

                    if ($isArrayOfObjects === true) {
                        // For arrays of objects, scan each item for relations.
                        foreach ($value as $index => $item) {
                            if (is_array($item)) {
                                $itemRelations = $this->scanForRelations(
                                    $item,
                                    $currentPath.'.'.$index,
                                    $schema
                                );
                                $relations     = array_merge($relations, $itemRelations);
                            } else if (is_string($item) && !empty($item)) {
                                // String values in object arrays are always treated as relations
                                $relations[$currentPath.'.'.$index] = $item;
                            }
                        }
                    } else {
                        // For non-object arrays, check each item
                        foreach ($value as $index => $item) {
                            if (is_array($item)) {
                                // Recursively scan nested arrays/objects
                                $itemRelations = $this->scanForRelations(
                                    $item,
                                    $currentPath.'.'.$index,
                                    $schema
                                );
                                $relations     = array_merge($relations, $itemRelations);
                            } else if (is_string($item) && !empty($item) && trim($item) !== '') {
                                // Check if the string looks like a reference
                                if ($this->isReference($item)) {
                                    $relations[$currentPath.'.'.$index] = $item;
                                }
                            }
                        }
                    }//end if
                } else if (is_string($value) && !empty($value) && trim($value) !== '') {
                    $shouldTreatAsRelation = false;

                    // Check schema property configuration first
                    if ($schemaProperties && isset($schemaProperties[$key])) {
                        $propertyConfig = $schemaProperties[$key];
                        $propertyType   = $propertyConfig['type'] ?? '';
                        $propertyFormat = $propertyConfig['format'] ?? '';

                        // Check for explicit relation types
                        if ($propertyType === 'text' && in_array($propertyFormat, ['uuid', 'uri', 'url'])) {
                            $shouldTreatAsRelation = true;
                        } else if ($propertyType === 'object') {
                            // Object properties with string values are always relations
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
            // Error scanning for relations
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

        // Empty strings are not references
        if (empty($value)) {
            return false;
        }

        // Check for standard UUID pattern (8-4-4-4-12 format)
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return true;
        }

        // Check for prefixed UUID patterns (e.g., "id-uuid", "ref-uuid", etc.)
        if (preg_match('/^[a-z]+-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return true;
        }

        // Check for URLs
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return true;
        }

        // Check for other common ID patterns, but be more selective to avoid false positives
        // Only consider strings that look like identifiers, not regular text
        if (preg_match('/^[a-z0-9][a-z0-9_-]{7,}$/i', $value)) {
            // Must contain at least one hyphen or underscore (indicating it's likely an ID)
            // AND must not contain spaces or common text words
            if ((strpos($value, '-') !== false || strpos($value, '_') !== false)
                && !preg_match('/\s/', $value)
                && !in_array(strtolower($value), ['applicatie', 'systeemsoftware', 'open-source', 'closed-source'])
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
        // Scan for relations in the object data
        $relations = $this->scanForRelations($data, '', $schema);

        // Set the relations on the object entity
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

        // Name field mapping
        if (isset($config['objectNameField']) === true) {
            $name = $this->extractMetadataValue($objectData, $config['objectNameField']);
            if ($name !== null && trim($name) !== '') {
                $entity->setName(trim($name));
            }
        }

        // Description field mapping
        if (isset($config['objectDescriptionField']) === true) {
            $description = $this->extractMetadataValue($objectData, $config['objectDescriptionField']);
            if ($description !== null && trim($description) !== '') {
                $entity->setDescription(trim($description));
            }
        }

        // Summary field mapping
        if (isset($config['objectSummaryField']) === true) {
            $summary = $this->extractMetadataValue($objectData, $config['objectSummaryField']);
            if ($summary !== null && trim($summary) !== '') {
                $entity->setSummary(trim($summary));
            }
        }

        // Image field mapping
        if (isset($config['objectImageField']) === true) {
            // First check if the field points to a file object
            $imageValue = $this->getValueFromPath($objectData, $config['objectImageField']);

            // Handle different value types:
            // 1. Array of file IDs: [123, 124]
            // 2. Array of file objects: [{accessUrl: ...}, {accessUrl: ...}]
            // 3. Single file ID: 123
            // 4. Single file object: {accessUrl: ...}
            // 5. String URL
            if (is_array($imageValue) && !empty($imageValue)) {
                // Check if first element is a file ID or file object
                $firstElement = $imageValue[0] ?? null;

                if (is_numeric($firstElement)) {
                    // Array of file IDs - load first file and get its download URL
                    try {
                        $fileNode = $this->fileService->getFile($entity, (int) $firstElement);
                        if ($fileNode !== null) {
                            $fileData = $this->fileService->formatFile($fileNode);

                            // IMPORTANT: Object image requires public access
                            // If file is not published, auto-publish it
                            if (empty($fileData['downloadUrl'])) {
                                $this->logger->warning(
                                    'File configured as objectImageField is not published. Auto-publishing file.',
                                    [
                                        'app'      => 'openregister',
                                        'fileId'   => $firstElement,
                                        'objectId' => $entity->getId(),
                                        'field'    => $config['objectImageField'],
                                    ]
                                );
                                // Publish the file
                                $this->fileService->publishFile($fileNode);
                                // Re-fetch file data after publishing
                                $fileData = $this->fileService->formatFile($fileNode);
                            }

                            if (isset($fileData['downloadUrl'])) {
                                $entity->setImage($fileData['downloadUrl']);
                            }
                        }//end if
                    } catch (\Exception $e) {
                        // File not found or error loading - skip
                        $this->logger->error(
                                'Failed to load file for objectImageField',
                                [
                                    'app'    => 'openregister',
                                    'fileId' => $firstElement,
                                    'error'  => $e->getMessage(),
                                ]
                                );
                    }//end try
                } else if (is_array($firstElement) && isset($firstElement['downloadUrl'])) {
                    // Array of file objects - use first file's downloadUrl
                    $entity->setImage($firstElement['downloadUrl']);
                } else if (is_array($firstElement) && isset($firstElement['accessUrl'])) {
                    // Fallback to accessUrl if downloadUrl not available
                    $entity->setImage($firstElement['accessUrl']);
                }//end if
            } else if (is_numeric($imageValue)) {
                // Single file ID - load file and get its download URL
                try {
                    $fileNode = $this->fileService->getFile($entity, (int) $imageValue);
                    if ($fileNode !== null) {
                        $fileData = $this->fileService->formatFile($fileNode);

                        // IMPORTANT: Object image requires public access
                        // If file is not published, auto-publish it
                        if (empty($fileData['downloadUrl'])) {
                            $this->logger->warning(
                                'File configured as objectImageField is not published. Auto-publishing file.',
                                [
                                    'app'      => 'openregister',
                                    'fileId'   => $imageValue,
                                    'objectId' => $entity->getId(),
                                    'field'    => $config['objectImageField'],
                                ]
                            );
                            // Publish the file
                            $this->fileService->publishFile($fileNode);
                            // Re-fetch file data after publishing
                            $fileData = $this->fileService->formatFile($fileNode);
                        }

                        if (isset($fileData['downloadUrl'])) {
                            $entity->setImage($fileData['downloadUrl']);
                        }
                    }//end if
                } catch (\Exception $e) {
                    // File not found or error loading - skip
                    $this->logger->error(
                            'Failed to load file for objectImageField',
                            [
                                'app'    => 'openregister',
                                'fileId' => $imageValue,
                                'error'  => $e->getMessage(),
                            ]
                            );
                }//end try
            } else if (is_array($imageValue) && isset($imageValue['downloadUrl'])) {
                // Single file object - use its downloadUrl
                $entity->setImage($imageValue['downloadUrl']);
            } else if (is_array($imageValue) && isset($imageValue['accessUrl'])) {
                // Fallback to accessUrl if downloadUrl not available
                $entity->setImage($imageValue['accessUrl']);
            } else if (is_string($imageValue) && trim($imageValue) !== '') {
                // Regular string URL
                $entity->setImage(trim($imageValue));
            }//end if
        }//end if

        // Slug field mapping
        if (isset($config['objectSlugField']) === true) {
            $slug = $this->extractMetadataValue($objectData, $config['objectSlugField']);
            if ($slug !== null && trim($slug) !== '') {
                // Generate URL-friendly slug
                $generatedSlug = $this->createSlugFromValue(trim($slug));
                if ($generatedSlug !== null) {
                    $entity->setSlug($generatedSlug);
                }
            }
        }

        // Published field mapping
        if (isset($config['objectPublishedField']) === true) {
            $published = $this->extractMetadataValue($objectData, $config['objectPublishedField']);
            if ($published !== null && trim($published) !== '') {
                try {
                    $publishedDate = new DateTime(trim($published));
                    $entity->setPublished($publishedDate);
                } catch (Exception $e) {
                    // Log warning but don't fail the entire operation
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

        // Depublished field mapping
        if (isset($config['objectDepublishedField']) === true) {
            $depublished = $this->extractMetadataValue($objectData, $config['objectDepublishedField']);
            if ($depublished !== null && trim($depublished) !== '') {
                try {
                    $depublishedDate = new DateTime(trim($depublished));
                    $entity->setDepublished($depublishedDate);
                } catch (Exception $e) {
                    // Log warning but don't fail the entire operation
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
    private function getValueFromPath(array $data, string $path): ?string
    {
        $keys    = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }

            $current = $current[$key];
        }

        // Convert to string if it's not null and not already a string
        if ($current !== null && !is_string($current)) {
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
        // Check if this is a twig-like template with {{ }} syntax
        if (str_contains($fieldPath, '{{') && str_contains($fieldPath, '}}')) {
            return $this->processTwigLikeTemplate($data, $fieldPath);
        }

        // Simple field path - use existing method
        return $this->getValueFromPath($data, $fieldPath);

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
     * @return string|null The processed template result, or null if no values found
     *
     * @psalm-return   string|null
     * @phpstan-return string|null
     */
    private function processTwigLikeTemplate(array $data, string $template): ?string
    {
        // Extract all {{ fieldName }} patterns
        preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $template, $matches);

        if (empty($matches[0])) {
            return null;
        }

        $result    = $template;
        $hasValues = false;

        // Replace each {{ fieldName }} with its value
        foreach ($matches[0] as $index => $fullMatch) {
            $fieldName = trim($matches[1][$index]);
            $value     = $this->getValueFromPath($data, $fieldName);

            if ($value !== null && trim($value) !== '') {
                $result    = str_replace($fullMatch, trim($value), $result);
                $hasValues = true;
            } else {
                // Replace with empty string for missing/empty values
                $result = str_replace($fullMatch, '', $result);
            }
        }

        if ($hasValues === false) {
            return null;
        }

        // Clean up excess whitespace and normalize spaces
        $result = preg_replace('/\s+/', ' ', $result);
        $result = trim($result);

        return $result !== '' ? $result : null;

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
        if (empty($value) || trim($value) === '') {
            return null;
        }

        // Use the existing createSlug method for consistency
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

            if (!isset($schemaObject['properties']) || !is_array($schemaObject['properties'])) {
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

        // Handle constant values - these should ALWAYS be set regardless of input data
        $constantValues = [];
        foreach ($properties as $property) {
            if (isset($property['const']) === true) {
                $constantValues[$property['title']] = $property['const'];
            }
        }

        // Handle default values with new behavior support
        $defaultValues = [];
        foreach ($properties as $property) {
            $key          = $property['title'];
            $defaultValue = $property['default'] ?? null;

            // Skip if no default value is defined
            if ($defaultValue === null) {
                continue;
            }

            $defaultBehavior    = $property['defaultBehavior'] ?? 'false';
            $shouldApplyDefault = false;

            // Determine if default should be applied based on behavior
            if ($defaultBehavior === 'falsy') {
                // Apply default if property is missing, null, empty string, or empty array/object
                $shouldApplyDefault = !isset($data[$key])
                    || $data[$key] === null
                    || $data[$key] === ''
                    || (is_array($data[$key]) && empty($data[$key]));
            } else {
                // Default behavior: only apply if property is missing or null
                $shouldApplyDefault = !isset($data[$key]) || $data[$key] === null;
            }

            if ($shouldApplyDefault === true) {
                $defaultValues[$key] = $defaultValue;
            }
        }//end foreach

        // Render twig templated default values.
        $renderedDefaultValues = [];
        foreach ($defaultValues as $key => $defaultValue) {
            try {
                if (is_string($defaultValue) && str_contains(haystack: $defaultValue, needle: '{{') && str_contains(haystack: $defaultValue, needle: '}}')) {
                    $renderedDefaultValues[$key] = $this->twig->createTemplate($defaultValue)->render($objectEntity->getObjectArray());
                } else {
                    $renderedDefaultValues[$key] = $defaultValue;
                }
            } catch (Exception $e) {
                $renderedDefaultValues[$key] = $defaultValue;
                // Use original value if template fails
            }
        }

        // Merge in this order:
        // 1. Start with existing data
        // 2. Apply rendered default values (only for properties that should get defaults)
        // 3. Override with constant values (constants always take precedence)
        $mergedData = array_merge($data, $renderedDefaultValues, $constantValues);

        // Generate slug if not present and schema has slug configuration
        if (!isset($mergedData['slug']) && !isset($mergedData['@self']['slug'])) {
            $slug = $this->generateSlug($mergedData, $schema);
            if ($slug !== null) {
                // Set slug in the data (will be applied to entity in setSelfMetadata)
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
     * @return string|null The generated slug or null if no slug could be generated
     */
    private function generateSlug(array $data, Schema $schema): ?string
    {
        try {
            $config    = $schema->getConfiguration();
            $slugField = $config['objectSlugField'] ?? null;

            if ($slugField === null) {
                return null;
            }

            // Get the value from the specified field
            $value = $this->getValueFromPath($data, $slugField);
            if ($value === null || empty($value)) {
                return null;
            }

            // Convert to string and generate slug
            $slug = $this->createSlug((string) $value);

            // Ensure uniqueness by appending timestamp if needed
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
        // Convert to lowercase
        $text = strtolower($text);

        // Replace non-alphanumeric characters with hyphens
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);

        // Remove leading and trailing hyphens
        $text = trim($text, '-');

        // Limit length
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

        // Cascade objects that have $ref with either:
        // 1. inversedBy (creates relation back to parent) - results in empty array/null in parent
        // 2. objectConfiguration.handling: "cascade" (stores IDs in parent) - results in IDs stored in parent
        // Objects with only $ref and nested-object handling remain in the data
        // BUT skip if they have writeBack enabled (those are handled by write-back method)
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
        $objectProperties = array_filter(
          $properties,
          function (array $property) {
            // Skip if writeBack is enabled (handled by write-back method)
            if (isset($property['writeBack']) && $property['writeBack'] === true) {
                return false;
            }

            return $property['type'] === 'object'
                && isset($property['$ref']) === true
                && (isset($property['inversedBy']) === true ||
                    (isset($property['objectConfiguration']['handling']) && $property['objectConfiguration']['handling'] === 'cascade'));
          }
          );

        // Same logic for array properties - cascade if they have inversedBy OR cascade handling
        // BUT skip if they have writeBack enabled (those are handled by write-back method)
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
        $arrayObjectProperties = array_filter(
          $properties,
          function (array $property) {
            // Skip if writeBack is enabled (handled by write-back method)
            if ((isset($property['writeBack']) && $property['writeBack'] === true)
                || (isset($property['items']['writeBack']) && $property['items']['writeBack'] === true)
            ) {
                return false;
            }

            return $property['type'] === 'array'
                && (isset($property['$ref']) || isset($property['items']['$ref']))
                && (isset($property['inversedBy']) === true || isset($property['items']['inversedBy']) === true ||
                    (isset($property['objectConfiguration']['handling']) && ($property['objectConfiguration']['handling'] === 'cascade'|| $property['objectConfiguration']['handling'] === 'related-object')) ||
                    (isset($property['items']['objectConfiguration']['handling']) && ($property['items']['objectConfiguration']['handling'] === 'cascade' || $property['objectConfiguration']['handling'] === 'related-object')));
          }
          );

        // Process single object properties that need cascading
        foreach ($objectProperties as $property => $definition) {
            // Skip if property not present in data
            if (isset($data[$property]) === false) {
                continue;
            }

            // Skip if the property is empty or not an array/object
            if (empty($data[$property]) === true || (!is_array($data[$property]) && !is_object($data[$property]))) {
                continue;
            }

            // Convert object to array if needed
            $objectData = is_object($data[$property]) ? (array) $data[$property] : $data[$property];

            // Skip if the object is effectively empty (only contains empty values)
            if ($this->isEffectivelyEmptyObject($objectData)) {
                continue;
            }

            try {
                $createdUuid = $this->cascadeSingleObject(objectEntity: $objectEntity, definition: $definition, object: $objectData);

                // Handle the result based on whether inversedBy is present
                if (isset($definition['inversedBy'])) {
                    // With inversedBy: check if writeBack is enabled
                    if (isset($definition['writeBack']) && $definition['writeBack'] === true) {
                        // Keep the property for write-back processing
                        $data[$property] = $createdUuid;
                    } else {
                        // Remove the property (traditional cascading)
                        unset($data[$property]);
                    }
                } else {
                    // Without inversedBy: store the created object's UUID
                    $data[$property] = $createdUuid;
                }
            } catch (Exception $e) {
                // Continue with other properties even if one fails
            }
        }//end foreach

        // Process array object properties that need cascading
        foreach ($arrayObjectProperties as $property => $definition) {
            // Skip if property not present, empty, or not an array
            if (isset($data[$property]) === false || empty($data[$property]) === true || !is_array($data[$property])) {
                continue;
            }

            try {
                $createdUuids = $this->cascadeMultipleObjects(objectEntity: $objectEntity, property: $definition, propData: $data[$property]);

                // Handle the result based on whether inversedBy is present
                if (isset($definition['inversedBy']) || isset($definition['items']['inversedBy'])) {
                    // With inversedBy: check if writeBack is enabled
                    $hasWriteBack = (isset($definition['writeBack']) && $definition['writeBack'] === true) ||
                                   (isset($definition['items']['writeBack']) && $definition['items']['writeBack'] === true);

                    if ($hasWriteBack === true) {
                        // Keep the property for write-back processing.
                        $data[$property] = $createdUuids;
                    } else {
                        // Remove the property (traditional cascading)
                        unset($data[$property]);
                    }
                } else {
                    // Without inversedBy: store the created objects' UUIDs
                    $data[$property] = $createdUuids;
                }
            } catch (Exception $e) {
                // Continue with other properties even if one fails
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
     * @return array Array of UUIDs of created objects
     * @throws Exception
     */
    private function cascadeMultipleObjects(ObjectEntity $objectEntity, array $property, array $propData): array
    {
        if (array_is_list($propData) === false) {
            return [];
        }

        // Filter out empty or invalid objects
        $validObjects = array_filter(
            $propData,
            function ($object) {
                return (is_array($object) === true && empty($object) === false && !(count($object) === 1 && isset($object['id']) === true && empty($object['id']) === true)) || (is_string($object) === true && Uuid::isValid($object) === true);
            }
        );

        if (empty($validObjects)) {
            return [];
        }

        if (isset($property['$ref']) === true) {
            $property['items']['$ref'] = $property['$ref'];
        }

        if (isset($property['inversedBy']) === true) {
            $property['items']['inversedBy'] = $property['inversedBy'];
        }

        if (isset($property['register']) === true) {
            $property['items']['register'] = $property['register'];
        }

        if (isset($property['objectConfiguration']) === true) {
            $property['items']['objectConfiguration'] = $property['objectConfiguration'];
        }

        // Validate that we have the necessary configuration
        if (!isset($property['items']['$ref'])) {
            return [];
        }

        $createdUuids = [];
        foreach ($validObjects as $object) {
            if (is_string($object) && Uuid::isValid($object)) {
                continue;
            }

            try {
                $uuid = $this->cascadeSingleObject(objectEntity: $objectEntity, definition: $property['items'], object: $object);
                if ($uuid !== null) {
                    $createdUuids[] = $uuid;
                }
            } catch (Exception $e) {
                // Continue with other objects even if one fails
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
        // Validate that we have the necessary configuration
        if (!isset($definition['$ref'])) {
            return null;
        }

        // Skip if object is empty or doesn't contain actual data
        if (empty($object) || (count($object) === 1 && isset($object['id']) && empty($object['id']))) {
            return null;
        }

        $objectId = $objectEntity->getUuid();
        if (empty($objectId)) {
            return null;
        }

        // Only set inversedBy if it's configured (for relation-based cascading)
        if (isset($definition['inversedBy'])) {
            $inversedByProperty = $definition['inversedBy'];

            // Check if the inversedBy property already exists and is an array
            if (isset($object[$inversedByProperty]) && is_array($object[$inversedByProperty])) {
                // Add to existing array if not already present
                if (!in_array($objectId, $object[$inversedByProperty])) {
                    $object[$inversedByProperty][] = $objectId;
                }
            } else {
                // Set as single value or create new array
                $object[$inversedByProperty] = $objectId;
            }
        }

        // Extract register ID from definition or use parent object's register
        $register = $definition['register'] ?? $objectEntity->getRegister();

        // If register is an array, extract the ID
        if (is_array($register)) {
            $register = $register['id'] ?? $register;
        }

        // For cascading with inversedBy, preserve existing UUID for updates
        // For cascading without inversedBy, always create new objects (no UUID)
        $uuid = null;
        if (isset($definition['inversedBy'])) {
            $uuid = $object['id'] ?? $object['@self']['id'] ?? null;
        } else {
            // Remove any existing UUID/id fields to force new object creation
            unset($object['id']);
            unset($object['@self']);
        }

        // Resolve schema reference to actual schema ID
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

        // Find properties that have inversedBy configuration with writeBack enabled
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
        $writeBackProperties = array_filter(
          $properties,
          function (array $property) {
            // Check for inversedBy with writeBack at property level
            if (isset($property['inversedBy']) && isset($property['writeBack']) && $property['writeBack'] === true) {
                return true;
            }

            // Check for inversedBy with writeBack in array items
            if ($property['type'] === 'array' && isset($property['items']['inversedBy']) && isset($property['items']['writeBack']) && $property['items']['writeBack'] === true) {
                return true;
            }

            // Check for inversedBy with writeBack at array property level (for array of objects)
            if ($property['type'] === 'array' && isset($property['items']['inversedBy']) && isset($property['writeBack']) && $property['writeBack'] === true) {
                return true;
            }

            return false;
          }
          );

        foreach ($writeBackProperties as $propertyName => $definition) {
            // Skip if property not present in data or is empty
            if (!isset($data[$propertyName]) || empty($data[$propertyName])) {
                continue;
            }

            $targetUuids      = $data[$propertyName];
            $inverseProperty  = null;
            $targetSchema     = null;
            $targetRegister   = null;
            $removeFromSource = false;

            // Extract configuration from property or array items
            if (isset($definition['inversedBy']) && isset($definition['writeBack']) && $definition['writeBack'] === true) {
                $inverseProperty  = $definition['inversedBy'];
                $targetSchema     = $definition['$ref'] ?? null;
                $targetRegister   = $definition['register'] ?? $objectEntity->getRegister();
                $removeFromSource = $definition['removeAfterWriteBack'] ?? false;
            } else if (isset($definition['items']['inversedBy']) && isset($definition['items']['writeBack']) && $definition['items']['writeBack'] === true) {
                $inverseProperty  = $definition['items']['inversedBy'];
                $targetSchema     = $definition['items']['$ref'] ?? null;
                $targetRegister   = $definition['items']['register'] ?? $objectEntity->getRegister();
                $removeFromSource = $definition['items']['removeAfterWriteBack'] ?? false;
            } else if (isset($definition['items']['inversedBy']) && isset($definition['writeBack']) && $definition['writeBack'] === true) {
                // Handle array of objects with writeBack at array level
                $inverseProperty  = $definition['items']['inversedBy'];
                $targetSchema     = $definition['items']['$ref'] ?? null;
                $targetRegister   = $definition['register'] ?? $objectEntity->getRegister();
                $removeFromSource = $definition['removeAfterWriteBack'] ?? false;
            }

            // Skip if we don't have the necessary configuration
            if (!$inverseProperty || !$targetSchema) {
                continue;
            }

            // Resolve schema reference to actual schema ID
            $resolvedSchemaId = $this->resolveSchemaReference($targetSchema);
            if ($resolvedSchemaId === null) {
                continue;
            }

            // Ensure targetUuids is an array
            if (!is_array($targetUuids)) {
                $targetUuids = [$targetUuids];
            }

            // Filter out empty or invalid UUIDs
            $validUuids = array_filter(
            $targetUuids,
           function ($uuid) {
                return !empty($uuid) && is_string($uuid) && trim($uuid) !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
           }
            );

            if (empty($validUuids)) {
                continue;
            }

            // Update each target object
            foreach ($validUuids as $targetUuid) {
                try {
                    // Find the target object.
                    $targetObject = $this->objectEntityMapper->find($targetUuid);
                    if ($targetObject === null) {
                        continue;
                    }

                    // Get current data from target object
                    $targetData = $targetObject->getObject();

                    // Initialize inverse property as array if it doesn't exist
                    if (!isset($targetData[$inverseProperty])) {
                        $targetData[$inverseProperty] = [];
                    }

                    // Ensure inverse property is an array
                    if (!is_array($targetData[$inverseProperty])) {
                        $targetData[$inverseProperty] = [$targetData[$inverseProperty]];
                    }

                    // Add current object's UUID to the inverse property if not already present
                    if (!in_array($objectEntity->getUuid(), $targetData[$inverseProperty])) {
                        $targetData[$inverseProperty][] = $objectEntity->getUuid();
                    }

                    // Save the updated target object
                    $this->saveObject(
                        register: $targetRegister,
                        schema: $resolvedSchemaId,
                        data: $targetData,
                        uuid: $targetUuid
                    );
                } catch (Exception $e) {
                    // Continue with other targets even if one fails
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
            // Skip if property is not in the data
            if (!isset($sanitizedData[$propertyName])) {
                continue;
            }

            $value        = $sanitizedData[$propertyName];
            $propertyType = $propertyDefinition['type'] ?? null;
            $isRequired   = in_array($propertyName, $required) || ($propertyDefinition['required'] ?? false);

            // Handle object properties
            if ($propertyType === 'object') {
                if ($value === '') {
                    // Empty string to null for object properties
                    $sanitizedData[$propertyName] = null;
                } else if (is_array($value) && empty($value) && !$isRequired) {
                    // Empty object {} to null for non-required object properties
                    $sanitizedData[$propertyName] = null;
                } else if (is_array($value) && empty($value) && $isRequired) {
                    // Keep empty object {} for required properties - will fail validation with clear error
                }
            }
            // Handle array properties
            else if ($propertyType === 'array') {
                if ($value === '') {
                    // Empty string to null for array properties
                    $sanitizedData[$propertyName] = null;
                } else if (is_array($value)) {
                    // Check minItems constraint
                    $minItems = $propertyDefinition['minItems'] ?? 0;

                    if (empty($value) && $minItems > 0) {
                        // Keep empty array [] for arrays with minItems > 0 - will fail validation with clear error
                    } else if (empty($value) && $minItems === 0) {
                        // Empty array is valid for arrays with no minItems constraint
                    } else {
                        // Handle array items that might contain empty strings
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
            else if ($value === '' && in_array($propertyType, ['string', 'number', 'integer', 'boolean'])) {
                if ($isRequired === false) {
                    // Convert empty string to null for non-required scalar properties.
                    $sanitizedData[$propertyName] = null;
                } else {
                    // Keep empty string for required properties - will fail validation with clear error
                }
            }
        }//end foreach

        return $sanitizedData;

    }//end sanitizeEmptyStringsForObjectProperties()


    /**
     * Saves an object.
     *
     * @param Register|int|string|null $register   The register containing the object.
     * @param Schema|int|string        $schema     The schema to validate against.
     * @param array                    $data       The object data to save.
     * @param string|null              $uuid       The UUID of the object to update (if updating).
     * @param int|null                 $folderId   The folder ID to set on the object (optional).
     * @param bool                     $rbac       Whether to apply RBAC checks (default: true).
     * @param bool                     $multi      Whether to apply multitenancy filtering (default: true).
     * @param bool                     $persist    Whether to persist the object to database (default: true).
     * @param bool                     $silent     Whether to skip audit trail creation and events (default: false).
     * @param bool                     $validation Whether to validate the object (default: true).
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
        bool $rbac=true,
        bool $multi=true,
        bool $persist=true,
        bool $silent=false,
        bool $validation=true,
        ?array $uploadedFiles=null
    ): ObjectEntity {

        $selfData = [];
        if (isset($data['@self']) && is_array($data['@self'])) {
            $selfData = $data['@self'];
        }

        // Use @self.id as UUID if no UUID is provided
        if ($uuid === null && (isset($selfData['id']) || isset($data['id']))) {
            $uuid = $selfData['id'] ?? $data['id'];
        }

        if ($uuid === '') {
            $uuid = null;
        }

        // Remove the @self property from the data.
        unset($data['@self']);
        unset($data['id']);

        // Process uploaded files and inject them into data
        if ($uploadedFiles !== null && !empty($uploadedFiles)) {
            $data = $this->processUploadedFiles($uploadedFiles, $data);
        }

        // Debug logging can be added here if needed
        // Set schema ID based on input type.
        $schemaId = null;
        if ($schema instanceof Schema === true) {
            $schemaId = $schema->getId();
        } else {
            // Resolve schema reference if it's a string
            if (is_string($schema)) {
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

        $registerId = null;
        if ($register instanceof Register === true) {
            $registerId = $register->getId();
        } else {
            // Resolve register reference if it's a string
            if (is_string($register)) {
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

        // NOTE: Do NOT sanitize here - let validation happen first in ObjectService
        // Sanitization will happen after validation but before cascading operations
        // If UUID is provided, try to find and update existing object.
        if ($uuid !== null) {
            try {
                $existingObject = $this->objectEntityMapper->find(identifier: $uuid);

                // Prepare the object for update
                $preparedObject = $this->prepareObjectForUpdate(
                    existingObject: $existingObject,
                    schema: $schema,
                    data: $data,
                    selfData: $selfData ?? [],
                    folderId: $folderId
                );
                // If not persisting, return the prepared object.
                if ($persist === false) {
                    return $preparedObject;
                }

                // Update the object
                return $this->updateObject(register: $register, schema: $schema, data: $data, existingObject: $preparedObject, folderId: $folderId, silent: $silent);
            } catch (DoesNotExistException $e) {
                // Object not found, proceed with creating new object.
            } catch (Exception $e) {
                // Other errors during object lookup
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

        // Set folder ID if provided
        if ($folderId !== null) {
            $objectEntity->setFolder((string) $folderId);
        }

        // Prepare the object for creation (WITHOUT file processing yet)
        $preparedObject = $this->prepareObjectForCreation(
            objectEntity: $objectEntity,
            schema: $schema,
            data: $data,
            selfData: $selfData ?? [],
            multi: $multi
        );

        // If not persisting, return the prepared object.
        if ($persist === false) {
            return $preparedObject;
        }

        // Save the object to database FIRST (so it gets an ID)
        $savedEntity = $this->objectEntityMapper->insert($preparedObject);

        // NOW handle file properties - process them and replace content with file IDs
        // This must happen AFTER insert so the object has a database ID for FileService
        // IMPORTANT: If file processing fails, we must rollback the object insertion
        $filePropertiesProcessed = false;
        try {
            foreach ($data as $propertyName => $value) {
                if ($this->isFileProperty($value, $schema, $propertyName) === true) {
                    $this->handleFileProperty($savedEntity, $data, $propertyName, $schema);
                    $filePropertiesProcessed = true;
                }
            }

            // If files were processed, update the object with file IDs.
            if ($filePropertiesProcessed === true) {
                $savedEntity->setObject($data);

                // Re-hydrate image metadata if objectImageField points to a file property
                // At this point, file properties are file IDs, but we need to check if we should
                // clear the image metadata so it can be properly extracted during rendering
                $config = $schema->getConfiguration();
                if (isset($config['objectImageField'])) {
                    $imageField       = $config['objectImageField'];
                    $schemaProperties = $schema->getProperties() ?? [];

                    // Check if the image field is a file property
                    if (isset($schemaProperties[$imageField])) {
                        $propertyConfig = $schemaProperties[$imageField];
                        if (($propertyConfig['type'] ?? '') === 'file') {
                            // Clear the image metadata so it will be extracted from the file object during rendering
                            $savedEntity->setImage(null);
                        }
                    }
                }

                $savedEntity = $this->objectEntityMapper->update($savedEntity);
            }//end if
        } catch (\Exception $e) {
            // ROLLBACK: Delete the object if file processing failed
            $this->logger->warning(
                    'File processing failed, rolling back object creation',
                    [
                        'uuid'  => $savedEntity->getUuid(),
                        'error' => $e->getMessage(),
                    ]
                    );
            $this->objectEntityMapper->delete($savedEntity);

            // Re-throw the exception so the controller can handle it
            throw $e;
        }//end try

        // Create audit trail for creation if audit trails are enabled and not in silent mode.
        if (!$silent && $this->isAuditTrailsEnabled()) {
            $log = $this->auditTrailMapper->createAuditTrail(old: null, new: $savedEntity);
            $savedEntity->setLastLog($log->jsonSerialize());
        }

        // Update the object with the modified data (file IDs instead of content)
        // $savedEntity->setObject($data);
        // **CACHE INVALIDATION**: Clear collection and facet caches so new/updated objects appear immediately
        $this->objectCacheService->invalidateForObjectChange(
            $savedEntity,
            $uuid ? 'update' : 'create',
            $savedEntity->getRegister(),
            $savedEntity->getSchema()
        );

        return $savedEntity;

    }//end saveObject()


    /**
     * Prepares an object for creation by applying all necessary transformations.
     *
     * @param ObjectEntity $objectEntity The object entity to prepare.
     * @param Schema       $schema       The schema of the object.
     * @param array        $data         The object data.
     * @param array        $selfData     The @self metadata.
     * @param bool         $multi        Whether to apply multitenancy filtering.
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
        bool $multi
    ): ObjectEntity {
        // Set @self metadata properties
        $this->setSelfMetadata($objectEntity, $selfData, $data);

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

        // Prepare the data
        $preparedData = $this->prepareObjectData($objectEntity, $schema, $data);

        // Set the prepared data
        $objectEntity->setObject($preparedData);

        // Hydrate name and description from schema configuration.
        try {
            $this->hydrateObjectMetadata($objectEntity, $schema);
        } catch (Exception $e) {
            // CRITICAL FIX: Hydration failures indicate schema/data mismatch - don't suppress!
            throw new \Exception(
                'Object metadata hydration failed: '.$e->getMessage().'. This indicates a mismatch between object data and schema configuration.',
                0,
                $e
            );
        }

        // Auto-publish logic: Set published date to now if autoPublish is enabled in schema configuration
        // and no published date has been set yet (either from field mapping or explicit data)
        $config = $schema->getConfiguration();
        if (isset($config['autoPublish']) && $config['autoPublish'] === true) {
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

        // Set organisation from active organisation if not already set
        // Always respect user's active organisation regardless of multitenancy settings
        // BUT: Don't override if organisation was explicitly set via @self metadata (e.g., for organization activation)
        if (($objectEntity->getOrganisation() === null || $objectEntity->getOrganisation() === '')
            && !isset($selfData['organisation'])
        ) {
            $organisationUuid = $this->organisationService->getOrganisationForNewEntity();
            $objectEntity->setOrganisation($organisationUuid);
        }

        // Update object relations.
        try {
            $objectEntity = $this->updateObjectRelations($objectEntity, $preparedData, $schema);
        } catch (Exception $e) {
            // CRITICAL FIX: Relation processing failures indicate serious data integrity issues!
            throw new \Exception(
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
        // Set @self metadata properties
        $this->setSelfMetadata($existingObject, $selfData, $data);

        // Set folder ID if provided
        if ($folderId !== null) {
            $existingObject->setFolder((string) $folderId);
        }

        // Prepare the data
        $preparedData = $this->prepareObjectData($existingObject, $schema, $data);

        // Set the prepared data
        $existingObject->setObject($preparedData);

        // Hydrate name and description from schema configuration.
        $this->hydrateObjectMetadata($existingObject, $schema);

        // NOTE: Relations are already updated in prepareObjectForCreation() - no need to update again
        // Duplicate call would overwrite relations after handleInverseRelationsWriteBack removes properties
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
        // Extract and set slug property if present (check both @self and data)
        $slug = $selfData['slug'] ?? $data['slug'] ?? null;
        if (!empty($slug)) {
            $objectEntity->setSlug($slug);
        }

        // Extract and set published property if present
        $this->logger->debug(
                'Processing published field in SaveObject',
                [
                    'selfDataKeys' => array_keys($selfData),
                ]
                );

        if (array_key_exists('published', $selfData)) {
            $publishedValue = $selfData['published'];
            $isEmpty        = empty($publishedValue);

            $this->logger->debug(
                    'Published field found in object data',
                    [
                        'publishedValue' => $publishedValue,
                        'isEmpty'        => $isEmpty,
                    ]
                    );

            if (!empty($publishedValue)) {
                try {
                    // Convert string to DateTime if it's a valid date string
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
                    // Silently ignore invalid date formats
                }//end try
            } else {
                $this->logger->debug('Published value is empty, setting to null');
                $objectEntity->setPublished(null);
            }//end if
        } else {
            $this->logger->debug('No published field found in selfData, setting to existing value');
            $objectEntity->setPublished($objectEntity->getPublished());
        }//end if

        // Extract and set depublished property if present
        if (array_key_exists('depublished', $selfData) && !empty($selfData['depublished'])) {
            try {
                // Convert string to DateTime if it's a valid date string
                if (is_string($selfData['depublished']) === true) {
                    $objectEntity->setDepublished(new DateTime($selfData['depublished']));
                }
            } catch (Exception $exception) {
                // Silently ignore invalid date formats
            }
        } else {
            $objectEntity->setDepublished(null);
        }

        if (array_key_exists('owner', $selfData) && !empty($selfData['owner'])) {
            $objectEntity->setOwner($selfData['owner']);
        }

        if (array_key_exists('organisation', $selfData) && !empty($selfData['organisation'])) {
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
        // Sanitize empty strings after validation but before cascading operations
        // This prevents empty values from causing issues in downstream processing
        try {
            $data = $this->sanitizeEmptyStringsForObjectProperties($data, $schema);
        } catch (Exception $e) {
            // CRITICAL FIX: Sanitization failures indicate serious data problems - don't suppress!
            throw new \Exception(
                'Object data sanitization failed: '.$e->getMessage().'. This indicates invalid or corrupted object data that cannot be processed safely.',
                0,
                $e
            );
        }

        // Apply cascading operations
        $data = $this->cascadeObjects($objectEntity, $schema, $data);
        $data = $this->handleInverseRelationsWriteBack($objectEntity, $schema, $data);

        // Apply default values (including slug generation)
        $data = $this->setDefaultValues($objectEntity, $schema, $data);

        return $data;

    }//end prepareObjectData()


    /**
     * Prepares an object for saving without persisting to database.
     *
     * This method applies all the same transformations as saveObject but returns
     * the prepared ObjectEntity without saving it to the database.
     *
     * @param Register|int|string|null $register The register containing the object.
     * @param Schema|int|string        $schema   The schema to validate against.
     * @param array                    $data     The object data to prepare.
     * @param string|null              $uuid     The UUID of the object to update (if updating).
     * @param int|null                 $folderId The folder ID to set on the object (optional).
     * @param bool                     $rbac     Whether to apply RBAC checks (default: true).
     * @param bool                     $multi    Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The prepared object entity.
     *
     * @throws Exception If there is an error during preparation.
     */
    public function prepareObject(
        Register | int | string | null $register,
        Schema | int | string $schema,
        array $data,
        ?string $uuid=null,
        ?int $folderId=null,
        bool $rbac=true,
        bool $multi=true
    ): ObjectEntity {
        return $this->saveObject(
            register: $register,
            schema: $schema,
            data: $data,
            uuid: $uuid,
            folderId: $folderId,
            rbac: $rbac,
            multi: $multi,
            persist: false,
            validation: true
        );

    }//end prepareObject()


    /**
     * Sets default values for an object entity.
     *
     * @param ObjectEntity $objectEntity The object entity to set defaults for.
     *
     * @return ObjectEntity The object entity with defaults set.
     */
    public function setDefaults(ObjectEntity $objectEntity): ObjectEntity
    {
        if ($objectEntity->getCreatedAt() === null) {
            $objectEntity->setCreatedAt(new DateTime());
        }

        if ($objectEntity->getUpdatedAt() === null) {
            $objectEntity->setUpdatedAt(new DateTime());
        }

        if ($objectEntity->getUuid() === null) {
            $objectEntity->setUuid(Uuid::v4()->toRfc4122());
        }

        $user = $this->userSession->getUser();
        if ($user !== null) {
            if ($objectEntity->getCreatedBy() === null) {
                $objectEntity->setCreatedBy($user->getUID());
            }

            if ($objectEntity->getUpdatedBy() === null) {
                $objectEntity->setUpdatedBy($user->getUID());
            }
        }

        return $objectEntity;

    }//end setDefaults()


    /**
     * Processes uploaded files from multipart/form-data and injects them into object data.
     *
     * This method handles PHP's $_FILES format uploaded via multipart/form-data requests.
     * It converts uploaded files into a format that can be processed by existing file handlers.
     *
     * @param array $uploadedFiles The uploaded files array (from IRequest::getUploadedFile()).
     * @param array $data          The object data to inject files into.
     *
     * @return array The modified object data with file content injected.
     *
     * @throws Exception If file reading fails.
     *
     * @psalm-param    array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> $uploadedFiles
     * @phpstan-param  array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> $uploadedFiles
     * @psalm-param    array<string, mixed> $data
     * @phpstan-param  array<string, mixed> $data
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    private function processUploadedFiles(array $uploadedFiles, array $data): array
    {
        foreach ($uploadedFiles as $fieldName => $fileInfo) {
            // Skip files with upload errors
            if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
                // Log the error but don't fail the entire request
                $this->logger->warning(
                        'File upload error for field {field}: {error}',
                        [
                            'app'   => 'openregister',
                            'field' => $fieldName,
                            'error' => $fileInfo['error'],
                            'file'  => $fileInfo['name'] ?? 'unknown',
                        ]
                        );
                continue;
            }

            // Read file content
            $fileContent = file_get_contents($fileInfo['tmp_name']);
            if ($fileContent === false) {
                throw new Exception("Failed to read uploaded file for field '$fieldName'");
            }

            // Create a data URI from the uploaded file
            // This allows the existing file handling logic to process it
            $mimeType      = $fileInfo['type'] ?? 'application/octet-stream';
            $base64Content = base64_encode($fileContent);
            $dataUri       = "data:$mimeType;base64,$base64Content";

            // Handle array field names (e.g., 'images[]' or 'images[0]' becomes 'images')
            // Strip the array suffix/index and treat as array property
            $isArrayField   = false;
            $cleanFieldName = $fieldName;

            // Check for array notation: images[] or images[0], images[1], etc.
            if (preg_match('/^(.+)\[\d*\]$/', $fieldName, $matches)) {
                $isArrayField   = true;
                $cleanFieldName = $matches[1];
                // Extract 'images' from 'images[0]'
            }

            // Inject the data URI into the object data.
            // If the field already has a value in $data, the uploaded file takes precedence.
            if ($isArrayField === true) {
                // For array fields, append to array.
                if (!isset($data[$cleanFieldName])) {
                    $data[$cleanFieldName] = [];
                }

                $data[$cleanFieldName][] = $dataUri;
            } else {
                // For single fields, set directly
                $data[$fieldName] = $dataUri;
            }
        }//end foreach

        return $data;

    }//end processUploadedFiles()


    /**
     * Check if a value should be treated as a file property
     *
     * @param mixed       $value        The value to check
     * @param Schema|null $schema       Optional schema for property-based checking
     * @param string|null $propertyName Optional property name for schema lookup
     *
     * @return         bool True if the value should be treated as a file property
     * @phpstan-return bool
     */
    private function isFileProperty($value, ?Schema $schema=null, ?string $propertyName=null): bool
    {
        // If we have schema and property name, use schema-based checking
        if ($schema !== null && $propertyName !== null) {
            $schemaProperties = $schema->getProperties() ?? [];

            if (!isset($schemaProperties[$propertyName])) {
                return false;
                // Property not in schema, not a file
            }

            $propertyConfig = $schemaProperties[$propertyName];

            // Check if it's a direct file property
            if (($propertyConfig['type'] ?? '') === 'file') {
                return true;
            }

            // Check if it's an array of files
            if (($propertyConfig['type'] ?? '') === 'array') {
                $itemsConfig = $propertyConfig['items'] ?? [];
                if (($itemsConfig['type'] ?? '') === 'file') {
                    return true;
                }
            }

            return false;
            // Property exists but is not configured as file type
        }//end if

        // Fallback to format-based checking when schema info is not available
        // This is used within handleFileProperty for individual value validation
        // Check for single file (data URI, base64, URL with file extension, or file object)
        if (is_string($value)) {
            // Data URI format
            if (strpos($value, 'data:') === 0) {
                return true;
            }

            // URL format (http/https) - but only if it looks like a downloadable file
            if (filter_var($value, FILTER_VALIDATE_URL)
                && (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0)
            ) {
                // Parse URL to get path.
                $urlPath = parse_url($value, PHP_URL_PATH);
                if ($urlPath !== null && $urlPath !== '') {
                    // Get file extension.
                    $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

                    // Common file extensions that indicate downloadable files
                    $fileExtensions = [
                        // Documents
                        'pdf',
                        'doc',
                        'docx',
                        'xls',
                        'xlsx',
                        'ppt',
                        'pptx',
                        'odt',
                        'ods',
                        'odp',
                        'rtf',
                        'txt',
                        'csv',
                        // Images
                        'jpg',
                        'jpeg',
                        'png',
                        'gif',
                        'bmp',
                        'svg',
                        'webp',
                        'tiff',
                        'ico',
                        // Videos
                        'mp4',
                        'avi',
                        'mov',
                        'wmv',
                        'flv',
                        'webm',
                        'mkv',
                        '3gp',
                        // Audio
                        'mp3',
                        'wav',
                        'ogg',
                        'flac',
                        'aac',
                        'm4a',
                        'wma',
                        // Archives
                        'zip',
                        'rar',
                        '7z',
                        'tar',
                        'gz',
                        'bz2',
                        'xz',
                        // Other common file types
                        'xml',
                        'json',
                        'sql',
                        'exe',
                        'dmg',
                        'iso',
                        'deb',
                        'rpm',
                    ];

                    // Only treat as file if it has a recognized file extension
                    if (in_array($extension, $fileExtensions)) {
                        return true;
                    }
                }//end if

                // Don't treat regular website URLs as files
                return false;
            }//end if

            // Base64 encoded string (simple heuristic)
            if (base64_encode(base64_decode($value, true)) === $value && strlen($value) > 100) {
                return true;
            }
        }//end if

        // Check for file object (array with required file object properties)
        if (is_array($value) && $this->isFileObject($value)) {
            return true;
        }

        // Check for array of files
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item)) {
                    // Data URI
                    if (strpos($item, 'data:') === 0) {
                        return true;
                    }

                    // URL with file extension
                    if (filter_var($item, FILTER_VALIDATE_URL)
                        && (strpos($item, 'http://') === 0 || strpos($item, 'https://') === 0)
                    ) {
                        $urlPath = parse_url($item, PHP_URL_PATH);
                        if ($urlPath !== null && $urlPath !== '') {
                            $extension      = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
                            $fileExtensions = [
                                'pdf',
                                'doc',
                                'docx',
                                'xls',
                                'xlsx',
                                'ppt',
                                'pptx',
                                'odt',
                                'ods',
                                'odp',
                                'rtf',
                                'txt',
                                'csv',
                                'jpg',
                                'jpeg',
                                'png',
                                'gif',
                                'bmp',
                                'svg',
                                'webp',
                                'tiff',
                                'ico',
                                'mp4',
                                'avi',
                                'mov',
                                'wmv',
                                'flv',
                                'webm',
                                'mkv',
                                '3gp',
                                'mp3',
                                'wav',
                                'ogg',
                                'flac',
                                'aac',
                                'm4a',
                                'wma',
                                'zip',
                                'rar',
                                '7z',
                                'tar',
                                'gz',
                                'bz2',
                                'xz',
                                'xml',
                                'json',
                                'sql',
                                'exe',
                                'dmg',
                                'iso',
                                'deb',
                                'rpm',
                            ];
                            if (in_array($extension, $fileExtensions)) {
                                return true;
                            }
                        }//end if
                    }//end if

                    // Base64
                    if (base64_encode(base64_decode($item, true)) === $item && strlen($item) > 100) {
                        return true;
                    }
                } else if (is_array($item) && $this->isFileObject($item)) {
                    // File object in array
                    return true;
                }//end if
            }//end foreach
        }//end if

        return false;

    }//end isFileProperty()


    /**
     * Checks if an array represents a file object.
     *
     * A file object should have at least an 'id' and either 'title' or 'path'.
     * This matches the structure returned by the file renderer.
     *
     * @param array $value The array to check.
     *
     * @return bool Whether the array is a file object.
     *
     * @psalm-param    array<string, mixed> $value
     * @phpstan-param  array<string, mixed> $value
     * @psalm-return   bool
     * @phpstan-return bool
     */
    private function isFileObject(array $value): bool
    {
        // Must have an ID
        if (!isset($value['id'])) {
            return false;
        }

        // Must have either title or path (typical file object properties)
        if (!isset($value['title']) && !isset($value['path'])) {
            return false;
        }

        // Should not be a regular data array with other purposes
        // File objects typically have file-specific properties
        $fileProperties    = ['id', 'title', 'path', 'type', 'size', 'accessUrl', 'downloadUrl', 'labels', 'extension', 'hash', 'modified', 'published'];
        $hasFileProperties = false;

        foreach ($fileProperties as $prop) {
            if (isset($value[$prop])) {
                $hasFileProperties = true;
                break;
            }
        }

        return $hasFileProperties;

    }//end isFileObject()


    /**
     * Handles a file property during save with validation and proper ID storage.
     *
     * This method processes file properties by:
     * - Validating files against schema property configuration (MIME type, size)
     * - Applying auto tags from the property configuration
     * - Storing file IDs in the object data instead of just attaching files
     * - Supporting both single files and arrays of files
     *
     * @param ObjectEntity $objectEntity The object entity being saved.
     * @param array        &$object      The object data (passed by reference to update with file IDs).
     * @param string       $propertyName The name of the file property.
     * @param Schema       $schema       The schema containing property configuration.
     *
     * @return void
     *
     * @throws Exception If file validation fails or file operations fail.
     *
     * @psalm-param    ObjectEntity $objectEntity
     * @phpstan-param  ObjectEntity $objectEntity
     * @psalm-param    array<string, mixed> &$object
     * @phpstan-param  array<string, mixed> &$object
     * @psalm-param    string $propertyName
     * @phpstan-param  string $propertyName
     * @psalm-param    Schema $schema
     * @phpstan-param  Schema $schema
     * @psalm-return   void
     * @phpstan-return void
     */
    private function handleFileProperty(ObjectEntity $objectEntity, array &$object, string $propertyName, Schema $schema): void
    {
        $fileValue        = $object[$propertyName];
        $schemaProperties = $schema->getProperties() ?? [];

        // Get property configuration for this file property
        if (!isset($schemaProperties[$propertyName])) {
            throw new Exception("Property '$propertyName' not found in schema configuration");
        }

        $propertyConfig = $schemaProperties[$propertyName];

        // Determine if this is a direct file property or array[file]
        $isArrayProperty = ($propertyConfig['type'] ?? '') === 'array';
        $fileConfig      = $isArrayProperty ? ($propertyConfig['items'] ?? []) : $propertyConfig;

        // Validate that the property is configured for files
        if (($fileConfig['type'] ?? '') !== 'file') {
            throw new Exception("Property '$propertyName' is not configured as a file property");
        }

        // Handle file deletion: null for single files, empty array for array properties
        if ($fileValue === null || (is_array($fileValue) && empty($fileValue))) {
            // Get existing file IDs from the current object data
            $currentObjectData = $objectEntity->getObject();
            $existingFileIds   = $currentObjectData[$propertyName] ?? null;

            if ($existingFileIds !== null) {
                // Delete existing files
                if (is_array($existingFileIds)) {
                    // Array of file IDs
                    foreach ($existingFileIds as $fileId) {
                        if (is_numeric($fileId)) {
                            try {
                                $this->fileService->deleteFile((int) $fileId, $objectEntity);
                            } catch (\Exception $e) {
                                // Log but don't fail - file might already be deleted
                                $this->logger->warning("Failed to delete file $fileId: ".$e->getMessage());
                            }
                        }
                    }
                } else if (is_numeric($existingFileIds)) {
                    // Single file ID
                    try {
                        $this->fileService->deleteFile((int) $existingFileIds, $objectEntity);
                    } catch (\Exception $e) {
                        // Log but don't fail - file might already be deleted
                        $this->logger->warning("Failed to delete file $existingFileIds: ".$e->getMessage());
                    }
                }//end if
            }//end if

            // Set property to null or empty array
            $object[$propertyName] = $isArrayProperty ? [] : null;
            return;
        }//end if

        if ($isArrayProperty === true) {
            // Handle array of files.
            if (!is_array($fileValue)) {
                throw new Exception("Property '$propertyName' is configured as array but received non-array value");
            }

                         $fileIds = [];
            foreach ($fileValue as $index => $singleFileContent) {
                if ($this->isFileProperty($singleFileContent)) {
                    $fileId = $this->processSingleFileProperty(
                        objectEntity: $objectEntity,
                        fileInput: $singleFileContent,
                        propertyName: $propertyName,
                        fileConfig: $fileConfig,
                        index: $index
                    );
                    if ($fileId !== null) {
                        $fileIds[] = $fileId;
                    }
                }
            }

            // Replace the file content with file IDs in the object data
            $object[$propertyName] = $fileIds;
        } else {
            // Handle single file
            if ($this->isFileProperty($fileValue)) {
                $fileId = $this->processSingleFileProperty(
                    objectEntity: $objectEntity,
                    fileInput: $fileValue,
                    propertyName: $propertyName,
                    fileConfig: $fileConfig
                );

                // Replace the file content with file ID in the object data
                if ($fileId !== null) {
                    $object[$propertyName] = $fileId;
                }
            }
        }//end if

    }//end handleFileProperty()


    /**
     * Processes a single file property with validation, tagging, and storage.
     *
     * This method handles three types of file input:
     * - Base64 data URIs or encoded strings
     * - URLs (fetches file content from URL)
     * - File objects (existing files, returns existing ID or creates copy)
     *
     * @param ObjectEntity $objectEntity The object entity being saved.
     * @param mixed        $fileInput    The file input (string, URL, or file object).
     * @param string       $propertyName The name of the file property.
     * @param array        $fileConfig   The file property configuration from schema.
     * @param int|null     $index        Optional index for array properties.
     *
     * @return int|null The ID of the created/existing file, or null if processing fails.
     *
     * @throws Exception If file validation fails or file operations fail.
     *
     * @psalm-param    ObjectEntity $objectEntity
     * @phpstan-param  ObjectEntity $objectEntity
     * @psalm-param    mixed $fileInput
     * @phpstan-param  mixed $fileInput
     * @psalm-param    string $propertyName
     * @phpstan-param  string $propertyName
     * @psalm-param    array<string, mixed> $fileConfig
     * @phpstan-param  array<string, mixed> $fileConfig
     * @psalm-param    int|null $index
     * @phpstan-param  int|null $index
     * @psalm-return   int|null
     * @phpstan-return int|null
     */
    private function processSingleFileProperty(
        ObjectEntity $objectEntity,
        $fileInput,
        string $propertyName,
        array $fileConfig,
        ?int $index=null
    ): ?int {
        try {
            // Determine input type and process accordingly
            if (is_string($fileInput)) {
                // Handle string inputs (base64, data URI, or URL)
                return $this->processStringFileInput($objectEntity, $fileInput, $propertyName, $fileConfig, $index);
            } else if (is_array($fileInput) && $this->isFileObject($fileInput)) {
                // Handle file object input
                return $this->processFileObjectInput($objectEntity, $fileInput, $propertyName, $fileConfig, $index);
            } else {
                throw new Exception("Unsupported file input type for property '$propertyName'");
            }
        } catch (Exception $e) {
            throw $e;
        }

    }//end processSingleFileProperty()


    /**
     * Processes string file input (base64, data URI, or URL).
     *
     * @param ObjectEntity $objectEntity The object entity being saved.
     * @param string       $fileInput    The string input (base64, data URI, or URL).
     * @param string       $propertyName The name of the file property.
     * @param array        $fileConfig   The file property configuration from schema.
     * @param int|null     $index        Optional index for array properties.
     *
     * @return int The ID of the created file.
     *
     * @throws Exception If file processing fails.
     *
     * @psalm-param    ObjectEntity $objectEntity
     * @phpstan-param  ObjectEntity $objectEntity
     * @psalm-param    string $fileInput
     * @phpstan-param  string $fileInput
     * @psalm-param    string $propertyName
     * @phpstan-param  string $propertyName
     * @psalm-param    array<string, mixed> $fileConfig
     * @phpstan-param  array<string, mixed> $fileConfig
     * @psalm-param    int|null $index
     * @phpstan-param  int|null $index
     * @psalm-return   int
     * @phpstan-return int
     */
    private function processStringFileInput(
        ObjectEntity $objectEntity,
        string $fileInput,
        string $propertyName,
        array $fileConfig,
        ?int $index=null
    ): int {
        // Check if it's a URL
        if (filter_var($fileInput, FILTER_VALIDATE_URL)
            && (strpos($fileInput, 'http://') === 0 || strpos($fileInput, 'https://') === 0)
        ) {
            // Fetch file content from URL
            $fileContent = $this->fetchFileFromUrl($fileInput);
            $fileData    = $this->parseFileDataFromUrl($fileInput, $fileContent);
        } else {
            // Parse as base64 or data URI
            $fileData = $this->parseFileData($fileInput);
        }

        // Validate file against property configuration
        $this->validateFileAgainstConfig($fileData, $fileConfig, $propertyName, $index);

        // Generate filename
        $filename = $this->generateFileName($propertyName, $fileData['extension'], $index);

        // Prepare auto tags
        $autoTags = $this->prepareAutoTags($fileConfig, $propertyName, $index);

        // Check if auto-publish is enabled in the property configuration
        $autoPublish = $fileConfig['autoPublish'] ?? false;

        // Create the file with validation and tagging
        $file = $this->fileService->addFile(
            objectEntity: $objectEntity,
            fileName: $filename,
            content: $fileData['content'],
            share: $autoPublish,
            tags: $autoTags
        );

        return $file->getId();

    }//end processStringFileInput()


    /**
     * Processes file object input (existing file object).
     *
     * @param ObjectEntity $objectEntity The object entity being saved.
     * @param array        $fileObject   The file object input.
     * @param string       $propertyName The name of the file property.
     * @param array        $fileConfig   The file property configuration from schema.
     * @param int|null     $index        Optional index for array properties.
     *
     * @return int The ID of the existing or created file.
     *
     * @throws Exception If file processing fails.
     *
     * @psalm-param    ObjectEntity $objectEntity
     * @phpstan-param  ObjectEntity $objectEntity
     * @psalm-param    array<string, mixed> $fileObject
     * @phpstan-param  array<string, mixed> $fileObject
     * @psalm-param    string $propertyName
     * @phpstan-param  string $propertyName
     * @psalm-param    array<string, mixed> $fileConfig
     * @phpstan-param  array<string, mixed> $fileConfig
     * @psalm-param    int|null $index
     * @phpstan-param  int|null $index
     * @psalm-return   int
     * @phpstan-return int
     */
    private function processFileObjectInput(
        ObjectEntity $objectEntity,
        array $fileObject,
        string $propertyName,
        array $fileConfig,
        ?int $index=null
    ): int {
        // If file object has an ID, try to use the existing file
        if (isset($fileObject['id'])) {
            $fileId = (int) $fileObject['id'];

            // Validate that the existing file meets the property configuration
            // Get file info to validate against config
            try {
                $existingFile = $this->fileService->getFile(object: $objectEntity, file: $fileId);
                if ($existingFile !== null) {
                    // Validate the existing file against current config
                    $this->validateExistingFileAgainstConfig($existingFile, $fileConfig, $propertyName, $index);

                    // Apply auto tags if needed (non-destructive - adds to existing tags)
                    $this->applyAutoTagsToExistingFile($existingFile, $fileConfig, $propertyName, $index);

                    return $fileId;
                }
            } catch (Exception $e) {
                // Existing file not accessible, continue to create new one
            }
        }//end if

        // If no ID or existing file not accessible, create a new file
        // This requires downloadUrl or accessUrl to fetch content
        if (isset($fileObject['downloadUrl'])) {
            $fileUrl = $fileObject['downloadUrl'];
        } else if (isset($fileObject['accessUrl'])) {
            $fileUrl = $fileObject['accessUrl'];
        } else {
            throw new Exception("File object for property '$propertyName' has no downloadable URL");
        }

        // Fetch and process as URL
        return $this->processStringFileInput($objectEntity, $fileUrl, $propertyName, $fileConfig, $index);

    }//end processFileObjectInput()


    /**
     * Fetches file content from a URL.
     *
     * @param string $url The URL to fetch from.
     *
     * @return string The file content.
     *
     * @throws Exception If the URL cannot be fetched.
     *
     * @psalm-param    string $url
     * @phpstan-param  string $url
     * @psalm-return   string
     * @phpstan-return string
     */
    private function fetchFileFromUrl(string $url): string
    {
        // Create a context with appropriate options
        $context = stream_context_create(
                [
                    'http' => [
                        'timeout'         => 30,
        // 30 second timeout
                        'user_agent'      => 'OpenRegister/1.0',
                        'follow_location' => true,
                        'max_redirects'   => 5,
                    ],
                ]
                );

        $content = file_get_contents($url, false, $context);

        if ($content === false) {
            throw new Exception("Unable to fetch file from URL: $url");
        }

        return $content;

    }//end fetchFileFromUrl()


    /**
     * Parses file data from URL fetch results.
     *
     * @param string $url     The original URL.
     * @param string $content The fetched content.
     *
     * @return array File data with content, mimeType, extension, and size.
     *
     * @throws Exception If the file data cannot be parsed.
     *
     * @psalm-param    string $url
     * @phpstan-param  string $url
     * @psalm-param    string $content
     * @phpstan-param  string $content
     * @psalm-return   array{content: string, mimeType: string, extension: string, size: int}
     * @phpstan-return array{content: string, mimeType: string, extension: string, size: int}
     */
    private function parseFileDataFromUrl(string $url, string $content): array
    {
        // Try to detect MIME type from content
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);

        if ($mimeType === false) {
            $mimeType = 'application/octet-stream';
        }

        // Try to get extension from URL
        $parsedUrl = parse_url($url);
        $path      = $parsedUrl['path'] ?? '';
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        // If no extension from URL, get from MIME type
        if (empty($extension)) {
            $extension = $this->getExtensionFromMimeType($mimeType);
        }

        return [
            'content'   => $content,
            'mimeType'  => $mimeType,
            'extension' => $extension,
            'size'      => strlen($content),
        ];

    }//end parseFileDataFromUrl()


    /**
     * Validates an existing file against property configuration.
     *
     * @param \OCP\Files\File $file         The existing file.
     * @param array           $fileConfig   The file property configuration.
     * @param string          $propertyName The property name (for error messages).
     * @param int|null        $index        Optional array index (for error messages).
     *
     * @return void
     *
     * @throws Exception If validation fails.
     *
     * @psalm-param    \OCP\Files\File $file
     * @phpstan-param  \OCP\Files\File $file
     * @psalm-param    array<string, mixed> $fileConfig
     * @phpstan-param  array<string, mixed> $fileConfig
     * @psalm-param    string $propertyName
     * @phpstan-param  string $propertyName
     * @psalm-param    int|null $index
     * @phpstan-param  int|null $index
     * @psalm-return   void
     * @phpstan-return void
     */
    private function validateExistingFileAgainstConfig($file, array $fileConfig, string $propertyName, ?int $index=null): void
    {
        $errorPrefix = $index !== null ? "Existing file at $propertyName[$index]" : "Existing file at $propertyName";

        // Validate MIME type
        if (isset($fileConfig['allowedTypes']) && !empty($fileConfig['allowedTypes'])) {
            $fileMimeType = $file->getMimeType();
            if (!in_array($fileMimeType, $fileConfig['allowedTypes'], true)) {
                throw new Exception(
                    "$errorPrefix has invalid type '$fileMimeType'. "."Allowed types: ".implode(', ', $fileConfig['allowedTypes'])
                );
            }
        }

        // Validate file size
        if (isset($fileConfig['maxSize']) && $fileConfig['maxSize'] > 0) {
            $fileSize = $file->getSize();
            if ($fileSize > $fileConfig['maxSize']) {
                throw new Exception(
                    "$errorPrefix exceeds maximum size ({$fileConfig['maxSize']} bytes). "."File size: {$fileSize} bytes"
                );
            }
        }

    }//end validateExistingFileAgainstConfig()


    /**
     * Applies auto tags to an existing file (non-destructive).
     *
     * @param \OCP\Files\File $file         The existing file.
     * @param array           $fileConfig   The file property configuration.
     * @param string          $propertyName The property name.
     * @param int|null        $index        Optional array index.
     *
     * @return void
     *
     * @psalm-param    \OCP\Files\File $file
     * @phpstan-param  \OCP\Files\File $file
     * @psalm-param    array<string, mixed> $fileConfig
     * @phpstan-param  array<string, mixed> $fileConfig
     * @psalm-param    string $propertyName
     * @phpstan-param  string $propertyName
     * @psalm-param    int|null $index
     * @phpstan-param  int|null $index
     * @psalm-return   void
     * @phpstan-return void
     */
    private function applyAutoTagsToExistingFile($file, array $fileConfig, string $propertyName, ?int $index=null): void
    {
        $autoTags = $this->prepareAutoTags($fileConfig, $propertyName, $index);

        if (!empty($autoTags)) {
            // Get existing tags and merge with auto tags
            try {
                $formattedFile = $this->fileService->formatFile($file);
                $existingTags  = $formattedFile['labels'] ?? [];
                $allTags       = array_unique(array_merge($existingTags, $autoTags));

                // Update file with merged tags
                $this->fileService->updateFile(
                    filePath: $file->getId(),
                    content: null,
                // Don't change content
                    tags: $allTags
                );
            } catch (Exception $e) {
                // Log but don't fail - auto tagging is not critical
            }
        }

    }//end applyAutoTagsToExistingFile()


    /**
     * Parses file data from various formats (data URI, base64) and extracts metadata.
     *
     * @param string $fileContent The file content to parse.
     *
     * @return array File data with content, mimeType, extension, and size.
     *
     * @throws Exception If the file data format is invalid.
     *
     * @psalm-param    string $fileContent
     * @phpstan-param  string $fileContent
     * @psalm-return   array{content: string, mimeType: string, extension: string, size: int}
     * @phpstan-return array{content: string, mimeType: string, extension: string, size: int}
     */
    private function parseFileData(string $fileContent): array
    {
        $content   = '';
        $mimeType  = 'application/octet-stream';
        $extension = 'bin';

        // Handle data URI format (data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...)
        if (strpos($fileContent, 'data:') === 0) {
            // Extract MIME type and content from data URI
            if (preg_match('/^data:([^;]+);base64,(.+)$/', $fileContent, $matches)) {
                $mimeType = $matches[1];
                $content  = base64_decode($matches[2], true);
                // Strict mode
                if ($content === false) {
                    throw new \OCA\OpenRegister\Exception\ValidationException('Invalid base64 content in data URI');
                }
            } else {
                throw new \OCA\OpenRegister\Exception\ValidationException('Invalid data URI format');
            }
        } else {
            // Handle plain base64 content
            $content = base64_decode($fileContent, true);
            // Strict mode
            if ($content === false) {
                throw new \OCA\OpenRegister\Exception\ValidationException('Invalid base64 content');
            }

            // Try to detect MIME type from content
            $finfo            = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMimeType = $finfo->buffer($content);
            if ($detectedMimeType !== false) {
                $mimeType = $detectedMimeType;
            }
        }//end if

        // Determine file extension from MIME type
        $extension = $this->getExtensionFromMimeType($mimeType);

        return [
            'content'   => $content,
            'mimeType'  => $mimeType,
            'extension' => $extension,
            'size'      => strlen($content),
        ];

    }//end parseFileData()


    /**
     * Validates a file against property configuration.
     *
     * @param array    $fileData     The parsed file data.
     * @param array    $fileConfig   The file property configuration.
     * @param string   $propertyName The property name (for error messages).
     * @param int|null $index        Optional array index (for error messages).
     *
     * @return void
     *
     * @throws Exception If validation fails.
     *
     * @psalm-param    array{content: string, mimeType: string, extension: string, size: int} $fileData
     * @phpstan-param  array{content: string, mimeType: string, extension: string, size: int} $fileData
     * @psalm-param    array<string, mixed> $fileConfig
     * @phpstan-param  array<string, mixed> $fileConfig
     * @psalm-param    string $propertyName
     * @phpstan-param  string $propertyName
     * @psalm-param    int|null $index
     * @phpstan-param  int|null $index
     * @psalm-return   void
     * @phpstan-return void
     */
    private function validateFileAgainstConfig(array $fileData, array $fileConfig, string $propertyName, ?int $index=null): void
    {
        $errorPrefix = $index !== null ? "File at $propertyName[$index]" : "File at $propertyName";

        // Security: Block executable files (unless explicitly allowed).
        $allowExecutables = $fileConfig['allowExecutables'] ?? false;
        if ($allowExecutables === false) {
            $this->blockExecutableFiles($fileData, $errorPrefix);
        }

        // Validate MIME type
        if (isset($fileConfig['allowedTypes']) && !empty($fileConfig['allowedTypes'])) {
            if (!in_array($fileData['mimeType'], $fileConfig['allowedTypes'], true)) {
                throw new \OCA\OpenRegister\Exception\ValidationException(
                    "$errorPrefix has invalid type '{$fileData['mimeType']}'. "."Allowed types: ".implode(', ', $fileConfig['allowedTypes'])
                );
            }
        }

        // Validate file size
        if (isset($fileConfig['maxSize']) && $fileConfig['maxSize'] > 0) {
            if ($fileData['size'] > $fileConfig['maxSize']) {
                throw new \OCA\OpenRegister\Exception\ValidationException(
                    "$errorPrefix exceeds maximum size ({$fileConfig['maxSize']} bytes). "."File size: {$fileData['size']} bytes"
                );
            }
        }

    }//end validateFileAgainstConfig()


    /**
     * Blocks executable files from being uploaded for security.
     *
     * This method checks both file extensions and magic bytes to detect executables.
     *
     * @param array  $fileData    The file data containing content, mimeType, and filename.
     * @param string $errorPrefix The error message prefix for context.
     *
     * @return void
     *
     * @throws Exception If an executable file is detected.
     */
    private function blockExecutableFiles(array $fileData, string $errorPrefix): void
    {
        // List of dangerous executable extensions
        $dangerousExtensions = [
            // Windows executables
            'exe',
            'bat',
            'cmd',
            'com',
            'msi',
            'scr',
            'vbs',
            'vbe',
            'js',
            'jse',
            'wsf',
            'wsh',
            'ps1',
            'dll',
            // Unix/Linux executables
            'sh',
            'bash',
            'csh',
            'ksh',
            'zsh',
            'run',
            'bin',
            'app',
            'deb',
            'rpm',
            // Scripts and code
            'php',
            'phtml',
            'php3',
            'php4',
            'php5',
            'phps',
            'phar',
            'py',
            'pyc',
            'pyo',
            'pyw',
            'pl',
            'pm',
            'cgi',
            'rb',
            'rbw',
            'jar',
            'war',
            'ear',
            'class',
            // Containers and packages
            'appimage',
            'snap',
            'flatpak',
            // MacOS
            'dmg',
            'pkg',
            'command',
            // Android
            'apk',
            // Other dangerous
            'elf',
            'out',
            'o',
            'so',
            'dylib',
        ];

        // Check file extension
        if (isset($fileData['filename'])) {
            $extension = strtolower(pathinfo($fileData['filename'], PATHINFO_EXTENSION));
            if (in_array($extension, $dangerousExtensions, true)) {
                $this->logger->warning(
                        'Executable file upload blocked',
                        [
                            'app'       => 'openregister',
                            'filename'  => $fileData['filename'],
                            'extension' => $extension,
                            'mimeType'  => $fileData['mimeType'] ?? 'unknown',
                        ]
                        );

                throw new \OCA\OpenRegister\Exception\ValidationException(
                    "$errorPrefix is an executable file (.$extension). "."Executable files are blocked for security reasons. "."Allowed formats: documents, images, archives, data files."
                );
            }
        }

        // Check magic bytes (file signatures) in content
        if (isset($fileData['content']) && !empty($fileData['content'])) {
            $this->detectExecutableMagicBytes($fileData['content'], $errorPrefix);
        }

        // Check MIME types for executables
        $executableMimeTypes = [
            'application/x-executable',
            'application/x-sharedlib',
            'application/x-dosexec',
            'application/x-msdownload',
            'application/x-msdos-program',
            'application/x-sh',
            'application/x-shellscript',
            'application/x-php',
            'application/x-httpd-php',
            'text/x-php',
            'text/x-shellscript',
            'text/x-script.python',
            'application/x-python-code',
            'application/java-archive',
        ];

        if (isset($fileData['mimeType']) && in_array($fileData['mimeType'], $executableMimeTypes, true)) {
            $this->logger->warning(
                    'Executable MIME type blocked',
                    [
                        'app'      => 'openregister',
                        'mimeType' => $fileData['mimeType'],
                    ]
                    );

            throw new \OCA\OpenRegister\Exception\ValidationException(
                "$errorPrefix has executable MIME type '{$fileData['mimeType']}'. "."Executable files are blocked for security reasons."
            );
        }

    }//end blockExecutableFiles()


    /**
     * Detects executable magic bytes in file content.
     *
     * Magic bytes are signatures at the start of files that identify the file type.
     * This provides defense-in-depth against renamed executables.
     *
     * @param string $content     The file content to check.
     * @param string $errorPrefix The error message prefix.
     *
     * @return void
     *
     * @throws Exception If executable magic bytes are detected.
     */
    private function detectExecutableMagicBytes(string $content, string $errorPrefix): void
    {
        // Common executable magic bytes
        $magicBytes = [
            'MZ'               => 'Windows executable (PE/EXE)',
            "\x7FELF"          => 'Linux/Unix executable (ELF)',
            "#!/bin/sh"        => 'Shell script',
            "#!/bin/bash"      => 'Bash script',
            "#!/usr/bin/env"   => 'Script with env shebang',
            "<?php"            => 'PHP script',
            "\xCA\xFE\xBA\xBE" => 'Java class file',
            "PK\x03\x04"       => false,
        // ZIP - need deeper inspection as JARs are ZIPs
            "\x50\x4B\x03\x04" => false,
        // ZIP alternate
        ];

        foreach ($magicBytes as $signature => $description) {
            if ($description === false) {
                continue;
                // Skip patterns that need deeper inspection
            }

            if (strpos($content, $signature) === 0) {
                $this->logger->warning(
                        'Executable magic bytes detected',
                        [
                            'app'  => 'openregister',
                            'type' => $description,
                        ]
                        );

                throw new \OCA\OpenRegister\Exception\ValidationException(
                    "$errorPrefix contains executable code ($description). "."Executable files are blocked for security reasons."
                );
            }
        }//end foreach

        // Check for script shebangs anywhere in first 4 lines
        $firstLines = substr($content, 0, 1024);
        if (preg_match('/^#!.*\/(sh|bash|zsh|ksh|csh|python|perl|ruby|php|node)/m', $firstLines)) {
            throw new \OCA\OpenRegister\Exception\ValidationException(
                "$errorPrefix contains script shebang. "."Script files are blocked for security reasons."
            );
        }

        // Check for embedded PHP tags
        if (preg_match('/<\?php|<\?=|<script\s+language\s*=\s*["\']php/i', $firstLines)) {
            throw new \OCA\OpenRegister\Exception\ValidationException(
                "$errorPrefix contains PHP code. "."PHP files are blocked for security reasons."
            );
        }

    }//end detectExecutableMagicBytes()


    /**
     * Generates a filename for a file property.
     *
     * @param string   $propertyName The property name.
     * @param string   $extension    The file extension.
     * @param int|null $index        Optional array index.
     *
     * @return string The generated filename.
     *
     * @psalm-param    string $propertyName
     * @phpstan-param  string $propertyName
     * @psalm-param    string $extension
     * @phpstan-param  string $extension
     * @psalm-param    int|null $index
     * @phpstan-param  int|null $index
     * @psalm-return   string
     * @phpstan-return string
     */
    private function generateFileName(string $propertyName, string $extension, ?int $index=null): string
    {
        $timestamp   = time();
        $indexSuffix = $index !== null ? "_$index" : '';

        return "{$propertyName}{$indexSuffix}_{$timestamp}.{$extension}";

    }//end generateFileName()


    /**
     * Prepares auto tags for a file based on property configuration.
     *
     * @param array    $fileConfig   The file property configuration.
     * @param string   $propertyName The property name.
     * @param int|null $index        Optional array index.
     *
     * @return array The prepared auto tags.
     *
     * @psalm-param    array<string, mixed> $fileConfig
     * @phpstan-param  array<string, mixed> $fileConfig
     * @psalm-param    string $propertyName
     * @phpstan-param  string $propertyName
     * @psalm-param    int|null $index
     * @phpstan-param  int|null $index
     * @psalm-return   array<int, string>
     * @phpstan-return array<int, string>
     */
    private function prepareAutoTags(array $fileConfig, string $propertyName, ?int $index=null): array
    {
        $autoTags = $fileConfig['autoTags'] ?? [];

        // Replace placeholders in auto tags
        $processedTags = [];
        foreach ($autoTags as $tag) {
            // Replace property name placeholder
            $tag = str_replace('{property}', $propertyName, $tag);
            $tag = str_replace('{propertyName}', $propertyName, $tag);

            // Replace index placeholder for array properties
            if ($index !== null) {
                $tag = str_replace('{index}', (string) $index, $tag);
            }

            $processedTags[] = $tag;
        }

        return $processedTags;

    }//end prepareAutoTags()


    /**
     * Gets file extension from MIME type.
     *
     * @param string $mimeType The MIME type.
     *
     * @return string The file extension.
     *
     * @psalm-param    string $mimeType
     * @phpstan-param  string $mimeType
     * @psalm-return   string
     * @phpstan-return string
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeToExtension = [
            // Images
            'image/jpeg'                                                                => 'jpg',
            'image/jpg'                                                                 => 'jpg',
            'image/png'                                                                 => 'png',
            'image/gif'                                                                 => 'gif',
            'image/webp'                                                                => 'webp',
            'image/svg+xml'                                                             => 'svg',
            'image/bmp'                                                                 => 'bmp',
            'image/tiff'                                                                => 'tiff',
            'image/x-icon'                                                              => 'ico',

            // Documents
            'application/pdf'                                                           => 'pdf',
            'application/msword'                                                        => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
            'application/vnd.ms-excel'                                                  => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
            'application/vnd.ms-powerpoint'                                             => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/rtf'                                                           => 'rtf',
            'application/vnd.oasis.opendocument.text'                                   => 'odt',
            'application/vnd.oasis.opendocument.spreadsheet'                            => 'ods',
            'application/vnd.oasis.opendocument.presentation'                           => 'odp',

            // Text
            'text/plain'                                                                => 'txt',
            'text/csv'                                                                  => 'csv',
            'text/html'                                                                 => 'html',
            'text/css'                                                                  => 'css',
            'text/javascript'                                                           => 'js',
            'application/json'                                                          => 'json',
            'application/xml'                                                           => 'xml',
            'text/xml'                                                                  => 'xml',

            // Archives
            'application/zip'                                                           => 'zip',
            'application/x-rar-compressed'                                              => 'rar',
            'application/x-7z-compressed'                                               => '7z',
            'application/x-tar'                                                         => 'tar',
            'application/gzip'                                                          => 'gz',

            // Audio
            'audio/mpeg'                                                                => 'mp3',
            'audio/wav'                                                                 => 'wav',
            'audio/ogg'                                                                 => 'ogg',
            'audio/aac'                                                                 => 'aac',
            'audio/flac'                                                                => 'flac',

            // Video
            'video/mp4'                                                                 => 'mp4',
            'video/mpeg'                                                                => 'mpeg',
            'video/quicktime'                                                           => 'mov',
            'video/x-msvideo'                                                           => 'avi',
            'video/webm'                                                                => 'webm',
        ];

        return $mimeToExtension[$mimeType] ?? 'bin';

    }//end getExtensionFromMimeType()


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

        // Extract @self data if present
        $selfData = [];
        if (isset($data['@self']) && is_array($data['@self'])) {
            $selfData = $data['@self'];
        }

        // Remove @self and id from the data before processing
        unset($data['@self'], $data['id']);

        // Set register ID based on input type.
        $registerId = null;
        if ($register instanceof Register === true) {
            $registerId = $register->getId();
        } else {
            $registerId = $register;
        }

        // Set schema ID based on input type.
        $schemaId = null;
        if ($schema instanceof Schema === true) {
            $schemaId = $schema->getId();
        } else {
            $schemaId = $schema;
        }

        // Prepare the object for update using the new structure
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
        if (!$silent && $this->isAuditTrailsEnabled()) {
            $log = $this->auditTrailMapper->createAuditTrail(old: $oldObject, new: $updatedEntity);
            $updatedEntity->setLastLog($log->jsonSerialize());
        }

        // Handle file properties - process them and replace content with file IDs
        $filePropertiesProcessed = false;
        foreach ($data as $propertyName => $value) {
            if ($this->isFileProperty($value, $schema, $propertyName) === true) {
                $this->handleFileProperty($updatedEntity, $data, $propertyName, $schema);
                $filePropertiesProcessed = true;
            }
        }

        // Update the object with the modified data (file IDs instead of content).
        if ($filePropertiesProcessed === true) {
            $updatedEntity->setObject($data);

            // Clear image metadata if objectImageField points to a file property
            // This ensures the image URL is extracted from the file object during rendering
            $config = $schema->getConfiguration();
            if (isset($config['objectImageField'])) {
                $imageField       = $config['objectImageField'];
                $schemaProperties = $schema->getProperties() ?? [];

                // Check if the image field is a file property
                if (isset($schemaProperties[$imageField])) {
                    $propertyConfig = $schemaProperties[$imageField];
                    if (($propertyConfig['type'] ?? '') === 'file') {
                        // Clear the image metadata so it will be extracted from the file object during rendering
                        $updatedEntity->setImage(null);
                    }
                }
            }

            // Save the updated entity with file IDs back to database
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
        // If the array is completely empty, it's effectively empty
        if (empty($object)) {
            return true;
        }

        // Check each value in the object
        foreach ($object as $key => $value) {
            // Skip metadata keys that don't represent actual data
            if (in_array($key, ['@self', 'id', '_id']) === true) {
                continue;
            }

            // If we find any non-empty value, the object is not effectively empty
            if ($this->isValueNotEmpty($value)) {
                return false;
            }
        }

        // All values are empty, so the object is effectively empty
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
        // Null values are empty
        if ($value === null) {
            return false;
        }

        // Empty strings are empty
        if (is_string($value) && trim($value) === '') {
            return false;
        }

        // Empty arrays are empty
        if (is_array($value) && empty($value)) {
            return false;
        }

        // For objects/arrays with content, check recursively
        if (is_array($value) && !empty($value)) {
            // If it's an associative array (object-like), check if it's effectively empty
            if (array_keys($value) !== range(0, count($value) - 1)) {
                return !$this->isEffectivelyEmptyObject($value);
            }

            // For indexed arrays, check if any element is not empty
            foreach ($value as $item) {
                if ($this->isValueNotEmpty($item)) {
                    return true;
                }
            }

            return false;
        }

        // For all other values (numbers, booleans, etc.), they are not empty
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
        } catch (\Exception $e) {
            // If we can't get settings, default to enabled for safety
            $this->logger->warning('Failed to check audit trails setting, defaulting to enabled', ['error' => $e->getMessage()]);
            return true;
        }

    }//end isAuditTrailsEnabled()


}//end class
