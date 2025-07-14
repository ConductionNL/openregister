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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Db\DoesNotExistException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Handler class for saving objects in the OpenRegister application.
 *
 * This handler is responsible for saving objects to the database,
 * including handling relations, files, and audit trails.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\ObjectHandlers
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   1.0.0
 * @copyright 2024 Conduction b.v.
 */
class SaveObject
{

    private const URL_PATH_IDENTIFIER = 'openregister.objects.show';

    private Environment $twig;


    /**
     * Constructor for SaveObject handler.
     *
     * @param ObjectEntityMapper $objectEntityMapper Object entity data mapper.
     * @param FileService        $fileService        File service for managing files.
     * @param IUserSession       $userSession        User session service.
     * @param AuditTrailMapper   $auditTrailMapper   Audit trail mapper for logging changes.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService,
        private readonly IUserSession $userSession,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly IURLGenerator $urlGenerator,
        ArrayLoader $arrayLoader,
    ) {
        $this->twig = new Environment($arrayLoader);

    }//end __construct()


    /**
     * Scans an object for relations (UUIDs and URLs) and returns them in dot notation
     *
     * @param array  $data   The object data to scan
     * @param string $prefix The current prefix for dot notation (used in recursion)
     *
     * @return array Array of relations with dot notation paths as keys and UUIDs/URLs as values
     */
    private function scanForRelations(array $data, string $prefix=''): array
    {
        $relations = [];

        try {
            foreach ($data as $key => $value) {
                // Skip if key is not a string or is empty
                if (!is_string($key) || empty($key)) {
                    continue;
                }

                $currentPath = $prefix ? $prefix.'.'.$key : $key;

                if (is_array($value) && !empty($value)) {
                    // Recursively scan nested arrays
                    $relations = array_merge($relations, $this->scanForRelations($value, $currentPath));
                } else if (is_string($value) && !empty($value) && trim($value) !== '') {
                    // Check for UUID pattern
                    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
                        $relations[$currentPath] = $value;
                    }
                    // Check for URL pattern
                    else if (filter_var($value, FILTER_VALIDATE_URL)) {
                        $relations[$currentPath] = $value;
                    }
                }
            }//end foreach
        } catch (Exception $e) {
            error_log("[SaveObject] Error scanning for relations: ".$e->getMessage());
        }//end try

        return $relations;

    }//end scanForRelations()


    /**
     * Updates the relations property of an object entity
     *
     * @param ObjectEntity $objectEntity The object entity to update
     * @param array        $data         The object data to scan for relations
     *
     * @return ObjectEntity The updated object entity
     */
    private function updateObjectRelations(ObjectEntity $objectEntity, array $data): ObjectEntity
    {
        // Scan for relations in the object data
        $relations = $this->scanForRelations($data);

        // Set the relations on the object entity
        $objectEntity->setRelations($relations);

        return $objectEntity;

    }//end updateObjectRelations()


    /**
     * Hydrates the name and description of the entity from the object data based on schema configuration.
     *
     * This method uses the schema configuration to set the name and description fields
     * on the object entity based on the object data. It prevents an extra database call
     * by using the schema that's already available in the SaveObject handler.
     *
     * @param ObjectEntity $entity The entity to hydrate
     * @param Schema       $schema The schema containing the configuration
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    private function hydrateNameAndDescription(ObjectEntity $entity, Schema $schema): void
    {
        $config     = $schema->getConfiguration();
        $objectData = $entity->getObject();

        if (isset($config['objectNameField']) === true) {
            $name = $this->getValueFromPath($objectData, $config['objectNameField']);
            if ($name !== null) {
                $entity->setName($name);
            }
        }

        if (isset($config['objectDescriptionField']) === true) {
            $description = $this->getValueFromPath($objectData, $config['objectDescriptionField']);
            if ($description !== null) {
                $entity->setDescription($description);
            }
        }

    }//end hydrateNameAndDescription()


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
     * Set default values and constant values for properties based on the schema.
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
                error_log("[SaveObject] Schema has no properties or invalid properties structure");
                return $data;
            }
        } catch (Exception $e) {
            error_log("[SaveObject] Error getting schema object for default values: ".$e->getMessage());
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
                error_log("[SaveObject] Setting constant value for property '{$property['title']}': ".json_encode($property['const']));
            }
        }

        // Handle default values - only set if not already present in data
        $defaultValues = array_filter(array_column($properties, 'default', 'title'));

        // Remove all keys from array for which a value has already been set in $data.
        $defaultValues = array_diff_key($defaultValues, $data);

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
                error_log("[SaveObject] Error rendering default value template for property '$key': ".$e->getMessage());
                $renderedDefaultValues[$key] = $defaultValue;
                // Use original value if template fails
            }
        }

        // Merge in this order:
        // 1. Start with rendered default values (only for properties not in $data)
        // 2. Add existing data (this preserves user input)
        // 3. Override with constant values (constants always take precedence)
        $mergedData = array_merge($renderedDefaultValues, $data, $constantValues);

        if (!empty($constantValues)) {
            error_log("[SaveObject] Applied constant values: ".json_encode($constantValues));
            error_log("[SaveObject] Final merged data includes constants: ".json_encode(array_intersect_key($mergedData, $constantValues)));
        }

        return $mergedData;

    }//end setDefaultValues()


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
            error_log("[SaveObject] Error getting schema object for cascading: ".$e->getMessage());
            return $data;
        }

        // Only cascade objects that have BOTH $ref AND inversedBy (actual relations)
        // Objects with only $ref are nested objects that should remain in the data
        $objectProperties = array_filter(
          $properties,
          function (array $property) {
            return $property['type'] === 'object' 
                && isset($property['$ref']) === true 
                && isset($property['inversedBy']) === true;
          }
          );

        // Same logic for array properties - only cascade if they have inversedBy
        $arrayObjectProperties = array_filter(
          $properties,
          function (array $property) {
            return $property['type'] === 'array'
                && (isset($property['$ref']) || isset($property['items']['$ref']))
                && (isset($property['inversedBy']) === true || isset($property['items']['inversedBy']) === true);
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
                error_log("[SaveObject] Skipping cascade for property '$property': empty or invalid data");
                continue;
            }

            // Convert object to array if needed
            $objectData = is_object($data[$property]) ? (array) $data[$property] : $data[$property];

            // Skip if the object is effectively empty (only contains empty values)
            if ($this->isEffectivelyEmptyObject($objectData)) {
                error_log("[SaveObject] Skipping cascade for property '$property': object is effectively empty");
                continue;
            }

            try {
                $this->cascadeSingleObject(objectEntity: $objectEntity, definition: $definition, object: $objectData);
                unset($data[$property]);
            } catch (Exception $e) {
                error_log("[SaveObject] Error cascading single object for property '$property': ".$e->getMessage());
                // Continue with other properties even if one fails
            }
        }

        // Process array object properties that need cascading
        foreach ($arrayObjectProperties as $property => $definition) {
            // Skip if property not present, empty, or not an array
            if (isset($data[$property]) === false || empty($data[$property]) === true || !is_array($data[$property])) {
                continue;
            }

            try {
                $this->cascadeMultipleObjects(objectEntity: $objectEntity, property: $definition, propData: $data[$property]);
                unset($data[$property]);
            } catch (Exception $e) {
                error_log("[SaveObject] Error cascading multiple objects for property '$property': ".$e->getMessage());
                // Continue with other properties even if one fails
            }
        }

        return $data;

    }//end cascadeObjects()


    /**
     * Cascade multiple objects from an array of objects in the data.
     *
     * @param ObjectEntity $objectEntity The parent object.
     * @param array        $property     The property to add the objects to.
     * @param array        $propData     The data in the property.
     *
     * @return void
     * @throws Exception
     */
    private function cascadeMultipleObjects(ObjectEntity $objectEntity, array $property, array $propData): void
    {
        if (array_is_list($propData) === false) {
            error_log("[SaveObject] Data is not an array of objects for cascading multiple objects");
            return;
        }

        // Filter out empty or invalid objects
        $validObjects = array_filter(
          $propData,
          function ($object) {
            return is_array($object) && !empty($object) && !(count($object) === 1 && isset($object['id']) && empty($object['id']));
          }
          );

        if (empty($validObjects)) {
            error_log("[SaveObject] No valid objects found for cascading multiple objects");
            return;
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

        // Validate that we have the necessary configuration
        if (!isset($property['items']['$ref']) || !isset($property['items']['inversedBy'])) {
            error_log("[SaveObject] Missing required configuration for cascading multiple objects");
            return;
        }

        foreach ($validObjects as $object) {
            try {
                $this->cascadeSingleObject(objectEntity: $objectEntity, definition: $property['items'], object: $object);
            } catch (Exception $e) {
                error_log("[SaveObject] Error cascading single object in multiple objects: ".$e->getMessage());
                // Continue with other objects even if one fails
            }
        }

    }//end cascadeMultipleObjects()


    /**
     * Cascade a single object form an object in the source data
     *
     * @param  ObjectEntity $objectEntity The parent object.
     * @param  array        $definition   The definition of the property the cascaded object is found in.
     * @param  array        $object       The object to cascade.
     * @return void
     * @throws Exception
     */
    private function cascadeSingleObject(ObjectEntity $objectEntity, array $definition, array $object): void
    {
        // Validate that we have the necessary configuration
        if (!isset($definition['inversedBy']) || !isset($definition['$ref'])) {
            error_log("[SaveObject] Missing required configuration for cascading single object");
            return;
        }

        // Skip if object is empty or doesn't contain actual data
        if (empty($object) || (count($object) === 1 && isset($object['id']) && empty($object['id']))) {
            error_log("[SaveObject] Skipping cascade for empty or invalid object");
            return;
        }

        $objectId = $objectEntity->getUuid();
        if (empty($objectId)) {
            error_log("[SaveObject] Parent object UUID is empty, cannot cascade");
            return;
        }

        $object[$definition['inversedBy']] = $objectId;
        $register = $definition['register'] ?? $objectEntity->getRegister();
        $uuid     = $object['id'] ?? $object['@self']['id'] ?? null;

        try {
            $this->saveObject(register: $register, schema: $definition['$ref'], data: $object, uuid: $uuid);
        } catch (Exception $e) {
            error_log("[SaveObject] Error saving cascaded object: ".$e->getMessage());
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
            error_log("[SaveObject] Error getting schema object for inverse relations: ".$e->getMessage());
            return $data;
        }

        // Find properties that have inversedBy configuration with writeBack enabled
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
            }

            // Skip if we don't have the necessary configuration
            if (!$inverseProperty || !$targetSchema) {
                error_log("[SaveObject] Missing required configuration for inverse relations write-back for property '$propertyName'");
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
                return !empty($uuid) && is_string($uuid) && trim($uuid) !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
           }
            );

            if (empty($validUuids)) {
                error_log("[SaveObject] No valid UUIDs found for inverse relations write-back for property '$propertyName'");
                continue;
            }

            // Update each target object
            foreach ($validUuids as $targetUuid) {
                try {
                    // Find the target object
                    $targetObject = $this->objectEntityMapper->find($targetUuid);
                    if (!$targetObject) {
                        error_log("[SaveObject] Inverse relation write-back target object not found: {$targetUuid}");
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
                        schema: $targetSchema,
                        data: $targetData,
                        uuid: $targetUuid
                    );

                    error_log("[SaveObject] Updated inverse relation write-back: {$targetUuid} -> {$inverseProperty} now includes {$objectEntity->getUuid()}");
                } catch (Exception $e) {
                    error_log("[SaveObject] Failed to update inverse relation write-back for {$targetUuid}: ".$e->getMessage());
                    // Continue with other targets even if one fails
                }//end try
            }//end foreach

            // Remove the property from source object if configured to do so
            if ($removeFromSource) {
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
            $properties = json_decode(json_encode($schemaObject), associative: true)['properties'] ?? [];
            $required = json_decode(json_encode($schemaObject), associative: true)['required'] ?? [];
        } catch (Exception $e) {
            error_log("[SaveObject] Error getting schema object for sanitization: ".$e->getMessage());
            return $data;
        }

        $sanitizedData = $data;

        foreach ($properties as $propertyName => $propertyDefinition) {
            // Skip if property is not in the data
            if (!isset($sanitizedData[$propertyName])) {
                continue;
            }

            $value = $sanitizedData[$propertyName];
            $propertyType = $propertyDefinition['type'] ?? null;
            $isRequired = in_array($propertyName, $required) || ($propertyDefinition['required'] ?? false);

            // Handle object properties
            if ($propertyType === 'object') {
                if ($value === '') {
                    // Empty string to null for object properties
                    $sanitizedData[$propertyName] = null;
                    error_log("[SaveObject] Sanitized empty string to null for object property '$propertyName'");
                } elseif (is_array($value) && empty($value) && !$isRequired) {
                    // Empty object {} to null for non-required object properties
                    $sanitizedData[$propertyName] = null;
                    error_log("[SaveObject] Sanitized empty object to null for non-required object property '$propertyName'");
                } elseif (is_array($value) && empty($value) && $isRequired) {
                    // Keep empty object {} for required properties - will fail validation with clear error
                    error_log("[SaveObject] Keeping empty object for required object property '$propertyName' - will be validated");
                }
            }
            // Handle array properties
            elseif ($propertyType === 'array') {
                if ($value === '') {
                    // Empty string to null for array properties
                    $sanitizedData[$propertyName] = null;
                    error_log("[SaveObject] Sanitized empty string to null for array property '$propertyName'");
                } elseif (is_array($value)) {
                    // Check minItems constraint
                    $minItems = $propertyDefinition['minItems'] ?? 0;
                    
                    if (empty($value) && $minItems > 0) {
                        // Keep empty array [] for arrays with minItems > 0 - will fail validation with clear error
                        error_log("[SaveObject] Keeping empty array for array property '$propertyName' with minItems=$minItems - will be validated");
                    } elseif (empty($value) && $minItems === 0) {
                        // Empty array is valid for arrays with no minItems constraint
                        error_log("[SaveObject] Empty array is valid for array property '$propertyName' (no minItems constraint)");
                    } else {
                        // Handle array items that might contain empty strings
                        $sanitizedArray = [];
                        $hasChanges = false;
                        foreach ($value as $index => $item) {
                            if ($item === '') {
                                $sanitizedArray[$index] = null;
                                $hasChanges = true;
                                error_log("[SaveObject] Sanitized empty string to null in array property '$propertyName' at index $index");
                            } else {
                                $sanitizedArray[$index] = $item;
                            }
                        }
                        if ($hasChanges) {
                            $sanitizedData[$propertyName] = $sanitizedArray;
                        }
                    }
                }
            }
            // Handle other property types with empty strings
            elseif ($value === '' && in_array($propertyType, ['string', 'number', 'integer', 'boolean'])) {
                if (!$isRequired) {
                    // Convert empty string to null for non-required scalar properties
                    $sanitizedData[$propertyName] = null;
                    error_log("[SaveObject] Sanitized empty string to null for non-required $propertyType property '$propertyName'");
                } else {
                    // Keep empty string for required properties - will fail validation with clear error
                    error_log("[SaveObject] Keeping empty string for required $propertyType property '$propertyName' - will be validated");
                }
            }
        }

        return $sanitizedData;

    }//end sanitizeEmptyStringsForObjectProperties()


    /**
     * Saves an object.
     *
     * @param Register|int|string|null $register The register containing the object.
     * @param Schema|int|string        $schema   The schema to validate against.
     * @param array                    $data     The object data to save.
     * @param string|null              $uuid     The UUID of the object to update (if updating).
     * @param int|null                 $folderId The folder ID to set on the object (optional).
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
        ?int $folderId=null
    ): ObjectEntity {

        if (isset($data['@self']) && is_array($data['@self'])) {
            $selfData = $data['@self'];
        }

        // Remove the @self property from the data.
        unset($data['@self']);
        unset($data['id']);

        // Set schema ID based on input type.
        $schemaId = null;
        if ($schema instanceof Schema === true) {
            $schemaId = $schema->getId();
        } else {
            $schemaId = $schema;
            $schema   = $this->schemaMapper->find(id: $schema);
        }

        $registerId = null;
        if ($register instanceof Register === true) {
            $registerId = $register->getId();
        } else {
            $registerId = $register;
            $register   = $this->registerMapper->find(id: $register);
        }

        // Sanitize empty strings to null for object/array properties early in the process
        // This prevents empty strings from causing issues in downstream processing
        try {
            $data = $this->sanitizeEmptyStringsForObjectProperties($data, $schema);
        } catch (Exception $e) {
            error_log("[SaveObject] Error sanitizing empty strings: ".$e->getMessage());
            // Continue without sanitization if it fails
        }

        // If UUID is provided, try to find and update existing object.
        if ($uuid !== null) {
            error_log("[SaveObject] Attempting to find existing object with UUID: $uuid");
            try {
                $existingObject = $this->objectEntityMapper->find(identifier: $uuid);
                error_log("[SaveObject] Found existing object with ID: ".$existingObject->getId().", UUID: ".$existingObject->getUuid());

                // Check if '@self' metadata exists and contains published/depublished properties
                if (isset($selfData) === true) {
                    // Extract and set published property if present
                    if (array_key_exists('published', $selfData) && !empty($selfData['published'])) {
                        try {
                            // Convert string to DateTime if it's a valid date string
                            if (is_string($selfData['published']) === true) {
                                $existingObject->setPublished(new DateTime($selfData['published']));
                            }
                        } catch (Exception $exception) {
                            // Silently ignore invalid date formats
                            error_log("[SaveObject] Invalid published date format: ".$exception->getMessage());
                        }
                    } else {
                        $existingObject->setPublished(null);
                    }

                    // Extract and set depublished property if present
                    if (array_key_exists('depublished', $selfData) && !empty($selfData['depublished'])) {
                        try {
                            // Convert string to DateTime if it's a valid date string
                            if (is_string($selfData['depublished']) === true) {
                                $existingObject->setDepublished(new DateTime($selfData['depublished']));
                            }
                        } catch (Exception $exception) {
                            // Silently ignore invalid date formats
                            error_log("[SaveObject] Invalid depublished date format: ".$exception->getMessage());
                        }
                    } else {
                        $existingObject->setDepublished(null);
                    }
                }//end if

                try {
                    $data = $this->cascadeObjects(objectEntity: $existingObject, schema: $schema, data: $data);
                    $data = $this->handleInverseRelationsWriteBack(objectEntity: $existingObject, schema: $schema, data: $data);
                    $data = $this->setDefaultValues(objectEntity: $existingObject, schema: $schema, data: $data);
                    return $this->updateObject(register: $register, schema: $schema, data: $data, existingObject: $existingObject, folderId: $folderId);
                } catch (Exception $e) {
                    error_log("[SaveObject] Error processing existing object during update: ".$e->getMessage());
                    throw $e;
                }
            } catch (DoesNotExistException $e) {
                // Object not found, proceed with creating new object.
                error_log("[SaveObject] Object with UUID $uuid not found, creating new object. Error: ".$e->getMessage());
            } catch (Exception $e) {
                // Other errors during object lookup
                error_log("[SaveObject] Error looking up object with UUID $uuid: ".$e->getMessage());
                throw $e;
            }//end try
        }//end if

        // Create a new object entity.
        error_log("[SaveObject] Creating new object entity");
        $objectEntity = new ObjectEntity();
        $objectEntity->setRegister($registerId);
        $objectEntity->setSchema($schemaId);
        $objectEntity->setCreated(new DateTime());
        $objectEntity->setUpdated(new DateTime());

        // Set folder ID if provided
        if ($folderId !== null) {
            $objectEntity->setFolder((string) $folderId);
            error_log("[SaveObject] Setting folder ID on new object: ".$folderId);
        }

        // Check if '@self' metadata exists and contains published/depublished properties
        if (isset($selfData) === true) {
            // Extract and set published property if present
            if (array_key_exists('published', $selfData) && !empty($selfData['published'])) {
                try {
                    // Convert string to DateTime if it's a valid date string
                    if (is_string($selfData['published']) === true) {
                        $objectEntity->setPublished(new DateTime($selfData['published']));
                    }
                } catch (Exception $exception) {
                    // Silently ignore invalid date formats
                }
            } else {
                $objectEntity->setPublished(null);
            }

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
        }//end if

        // Set UUID if provided, otherwise generate a new one.
        if ($uuid !== null) {
            $objectEntity->setUuid($uuid);
            // @todo: check if this is a correct uuid.
        } else {
            $objectEntity->setUuid(Uuid::v4());
        }

        $objectEntity->setUri(
                $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute(
            self::URL_PATH_IDENTIFIER,
                [
                    'register' => $register instanceof Register === true && $schema->getSlug() !== null ? $register->getSlug() : $registerId,
                    'schema'   => $schema instanceof Schema === true && $schema->getSlug() !== null ? $schema->getSlug() : $schemaId,
                    'id'       => $objectEntity->getUuid(),
                ]
                )
                )
                );

        // Set default values.
        if ($schema instanceof Schema === false) {
            $schema = $this->schemaMapper->find($schemaId);
        }

        try {
            $data = $this->cascadeObjects($objectEntity, $schema, $data);
            $data = $this->handleInverseRelationsWriteBack($objectEntity, $schema, $data);
            $data = $this->setDefaultValues($objectEntity, $schema, $data);
        } catch (Exception $e) {
            error_log("[SaveObject] Error processing new object data: ".$e->getMessage());
            throw $e;
        }

        $objectEntity->setObject($data);

        // Hydrate name and description from schema configuration.
        try {
            $this->hydrateNameAndDescription($objectEntity, $schema);
        } catch (Exception $e) {
            error_log("[SaveObject] Error hydrating name and description: ".$e->getMessage());
            // Continue without hydration if it fails
        }

        // Set user information if available.
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $objectEntity->setOwner($user->getUID());
        }

        // Update object relations.
        try {
            $objectEntity = $this->updateObjectRelations($objectEntity, $data);
        } catch (Exception $e) {
            error_log("[SaveObject] Error updating object relations: ".$e->getMessage());
            // Continue without relations if it fails
        }

        // Save the object to database.
        error_log("[SaveObject] Inserting new object to database");
        $savedEntity = $this->objectEntityMapper->insert($objectEntity);
        error_log("[SaveObject] New object inserted with ID: ".$savedEntity->getId().", UUID: ".$savedEntity->getUuid());

        // Create audit trail for creation.
        $log = $this->auditTrailMapper->createAuditTrail(old: null, new: $savedEntity);
        $savedEntity->setLastLog($log->jsonSerialize());

        // Handle file properties.
        foreach ($data as $propertyName => $value) {
            if ($this->isFileProperty($value) === true) {
                $this->handleFileProperty($savedEntity, $data, $propertyName);
            }
        }

        return $savedEntity;

    }//end saveObject()


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
     * Checks if a value represents a file property.
     *
     * @param mixed $value The value to check.
     *
     * @return bool Whether the value is a file property.
     */
    private function isFileProperty($value): bool
    {
        return is_string($value) && strpos($value, 'data:') === 0;

    }//end isFileProperty()


    /**
     * Handles a file property during save.
     *
     * @param ObjectEntity $objectEntity The object entity being saved.
     * @param array        $object       The object data.
     * @param string       $propertyName The name of the file property.
     *
     * @return void
     */
    private function handleFileProperty(ObjectEntity $objectEntity, array $object, string $propertyName): void
    {
        $fileContent = $object[$propertyName];
        $fileName    = $propertyName.'_'.time();
        $this->fileService->addFile(objectEntity: $objectEntity, fileName: $fileName, content: $fileContent);

    }//end handleFileProperty()


    /**
     * Updates an existing object.
     *
     * @param Register|int|string $register       The register containing the object.
     * @param Schema|int|string   $schema         The schema to validate against.
     * @param array               $data           The updated object data.
     * @param ObjectEntity        $existingObject The existing object to update.
     * @param int|null            $folderId       The folder ID to set on the object (optional).
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
        ?int $folderId=null
    ): ObjectEntity {
        error_log("[SaveObject] Updating existing object ID: ".$existingObject->getId().", UUID: ".$existingObject->getUuid());

        // Store the old state for audit trail.
        $oldObject = clone $existingObject;

        // Lets filter out the id and @self properties from the old object.
        $oldObjectData = $oldObject->getObject();

        $oldObject->setObject($oldObjectData);

        // Set register ID based on input type.
        $registerId = null;
        if ($register instanceof Register) {
            $registerId = $register->getId();
        } else {
            $registerId = $register;
        }

        // Set schema ID based on input type.
        $schemaId = null;
        if ($schema instanceof Schema) {
            $schemaId = $schema->getId();
        } else {
            $schemaId = $schema;
        }

        // Check if '@self' metadata exists and contains published/depublished properties
        if (isset($data['@self']) && is_array($data['@self'])) {
            $selfData = $data['@self'];

            // Extract and set published property if present
            if (array_key_exists('published', $selfData) && !empty($selfData['published'])) {
                try {
                    // Convert string to DateTime if it's a valid date string
                    if (is_string($selfData['published']) === true) {
                        $existingObject->setPublished(new DateTime($selfData['published']));
                    }
                } catch (Exception $exception) {
                    // Silently ignore invalid date formats
                }
            } else {
                $existingObject->setPublished(null);
            }

            // Extract and set depublished property if present
            if (array_key_exists('depublished', $selfData) && !empty($selfData['depublished'])) {
                try {
                    // Convert string to DateTime if it's a valid date string
                    if (is_string($selfData['depublished']) === true) {
                        $existingObject->setDepublished(new DateTime($selfData['depublished']));
                    }
                } catch (Exception $exception) {
                    // Silently ignore invalid date formats
                }
            } else {
                $existingObject->setDepublished(null);
            }
        }//end if

        // Remove @self and id from the data before setting object
        unset($data['@self'], $data['id']);

        // Sanitize empty strings to null for object/array properties early in the process
        // This prevents empty strings from causing issues in downstream processing
        try {
            if ($schema instanceof Schema) {
                $data = $this->sanitizeEmptyStringsForObjectProperties($data, $schema);
            } else {
                $schemaObject = $this->schemaMapper->find($schemaId);
                $data = $this->sanitizeEmptyStringsForObjectProperties($data, $schemaObject);
            }
        } catch (Exception $e) {
            error_log("[SaveObject] Error sanitizing empty strings during update: ".$e->getMessage());
            // Continue without sanitization if it fails
        }

        // Get schema object for processing
        $schemaObject = null;
        if ($schema instanceof Schema) {
            $schemaObject = $schema;
        } else {
            $schemaObject = $this->schemaMapper->find($schemaId);
        }

        // Process the data with the same logic as saveObject to prevent 404 errors
        try {
            $data = $this->cascadeObjects($existingObject, $schemaObject, $data);
            $data = $this->handleInverseRelationsWriteBack($existingObject, $schemaObject, $data);
            $data = $this->setDefaultValues($existingObject, $schemaObject, $data);
        } catch (Exception $e) {
            error_log("[SaveObject] Error processing object data during update: ".$e->getMessage());
            throw $e;
        }

        // Update the object properties.
        $existingObject->setRegister($registerId);
        $existingObject->setSchema($schemaId);
        $existingObject->setObject($data);
        $existingObject->setUpdated(new DateTime());

        // Set folder ID if provided
        if ($folderId !== null) {
            $existingObject->setFolder((string) $folderId);
            error_log("[SaveObject] Setting folder ID on updated object: ".$folderId);
        }

        // Hydrate name and description from schema configuration.
        $this->hydrateNameAndDescription($existingObject, $schemaObject);

        // Update object relations.
        $existingObject = $this->updateObjectRelations($existingObject, $data);

        // Save the object to database.
        error_log("[SaveObject] Updating existing object in database");
        $updatedEntity = $this->objectEntityMapper->update($existingObject);
        error_log("[SaveObject] Object updated with ID: ".$updatedEntity->getId().", UUID: ".$updatedEntity->getUuid());

        // Create audit trail for update.
        $log = $this->auditTrailMapper->createAuditTrail(old: $oldObject, new: $updatedEntity);
        $updatedEntity->setLastLog($log->jsonSerialize());

        // Handle file properties.
        foreach ($data as $propertyName => $value) {
            if ($this->isFileProperty($value) === true) {
                $this->handleFileProperty($updatedEntity, $data, $propertyName);
            }
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


}//end class
