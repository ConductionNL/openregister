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
 * @package  OCA\OpenRegister\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\ObjectHandlers;

use JsonSerializable;
use OCA\OpenRegister\Service\FileService;
use OCP\IURLGenerator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;

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
     * Constructor for RenderObject handler.
     *
     * @param IURLGenerator      $urlGenerator       URL generator service.
     * @param FileService        $fileService        File service for managing files.
     * @param ObjectEntityMapper $objectEntityMapper Object entity mapper for database operations.
     * @param RegisterMapper     $registerMapper     Register mapper for database operations.
     * @param SchemaMapper      $schemaMapper       Schema mapper for database operations.
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly FileService $fileService,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper
    ) {
    }

    /**
     * Get a register from cache or database
     *
     * @param int|string $id The register ID
     *
     * @return Register|null The register or null if not found
     */
    private function getRegister(int|string $id): ?Register
    {
        // Return from cache if available
        if (isset($this->registersCache[$id])) {
            return $this->registersCache[$id];
        }

        try {
            $register = $this->registerMapper->find($id);
            // Cache the result
            $this->registersCache[$id] = $register;
            return $register;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get a schema from cache or database
     *
     * @param int|string $id The schema ID
     *
     * @return Schema|null The schema or null if not found
     */
    private function getSchema(int|string $id): ?Schema
    {
        // Return from cache if available
        if (isset($this->schemasCache[$id])) {
            return $this->schemasCache[$id];
        }

        try {
            $schema = $this->schemaMapper->find($id);
            // Cache the result
            $this->schemasCache[$id] = $schema;
            return $schema;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get an object from cache or database
     *
     * @param int|string $id The object ID or UUID
     *
     * @return ObjectEntity|null The object or null if not found
     */
    private function getObject(int|string $id): ?ObjectEntity
    {
        // Return from cache if available
        if (isset($this->objectsCache[$id])) {
            return $this->objectsCache[$id];
        }

        try {
            $object = $this->objectEntityMapper->find($id);
            // Cache the result
            $this->objectsCache[$id] = $object;
            $this->objectsCache[$object->getUuid()] = $object;
            return $object;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Pre-cache multiple registers
     *
     * @param array<int|string> $ids Array of register IDs to cache
     *
     * @return void
     */
    private function preloadRegisters(array $ids): void
    {
        // Filter out IDs that are not already cached and cache them
        array_filter($ids, function($id) {
            if (!isset($this->registersCache[$id])) {
                $this->getRegister($id);
            }
            return false; // Return false to ensure array_filter doesn't keep any elements
        });
    }

    /**
     * Pre-cache multiple schemas
     *
     * @param array<int|string> $ids Array of schema IDs to cache
     *
     * @return void
     */
    private function preloadSchemas(array $ids): void
    {
        // Filter out IDs that are not already cached and cache them
        array_filter($ids, function($id) {
            if (!isset($this->schemasCache[$id])) {
                $this->getSchema($id);
            }
            return false; // Return false to ensure array_filter doesn't keep any elements
        });
    }

    /**
     * Pre-cache multiple objects
     *
     * @param array<int|string> $ids Array of object IDs or UUIDs to cache
     *
     * @return void
     */
    private function preloadObjects(array $ids): void
    {
        // Filter out IDs that are not already cached and cache them
        array_filter($ids, function($id) {
            if (!isset($this->objectsCache[$id])) {
                $this->getObject($id);
            }
            return false; // Return false to ensure array_filter doesn't keep any elements
        });
    }

    /**
     * Clear all caches
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->registersCache = [];
        $this->schemasCache = [];
        $this->objectsCache = [];
    }

    /**
     * Renders an entity with optional extensions and filters.
     *
     * This method takes an ObjectEntity and applies extensions and filters to it.
     * It maintains the object's structure while allowing for property extension
     * and filtering based on the provided parameters. Additionally, it accepts
     * preloaded registers, schemas, and objects to enhance rendering performance.
     *
     * @param ObjectEntity      $entity    The entity to render
     * @param array|string|null $extend    Properties to extend the entity with
     * @param int               $depth     The depth level for nested rendering
     * @param array|null        $filter    Filters to apply to the rendered entity
     * @param array|null        $fields    Specific fields to include in the output
     * @param array|null        $registers Preloaded registers to use
     * @param array|null        $schemas   Preloaded schemas to use
     * @param array|null        $objects   Preloaded objects to use
     *
     * @return ObjectEntity The rendered entity with applied extensions and filters
     */
    public function renderEntity(
        ObjectEntity $entity,
        array|string|null $extend = [],
        int $depth = 0,
        ?array $filter = [],
        ?array $fields = [],
        ?array $registers = [],
        ?array $schemas = [],
        ?array $objects = []
    ): ObjectEntity {
        // Add preloaded registers to the global cache
        if (!empty($registers)) {
            foreach ($registers as $id => $register) {
                $this->registersCache[$id] = $register;
            }
        }

        // Add preloaded schemas to the global cache
        if (!empty($schemas)) {
            foreach ($schemas as $id => $schema) {
                $this->schemasCache[$id] = $schema;
            }
        }

        // Add preloaded objects to the global cache
        if (!empty($objects)) {
            foreach ($objects as $id => $object) {
                $this->objectsCache[$id] = $object;
            }
        }

        // Convert extend to an array if it's a string
        if (is_string($extend)) {
            $extend = explode(',', $extend);
        }

        // Get the object data as an array for manipulation
        $objectData = $entity->getObject();

        // Apply field filtering if specified
        if (!empty($fields)) {
            $filteredData = [];
            foreach ($fields as $field) {
                if (isset($objectData[$field])) {
                    $filteredData[$field] = $objectData[$field];
                }
            }
            $objectData = $filteredData;
            $entity->setObject($objectData);
        }

        // Apply filters if specified
        if (!empty($filter)) {
            foreach ($filter as $key => $value) {
                if (isset($objectData[$key]) && $objectData[$key] !== $value) {
                    $entity->setObject([]);
                    return $entity;
                }
            }
        }

        // Handle inversed properties if depth limit not reached
        if ($depth < 10) {
            $objectData = $this->handleInversedProperties(
                $entity,
                $objectData,
                $depth,
                $filter,
                $fields,
                $registers,
                $schemas,
                $objects
            );
        }

        // Handle extensions if depth limit not reached
        if (!empty($extend) && $depth < 10) {
            $objectData = $this->extendObject($entity, $extend, $objectData, $depth, $filter, $fields);
        }
        
        $entity->setObject($objectData);

        return $entity;
    }

    /**
     * Extends an object with additional data based on the extension configuration
     *
     * @param ObjectEntity      $entity The entity to extend
     * @param array            $extend Extension configuration
     * @param array            $objectData Current object data
     * @param int              $depth Current depth level
     * @param array|null       $filter Filters to apply
     * @param array|null       $fields Fields to include
     *
     * @return array The extended object data
     */
    private function extendObject(
        ObjectEntity $entity,
        array $extend,
        array $objectData,
        int $depth,
        ?array $filter = [],
        ?array $fields = []
    ): array {
        // Add register and schema context to @self if requested
        if (in_array('@self.register', $extend) || in_array('@self.schema', $extend)) {
            $self = $objectData['@self'] ?? [];
            
            if (in_array('@self.register', $extend)) {
                $register = $this->getRegister($entity->getRegister());
                if ($register !== null) {
                    $self['register'] = $register->jsonSerialize();
                }
            }

            if (in_array('@self.schema', $extend)) {
                $schema = $this->getSchema($entity->getSchema());
                if ($schema !== null) {
                    $self['schema'] = $schema->jsonSerialize();
                }
            }

            $objectData['@self'] = $self;
        }

        // Handle other extensions
        foreach ($extend as $key => $value) {
            if (isset($objectData[$key]) && is_array($value)) {
                // Handle object references
                if (isset($objectData[$key]['id']) || isset($objectData[$key]['uuid'])) {
                    $refId = $objectData[$key]['id'] ?? $objectData[$key]['uuid'];
                    $referencedObject = $this->getObject($refId);
                    if ($referencedObject !== null) {
                        $objectData[$key] = $this->renderEntity(
                            $referencedObject,
                            $value,
                            $depth + 1,
                            $filter,
                            $fields
                        );
                    }
                }
            }
        }

        return $objectData;
    }

    /**
     * Gets the inversed properties from a schema
     *
     * @param Schema $schema The schema to check for inversed properties
     *
     * @return array Array of property names that have inversedBy configurations
     */
    private function getInversedProperties(Schema $schema): array
    {
        $properties = $schema->getProperties();

        // Use array_filter to get properties with inversedBy configurations
        $inversedProperties = array_filter($properties, function($property) {
            return isset($property['inversedBy']) && !empty($property['inversedBy']);
        });

        // Extract the property names and their inversedBy values
        return array_map(function($property) {
            return $property['inversedBy'];
        }, $inversedProperties);
    }

    /**
     * Handles inversed properties for an object
     *
     * @param ObjectEntity      $entity    The entity to process
     * @param array            $objectData The current object data
     * @param int              $depth     Current depth level
     * @param array|null       $filter    Filters to apply
     * @param array|null       $fields    Fields to include
     * @param array|null       $registers Preloaded registers
     * @param array|null       $schemas   Preloaded schemas
     * @param array|null       $objects   Preloaded objects
     *
     * @return array The updated object data with inversed properties
     */
    private function handleInversedProperties(
        ObjectEntity $entity,
        array $objectData,
        int $depth,
        ?array $filter = [],
        ?array $fields = [],
        ?array $registers = [],
        ?array $schemas = [],
        ?array $objects = []
    ): array {
        // Get the schema for this object
        $schema = $this->getSchema($entity->getSchema());
        if ($schema === null) {
            return $objectData;
        }

        // Get properties that have inversedBy configurations
        $inversedProperties = $this->getInversedProperties($schema);
        if (empty($inversedProperties)) {
            return $objectData;
        }

        // Find objects that reference this object
        $referencingObjects = $this->objectEntityMapper->findByRelation($entity->getUuid());
        
        // Process each inversed property
        foreach ($inversedProperties as $propertyName => $inversedBy) {
            $objectData[$propertyName] = [];
            
            foreach ($referencingObjects as $referencingObject) {
                // Check if the referencing object has the correct inversedBy property
                $referencingData = $referencingObject->getObject();
                if (isset($referencingData[$inversedBy]) && 
                    (isset($referencingData[$inversedBy]['uuid']) && $referencingData[$inversedBy]['uuid'] === $entity->getUuid()) ||
                    (isset($referencingData[$inversedBy]['id']) && $referencingData[$inversedBy]['id'] === $entity->getId())
                ) {
                    // Add to the inversed property array
                    $objectData[$propertyName][] = $this->renderEntity(
                        $referencingObject,
                        [],  // No extensions for inversed properties to prevent loops
                        $depth + 1,
                        $filter,
                        $fields,
                        $registers,
                        $schemas,
                        $objects
                    );
                }
            }
        }

        return $objectData;
    }

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


}//end class
