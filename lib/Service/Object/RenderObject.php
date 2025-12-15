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
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
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
     * @param CacheHandler           $cacheHandler       Cache service for performance optimization.
     * @param LoggerInterface        $logger             Logger for performance monitoring.
     */
    public function __construct(
        private readonly FileMapper $fileMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ISystemTagManager $systemTagManager,
        private readonly ISystemTagObjectMapper $systemTagMapper,
        private readonly CacheHandler $cacheHandler,
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
     * @return string[]
     *
     * @phpstan-return array<int, string>
     *
     * @psalm-return list<string>
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
        if (($propertyConfig['type'] ?? '' === true) === 'array'
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
        } else {
            // Handle single file.
            if (is_numeric($propertyValue) === true || (is_string($propertyValue) === true && ctype_digit($propertyValue) === true) === true) {
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
            if (empty($config['objectImageField']) === false) {
                $imageField = $config['objectImageField'];

                // Get the value from the configured field.
                $value = $this->getValueFromPath(data: $objectData, path: $imageField);

                // Check if the value is a file object (has downloadUrl or accessUrl).
                if (is_array($value) === true && (($value['downloadUrl'] ?? null) !== null || (($value['accessUrl'] ?? null) !== null) === true) === true) {
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
            if (is_array($value) === true && (($value[$key] ?? null) !== null)) {
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
     * @return (int|mixed|null|string|string[])[]|null
     *
     * @psalm-param mixed $fileId
     *
     * @phpstan-param mixed $fileId
     *
     * @psalm-return   array{id: numeric-string, path: string, title: string, accessUrl: null|string, downloadUrl: null|string, type: string, extension: string, size: int, hash: string, published: null|string, modified: mixed, labels: array<int, string>}|null
     * @phpstan-return array<string, mixed>|null
     */
    private function getFileObject($fileId): array|null
    {
        try {
            // Convert to string/int as needed.
            if (is_numeric($fileId) === true) {
                $fileIdStr = (string) $fileId;
            } else {
                $fileIdStr = $fileId;
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
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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
        bool $_multi=true
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
                if (is_array($objectData) && ($objectData[$field] ?? null) !== null) {
                    $filteredData[$field] = $objectData[$field];
                }
            }

            $objectData = $filteredData;
            $entity->setObject($objectData);
        }

        // Apply filters if specified.
        if (empty($filter) === false) {
            foreach ($filter as $key => $value) {
                if (is_array($objectData) && ($objectData[$key] ?? null) !== null && $objectData[$key] !== $value) {
                    $entity->setObject([]);
                    return $entity;
                }
            }
        }

        // Apply unset - remove specified properties from the response.
        if (empty($unset) === false) {
            foreach ($unset as $property) {
                if (is_array($objectData) && ($objectData[$property] ?? null) !== null) {
                    unset($objectData[$property]);
                }
            }

            $entity->setObject($objectData);
        }

        // Handle inversed properties if depth limit not reached.
        if ($depth < 10) {
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

        // Handle extensions if depth limit not reached.
        if (empty($_extend) === false && $depth < 10) {
            $objectData = $this->extendObject(entity: $entity, extend: $_extend, objectData: $objectData, depth: $depth, filter: $filter, fields: $fields, unset: $unset, visitedIds: $visitedIds);
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
            } else {
                [$root, $path] = explode(separator: '.$.', string: $key, limit: 2);
                $extendedRoots[$root][$path] = $extends;
            }
        }

        foreach ($extendedRoots as $root => $extends) {
            $data = $objectData->get(key: $root);
            if (is_iterable($data) === false) {
                continue;
            }

            foreach ($data as $key => $datum) {
                $tmpExtends = $extends;
                $data[$key] = $this->handleExtendDot(data: $datum, extend: $tmpExtends, depth: $depth);
            }

            $objectData->set($root, $data);
        }

        return $objectData->all();

    }//end handleWildcardExtends()


    /**
     * Handle extends on a dot array
     *
     * @param array $data       The data to extend.
     * @param array $extend     The fields to extend.
     * @param int   $depth      The current depth.
     * @param bool  $allFlag    If we extend all or not.
     * @param array $visitedIds All ids we already handled.
     *
     * @return array
     *
     * @throws \OCP\DB\Exception
     */
    private function handleExtendDot(array $data, array &$_extend, int $depth, bool $allFlag=false, array $visitedIds=[]): array
    {
        $data = $this->handleWildcardExtends(objectData: $data, extend: $_extend, depth: $depth + 1);

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
                    fn($v) => $v !== null && (is_string($v) === false || str_starts_with(haystack: $v, needle: '@') === false)
                );
                $renderedValue = array_map(
                        function ($identifier) use ($depth, $keyExtends, $allFlag, $visitedIds) {
                            if (is_array($identifier) === true) {
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

                            if (in_array($object->getUuid(), $visitedIds, true) === true) {
                                return ['@circular' => true, 'id' => $object->getUuid()];
                            }

                            if ($allFlag === true) {
                                $subExtend = array_merge(['all'], $keyExtends);
                            } else {
                                $subExtend = $keyExtends;
                            }

                            return $this->renderEntity(entity: $object, extend: $subExtend, depth: $depth + 1, filter: [], fields: [], unset: [], visitedIds: $visitedIds)->jsonSerialize();
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
                if (is_string($value) === true && ((str_starts_with(haystack: $value, needle: '@') === true) || (str_starts_with(haystack: $value, needle: '_') === true)) === true) {
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

                if ($allFlag === true) {
                    $subExtend = array_merge(['all'], $keyExtends);
                } else {
                    $subExtend = $keyExtends;
                }

                if (in_array($object->getUuid(), $visitedIds, true) === true) {
                    $rendered = ['@circular' => true, 'id' => $object->getUuid()];
                } else {
                    $rendered = $this->renderEntity(
                        entity: $object,
                        extend: $subExtend,
                        depth: $depth + 1,
                        filter: [],
                        fields: [],
                        unset: [],
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

        $objectDataDot = $this->handleExtendDot(data: $objectData, extend: $_extend, depth: $depth, allFlag: in_array('all', $_extend, true), visitedIds: $visitedIds);

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
                    return (isset($property['inversedBy']) && empty($property['inversedBy']) === false) || (isset($property['items']['inversedBy']) && empty($property['items']['inversedBy']) === false);
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

        // Find objects that reference this object.
        $referencingObjects = $this->objectEntityMapper->findByRelation($entity->getUuid());

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
        foreach ($inversedProperties as $propertyName => $propertyConfig) {
            $objectData[$propertyName] = [];

            // Extract inversedBy configuration based on property structure.
            // Check if this is an array property with inversedBy in items.
            if (($propertyConfig['type'] ?? null) !== null && ($propertyConfig['type'] === 'array') === true && (($propertyConfig['items']['inversedBy'] ?? null) !== null) === true) {
                $inversedByProperty = $propertyConfig['items']['inversedBy'];
                $targetSchema       = $propertyConfig['items']['$ref'] ?? null;
                $isArray            = true;
            }
            // Check if this is a direct object property with inversedBy.
            else if (($propertyConfig['inversedBy'] ?? null) !== null) {
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
                        if (isset($data[$inversedByProperty]) === false) {
                            return false;
                        }

                        $referenceValue = $data[$inversedByProperty];

                        // Handle both array and single value references.
                        if (is_array($referenceValue) === true) {
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
                if (empty($inversedUuids) === false) {
                    $objectData[$propertyName] = end($inversedUuids);
                } else {
                    $objectData[$propertyName] = null;
                }
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
     * @param array             $entities Array of ObjectEntity instances to render.
     * @param array|string|null $_extend  Properties to extend/embed in the response.
     * @param array|null        $_filter  Filters to apply to the rendered entities.
     * @param array|null        $_fields  Specific fields to include in the response.
     * @param array|null        $_unset   Fields to exclude from the response.
     * @param bool              $_rbac    Whether to apply RBAC checks (default: true).
     * @param bool              $_multi   Whether to apply multitenancy filtering (default: true).
     *
     * @return array<int, ObjectEntity> Array of rendered ObjectEntity instances.
     */
    public function renderEntities(
        array $entities,
        array | string | null $_extend=[],
        ?array $_filter=null,
        ?array $_fields=null,
        ?array $_unset=null,
        bool $_rbac=true,
        bool $_multi=true
    ): array {
        $renderedEntities = [];

        // Render each entity individually.
        foreach ($entities as $entity) {
            $renderedEntities[] = $this->renderEntity(
                entity: $entity,
                _extend: $_extend,
                depth: 0,
                filter: $_filter,
                fields: $_fields,
                unset: $_unset,
                _rbac: $_rbac,
                _multi: $_multi
            );
        }

        return $renderedEntities;

    }//end renderEntities()


}//end class
