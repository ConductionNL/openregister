<?php
/**
 * OpenRegister RenderObject Handler
 *
 * Handler class responsible for transforming objects into their presentational format.
 * This handler provides methods for:
 * - Converting objects to their JSON representation
 * - Handling property extensions and nested objects
 * - Managing depth control for nested rendering
 * - Applying field filtering and selection
 * - Formatting object properties for display
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
use Exception;
use JsonSerializable;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Service\FileService;
use OCP\IURLGenerator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\ObjectCacheService;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handler class for rendering objects in the OpenRegister application.
 *
 * This handler is responsible for transforming objects into their presentational format,
 * including handling of extensions, depth control, and field filtering.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\ObjectHandlers
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   1.0.0
 * @copyright 2024 Conduction b.v.
 */
class RenderObject
{

    /**
     * Cache of registers indexed by ID
     *
     * @var array<int|string, Register>
     */
    private array $registersCache = [];

    /**
     * Cache of schemas indexed by ID
     *
     * @var array<int|string, Schema>
     */
    private array $schemasCache = [];

    /**
     * Cache of objects indexed by ID or UUID
     *
     * @var array<int|string, ObjectEntity>
     */
    private array $objectsCache = [];

    /**
     * Ultra-aggressive preload cache for sub-second performance
     *
     * Contains ALL relationship objects preloaded in a single query
     * for instant access during rendering without any additional database calls.
     *
     * @var array<string, ObjectEntity>
     */
    private array $ultraPreloadCache = [];


    /**
     * Constructor for RenderObject handler.
     *
     * @param IURLGenerator          $urlGenerator       URL generator service.
     * @param FileMapper             $fileMapper         File mapper for database operations.
     * @param FileService            $fileService        File service for managing files.
     * @param ObjectEntityMapper     $objectEntityMapper Object entity mapper for database operations.
     * @param RegisterMapper         $registerMapper     Register mapper for database operations.
     * @param SchemaMapper           $schemaMapper       Schema mapper for database operations.
     * @param ISystemTagManager      $systemTagManager   System tag manager for file tags.
     * @param ISystemTagObjectMapper $systemTagMapper    System tag object mapper for file tags.
     * @param ObjectCacheService     $objectCacheService Cache service for performance optimization.
     * @param LoggerInterface        $logger             Logger for performance monitoring.
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly FileMapper $fileMapper,
        private readonly FileService $fileService,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ISystemTagManager $systemTagManager,
        private readonly ISystemTagObjectMapper $systemTagMapper,
        private readonly ObjectCacheService $objectCacheService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Preload all related objects for bulk operations to prevent N+1 queries
     *
     * This method analyzes all objects and their extend requirements, collects
     * all related object IDs, and loads them in bulk to eliminate N+1 query problems.
     *
     * @param array $objects Array of ObjectEntity objects to analyze
     * @param array $extend  Array of properties to extend
     *
     * @return array Array of preloaded objects indexed by ID/UUID
     *
     * @phpstan-param  array<ObjectEntity> $objects
     * @phpstan-param  array<string> $extend
     * @phpstan-return array<string, ObjectEntity>
     * @psalm-param    array<ObjectEntity> $objects
     * @psalm-param    array<string> $extend
     * @psalm-return   array<string, ObjectEntity>
     */
    public function preloadRelatedObjects(array $objects, array $extend): array
    {
        if ($objects === [] || $extend === []) {
            return [];
        }

        $allRelatedIds = [];

        // Step 1: Collect all relationship IDs from all objects.
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $objectData = $object->getObject();

            foreach ($extend as $extendField) {
                // Skip special fields.
                if (str_starts_with($extendField, '@') === true) {
                    continue;
                }

                $value = $objectData[$extendField] ?? null;

                if (is_array($value) === true) {
                    // Multiple relationships.
                    foreach ($value as $relatedId) {
                        if (is_string($relatedId) === true || is_int($relatedId) === true) {
                            $allRelatedIds[] = (string) $relatedId;
                        }
                    }
                } else if (is_string($value) === true || is_int($value) === true) {
                    // Single relationship.
                    $allRelatedIds[] = (string) $value;
                }
            }
        }//end foreach

        // Step 2: Remove duplicates and empty values.
        $uniqueIds = array_filter(array_unique($allRelatedIds), fn($id) => $id !== '');

        if ($uniqueIds === []) {
            return [];
        }

        // Step 3: Use ObjectCacheService for optimized bulk loading.
        try {
            $preloadStart   = microtime(true);
            $relatedObjects = $this->objectCacheService->preloadObjects($uniqueIds);
            $preloadTime    = round((microtime(true) - $preloadStart) * 1000, 2);

            $this->logger->debug(
                    'ObjectCache preload completed',
                    [
                        'preloadTime'  => $preloadTime.'ms',
                        'requestedIds' => count($uniqueIds),
                        'foundObjects' => count($relatedObjects),
                    ]
                    );

            // Step 4: Index by both ID and UUID for quick lookup.
            $indexedObjects = [];
            foreach ($relatedObjects as $relatedObject) {
                if ($relatedObject instanceof ObjectEntity) {
                    $indexedObjects[$relatedObject->getId()] = $relatedObject;
                    if ($relatedObject->getUuid()) {
                        $indexedObjects[$relatedObject->getUuid()] = $relatedObject;
                    }
                }
            }

            // Step 5: Add to local cache for backward compatibility.
            $this->objectsCache = array_merge($this->objectsCache, $indexedObjects);

            return $indexedObjects;
        } catch (\Exception $e) {
            // Log error but don't break the process.
            $this->logger->error(
                    'Bulk preloading failed',
                    [
                        'exception' => $e->getMessage(),
                        'uniqueIds' => count($uniqueIds),
                        'objects'   => count($objects),
                    ]
                    );
            return [];
        }//end try

    }//end preloadRelatedObjects()


    /**
     * Set the ultra-aggressive preload cache for maximum performance
     *
     * This method receives ALL relationship objects loaded in a single query
     * and stores them for instant access during rendering, eliminating all
     * individual database queries for extended properties.
     *
     * @param array $ultraPreloadCache Array of preloaded objects indexed by ID/UUID
     *
     * @phpstan-param array<string, ObjectEntity> $ultraPreloadCache
     * @psalm-param   array<string, ObjectEntity> $ultraPreloadCache
     */
    public function setUltraPreloadCache(array $ultraPreloadCache): void
    {
        $this->ultraPreloadCache = $ultraPreloadCache;
        $this->logger->debug(
                'Ultra preload cache set',
                [
                    'cachedObjectCount' => count($ultraPreloadCache),
                ]
                );

    }//end setUltraPreloadCache()


    /**
     * Get the size of the ultra preload cache for monitoring
     *
     * @return int Number of objects in the ultra preload cache
     */
    public function getUltraCacheSize(): int
    {
        return count($this->ultraPreloadCache);

    }//end getUltraCacheSize()


    /**
     * Get a register from cache or database
     *
     * @param int|string $id The register ID
     *
     * @return Register|null The register or null if not found
     */
    private function getRegister(int | string $id): ?Register
    {
        // Return from cache if available.
        if (isset($this->registersCache[$id]) === true) {
            return $this->registersCache[$id];
        }

        try {
            $register = $this->registerMapper->find($id);
            // Cache the result.
            $this->registersCache[$id] = $register;
            return $register;
        } catch (\Exception $e) {
            return null;
        }

    }//end getRegister()


    /**
     * Get a schema from cache or database
     *
     * @param int|string $id The schema ID
     *
     * @return Schema|null The schema or null if not found
     */
    private function getSchema(int | string $id): ?Schema
    {
        // Return from cache if available.
        if (isset($this->schemasCache[$id]) === true) {
            return $this->schemasCache[$id];
        }

        try {
            $schema = $this->schemaMapper->find($id);
            // Cache the result.
            $this->schemasCache[$id] = $schema;
            return $schema;
        } catch (\Exception $e) {
            return null;
        }

    }//end getSchema()


    /**
     * Get an object from cache or database
     *
     * @param int|string $id The object ID or UUID
     *
     * @return ObjectEntity|null The object or null if not found
     */
    private function getObject(int | string $id): ?ObjectEntity
    {
        // **ULTRA PERFORMANCE**: Check ultra preload cache first (fastest possible).
        if (isset($this->ultraPreloadCache[(string) $id])) {
            return $this->ultraPreloadCache[(string) $id];
        }

        // **PERFORMANCE OPTIMIZATION**: Use ObjectCacheService for optimized caching.
        // First check local cache for backward compatibility.
        if (isset($this->objectsCache[$id]) === true) {
            return $this->objectsCache[$id];
        }

        // Use cache service for optimized loading (only if not in ultra cache).
        $object = $this->objectCacheService->getObject($id);

        // Update local cache for backward compatibility.
        if ($object !== null) {
            $this->objectsCache[$id] = $object;
            if ($object->getUuid() !== null && $object->getUuid() !== '') {
                $this->objectsCache[$object->getUuid()] = $object;
            }
        }

        return $object;

    }//end getObject()


    /**
     * Pre-cache multiple registers
     *
     * @param array<int|string> $ids Array of register IDs to cache
     *
     * @return void
     */
    private function preloadRegisters(array $ids): void
    {
        // Filter out IDs that are not already cached and cache them.
        array_filter(
                $ids,
                function ($id) {
                    if (isset($this->registersCache[$id]) === false) {
                        $this->getRegister($id);
                    }

                    return false;
                    // Return false to ensure array_filter doesn't keep any elements.
                }
                );

    }//end preloadRegisters()


    /**
     * Pre-cache multiple schemas
     *
     * @param array<int|string> $ids Array of schema IDs to cache
     *
     * @return void
     */
    private function preloadSchemas(array $ids): void
    {
        // Filter out IDs that are not already cached and cache them.
        array_filter(
                $ids,
                function ($id) {
                    if (isset($this->schemasCache[$id]) === false) {
                        $this->getSchema($id);
                    }

                    return false;
                    // Return false to ensure array_filter doesn't keep any elements.
                }
                );

    }//end preloadSchemas()


    /**
     * Pre-cache multiple objects
     *
     * @param array<int|string> $ids Array of object IDs or UUIDs to cache
     *
     * @return void
     */
    private function preloadObjects(array $ids): void
    {
        // Filter out IDs that are not already cached and cache them.
        array_filter(
                $ids,
                function ($id) {
                    if (isset($this->objectsCache[$id]) === false) {
                        $this->getObject($id);
                    }

                    return false;
                    // Return false to ensure array_filter doesn't keep any elements.
                }
                );

    }//end preloadObjects()


    /**
     * Clear all caches
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->registersCache = [];
        $this->schemasCache   = [];
        $this->objectsCache   = [];

    }//end clearCache()


    /**
     * Add formatted files to the files array in the entity using FileMapper.
     *
     * This method retrieves files for an object using the FileMapper's getFilesForObject method,
     * which handles both folder property lookup and UUID-based fallback search.
     * The retrieved files are then formatted to match the FileService->formatFile() structure.
     * Share information is now included directly from the FileMapper database query.
     *
     * @param ObjectEntity $object The entity to add the files to
     *
     * @return ObjectEntity The updated object with files information
     *
     * @throws \RuntimeException If multiple nodes are found for the object's uuid
     */
    private function renderFiles(ObjectEntity $object): ObjectEntity
    {
        // Use FileMapper to get files for the object (handles folder property and UUID fallback).
        $fileRecords = $this->fileMapper->getFilesForObject($object);

        // If no files found, set empty array and return.
        if (empty($fileRecords)) {
            $object->setFiles([]);
            return $object;
        }

        // Format the files to match FileService->formatFile() structure.
        $formattedFiles = [];
        foreach ($fileRecords as $fileRecord) {
            // Get file tags using our local getFileTags method.
            $labels = $this->getFileTags((string) $fileRecord['fileid']);

            // Create formatted file metadata matching FileService->formatFile() structure.
            // Share information is now included directly from FileMapper.
            $formattedFile = [
                'id'          => (string) $fileRecord['fileid'],
                'path'        => $fileRecord['path'],
                'title'       => $fileRecord['name'],
                'accessUrl'   => $fileRecord['accessUrl'] ?? null,
                'downloadUrl' => $fileRecord['downloadUrl'] ?? null,
                'type'        => $fileRecord['mimetype'] ?? 'application/octet-stream',
                'extension'   => pathinfo($fileRecord['name'], PATHINFO_EXTENSION),
                'size'        => (int) $fileRecord['size'],
                'hash'        => $fileRecord['etag'] ?? '',
                'published'   => $fileRecord['published'] ?? null,
                'modified'    => isset($fileRecord['mtime']) ? (new \DateTime())->setTimestamp($fileRecord['mtime'])->format('c') : null,
                'labels'      => $labels,
            ];

            $formattedFiles[] = $formattedFile;
        }//end foreach

        // Set the formatted files on the object.
        $object->setFiles($formattedFiles);

        return $object;

    }//end renderFiles()


    /**
     * Get the tags associated with a file.
     *
     * This method implements the same logic as FileService->getFileTags() to retrieve
     * tags associated with a file by its ID. It filters out internal 'object:' tags.
     *
     * @param string $fileId The ID of the file
     *
     * @return array<int, string> The list of tags associated with the file (excluding object: tags)
     *
     * @phpstan-return array<int, string>
     * @psalm-return   array<int, string>
     */
    private function getFileTags(string $fileId): array
    {
        // File tag type constant (same as in FileService).
        $fileTagType = 'files';

        // Get tag IDs for the file.
        $tagIds = $this->systemTagMapper->getTagIdsForObjects(
            objIds: [$fileId],
            objectType: $fileTagType
        );

        // Check if file has any tags.
        if (isset($tagIds[$fileId]) === false || empty($tagIds[$fileId]) === true) {
            return [];
        }

        // Get the actual tag objects by their IDs.
        $tags = $this->systemTagManager->getTagsByIds(tagIds: $tagIds[$fileId]);

        // Extract tag names from tag objects and filter out 'object:' tags.
        $tagNames = array_filter(
            array_map(
           static function ($tag) {
                return $tag->getName();
           },
            $tags
           ),
            static function ($tagName) {
                // Filter out internal object tags.
                return !str_starts_with($tagName, 'object:');
            }
        );

        // Return array of filtered tag names.
        return array_values($tagNames);

    }//end getFileTags()


    /**
     * Hydrates file properties by replacing file IDs with actual file objects.
     *
     * This method processes object properties that are configured as file types in the schema,
     * replacing stored file IDs with complete file objects for presentation. It handles both
     * single file properties and arrays of files.
     *
     * @param ObjectEntity $entity The entity to process.
     *
     * @return ObjectEntity The entity with hydrated file properties.
     *
     * @throws Exception If schema or file operations fail.
     *
     * @psalm-param    ObjectEntity $entity
     * @phpstan-param  ObjectEntity $entity
     * @psalm-return   ObjectEntity
     * @phpstan-return ObjectEntity
     */
    private function renderFileProperties(ObjectEntity $entity): ObjectEntity
    {
        try {
            // Get the schema for this object to understand property configurations.
            $schema = $this->getSchema($entity->getSchema());
            if ($schema === null) {
                // If no schema found, return entity unchanged.
                return $entity;
            }

            $schemaProperties = $schema->getProperties() ?? [];
            $objectData       = $entity->getObject();

            // First, ensure all file array properties exist in objectData (even if empty).
            // This is important for properties that have been set to empty arrays.
            foreach ($schemaProperties as $propertyName => $propertyConfig) {
                if ($this->isFilePropertyConfig($propertyConfig)) {
                    $isArrayProperty = ($propertyConfig['type'] ?? '') === 'array';

                    // If it's an array property and not set, initialize it as empty array.
                    if ($isArrayProperty && !isset($objectData[$propertyName])) {
                        $objectData[$propertyName] = [];
                    }
                }
            }

            // Process each property in the object data.
            foreach ($objectData as $propertyName => $propertyValue) {
                // Skip metadata properties.
                if (str_starts_with($propertyName, '@') === true || $propertyName === 'id') {
                    continue;
                }

                // Check if this property is configured in the schema.
                if (isset($schemaProperties[$propertyName]) === false) {
                    continue;
                }

                $propertyConfig = $schemaProperties[$propertyName];

                // Check if this is a file property (direct or array[file]).
                if ($this->isFilePropertyConfig($propertyConfig) === true) {
                    $objectData[$propertyName] = $this->hydrateFileProperty(
                     propertyValue: $propertyValue,
                     propertyConfig: $propertyConfig,
                     propertyName: $propertyName
                    );
                }
            }//end foreach

            // Update the entity with hydrated data.
            $entity->setObject($objectData);
        } catch (Exception $e) {
            // Log error but don't break rendering - just return original entity.
        }//end try

        return $entity;

    }//end renderFileProperties()


    /**
     * Checks if a property configuration indicates a file property.
     *
     * @param array $propertyConfig The property configuration from schema.
     *
     * @return bool True if this is a file property configuration.
     *
     * @psalm-param    array<string, mixed> $propertyConfig
     * @phpstan-param  array<string, mixed> $propertyConfig
     * @psalm-return   bool
     * @phpstan-return bool
     */
    private function isFilePropertyConfig(array $propertyConfig): bool
    {
        // Direct file property.
        if (($propertyConfig['type'] ?? '') === 'file') {
            return true;
        }

        // Array of files.
        if (($propertyConfig['type'] ?? '') === 'array'
            && isset($propertyConfig['items'])
            && ($propertyConfig['items']['type'] ?? '') === 'file'
        ) {
            return true;
        }

        return false;

    }//end isFilePropertyConfig()


    /**
     * Hydrates a file property by replacing file IDs with file objects.
     *
     * @param mixed  $propertyValue  The property value (file ID or array of file IDs).
     * @param array  $propertyConfig The property configuration from schema.
     * @param string $propertyName   The property name (for error reporting).
     *
     * @return mixed The hydrated property value (file object or array of file objects).
     *
     * @psalm-param   mixed $propertyValue
     * @phpstan-param mixed $propertyValue
     * @psalm-param   array<string, mixed> $propertyConfig
     * @phpstan-param array<string, mixed> $propertyConfig
     * @psalm-param   string $propertyName
     * @phpstan-param string $propertyName
     *
     * @psalm-return   mixed
     * @phpstan-return mixed
     */
    private function hydrateFileProperty($propertyValue, array $propertyConfig, string $propertyName)
    {
        $isArrayProperty = ($propertyConfig['type'] ?? '') === 'array';

        if ($isArrayProperty === true) {
            // Handle array of files.
            if (is_array($propertyValue) === false) {
                return $propertyValue;
                // Return unchanged if not an array.
            }

            $hydratedFiles = [];
            foreach ($propertyValue as $fileId) {
                $fileObject = $this->getFileObject($fileId);
                if ($fileObject !== null) {
                    $hydratedFiles[] = $fileObject;
                }
            }

            return $hydratedFiles;
        } else {
            // Handle single file.
            if (is_numeric($propertyValue) || (is_string($propertyValue) && ctype_digit($propertyValue))) {
                return $this->getFileObject($propertyValue);
            }

            return $propertyValue;
            // Return unchanged if not a file ID.
        }//end if

    }//end hydrateFileProperty()


    /**
     * Hydrates metadata (@self) from file properties after they've been converted to file objects.
     *
     * This method extracts metadata like image URLs from file properties that have been
     * hydrated with accessUrl, downloadUrl, etc.
     *
     * @param ObjectEntity $entity The entity to hydrate metadata for.
     *
     * @return ObjectEntity The entity with hydrated metadata.
     */
    private function hydrateMetadataFromFileProperties(ObjectEntity $entity): ObjectEntity
    {
        try {
            // Get the schema for this object to understand property configurations.
            $schema = $this->getSchema($entity->getSchema());
            if ($schema === null) {
                return $entity;
            }

            $config     = $schema->getConfiguration();
            $objectData = $entity->getObject();

            // Check if objectImageField is configured.
            if (!empty($config['objectImageField'])) {
                $imageField = $config['objectImageField'];

                // Get the value from the configured field.
                $value = $this->getValueFromPath($objectData, $imageField);

                // Check if the value is a file object (has downloadUrl or accessUrl).
                if (is_array($value) && (isset($value['downloadUrl']) || isset($value['accessUrl']))) {
                    // Set the image URL on the entity itself (not in object data).
                    // This will be serialized to @self.image in jsonSerialize().
                    // Prefer downloadUrl, fallback to accessUrl.
                    $entity->setImage($value['downloadUrl'] ?? $value['accessUrl']);
                } else {
                    // If the file property is null/empty, set image to null.
                    $entity->setImage(null);
                }
            }
        } catch (\Exception $e) {
            // Log error but don't break rendering - just return original entity.
        }//end try

        return $entity;

    }//end hydrateMetadataFromFileProperties()


    /**
     * Helper method to get a value from a nested path in an array.
     *
     * @param array  $data The data array.
     * @param string $path The path (e.g., 'logo' or 'nested.field').
     *
     * @return mixed|null The value at the path or null if not found.
     */
    private function getValueFromPath(array $data, string $path)
    {
        $keys  = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;

    }//end getValueFromPath()


    /**
     * Gets a file object by its ID using the FileService.
     *
     * @param mixed $fileId The file ID to retrieve.
     *
     * @return array|null The formatted file object or null if not found.
     *
     * @psalm-param    mixed $fileId
     * @phpstan-param  mixed $fileId
     * @psalm-return   array<string, mixed>|null
     * @phpstan-return array<string, mixed>|null
     */
    private function getFileObject($fileId): ?array
    {
        try {
            // Convert to string/int as needed.
            $fileIdStr = is_numeric($fileId) ? (string) $fileId : $fileId;

            if (!is_string($fileIdStr) && !is_int($fileIdStr)) {
                return null;
            }

            // Use FileMapper to get file information directly.
            $fileRecord = $this->fileMapper->getFile((int) $fileIdStr);

            if (empty($fileRecord)) {
                return null;
            }

            // Get file tags.
            $labels = $this->getFileTags((string) $fileRecord['fileid']);

            // Format the file object (same structure as renderFiles method).
            return [
                'id'          => (string) $fileRecord['fileid'],
                'path'        => $fileRecord['path'],
                'title'       => $fileRecord['name'],
                'accessUrl'   => $fileRecord['accessUrl'] ?? null,
                'downloadUrl' => $fileRecord['downloadUrl'] ?? null,
                'type'        => $fileRecord['mimetype'] ?? 'application/octet-stream',
                'extension'   => pathinfo($fileRecord['name'], PATHINFO_EXTENSION),
                'size'        => (int) $fileRecord['size'],
                'hash'        => $fileRecord['etag'] ?? '',
                'published'   => $fileRecord['published'] ?? null,
                'modified'    => isset($fileRecord['mtime']) ? (new \DateTime())->setTimestamp($fileRecord['mtime'])->format('c') : null,
                'labels'      => $labels,
            ];
        } catch (Exception $e) {
            return null;
        }//end try

    }//end getFileObject()


    /**
     * Renders an entity with optional extensions and filters.
     *
     * This method takes an ObjectEntity and applies extensions and filters to it.
     * It maintains the object's structure while allowing for property extension
     * and filtering based on the provided parameters. Additionally, it accepts
     * preloaded registers, schemas, and objects to enhance rendering performance.
     *
     * @param ObjectEntity      $entity     The entity to render
     * @param array|string|null $extend     Properties to extend the entity with
     * @param int               $depth      The depth level for nested rendering
     * @param array|null        $filter     Filters to apply to the rendered entity
     * @param array|null        $fields     Specific fields to include in the output
     * @param array|null        $unset      Properties to remove from the rendered entity
     * @param array|null        $registers  Preloaded registers to use
     * @param array|null        $schemas    Preloaded schemas to use
     * @param array|null        $objects    Preloaded objects to use
     * @param array|null        $visitedIds All ids we already handled
     * @param bool              $rbac       Whether to apply RBAC checks (default: true).
     * @param bool              $multi      Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The rendered entity with applied extensions and filters
     */
    public function renderEntity(
        ObjectEntity $entity,
        array | string | null $extend=[],
        int $depth=0,
        ?array $filter=[],
        ?array $fields=[],
        ?array $unset=[],
        ?array $registers=[],
        ?array $schemas=[],
        ?array $objects=[],
        ?array $visitedIds=[],
        bool $rbac=true,
        bool $multi=true
    ): ObjectEntity {
        if ($entity->getUuid() !== null && in_array($entity->getUuid(), $visitedIds, true)) {
            return $entity->setObject(['@circular' => true, 'id' => $entity->getUuid()]);
        }

        if ($entity->getUuid() !== null) {
            $visitedIds[] = $entity->getUuid();
        }

        // Add preloaded registers to the global cache.
        if (empty($registers) === false) {
            foreach ($registers as $id => $register) {
                $this->registersCache[$id] = $register;
            }
        }

        // Add preloaded schemas to the global cache.
        if (empty($schemas) === false) {
            foreach ($schemas as $id => $schema) {
                $this->schemasCache[$id] = $schema;
            }
        }

        // Add preloaded objects to the global cache.
        if (empty($objects) === false) {
            foreach ($objects as $id => $object) {
                $this->objectsCache[$id] = $object;
            }
        }

        $entity = $this->renderFiles($entity);

        // Hydrate file properties (replace file IDs with file objects).
        $entity = $this->renderFileProperties($entity);

        // Hydrate metadata from file properties (e.g., extract accessUrl for image metadata).
        $entity = $this->hydrateMetadataFromFileProperties($entity);

        // Get the object data as an array for manipulation.
        $objectData = $entity->getObject();

        // Apply field filtering if specified.
        if (empty($fields) === false) {
            $fields[] = '@self';
            $fields[] = 'id';

            $filteredData = [];
            foreach ($fields as $field) {
                if (isset($objectData[$field]) === true) {
                    $filteredData[$field] = $objectData[$field];
                }
            }

            $objectData = $filteredData;
            $entity->setObject($objectData);
        }

        // Apply filters if specified.
        if (empty($filter) === false) {
            foreach ($filter as $key => $value) {
                if (isset($objectData[$key]) === true && $objectData[$key] !== $value) {
                    $entity->setObject([]);
                    return $entity;
                }
            }
        }

        // Apply unset - remove specified properties from the response.
        if (empty($unset) === false) {
            foreach ($unset as $property) {
                if (isset($objectData[$property]) === true) {
                    unset($objectData[$property]);
                }
            }

            $entity->setObject($objectData);
        }

        // Handle inversed properties if depth limit not reached.
        if ($depth < 10) {
            $objectData = $this->handleInversedProperties(
                $entity,
                $objectData,
                $depth,
                $filter,
                $fields,
                $unset,
                $registers,
                $schemas,
                $objects
            );
        }

        // Convert extend to an array if it's a string.
        if (is_array($extend) === true && in_array('all', $extend, true)) {
            $id       = $objectData['id'] ?? null;
            $originId = $objectData['originId'] ?? null;

            foreach ($objectData as $key => $value) {
                if (in_array($key, ['id', 'originId'], true)) {
                    continue;
                }

                if ($value !== $id && $value !== $originId) {
                    $extend[] = $key;
                }
            }
        } else if (is_string($extend) === true) {
            $extend = explode(',', $extend);
        }

        // Handle extensions if depth limit not reached.
        if (empty($extend) === false && $depth < 10) {
            $objectData = $this->extendObject($entity, $extend, $objectData, $depth, $filter, $fields, $unset, $visitedIds);
        }

        $entity->setObject($objectData);

        return $entity;

    }//end renderEntity()


    /**
     * Handle extends containing a wildcard ($)
     *
     * @param array $objectData The data to extend
     * @param array $extend     The fields that should be extended
     * @param int   $depth      The current depth.
     *
     * @return array|Dot
     */
    private function handleWildcardExtends(array $objectData, array &$extend, int $depth): array
    {
        $objectData = new Dot($objectData);
        if ($depth >= 10) {
            return $objectData->all();
        }

        $wildcardExtends = array_filter(
                $extend,
                function (string $key) {
                    return str_contains($key, '.$.');
                }
        );

        $extendedRoots = [];

        foreach ($wildcardExtends as $key => $wildcardExtend) {
            unset($extend[$key]);

            [$root, $extends] = explode(separator: '.$.', string: $wildcardExtend, limit: 2);

            if (is_numeric($key) === true) {
                $extendedRoots[$root][] = $extends;
            } else {
                [$root, $path] = explode(separator: '.$.', string: $key, limit: 2);
                $extendedRoots[$root][$path] = $extends;
            }
        }

        foreach ($extendedRoots as $root => $extends) {
            $data = $objectData->get(userId: $root);
            if (is_iterable($data) === false) {
                continue;
            }

            foreach ($data as $key => $datum) {
                $tmpExtends = $extends;
                $data[$key] = $this->handleExtendDot($datum, $tmpExtends, $depth);
            }

            $objectData->set($root, $data);
        }

        return $objectData->all();

    }//end handleWildcardExtends()


    /**
     * Handle extends on a dot array
     *
     * @param array      $data       The data to extend.
     * @param array      $extend     The fields to extend.
     * @param int        $depth      The current depth.
     * @param bool|null  $allFlag    If we extend all or not.
     * @param array|null $visitedIds All ids we already handled.
     *
     * @return array
     *
     * @throws \OCP\DB\Exception
     */
    private function handleExtendDot(array $data, array &$extend, int $depth, bool $allFlag=false, array $visitedIds=[]): array
    {
        $data = $this->handleWildcardExtends(objectData: $data, extend: $extend, depth: $depth + 1);

        $dataDot = new Dot($data);

        foreach ($extend as $override => $key) {
            // Skip if the key does not have to be extended.
            if ($dataDot->has(keys: $key) === false) {
                continue;
            }

            // Skip if the key starts with '@' (special fields).
            if (str_starts_with($key, '@')) {
                continue;
            }

            // Get sub-keys for nested extension.
            $keyExtends = array_map(
                fn(string $extendedKey) => substr(string: $extendedKey, offset: strlen($key) + 1),
                array_filter(
                    $extend,
                    fn(string $singleKey) => str_starts_with(haystack: $singleKey, needle: $key.'.')
                )
            );

            $value = $dataDot->get(key: $key);

            // Make sure arrays are arrays.
            if ($value instanceof Dot) {
                $value = $value->jsonSerialize();
            }

            // Skip if the value is null.
            if ($value === null) {
                continue;
            }

            // Extend the subobject(s).
            if (is_array($value) === true) {
                // Filter out null values and values starting with '@' before mapping.
                $value         = array_filter(
                    $value,
                    fn($v) => $v !== null && (is_string($v) === false || str_starts_with(haystack: $v, needle: '@') === false)
                );
                $renderedValue = array_map(
                        function ($identifier) use ($depth, $keyExtends, $allFlag, $visitedIds) {
                            if (is_array($identifier)) {
                                return null;
                            }

                            // **PERFORMANCE OPTIMIZATION**: Use preloaded cache instead of individual queries.
                            $object = $this->getObject(id: $identifier);
                            if ($object === null) {
                                // If not in cache, this object wasn't preloaded - skip it to prevent N+1.
                                $this->logger->debug(
                                        'Object not found in preloaded cache - skipping to prevent N+1 query',
                                        [
                                            'identifier' => $identifier,
                                            'context'    => 'extend_array_processing',
                                        ]
                                        );
                                return null;
                            }

                            if (in_array($object->getUuid(), $visitedIds, true)) {
                                return ['@circular' => true, 'id' => $object->getUuid()];
                            }

                            $subExtend = $allFlag ? array_merge(['all'], $keyExtends) : $keyExtends;

                            return $this->renderEntity(entity: $object, extend: $subExtend, depth: $depth + 1, filter: $filter ?? [], fields: $fields ?? [], unset: $unset ?? [], visitedIds: $visitedIds)->jsonSerialize();
                        },
                        $value
                        );

                // Filter out any null values that might have been returned from the mapping.
                $renderedValue = array_filter($renderedValue, fn($v) => $v !== null);

                if (is_numeric($override) === true) {
                    // Reset array keys.
                    $dataDot->set(keys: $key, value: array_values($renderedValue));
                } else {
                    // Reset array keys.
                    $dataDot->set(keys: $override, value: array_values($renderedValue));
                }
            } else {
                // Skip if the value starts with '@' or '_'.
                if (is_string($value) && (str_starts_with(haystack: $value, needle: '@') || str_starts_with(haystack: $value, needle: '_'))) {
                    continue;
                }

                if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
                    $path         = parse_url($value, PHP_URL_PATH);
                    $pathExploded = explode('/', $path);
                    $value        = end($pathExploded);
                }

                // **PERFORMANCE OPTIMIZATION**: Use preloaded cache instead of individual queries.
                $object = $this->getObject(id: $value);

                if ($object === null) {
                    // If not in cache, this object wasn't preloaded - skip it to prevent N+1.
                    $this->logger->debug(
                            'Single object not found in preloaded cache - skipping to prevent N+1 query',
                            [
                                'identifier' => $value,
                                'context'    => 'extend_single_processing',
                            ]
                            );
                    continue;
                }

                $subExtend = $allFlag ? array_merge(['all'], $keyExtends) : $keyExtends;

                if (in_array($object->getUuid(), $visitedIds, true) === true) {
                    $rendered = ['@circular' => true, 'id' => $object->getUuid()];
                } else {
                    $rendered = $this->renderEntity(
                        entity: $object,
                        extend: $subExtend,
                        depth: $depth + 1,
                        filter: $filter ?? [],
                        fields: $fields ?? [],
                        unset: $unset ?? [],
                        visitedIds: $visitedIds
                    )->jsonSerialize();
                }

                if (is_numeric($override) === true) {
                    $dataDot->set(keys: $key, value: $rendered);
                } else {
                    $dataDot->set(keys: $override, value: $rendered);
                }
            }//end if
        }//end foreach

        return $dataDot->jsonSerialize();

    }//end handleExtendDot()


    /**
     * Extends an object with additional data based on the extension configuration
     *
     * @param ObjectEntity $entity     The entity to extend
     * @param array        $extend     Extension configuration
     * @param array        $objectData Current object data
     * @param int          $depth      Current depth level
     * @param array|null   $filter     Filters to apply
     * @param array|null   $fields     Fields to include
     * @param array|null   $unset      Properties to remove from the rendered entity
     * @param array|null   $visitedIds ids of objects already handled
     *
     * @return array The extended object data
     */
    private function extendObject(
        ObjectEntity $entity,
        array $extend,
        array $objectData,
        int $depth,
        ?array $filter=[],
        ?array $fields=[],
        ?array $unset=[],
        ?array $visitedIds=[]
    ): array {
        // Add register and schema context to @self if requested.
        if (in_array('@self.register', $extend) === true || in_array('@self.schema', $extend) === true) {
            $self = $objectData['@self'] ?? [];

            if (in_array('@self.register', $extend) === true) {
                $register = $this->getRegister($entity->getRegister());
                if ($register !== null) {
                    $self['register'] = $register->jsonSerialize();
                }
            }

            if (in_array('@self.schema', $extend) === true) {
                $schema = $this->getSchema($entity->getSchema());
                if ($schema !== null) {
                    $self['schema'] = $schema->jsonSerialize();
                }
            }

            $objectData['@self'] = $self;
        }

        $objectDataDot = $this->handleExtendDot(data: $objectData, extend: $extend, depth: $depth, allFlag: in_array('all', $extend, true), visitedIds: $visitedIds);

        return $objectDataDot;

    }//end extendObject()


    /**
     * Gets the inversed properties from a schema
     *
     * TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
     *
     * @param Schema $schema The schema to check for inversed properties
     *
     * @return array Array of property names that have inversedBy configurations
     */
    private function getInversedProperties(Schema $schema): array
    {
        $properties = $schema->getProperties();

        // Use array_filter to get properties with inversedBy configurations.
                        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property.
        $inversedProperties = array_filter(
                $properties,
                function ($property) {
                    return (isset($property['inversedBy']) && !empty($property['inversedBy'])) || (isset($property['items']['inversedBy']) && !empty($property['items']['inversedBy']));
                }
                );

        // Extract the property names and their inversedBy values.
        return $inversedProperties;

    }//end getInversedProperties()


    /**
     * Handles inversed properties for an object
     *
     * @param ObjectEntity $entity     The entity to process
     * @param array        $objectData The current object data
     * @param int          $depth      Current depth level
     * @param array|null   $filter     Filters to apply
     * @param array|null   $fields     Fields to include
     * @param array|null   $unset      Properties to remove from the rendered entity
     * @param array|null   $registers  Preloaded registers
     * @param array|null   $schemas    Preloaded schemas
     * @param array|null   $objects    Preloaded objects
     *
     * @return array The updated object data with inversed properties
     */
    private function handleInversedProperties(
        ObjectEntity $entity,
        array $objectData,
        int $depth,
        ?array $filter=[],
        ?array $fields=[],
        ?array $unset=[],
        ?array $registers=[],
        ?array $schemas=[],
        ?array $objects=[]
    ): array {
        // Get the schema for this object.
        $schema = $this->getSchema($entity->getSchema());
        if ($schema === null) {
            return $objectData;
        }

        // Get properties that have inversedBy configurations.
        $inversedProperties = $this->getInversedProperties($schema);
        if (empty($inversedProperties) === true) {
            return $objectData;
        }

        // Find objects that reference this object.
        $referencingObjects = $this->objectEntityMapper->findByRelation($entity->getUuid());

        // Set all found objects to the objectsCache.
        $ids = array_map(
                function (ObjectEntity $object) {
                    return $object->getUuid();
                },
                $referencingObjects
                );

        $objectsToCache     = array_combine(keys: $ids, values: $referencingObjects);
        $this->objectsCache = array_merge($objectsToCache, $this->objectsCache);

        // Process each inversed property.
        foreach ($inversedProperties as $propertyName => $propertyConfig) {
            $objectData[$propertyName] = [];

            // Extract inversedBy configuration based on property structure.
            $inversedByProperty = null;
            $targetSchema       = null;
            $isArray            = false;

            // Check if this is an array property with inversedBy in items.
            if (isset($propertyConfig['type']) && $propertyConfig['type'] === 'array' && isset($propertyConfig['items']['inversedBy'])) {
                $inversedByProperty = $propertyConfig['items']['inversedBy'];
                $targetSchema       = $propertyConfig['items']['$ref'] ?? null;
                $isArray            = true;
            }
            // Check if this is a direct object property with inversedBy.
            else if (isset($propertyConfig['inversedBy'])) {
                $inversedByProperty = $propertyConfig['inversedBy'];
                $targetSchema       = $propertyConfig['$ref'] ?? null;
                $isArray            = false;

                // Fallback for misconfigured arrays.
                if ($propertyConfig['type'] === 'array') {
                    $isArray = true;
                }
            }
            // Skip if no inversedBy configuration found.
            else {
                continue;
            }

            // Resolve schema reference to actual schema ID.
            if ($targetSchema !== null) {
                $schemaId = $this->resolveSchemaReference($targetSchema);
            } else {
                $schemaId = $entity->getSchema();
                // Use current schema if no target specified.
            }

            $inversedObjects = array_values(
                    array_filter(
                    $referencingObjects,
                    function (ObjectEntity $object) use ($inversedByProperty, $schemaId, $entity) {
                        $data = $object->getObject();

                        // Check if the referencing object has the inversedBy property.
                        if (!isset($data[$inversedByProperty])) {
                            return false;
                        }

                        $referenceValue = $data[$inversedByProperty];

                        // Handle both array and single value references.
                        if (is_array($referenceValue)) {
                            // Check if the current entity's UUID is in the array.
                            return in_array($entity->getUuid(), $referenceValue, true) && $object->getSchema() === $schemaId;
                        } else {
                            // Check if the reference value matches the current entity's UUID.
                            return str_ends_with(haystack: $referenceValue, needle: $entity->getUuid()) && $object->getSchema() === $schemaId;
                        }
                    }
                    )
                    );

            $inversedUuids = array_map(
                    function (ObjectEntity $object) {
                        return $object->getUuid();
                    },
                    $inversedObjects
                    );

            // Set the inversed property value based on whether it's an array or single value.
            if ($isArray === true) {
                $objectData[$propertyName] = $inversedUuids;
            } else {
                $objectData[$propertyName] = !empty($inversedUuids) ? end($inversedUuids) : null;
            }
        }//end foreach

        return $objectData;

    }//end handleInversedProperties()


    /**
     * Resolve schema reference to actual schema ID
     *
     * @param string $schemaRef The schema reference (ID, UUID, path, or slug)
     *
     * @return string The resolved schema ID
     */
    private function resolveSchemaReference(string $schemaRef): string
    {
        // Remove query parameters if present (e.g., "schema?key=value" -> "schema").
        $cleanSchemaRef = $this->removeQueryParameters($schemaRef);

        // If it's already a numeric ID, return it.
        if (is_numeric($cleanSchemaRef)) {
            return $cleanSchemaRef;
        }

        // If it's a UUID, try to find the schema by UUID.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $cleanSchemaRef)) {
            try {
                $schema = $this->schemaMapper->find($cleanSchemaRef);
                return (string) $schema->getId();
            } catch (\Exception $e) {
                // If not found by UUID, continue with other methods.
            }
        }

        // Handle JSON Schema path references (e.g., "#/components/schemas/organisatie").
        if (str_contains($cleanSchemaRef, '/')) {
            $lastSegment = basename($cleanSchemaRef);
            // Remove any file extension or fragment.
            $lastSegment = preg_replace('/\.[^.]*$/', '', $lastSegment);
            $lastSegment = preg_replace('/#.*$/', '', $lastSegment);

            // Try to find schema by slug (case-insensitive).
            try {
                $schemas = $this->schemaMapper->findAll();
                foreach ($schemas as $schema) {
                    if (strtolower($schema->getSlug()) === strtolower($lastSegment)) {
                        return (string) $schema->getId();
                    }
                }
            } catch (\Exception $e) {
                // If not found by slug, continue.
            }
        }

        // If it's a slug, try to find the schema by slug.
        $schemas = $this->schemaMapper->findAll(filters: ['slug' => $cleanSchemaRef]);

        if (count($schemas) === 1) {
            return (string) array_shift($schemas)->getId();
        }

        // If all else fails, try to use the reference as-is.
        return $cleanSchemaRef;

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
        if (str_contains($reference, '?')) {
            return substr($reference, 0, strpos($reference, '?'));
        }

        return $reference;

    }//end removeQueryParameters()


    /**
     * Gets the string before a dot in a given input.
     *
     * @param string $input The input string to process.
     *
     * @return string The substring before the first dot.
     */
    private function getStringBeforeDot(string $input): string
    {
        $dotPosition = strpos($input, '.');
        if ($dotPosition === false) {
            return $input;
        }

        return substr($input, 0, $dotPosition);

    }//end getStringBeforeDot()


    /**
     * Gets the string after the last slash in a given input.
     *
     * @param string $input The input string to process.
     *
     * @return string The substring after the last slash.
     */
    private function getStringAfterLastSlash(string $input): string
    {
        $lastSlashPosition = strrpos($input, '/');
        if ($lastSlashPosition === false) {
            return $input;
        }

        return substr($input, $lastSlashPosition + 1);

    }//end getStringAfterLastSlash()


    /**
     * Get file modified time
     *
     * @param array<string,mixed> $fileRecord File record array
     *
     * @return string|null Formatted datetime string or null
     */
    private function getFileModifiedTime(array $fileRecord): ?string
    {
        if (isset($fileRecord['mtime']) === true) {
            return (new \DateTime())->setTimestamp($fileRecord['mtime'])->format('c');
        }

        return null;

    }//end getFileModifiedTime()


}//end class
