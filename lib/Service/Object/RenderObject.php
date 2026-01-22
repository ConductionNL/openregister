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

namespace OCA\OpenRegister\Service\Object;

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
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
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
 * @package   OCA\OpenRegister\Service\Objects
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
 * @copyright 2024 Conduction b.v.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Rendering requires comprehensive transformation methods
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex rendering logic with multiple output formats
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Rendering requires multiple mapper and service dependencies
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
     * Cache of inverse relationships: maps entity UUID to array of referencing objects.
     * Used to batch-load inverse relationships for performance optimization.
     *
     * @var array<string, ObjectEntity[]>
     */
    private array $inverseRelationCache = [];

    /**
     * Constructor for RenderObject handler.
     *
     * @param FileMapper             $fileMapper            File mapper for database operations.
     * @param ObjectEntityMapper     $objectEntityMapper    Object entity mapper for database operations.
     * @param RegisterMapper         $registerMapper        Register mapper for database operations.
     * @param SchemaMapper           $schemaMapper          Schema mapper for database operations.
     * @param ISystemTagManager      $systemTagManager      System tag manager for file tags.
     * @param ISystemTagObjectMapper $systemTagMapper       System tag object mapper for file tags.
     * @param CacheHandler           $cacheHandler          Cache service for performance optimization.
     * @param CacheHandler           $objectCacheService    Object cache service for optimized loading.
     * @param PropertyRbacHandler    $propertyRbacHandler   Property-level RBAC handler.
     * @param LoggerInterface        $logger                Logger for performance monitoring.
     */
    public function __construct(
        private readonly FileMapper $fileMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ISystemTagManager $systemTagManager,
        private readonly ISystemTagObjectMapper $systemTagMapper,
        private readonly CacheHandler $cacheHandler,
        private readonly CacheHandler $objectCacheService,
        private readonly PropertyRbacHandler $propertyRbacHandler,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Set the ultra-aggressive preload cache for maximum performance
     *
     * This method receives ALL relationship objects loaded in a single query
     * and stores them for instant access during rendering, eliminating all
     * individual database queries for extended properties.
     *
     * @param array $ultraPreloadCache Array of preloaded objects indexed by ID/UUID
     *
     * @psalm-param   array<string, ObjectEntity> $ultraPreloadCache
     * @phpstan-param array<string, ObjectEntity> $ultraPreloadCache
     *
     * @return void
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
     *
     * @psalm-return int<0, max>
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
        if (($this->registersCache[$id] ?? null) !== null) {
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
        if (($this->schemasCache[$id] ?? null) !== null) {
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
     * Check if a string looks like a UUID (using regex, not strict RFC 4122 validation).
     *
     * This allows non-RFC 4122 compliant UUIDs like those from GEMMA ArchiMate exports
     * which may have non-standard variant bits.
     *
     * @param string $value The string to check
     *
     * @return bool True if the string matches UUID format
     */
    private function isUuidLike(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

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
        if (($this->ultraPreloadCache[(string) $id] ?? null) !== null) {
            return $this->ultraPreloadCache[(string) $id];
        }

        // **PERFORMANCE OPTIMIZATION**: Use CacheHandler for optimized caching.
        // First check local cache for backward compatibility.
        if (($this->objectsCache[$id] ?? null) !== null) {
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
     * Get the objects cache containing all extended/related objects indexed by UUID.
     *
     * This method returns all objects that were loaded during rendering (via _extend).
     * Objects are indexed by their UUID for easy lookup by the frontend.
     *
     * @return array<string, array> Objects indexed by UUID, serialized as arrays
     */
    public function getObjectsCache(): array
    {
        $result = [];
        foreach ($this->objectsCache as $key => $object) {
            // Only include entries keyed by UUID (skip numeric IDs).
            if (is_string($key) === true && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key) === 1) {
                if ($object instanceof ObjectEntity) {
                    $result[$key] = $object->jsonSerialize();
                } else if (is_array($object) === true) {
                    $result[$key] = $object;
                }
            }
        }

        return $result;
    }//end getObjectsCache()

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
        if (empty($fileRecords) === true) {
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
                'modified'    => $fileRecord['mtime'] ?? null,
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
     * @psalm-return   list<string>
     * @phpstan-return array<int, string>
     *
     * @return array List of file tags
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
     * @psalm-param   ObjectEntity $entity
     * @phpstan-param ObjectEntity $entity
     *
     * @return ObjectEntity The entity with hydrated file properties.
     *
     * @psalm-return   ObjectEntity
     * @phpstan-return ObjectEntity
     *
     * @throws Exception If schema or file operations fail.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) File property handling requires multiple type checks
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
                if ($this->isFilePropertyConfig($propertyConfig) === true) {
                    $isArrayProperty = ($propertyConfig['type'] ?? '') === 'array';

                    // If it's an array property and not set, initialize it as empty array.
                    if (($isArrayProperty === true) && (($objectData[$propertyName] ?? null) === null) === true) {
                        $objectData[$propertyName] = [];
                    }
                }
            }

            // Process each property in the object data.
            foreach ($objectData ?? [] as $propertyName => $propertyValue) {
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
                        _propertyName: $propertyName
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
     * @psalm-param   array<string, mixed> $propertyConfig
     * @phpstan-param array<string, mixed> $propertyConfig
     *
     * @return bool True if this is a file property configuration.
     *
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
            && (($propertyConfig['items'] ?? null) !== null)
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
     * @param string $_propertyName  The property name (for error reporting).
     *
     * @psalm-param   mixed $propertyValue
     * @psalm-param   array<string, mixed> $propertyConfig
     * @psalm-param   string $_propertyName
     * @phpstan-param mixed $propertyValue
     * @phpstan-param array<string, mixed> $propertyConfig
     * @phpstan-param string $_propertyName
     *
     * @return mixed The hydrated property value (file object or array of file objects).
     *
     * @psalm-return   mixed
     * @phpstan-return mixed
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function hydrateFileProperty($propertyValue, array $propertyConfig, string $_propertyName)
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
        }

        // Handle single file.
        $isDigitString = is_string($propertyValue) === true && ctype_digit($propertyValue) === true;
        if (is_numeric($propertyValue) === true || $isDigitString === true) {
            return $this->getFileObject($propertyValue);
        }

        return $propertyValue;
        // Return unchanged if not a file ID.
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Metadata extraction requires multiple conditional checks
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
            if (empty($config['objectImageField']) === false) {
                $imageField = $config['objectImageField'];

                // Get the value from the configured field.
                $value = $this->getValueFromPath(data: $objectData, path: $imageField);

                // Check if the value is a file object (has downloadUrl or accessUrl).
                $hasNoDownloadUrl = ($value['downloadUrl'] ?? null) === null;
                $hasNoAccessUrl   = ($value['accessUrl'] ?? null) === null;
                if (is_array($value) === false || ($hasNoDownloadUrl === true && $hasNoAccessUrl === true)) {
                    // If the file property is null/empty, set image to null.
                    $entity->setImage(null);
                }

                if (is_array($value) === true && ($hasNoDownloadUrl === false || $hasNoAccessUrl === false)) {
                    // Set the image URL on the entity itself (not in object data).
                    // This will be serialized to @self.image in jsonSerialize().
                    // Prefer downloadUrl, fallback to accessUrl.
                    $entity->setImage($value['downloadUrl'] ?? $value['accessUrl']);
                }
            }//end if
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
            if (is_array($value) === false || (($value[$key] ?? null) === null)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }//end getValueFromPath()

    /**
     * Gets a file object by its ID using the FileService.
     *
     * @param mixed $fileId The file ID to retrieve.
     *
     * @psalm-param mixed $fileId
     *
     * @phpstan-param mixed $fileId
     *
     * @return (int|null|string[])[]|null
     *
     * @psalm-return   array{id: numeric-string, path: string, title: string,
     *     accessUrl: null|string, downloadUrl: null|string, type: string,
     *     extension: string, size: int, hash: string, published: null|string,
     *     modified: int|null, labels: list<string>}|null
     * @phpstan-return array<string, mixed>|null
     */
    private function getFileObject($fileId): array|null
    {
        try {
            // Convert to string/int as needed.
            $fileIdStr = $fileId;
            if (is_numeric($fileId) === true) {
                $fileIdStr = (string) $fileId;
            }

            if (is_string($fileIdStr) === false && is_int($fileIdStr) === false) {
                return null;
            }

            // Use FileMapper to get file information directly.
            $fileRecord = $this->fileMapper->getFile((int) $fileIdStr);

            if (empty($fileRecord) === true) {
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
                'modified'    => $fileRecord['mtime'] ?? null,
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
     * @param ObjectEntity      $entity        The entity to render
     * @param array|string|null $_extend       Properties to extend the entity with
     * @param int               $depth         The depth level for nested rendering
     * @param array|null        $filter        Filters to apply to the rendered entity
     * @param array|null        $fields        Specific fields to include in the output
     * @param array|null        $unset         Properties to remove from the rendered entity
     * @param array|null        $registers     Preloaded registers to use
     * @param array|null        $schemas       Preloaded schemas to use
     * @param array|null        $objects       Preloaded objects to use
     * @param array|null        $visitedIds    All ids we already handled
     * @param bool              $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool              $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The rendered entity with applied extensions and filters
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible rendering options
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)   Complex rendering logic with multiple code paths
     * @SuppressWarnings(PHPMD.NPathComplexity)        Multiple optional rendering features create many paths
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Comprehensive rendering requires extensive logic
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    RBAC and multitenancy flags control security behavior
     */
    public function renderEntity(
        ObjectEntity $entity,
        array | string | null $_extend=[],
        int $depth=0,
        ?array $filter=[],
        ?array $fields=[],
        ?array $unset=[],
        ?array $registers=[],
        ?array $schemas=[],
        ?array $objects=[],
        ?array $visitedIds=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        if ($entity->getUuid() !== null && in_array($entity->getUuid(), $visitedIds ?? [], true) === true) {
            // @psalm-suppress NullableReturnStatement - setObject() returns $this (ObjectEntity) despite void annotation
            $entity->setObject(object: ['@circular' => true, 'id' => $entity->getUuid()]);
            return $entity;
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
                if (is_array($objectData) === true && ($objectData[$field] ?? null) !== null) {
                    $filteredData[$field] = $objectData[$field];
                }
            }

            $objectData = $filteredData;
            $entity->setObject($objectData);
        }

        // Apply filters if specified.
        if (empty($filter) === false) {
            foreach ($filter as $key => $value) {
                if (is_array($objectData) === true && ($objectData[$key] ?? null) !== null && $objectData[$key] !== $value) {
                    $entity->setObject([]);
                    return $entity;
                }
            }
        }

        // Apply unset - remove specified properties from the response.
        if (empty($unset) === false) {
            foreach ($unset as $property) {
                if (is_array($objectData) === true && ($objectData[$property] ?? null) !== null) {
                    unset($objectData[$property]);
                }
            }

            $entity->setObject($objectData);
        }

        // Handle inversed properties ONLY if we're extending an inverse property.
        // This is a performance optimization: inverse lookups are expensive (search all magic tables),
        // so we only do them when explicitly requested via _extend.
        if ($depth < 10 && empty($_extend) === false) {
            $schema = $this->getSchema($entity->getSchema());
            if ($schema !== null) {
                $inversedProperties = $this->getInversedProperties($schema);
                // Get the property names that have inversedBy configs (e.g., "contactpersonen").
                // These are the properties that need inverse lookups to populate their data.
                $inversePropertyNames = array_keys($inversedProperties);

                // Normalize extend to array.
                $extendArray = is_array($_extend) ? $_extend : explode(',', $_extend);

                // Check if any inverse property is being extended (or 'all' is specified).
                $shouldHandleInverse = in_array('all', $extendArray, true)
                    || array_intersect($inversePropertyNames, $extendArray) !== [];

                if ($shouldHandleInverse === true) {
                    $objectData = $this->handleInversedProperties(
                        entity: $entity,
                        objectData: $objectData,
                        _depth: $depth,
                        _filter: $filter,
                        _fields: $fields,
                        _unset: $unset,
                        _registers: $registers,
                        _schemas: $schemas,
                        _objects: $objects
                    );
                }
            }
        }

        // Convert extend to an array if it's a string.
        if (is_array($_extend) === true && in_array('all', $_extend, true) === true) {
            $id       = $objectData['id'] ?? null;
            $originId = $objectData['originId'] ?? null;

            foreach ($objectData as $key => $value) {
                if (in_array($key, ['id', 'originId'], true) === true) {
                    continue;
                }

                if ($value !== $id && $value !== $originId) {
                    $_extend[] = $key;
                }
            }
        } else if (is_string($_extend) === true) {
            $_extend = explode(',', $_extend);
        }

        // Normalize shorthand extend parameters to their @self equivalents.
        // This allows both _schema and @self.schema to work the same way.
        if (is_array($_extend) === true) {
            $normalizeMap = [
                '_schema'   => '@self.schema',
                '_register' => '@self.register',
            ];
            foreach ($normalizeMap as $shorthand => $full) {
                $key = array_search($shorthand, $_extend, true);
                if ($key !== false) {
                    $_extend[$key] = $full;
                }
            }
        }

        // Handle extensions if depth limit not reached.
        if (empty($_extend) === false && $depth < 10) {
            $objectData = $this->extendObject(
                entity: $entity,
                _extend: $_extend,
                objectData: $objectData,
                depth: $depth,
                _filter: $filter,
                _fields: $fields,
                _unset: $unset,
                visitedIds: $visitedIds
            );
        }

        // Apply property-level RBAC filtering.
        // This filters out properties that the current user is not authorized to read.
        $schema = $this->getSchema($entity->getSchema());
        if ($schema !== null && $schema->hasPropertyAuthorization() === true) {
            $objectData = $this->propertyRbacHandler->filterReadableProperties(
                schema: $schema,
                object: $objectData
            );
        }

        $entity->setObject($objectData);

        return $entity;
    }//end renderEntity()

    /**
     * Handle extends containing a wildcard ($)
     *
     * @param array $objectData The data to extend
     * @param array $_extend    The fields that should be extended
     * @param int   $depth      The current depth.
     *
     * @return array
     */
    private function handleWildcardExtends(array $objectData, array &$_extend, int $depth): array
    {
        $objectData = new Dot($objectData);
        if ($depth >= 10) {
            return $objectData->all();
        }

        $wildcardExtends = array_filter(
            $_extend,
            function (string $key) {
                return str_contains($key, '.$.');
            }
        );

        $extendedRoots = [];

        foreach ($wildcardExtends as $key => $wildcardExtend) {
            unset($_extend[$key]);

            [$root, $extends] = explode(separator: '.$.', string: $wildcardExtend, limit: 2);

            if (is_numeric($key) === true) {
                $extendedRoots[$root][] = $extends;
                continue;
            }

            [$root, $path] = explode(separator: '.$.', string: $key, limit: 2);
            $extendedRoots[$root][$path] = $extends;
        }

        foreach ($extendedRoots as $root => $extends) {
            $data = $objectData->get(key: $root);
            if (is_iterable($data) === false) {
                continue;
            }

            foreach ($data as $key => $datum) {
                $tmpExtends = $extends;
                $data[$key] = $this->handleExtendDot(data: $datum, _extend: $tmpExtends, depth: $depth);
            }

            $objectData->set($root, $data);
        }

        return $objectData->all();
    }//end handleWildcardExtends()

    /**
     * Handle extends on a dot array
     *
     * @param array $data       The data to extend.
     * @param array $_extend    The fields to extend.
     * @param int   $depth      The current depth.
     * @param bool  $allFlag    If we extend all or not.
     * @param array $visitedIds All ids we already handled.
     *
     * @return array
     *
     * @throws \OCP\DB\Exception
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex extension handling with multiple data types
     * @SuppressWarnings(PHPMD.NPathComplexity)       Many extension scenarios create multiple code paths
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive dot notation handling requires extensive logic
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   All flag controls extension behavior
     */
    private function handleExtendDot(
        array $data,
        array &$_extend,
        int $depth,
        bool $allFlag=false,
        array $visitedIds=[]
    ): array {
        $data = $this->handleWildcardExtends(objectData: $data, _extend: $_extend, depth: $depth + 1);

        $dataDot = new Dot($data);

        foreach ($_extend as $override => $key) {
            // Skip if the key does not have to be extended.
            if ($dataDot->has(keys: $key) === false) {
                continue;
            }

            // Skip if the key starts with '@' (special fields).
            if (str_starts_with($key, '@') === true) {
                continue;
            }

            // Get sub-keys for nested extension.
            $keyExtends = array_map(
                fn(string $extendedKey) => substr(string: $extendedKey, offset: strlen($key) + 1),
                array_filter(
                    $_extend,
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
                    fn($v) => $v !== null
                        && (is_string($v) === false || str_starts_with(haystack: $v, needle: '@') === false)
                );
                $renderedValue = array_map(
                    function ($identifier) use ($depth, $keyExtends, $allFlag, $visitedIds) {
                        // If already an extended object (has 'id' and '@self' keys), return as-is.
                        // This prevents double-processing when extend is called multiple times.
                        if (is_array($identifier) === true) {
                            if (isset($identifier['id']) === true || isset($identifier['@self']) === true) {
                                return $identifier;
                            }
                            return null;
                        }

                        // **PERFORMANCE OPTIMIZATION**: Use preloaded cache instead of individual queries.
                        $object = $this->getObject(id: $identifier);
                        if ($object === null) {
                            // Object not found - preserve the original UUID instead of returning null.
                            // This keeps the reference data intact even when the referenced object
                            // doesn't exist (e.g., data imported from CSV with external references).
                            $this->logger->debug(
                                'Object not found in preloaded cache - preserving original UUID',
                                [
                                    'identifier' => $identifier,
                                    'context'    => 'extend_array_processing',
                                ]
                            );
                            return $identifier;
                        }

                        if (in_array($object->getUuid(), $visitedIds, true) === true) {
                            return ['@circular' => true, 'id' => $object->getUuid()];
                        }

                        $subExtend = $keyExtends;
                        if ($allFlag === true) {
                            $subExtend = array_merge(['all'], $keyExtends);
                        }

                        return $this->renderEntity(
                            entity: $object,
                            _extend: $subExtend,
                            depth: $depth + 1,
                            filter: [],
                            fields: [],
                            unset: [],
                            visitedIds: $visitedIds
                        )->jsonSerialize();
                    },
                    $value
                );

                // Filter out any null values that might have been returned from the mapping.
                $renderedValue = array_filter($renderedValue, fn($v) => $v !== null);

                if (is_numeric($override) === false) {
                    // Reset array keys.
                    $dataDot->set(keys: $override, value: array_values($renderedValue));
                    continue;
                }

                // Reset array keys.
                $dataDot->set(keys: $key, value: array_values($renderedValue));
                continue;
            }//end if

            // Skip if the value starts with '@' or '_'.
            if (is_string($value) === true
                && ((str_starts_with(haystack: $value, needle: '@') === true)
                || (str_starts_with(haystack: $value, needle: '_') === true)) === true
            ) {
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

            $subExtend = $keyExtends;
            if ($allFlag === true) {
                $subExtend = array_merge(['all'], $keyExtends);
            }

            $rendered = $this->renderEntity(
                entity: $object,
                _extend: $subExtend,
                depth: $depth + 1,
                filter: [],
                fields: [],
                unset: [],
                visitedIds: $visitedIds
            )->jsonSerialize();

            if (in_array($object->getUuid(), $visitedIds, true) === true) {
                $rendered = ['@circular' => true, 'id' => $object->getUuid()];
            }

            if (is_numeric($override) === false) {
                $dataDot->set(keys: $override, value: $rendered);
                continue;
            }

            $dataDot->set(keys: $key, value: $rendered);
        }//end foreach

        return $dataDot->jsonSerialize();
    }//end handleExtendDot()

    /**
     * Extends an object with additional data based on the extension configuration
     *
     * @param ObjectEntity $entity     The entity to extend
     * @param array        $_extend    Extension configuration
     * @param array        $objectData Current object data
     * @param int          $depth      Current depth level
     * @param array|null   $_filter    Filters to apply
     * @param array|null   $_fields    Fields to include
     * @param array|null   $_unset     Properties to remove from the rendered entity
     * @param array|null   $visitedIds ids of objects already handled
     *
     * @return array The extended object data
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function extendObject(
        ObjectEntity $entity,
        array $_extend,
        array $objectData,
        int $depth,
        ?array $_filter=[],
        ?array $_fields=[],
        ?array $_unset=[],
        ?array $visitedIds=[]
    ): array {
        // Add register and schema context to @self if requested.
        if (in_array('@self.register', $_extend) === true || in_array('@self.schema', $_extend) === true) {
            $self = $objectData['@self'] ?? [];

            if (in_array('@self.register', $_extend) === true) {
                $register = $this->getRegister($entity->getRegister());
                if ($register !== null) {
                    $self['register'] = $register->jsonSerialize();
                }
            }

            if (in_array('@self.schema', $_extend) === true) {
                $schema = $this->getSchema($entity->getSchema());
                if ($schema !== null) {
                    $self['schema'] = $schema->jsonSerialize();
                }
            }

            $objectData['@self'] = $self;
        }

        // **PERFORMANCE OPTIMIZATION**: Batch preload all UUIDs that will be extended.
        // This collects all UUIDs from the properties that will be extended and loads
        // them in a SINGLE database query, instead of one query per UUID.
        $uuidsToPreload = $this->collectUuidsForExtend(objectData: $objectData, extend: $_extend);
        if (empty($uuidsToPreload) === false) {
            $preloadedObjects = $this->objectCacheService->preloadObjects($uuidsToPreload);
            // Add preloaded objects to local cache for immediate access.
            foreach ($preloadedObjects as $object) {
                $this->objectsCache[$object->getUuid()] = $object;
                $this->objectsCache[$object->getId()] = $object;
            }

            $this->logger->debug(
                'Batch preloaded objects for extend',
                [
                    'requestedUuids' => count($uuidsToPreload),
                    'loadedObjects'  => count($preloadedObjects),
                ]
            );
        }

        $objectDataDot = $this->handleExtendDot(
            data: $objectData,
            _extend: $_extend,
            depth: $depth,
            allFlag: in_array('all', $_extend, true),
            visitedIds: $visitedIds
        );

        return $objectDataDot;
    }//end extendObject()

    /**
     * Collect all UUIDs from object data for properties that will be extended.
     *
     * This method scans the object data for all UUIDs in properties that match
     * the extend configuration, so they can be batch-loaded in a single query.
     *
     * @param array $objectData The object data to scan
     * @param array $extend     The properties to extend
     *
     * @return array Array of UUIDs to preload
     */
    private function collectUuidsForExtend(array $objectData, array $extend): array
    {
        $uuids = [];
        $dataDot = new Dot($objectData);

        foreach ($extend as $key) {
            // Skip special keys.
            if (str_starts_with($key, '@') === true) {
                continue;
            }

            // Get the base property name (before any dots for nested extends).
            $baseProp = explode('.', $key)[0];

            if ($dataDot->has($baseProp) === false) {
                continue;
            }

            $value = $dataDot->get($baseProp);

            // Handle array of UUIDs.
            if (is_array($value) === true) {
                foreach ($value as $item) {
                    // Use regex-based UUID validation to support non-RFC 4122 compliant UUIDs
                    // (e.g., GEMMA ArchiMate UUIDs which have non-standard variant bits)
                    if (is_string($item) === true && $this->isUuidLike($item) === true) {
                        $uuids[] = $item;
                    }
                }

                continue;
            }

            // Handle single UUID.
            // Use regex-based UUID validation to support non-RFC 4122 compliant UUIDs
            if (is_string($value) === true && $this->isUuidLike($value) === true) {
                $uuids[] = $value;
            }
        }

        return array_unique($uuids);
    }//end collectUuidsForExtend()

    /**
     * Batch preload inverse relationships for all entities.
     *
     * This is a CRITICAL performance optimization that prevents N+1 queries when extending
     * inverse properties like 'contactpersonen'. Instead of searching all magic tables
     * for each entity, we:
     * 1. Identify which inverse properties are being extended
     * 2. Determine the target schema for each inverse property
     * 3. Do ONE batch query per target schema to find ALL referencing objects
     * 4. Cache the results for use during individual entity rendering
     *
     * @param array $entities Array of ObjectEntity instances being rendered
     * @param array $extend   The _extend parameter specifying which properties to extend
     *
     * @return void
     */
    private function preloadInverseRelationships(array $entities, array $extend): void
    {
        if (empty($entities) === true || empty($extend) === true) {
            return;
        }

        // Get the first entity to determine the schema (all entities should have the same schema).
        $firstEntity = reset($entities);
        if ($firstEntity instanceof \OCA\OpenRegister\Db\ObjectEntity === false) {
            return;
        }

        $schema = $this->getSchema($firstEntity->getSchema());
        if ($schema === null) {
            return;
        }

        // Get properties that have inversedBy configurations.
        $inversedProperties = $this->getInversedProperties($schema);
        if (empty($inversedProperties) === true) {
            return;
        }

        // Filter to only inverse properties that are being extended.
        $inversePropertiesToExtend = [];
        foreach ($inversedProperties as $propName => $propConfig) {
            if (in_array($propName, $extend, true) === true || in_array('all', $extend, true) === true) {
                $inversePropertiesToExtend[$propName] = $propConfig;
            }
        }

        if (empty($inversePropertiesToExtend) === true) {
            return;
        }

        // Collect all entity UUIDs.
        $entityUuids = [];
        foreach ($entities as $entity) {
            if ($entity instanceof \OCA\OpenRegister\Db\ObjectEntity === true && $entity->getUuid() !== null) {
                $entityUuids[] = $entity->getUuid();
            }
        }

        if (empty($entityUuids) === true) {
            return;
        }

        $this->logger->debug('[INVERSE_PRELOAD] Starting batch inverse preload', [
            'entityCount' => count($entityUuids),
            'inverseProperties' => array_keys($inversePropertiesToExtend),
        ]);

        // For each inverse property, determine target schema and batch-load referencing objects.
        foreach ($inversePropertiesToExtend as $propName => $propConfig) {
            // Extract target schema reference.
            $targetSchemaRef = $propConfig['items']['$ref'] ?? $propConfig['$ref'] ?? null;
            $inversedByField = $propConfig['items']['inversedBy'] ?? $propConfig['inversedBy'] ?? null;

            if ($targetSchemaRef === null || $inversedByField === null) {
                continue;
            }

            // Resolve schema reference to ID.
            $targetSchemaId = $this->resolveSchemaReference($targetSchemaRef);
            if (empty($targetSchemaId) === true) {
                continue;
            }

            // Get the target schema to find its register.
            $targetSchema = $this->getSchema($targetSchemaId);
            if ($targetSchema === null) {
                continue;
            }

            // Batch find all objects of the target schema that reference ANY of our entity UUIDs.
            // This uses the _relations column with GIN index for efficiency.
            try {
                $magicMapper = \OC::$server->get(\OCA\OpenRegister\Db\MagicMapper::class);
                $referencingObjects = $magicMapper->findByRelationBatchInSchema(
                    uuids: $entityUuids,
                    schemaId: (int) $targetSchemaId,
                    registerId: (int) $firstEntity->getRegister(),
                    fieldName: $inversedByField
                );

                // If batch query found results, pre-initialize cache for ALL entities.
                // If no results, DON'T cache - let the slow path run to verify.
                // This ensures correctness while we optimize batch queries.
                if (count($referencingObjects) > 0) {
                    // Pre-initialize cache entries for ALL entities with empty arrays.
                    foreach ($entityUuids as $entityUuid) {
                        $cacheKey = $entityUuid.'_'.$propName;
                        if (isset($this->inverseRelationCache[$cacheKey]) === false) {
                            $this->inverseRelationCache[$cacheKey] = [];
                        }
                    }

                    // Index the results by which entity UUID they reference.
                    foreach ($referencingObjects as $refObject) {
                        $refData = $refObject->getObject();
                        $referencedUuid = $refData[$inversedByField] ?? null;

                        // Handle both single UUID and array of UUIDs.
                        $referencedUuids = is_array($referencedUuid) ? $referencedUuid : [$referencedUuid];

                        foreach ($referencedUuids as $uuid) {
                            if ($uuid !== null && in_array($uuid, $entityUuids, true) === true) {
                                $cacheKey = $uuid.'_'.$propName;
                                $this->inverseRelationCache[$cacheKey][] = $refObject;

                                // Also add to objects cache for extended rendering.
                                $this->objectsCache[$refObject->getUuid()] = $refObject;
                            }
                        }
                    }
                }

                $this->logger->debug('[INVERSE_PRELOAD] Batch loaded inverse relationships', [
                    'property' => $propName,
                    'targetSchema' => $targetSchemaId,
                    'foundObjects' => count($referencingObjects),
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('[INVERSE_PRELOAD] Batch preload failed, falling back to per-entity lookup', [
                    'property' => $propName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }//end preloadInverseRelationships()

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
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy
        // from items property to configuration property.
        $inversedProperties = array_filter(
            $properties,
            function (array $property): bool {
                return (isset($property['inversedBy'])
                    && empty($property['inversedBy']) === false)
                    || (isset($property['items']['inversedBy'])
                    && empty($property['items']['inversedBy']) === false);
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
     * @param int          $_depth     Current depth level
     * @param array|null   $_filter    Filters to apply
     * @param array|null   $_fields    Fields to include
     * @param array|null   $_unset     Properties to remove from the rendered entity
     * @param array|null   $_registers Preloaded registers
     * @param array|null   $_schemas   Preloaded schemas
     * @param array|null   $_objects   Preloaded objects
     *
     * @return array The updated object data with inversed properties
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex inversed relationship resolution
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple relationship types create many paths
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive relationship handling requires extensive logic
     */
    private function handleInversedProperties(
        ObjectEntity $entity,
        array $objectData,
        int $_depth,
        ?array $_filter=[],
        ?array $_fields=[],
        ?array $_unset=[],
        ?array $_registers=[],
        ?array $_schemas=[],
        ?array $_objects=[]
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

        // **PERFORMANCE OPTIMIZATION**: Check if we have preloaded cache for this entity.
        // If yes, we can skip the expensive findByRelation call entirely.
        $entityUuid    = $entity->getUuid();
        $hasCache      = false;
        $propertyNames = array_keys($inversedProperties);

        foreach ($propertyNames as $propName) {
            $cacheKey = $entityUuid.'_'.$propName;
            if (isset($this->inverseRelationCache[$cacheKey]) === true) {
                $hasCache = true;
                break;
            }
        }

        // If we have preloaded cache, use it directly instead of querying.
        if ($hasCache === true) {
            return $this->handleInversedPropertiesFromCache($entity, $objectData, $inversedProperties);
        }

        // Fallback: Query for referencing objects (original slower path).
        // This happens when preloading wasn't done (e.g., single entity render).
        $referencingObjects = $this->objectEntityMapper->findByRelation($entityUuid);

        // Set all found objects to the objectsCache.
        $ids = array_map(
            function (ObjectEntity $object) {
                return $object->getUuid();
            },
            $referencingObjects
        );

        // Filter out null IDs before combining arrays.
        $validIds     = [];
        $validObjects = [];
        foreach ($ids as $index => $id) {
            if ($id !== null) {
                $validIds[]     = $id;
                $validObjects[] = $referencingObjects[$index];
            }
        }

        $objectsToCache     = array_combine(keys: $validIds, values: $validObjects);
        $this->objectsCache = array_merge($objectsToCache, $this->objectsCache);

        // Process each inversed property.
        // For a property like 'deelnemers' with inversedBy='deelnames':
        // - Keep the original 'deelnemers' values (forward references to other orgs)
        // - Find objects that have our UUID in THEIR 'deelnemers' field
        // - Put those objects' UUIDs in OUR 'deelnames' field (inverse references)
        foreach ($inversedProperties as $propertyName => $propertyConfig) {
            // Extract inversedBy configuration based on property structure.
            // Check if this is an array property with inversedBy in items.
            $inversedByProperty = null;
            $targetSchema       = null;
            $isArray            = false;

            if (($propertyConfig['type'] ?? null) !== null
                && ($propertyConfig['type'] === 'array') === true
                && (($propertyConfig['items']['inversedBy'] ?? null) !== null) === true
            ) {
                $inversedByProperty = $propertyConfig['items']['inversedBy'];
                $targetSchema       = $propertyConfig['items']['$ref'] ?? null;
                $isArray            = true;
            } else if (($propertyConfig['inversedBy'] ?? null) !== null) {
                // Check if this is a direct object property with inversedBy.
                $inversedByProperty = $propertyConfig['inversedBy'];
                $targetSchema       = $propertyConfig['$ref'] ?? null;

                // Fallback for misconfigured arrays.
                if ($propertyConfig['type'] === 'array') {
                    $isArray = true;
                }
            }

            // Skip if no inversedBy configuration found.
            if ($inversedByProperty === null) {
                continue;
            }

            // Resolve schema reference to actual schema ID.
            $schemaId = $entity->getSchema();
            // Use current schema if no target specified.
            if ($targetSchema !== null) {
                $schemaId = $this->resolveSchemaReference($targetSchema);
            }

            // Always use $propertyName as the target property to populate.
            // This is the property being extended (e.g., 'standaardVersies').
            // The $inversedByProperty (e.g., 'standaard') is the field on related objects
            // that points back to this entity.
            $targetProperty = $propertyName;

            // Initialize the target property if not already set to preserve any existing values.
            if (isset($objectData[$targetProperty]) === false) {
                $objectData[$targetProperty] = $isArray ? [] : null;
            }

            // Find objects that have our UUID in their inversedBy field.
            // The $inversedByProperty (e.g., 'standaard') is the field on related objects
            // that should contain our UUID.
            $inversedObjects = array_values(
                array_filter(
                    $referencingObjects,
                    function (ObjectEntity $object) use ($inversedByProperty, $schemaId, $entity) {
                        $data = $object->getObject();

                        // Check the inversedBy field on the related object.
                        // This field should contain the UUID of the current entity.
                        $fieldToCheck = $inversedByProperty;

                        if (isset($data[$fieldToCheck]) === false) {
                            return false;
                        }

                        $referenceValue = $data[$fieldToCheck];

                        // Handle both array and single value references.
                        if (is_array($referenceValue) === true) {
                            // Check if the current entity's UUID is in the array.
                            return in_array($entity->getUuid(), $referenceValue, true) && $object->getSchema() === $schemaId;
                        }

                        // Check if the reference value matches the current entity's UUID.
                        $matchesUuid = str_ends_with(haystack: $referenceValue, needle: $entity->getUuid());
                        return $matchesUuid && $object->getSchema() === $schemaId;
                    }
                )
            );

            // Render each inversed object to get full object data (not just UUIDs).
            // This makes inversedBy behave like regular _extend - returning full objects.
            $renderedObjects = array_map(
                function (ObjectEntity $object) {
                    return $this->renderEntity(
                        entity: $object,
                        _extend: [],
                        depth: 1,
                        filter: [],
                        fields: [],
                        unset: []
                    )->jsonSerialize();
                },
                $inversedObjects
            );

            // Set the target property value based on whether it's an array or single value.
            if ($isArray === true) {
                $objectData[$targetProperty] = $renderedObjects;
                continue;
            }

            $objectData[$targetProperty] = null;
            if (empty($renderedObjects) === false) {
                $objectData[$targetProperty] = end($renderedObjects);
            }
        }//end foreach

        return $objectData;
    }//end handleInversedProperties()

    /**
     * Handle inversed properties using the preloaded cache.
     *
     * This is the FAST path that uses batch-preloaded inverse relationships
     * instead of querying for each entity individually.
     *
     * @param ObjectEntity $entity             The entity to process
     * @param array        $objectData         The current object data
     * @param array        $inversedProperties The inversed property configurations
     *
     * @return array The updated object data with inversed properties populated
     */
    private function handleInversedPropertiesFromCache(
        ObjectEntity $entity,
        array $objectData,
        array $inversedProperties
    ): array {
        $entityUuid = $entity->getUuid();

        foreach ($inversedProperties as $propertyName => $propertyConfig) {
            // Extract configuration.
            $isArray = false;

            if (($propertyConfig['type'] ?? null) !== null
                && ($propertyConfig['type'] === 'array') === true
                && (($propertyConfig['items']['inversedBy'] ?? null) !== null) === true
            ) {
                $targetSchema = $propertyConfig['items']['$ref'] ?? null;
                $isArray      = true;
            } else if (($propertyConfig['inversedBy'] ?? null) !== null) {
                $targetSchema = $propertyConfig['$ref'] ?? null;
                if ($propertyConfig['type'] === 'array') {
                    $isArray = true;
                }
            } else {
                continue;
            }

            // Resolve schema reference.
            $schemaId = $entity->getSchema();
            if ($targetSchema !== null) {
                $schemaId = $this->resolveSchemaReference($targetSchema);
            }

            // Always use $propertyName as the target property to populate.
            $targetProperty = $propertyName;

            // Get cached objects for this entity+property combination.
            $cacheKey      = $entityUuid.'_'.$propertyName;
            $cachedObjects = $this->inverseRelationCache[$cacheKey] ?? [];

            // Render each cached object to get full object data (not just UUIDs).
            // This makes inversedBy behave like regular _extend - returning full objects.
            $renderedObjects = array_map(
                function (ObjectEntity $object) {
                    return $this->renderEntity(
                        entity: $object,
                        _extend: [],
                        depth: 1,
                        filter: [],
                        fields: [],
                        unset: []
                    )->jsonSerialize();
                },
                $cachedObjects
            );

            // Set the target property value with full rendered objects.
            if ($isArray === true) {
                $objectData[$targetProperty] = $renderedObjects;
            } else {
                $objectData[$targetProperty] = empty($renderedObjects) === false ? end($renderedObjects) : null;
            }
        }

        return $objectData;
    }//end handleInversedPropertiesFromCache()

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
        if (is_numeric($cleanSchemaRef) === true) {
            return $cleanSchemaRef;
        }

        // If it's a UUID, try to find the schema by UUID.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $cleanSchemaRef) === true) {
            try {
                $schema = $this->schemaMapper->find($cleanSchemaRef);
                return (string) $schema->getId();
            } catch (\Exception $e) {
                // If not found by UUID, continue with other methods.
            }
        }

        // Handle JSON Schema path references (e.g., "#/components/schemas/organisatie").
        if (str_contains($cleanSchemaRef, '/') === true) {
            $lastSegment = basename($cleanSchemaRef);
            // Remove any file extension or fragment.
            $lastSegment = preg_replace('/\.[^.]*$/', '', $lastSegment);
            $lastSegment = preg_replace('/#.*$/', '', $lastSegment);

            // Try to find schema by slug (case-insensitive).
            try {
                $schemas = $this->schemaMapper->findAll();
                foreach ($schemas as $schema) {
                    if (strtolower($schema->getSlug() ?? '') === strtolower($lastSegment)) {
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
        if (str_contains($reference, '?') === true) {
            return substr($reference, 0, strpos($reference, '?'));
        }

        return $reference;
    }//end removeQueryParameters()

    /**
     * Render multiple entities with extensions, filters, and field selections.
     *
     * This method renders an array of ObjectEntities by calling renderEntity() for each one.
     * It's used for batch rendering of search results and collections.
     *
     * @param array             $entities      Array of ObjectEntity instances to render.
     * @param array|string|null $_extend       Properties to extend/embed in the response.
     * @param array|null        $_filter       Filters to apply to the rendered entities.
     * @param array|null        $_fields       Specific fields to include in the response.
     * @param array|null        $_unset        Fields to exclude from the response.
     * @param bool              $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool              $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC and multitenancy flags control security behavior
     */
    public function renderEntities(
        array $entities,
        array | string | null $_extend=[],
        ?array $_filter=null,
        ?array $_fields=null,
        ?array $_unset=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        // Convert extend to array if it's a string.
        if (is_string($_extend) === true) {
            $_extend = explode(',', $_extend);
        }

        $_extend = $_extend ?? [];

        // **PERFORMANCE OPTIMIZATION**: Batch preload ALL related objects BEFORE rendering.
        // This prevents N+1 query problem when extending relations across multiple entities.
        $this->logger->info('[BATCH_PRELOAD] Starting batch preload check', [
            'extendParam' => $_extend,
            'entityCount' => count($entities),
        ]);

        if (empty($_extend) === false && empty($entities) === false) {
            $allUuidsToPreload = [];

            // Collect ALL UUIDs from ALL entities that need to be extended.
            foreach ($entities as $entity) {
                if ($entity instanceof \OCA\OpenRegister\Db\ObjectEntity === false) {
                    continue;
                }

                $objectData = $entity->getObject();
                if (is_array($objectData) === false) {
                    continue;
                }

                // Use the existing collectUuidsForExtend method to extract UUIDs.
                $entityUuids = $this->collectUuidsForExtend($objectData, $_extend);
                $allUuidsToPreload = array_merge($allUuidsToPreload, $entityUuids);
            }

            // Remove duplicates and batch preload ALL related objects in ONE query.
            $allUuidsToPreload = array_unique($allUuidsToPreload);

            $this->logger->info('[BATCH_PRELOAD] UUIDs collected', [
                'uuidCount' => count($allUuidsToPreload),
                'sampleUuids' => array_slice($allUuidsToPreload, 0, 3),
            ]);

            if (empty($allUuidsToPreload) === false) {
                $preloadedObjects = $this->objectCacheService->preloadObjects($allUuidsToPreload);

                // Add preloaded objects to local cache for immediate access during rendering.
                foreach ($preloadedObjects as $object) {
                    $this->objectsCache[$object->getUuid()] = $object;
                    $this->objectsCache[$object->getId()] = $object;
                }

                $this->logger->debug(
                    'Batch preloaded objects for renderEntities',
                    [
                        'entityCount'      => count($entities),
                        'requestedUuids'   => count($allUuidsToPreload),
                        'loadedObjects'    => count($preloadedObjects),
                    ]
                );
            }

            // **INVERSE RELATIONSHIP OPTIMIZATION**: Batch preload objects that REFERENCE our entities.
            // This prevents N+1 queries when extending inverse properties like 'contactpersonen'.
            $this->preloadInverseRelationships($entities, $_extend);
        }

        $renderedEntities = [];

        // Render each entity (now using warm cache for forward relations).
        foreach ($entities as $entity) {
            $renderedEntity = $this->renderEntity(
                entity: $entity,
                _extend: $_extend,
                depth: 0,
                filter: $_filter,
                fields: $_fields,
                unset: $_unset,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );

            // Remove source from @self in list responses.
            // The source property is only included in individual object responses,
            // not in collection/list responses for cleaner output.
            $renderedEntity->setSource(null);

            $renderedEntities[] = $renderedEntity;
        }

        return $renderedEntities;
    }//end renderEntities()
}//end class
