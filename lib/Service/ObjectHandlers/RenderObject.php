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
use OCA\OpenRegister\Service\FileService;
use OCP\IURLGenerator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;

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
     * @param SchemaMapper       $schemaMapper       Schema mapper for database operations.
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly FileService $fileService,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper
    ) {

    }//end __construct()


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
        // Return from cache if available.
        if (isset($this->objectsCache[$id]) === true) {
            return $this->objectsCache[$id];
        }

        try {
            $object = $this->objectEntityMapper->find($id);
            // Cache the result.
            $this->objectsCache[$id] = $object;
            $this->objectsCache[$object->getUuid()] = $object;
            return $object;
        } catch (\Exception $e) {
            return null;
        }

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
        array | string | null $extend=[],
        int $depth=0,
        ?array $filter=[],
        ?array $fields=[],
        ?array $registers=[],
        ?array $schemas=[],
        ?array $objects=[]
    ): ObjectEntity {

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

        // Convert extend to an array if it's a string.
        if (is_string($extend) === true) {
            $extend = explode(',', $extend);
        }

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

        // Handle inversed properties if depth limit not reached.
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

        // Handle extensions if depth limit not reached.
        if (empty($extend) === false && $depth < 10) {
            $objectData = $this->extendObject($entity, $extend, $objectData, $depth, $filter, $fields);
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
            $data = $objectData->get($root);
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
     * @param array $data   The data to extend.
     * @param array $extend The fields to extend.
     * @param int   $depth  The current depth.
     *
     * @return array
     *
     * @throws \OCP\DB\Exception
     */
    private function handleExtendDot(array $data, array &$extend, int $depth): array
    {
        $data = $this->handleWildcardExtends($data, $extend, $depth + 1);

        $data = new Dot($data);

        foreach ($extend as $override => $key) {
            // Skip if the key does not have to be extended.
            if ($data->has(keys: $key) === false) {
                continue;
            }

            // Skip if the key starts with '@' (special fields)
            if (str_starts_with($key, '@')) {
                continue;
            }

            // Get all the keys that should be extended withtin the extended object.
            $keyExtends = array_map(
                function (string $extendedKey) use ($key) {
                    return substr(string: $extendedKey, offset: strlen(string: $key.'.'));
                },
                array_filter(
                    $extend,
                    function (string $singleKey) use ($key) {
                        return str_starts_with(haystack: $singleKey, needle: $key.'.');
                    }
                )
            );

            $value = $data->get(key: $key);


            // Make sure arrays are arrays.
            if ($value instanceof Dot) {
                $value = $value->jsonSerialize();
            }

            // Skip if the value is null
            if ($value === null) {
                continue;
            }

            // Extend the object(s).
            if (is_array($value) === true) {
                // Filter out null values and values starting with '@' before mapping
                $value = array_filter(
                        $value,
                        function ($v) {
                            return $v !== null && (is_string($v) === false || str_starts_with($v, '@') === false);
                        }
                        );

                $renderedValue = array_map(
                        function (string | int | array $identifier) use ($depth, $keyExtends) {

                            $object = $this->getObject(id: $identifier);
                            if ($object === null) {
                                $multiObject = $this->objectEntityMapper->findAll(filters: ['identifier' => $identifier]);

                                if (count($multiObject) === 1) {
                                    $object = array_shift($multiObject);
                                } else {
                                    return null;
                                }
                            }

                            return $this->renderEntity(entity: $object, extend: $keyExtends, depth: $depth + 1)->jsonSerialize();
                        },
                        $value
                        );

                // Filter out any null values that might have been returned from the mapping
                $renderedValue = array_filter(
                        $renderedValue,
                        function ($v) {
                            return $v !== null;
                        }
                        );

                if (is_numeric($override) === true) {
                    $data->set(keys: $key, value: array_values($renderedValue));
                    // Reset array keys
                } else {
                    $data->set(keys: $override, value: array_values($renderedValue));
                    // Reset array keys
                }
            } else {
                // Skip if the value starts with '@' or '_'
                if (is_string($value) && (str_starts_with($value, '@') || str_starts_with($value, '_'))) {
                    continue;
                }


				if(filter_var($value, FILTER_VALIDATE_URL) !== false) {
					$path = parse_url($value, PHP_URL_PATH);
					$pathExploded = explode('/', $path);

					$value = end($pathExploded);
				}


                $object = $this->getObject(id: $value);

                if ($object === null) {
                    $multiObject = $this->objectEntityMapper->findAll(filters: ['identifier' => $value]);

                    if (count($multiObject) === 1) {
                        $object = array_shift($multiObject);
                    } else {
                        continue;
                    }
                }

                if (is_numeric($override) === true) {
                    $data->set(keys: $key, value: $this->renderEntity(entity: $object, extend: $keyExtends, depth: $depth + 1)->jsonSerialize());
                } else {
                    $data->set(keys: $override, value: $this->renderEntity(entity: $object, extend: $keyExtends, depth: $depth + 1)->jsonSerialize());
                }

            }//end if

        }//end foreach

        return $data->jsonSerialize();

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
     *
     * @return array The extended object data
     */
    private function extendObject(
        ObjectEntity $entity,
        array $extend,
        array $objectData,
        int $depth,
        ?array $filter=[],
        ?array $fields=[]
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

        $objectDataDot = $this->handleExtendDot($objectData, $extend, $depth);

        return $objectDataDot;

    }//end extendObject()


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

        // Use array_filter to get properties with inversedBy configurations.
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
        $ids            = array_map(
                function (ObjectEntity $object) {
                    return $object->getUuid();
                },
                $referencingObjects
                );

        $objectsToCache = array_combine(keys: $ids, values: $referencingObjects);
        $this->objectsCache = array_merge($objectsToCache, $this->objectsCache);

        // Process each inversed property.
        foreach ($inversedProperties as $propertyName => $inversedBy) {
            $objectData[$propertyName] = [];

            $inversedObjects = array_values(array_filter(
                    $referencingObjects,
                    function (ObjectEntity $object) use ($propertyName, $inversedBy, $entity) {
                        $data   = $object->jsonSerialize();
                        $schema = $object->getSchema();

                        // @TODO: accomodate schema references.
                        if (isset($inversedBy['$ref']) === true) {
                            $schemaId = str_contains(haystack: $inversedBy['$ref'], needle: '/') ? substr(string: $inversedBy['$ref'], offset: strrpos($inversedBy['$ref'], '/') + 1) : $inversedBy['$ref'];
                        } else if (isset($inversedBy['items']['$ref']) === true) {
                            $schemaId = str_contains(haystack: $inversedBy['items']['$ref'], needle: '/') ? substr(string: $inversedBy['items']['$ref'], offset: strrpos($inversedBy['items']['$ref'], needle: '/') + 1) : $inversedBy['items']['$ref'];
                        } else {
                            return false;
                        }//end if

						return isset($data[$inversedBy['inversedBy']]) === true && (str_ends_with(haystack: $data[$inversedBy['inversedBy']], needle: $entity->getUuid()) || $data[$inversedBy['inversedBy']] === $entity->getId()) && $schemaId === (int) $object->getSchema();
					}
                    ));

            $inversedUuids = array_map(
                    function (ObjectEntity $object) {
                        return $object->getUuid();
                    },
                    $inversedObjects
                    );

            if ($inversedBy['type'] === 'array') {
                $objectData[$propertyName] = $inversedUuids;
            } else {
                $objectData[$propertyName] = end($inversedUuids);
            }

        }//end foreach

        return $objectData;

    }//end handleInversedProperties()


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
